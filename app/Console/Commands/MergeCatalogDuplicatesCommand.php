<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Cataloger;
use App\Models\CatalogVolume;
use App\Models\Library;
use App\Suggestions\LibraryMerger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Folds duplicate libraries and catalogue volumes and corrects misattributed volumes (backlog item 38).
 *
 * Data: database/data/catalog_duplicates.json, approved by the user.
 *  - libraries: each source is folded into its target with LibraryMerger, the same tool as an approved
 *    «duplicate» suggestion, and logged as a merge so the audit log can undo it;
 *  - volume_fixes: a volume that carried another library's catalogue gets its own title and catalogers,
 *    and its copies the right cataloger; a split sends the copies citing another volume to it;
 *  - volume_merges: a duplicate volume's copies and catalogers move to the kept one, which takes its
 *    MTIF id when it has none, and the duplicate is deleted.
 * Everything is matched by natural key (library slug, MTIF id, or library and title). Safe to run again:
 * a source library or duplicate volume already gone is skipped.
 */
class MergeCatalogDuplicatesCommand extends Command
{
    protected $signature = 'catalogs:merge-duplicates
        {--dry-run : Report the changes without saving them}
        {--data= : Path to the data file (default database/data/catalog_duplicates.json)}';

    protected $description = 'Fold duplicate libraries and catalogue volumes and correct misattributed volumes by natural key';

    protected bool $dry = false;

    public function handle(LibraryMerger $merger): int
    {
        DB::disableQueryLog();
        $this->dry = (bool) $this->option('dry-run');
        $path = $this->option('data') ?: database_path('data/catalog_duplicates.json');
        if (! File::exists($path)) {
            $this->error("Data file not found: {$path}");

            return self::FAILURE;
        }
        $data = json_decode(File::get($path), true);
        if ($this->dry) {
            $this->warn('Dry run: nothing is saved.');
        }
        $this->line('Before: ' . Library::count() . ' libraries, ' . CatalogVolume::count() . ' volumes');

        foreach ($data['libraries'] ?? [] as $group) {
            $this->mergeLibraries($merger, $group);
        }
        $run = function () use ($data) {
            foreach ($data['volume_fixes'] ?? [] as $fix) {
                $this->fixVolume($fix);
            }
            foreach ($data['volume_merges'] ?? [] as $merge) {
                $this->mergeVolumes($merge);
            }
            if (! $this->dry) {
                LinkCatalogVolumesCommand::recountVolumesAndCatalogers();
            }
        };
        $this->dry ? $run() : DB::transaction($run);

        if (! $this->dry) {
            $this->info('After: ' . Library::count() . ' libraries, ' . CatalogVolume::count() . ' volumes');
        }

        return self::SUCCESS;
    }

    protected function mergeLibraries(LibraryMerger $merger, array $group): void
    {
        $target = Library::where('slug', $group['target'])->first()
            ?? throw new \RuntimeException("Target library not found: {$group['target']}");
        foreach ($group['sources'] as $spec) {
            $source = Library::where('slug', $spec['slug'])->first();
            if (! $source) {
                continue;
            }
            $copies = DB::table('manuscripts')->where('library_id', $source->id)->count();
            $volumes = $source->catalogVolumes()->count();
            $this->line(sprintf('library %-45s → %s (%d copies, %d volumes)', "{$source->name} ({$source->city})", "{$target->name} ({$target->city})", $copies, $volumes));
            if ($this->dry) {
                continue;
            }
            DB::transaction(function () use ($merger, $source, $target) {
                $result = $merger->merge($source, $target);
                AuditLog::create([
                    'user_id' => null,
                    'auditable_type' => Library::class,
                    'auditable_id' => $target->id,
                    'action' => 'merge',
                    'old_values' => $result,
                    'new_values' => ['target_id' => $target->id, 'added_aliases' => $result['added_aliases'], 'command' => 'catalogs:merge-duplicates'],
                ]);
            });
            $target->refresh();
        }
    }

    protected function fixVolume(array $fix): void
    {
        $volume = $this->findFixed($fix);
        if (! $volume) {
            $this->warn('volume to fix not found: ' . $fix['titles'][0]);

            return;
        }
        $set = $fix['set'];
        if (array_key_exists('library', $set)) {
            $set['library_id'] = $set['library'] ? Library::where('slug', $set['library'])->firstOrFail()->id : null;
            unset($set['library']);
        }
        $catalogers = array_key_exists('catalogers', $fix) ? $this->catalogers($fix['catalogers']) : null;

        // copies citing a volume of the split go to it
        $splits = [];
        foreach ($fix['split'] ?? [] as $spec) {
            $splits[(int) $spec['number']] = $spec;
        }
        $moves = [];
        if ($splits) {
            DB::table('manuscripts')->where('catalog_volume_id', $volume->id)->select(['id', 'metadata', 'raw_text'])->orderBy('id')
                ->each(function ($ms) use ($splits, &$moves) {
                    $parsed = LinkCatalogVolumesCommand::parseCitation(LinkCatalogVolumesCommand::citationOf($ms->metadata, $ms->raw_text), 'ف');
                    if ($parsed && isset($splits[$parsed['volume']])) {
                        $moves[$parsed['volume']][] = $ms->id;
                    }
                });
        }
        $copies = DB::table('manuscripts')->where('catalog_volume_id', $volume->id)->count();
        $names = $catalogers ? ($catalogers->pluck('name')->implode('، ') ?: 'none') : 'unchanged';
        $this->line(sprintf('fix   #%d «%s» → «%s», catalogers: %s, %d copies%s', $volume->id, $volume->title, $set['title'] ?? $volume->title,
            $names, $copies, $moves ? ', split: ' . collect($moves)->map(fn ($ids, $n) => "ج {$n}: " . count($ids))->implode('، ') : ''));
        if ($this->dry) {
            return;
        }

        $volume->update($set + ['metadata' => array_merge($volume->metadata ?? [], ['cataloger_note' => $fix['source']])]);
        $targets = [$volume];
        foreach ($moves as $number => $ids) {
            $spec = $splits[$number];
            $split = CatalogVolume::where('library_id', $volume->library_id)->where('title', $spec['title'])->first()
                ?? CatalogVolume::create([
                    'title' => $spec['title'], 'volume_number' => $number, 'library_id' => $volume->library_id,
                    'catalog_id' => $volume->catalog_id, 'publication_year' => $spec['publication_year'] ?? null,
                    'metadata' => ['cataloger_note' => $fix['source']],
                ]);
            DB::table('manuscripts')->whereIn('id', $ids)->update(['catalog_volume_id' => $split->id]);
            $targets[] = $split;
        }
        if ($catalogers !== null) {
            foreach ($targets as $target) {
                $target->catalogers()->sync($catalogers->mapWithKeys(fn ($c) => [$c->id => ['role' => 'cataloger']])->all());
                DB::table('manuscripts')->where('catalog_volume_id', $target->id)->update(['cataloger_id' => $catalogers->first()?->id]);
            }
        }
    }

    protected function findFixed(array $fix): ?CatalogVolume
    {
        if (! empty($fix['mtif']) && ($volume = CatalogVolume::where('mtif_entry_id', $fix['mtif'])->first())) {
            return $volume;
        }
        $library = Library::where('slug', $fix['library'])->first();
        $query = CatalogVolume::whereIn('title', $fix['titles'])->where(function ($q) use ($library, $fix) {
            $q->where('library_id', $library?->id);
            if (! empty($fix['also_library_null'])) {
                $q->orWhereNull('library_id');
            }
        });

        return $query->first();
    }

    protected function mergeVolumes(array $merge): void
    {
        $drop = $this->findVolume($merge['drop']);
        if (! $drop) {
            return;
        }
        $keep = $this->findVolume($merge['keep']);
        if (! $keep) {
            // the kept volume may not exist yet in a dry run, after a library merge that is only reported
            $this->warn('kept volume not found for ' . json_encode($merge['keep'], JSON_UNESCAPED_UNICODE));

            return;
        }
        // after a run the kept volume carries the duplicate's MTIF id, so that key now finds the kept one
        if ($drop->is($keep)) {
            return;
        }
        $copies = DB::table('manuscripts')->where('catalog_volume_id', $drop->id)->count();
        $this->line(sprintf('volume #%d «%s» → #%d «%s» (%d copies; %s)', $drop->id, $drop->title, $keep->id, $keep->title, $copies, $merge['note'] ?? ''));
        if ($this->dry) {
            return;
        }
        if (! empty($merge['reassign'])) {
            // the duplicate's catalogers were wrong: its copies take the kept volume's cataloger
            $catalogerId = $keep->catalogers()->wherePivot('role', 'cataloger')->orderBy('catalog_volume_cataloger.id')->value('catalogers.id');
            DB::table('manuscripts')->where('catalog_volume_id', $drop->id)->update(['catalog_volume_id' => $keep->id, 'cataloger_id' => $catalogerId]);
        } else {
            DB::table('manuscripts')->where('catalog_volume_id', $drop->id)->update(['catalog_volume_id' => $keep->id]);
            $keep->catalogers()->syncWithoutDetaching(
                $drop->catalogers()->get()->mapWithKeys(fn ($c) => [$c->id => ['role' => $c->pivot->role]])->all()
            );
        }
        $mtif = $drop->mtif_entry_id;
        $drop->catalogers()->detach();
        $drop->delete();
        if (! $keep->mtif_entry_id && $mtif) {
            $keep->update(['mtif_entry_id' => $mtif]);
        }
    }

    protected function findVolume(array $key): ?CatalogVolume
    {
        if (isset($key['mtif'])) {
            return CatalogVolume::where('mtif_entry_id', $key['mtif'])->first();
        }
        $library = Library::where('slug', $key['library'])->first();

        return $library ? CatalogVolume::where('library_id', $library->id)->where('title', $key['title'])->first() : null;
    }

    protected function catalogers(array $specs): \Illuminate\Support\Collection
    {
        return collect($specs)->map(fn ($spec) => Cataloger::where('mtif_entry_id', $spec['mtif_entry_id'])->first()
            ?? throw new \RuntimeException("Cataloger with MTIF id {$spec['mtif_entry_id']} ({$spec['name']}) not found"));
    }
}

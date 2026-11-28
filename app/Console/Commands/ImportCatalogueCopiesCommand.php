<?php

namespace App\Console\Commands;

use App\Models\CatalogVolume;
use App\Models\Cataloger;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\SourceEntry;
use App\Models\Work;
use App\Suggestions\ManuscriptCreator;
use App\Suggestions\RecordCreator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Makes the copies a printed library catalogue describes and Fankha never cites (backlog item 158, step 14.447): the
 * catalogue's library, its volume, and a copy for each entry, from a data file under database/data/catalogue_copies/
 * built outside the app from the catalogue's text (for the Eshkevari library, ../fahares-sources/eshkevari/).
 *
 * Each copy goes through ManuscriptCreator, as one made in the panel: under a work the site has (by its slug, the same
 * on production) or under a new one with the catalogue's title, author, subject and language. Its place in the
 * catalogue is the catalogue's own entry of it (origin catalogue), verified as made: the copy is made from that page,
 * so the citation is not in doubt and takes no ⓘ (step 14.450). Its provenance says it comes from the printed catalogue.
 *
 * Safe to run again: a copy the library already has under the shelfmark is left alone and counted as unchanged. Its
 * entry, if the first version of this command wrote it as a match awaiting review (step 14.447), is settled as the
 * catalogue's own entry, unless a reviewer has since acted on it.
 */
class ImportCatalogueCopiesCommand extends Command
{
    protected $signature = 'catalogs:import-copies
        {file : The data file (JSON: library, volume, copies)}
        {--dry-run : Report what would be made, without saving}
        {--undo : Take back the copies this data made (ManuscriptCreator::undo), then the volume and library when nothing else is left in them}';

    protected $description = 'Make the library, volume and copies of a printed catalogue that Fankha does not cite';

    private const VOLUME_COLUMNS = ['title', 'volume_number', 'facsimile_code', 'page_first', 'page_last', 'pages_count', 'publisher',
        'publication_year', 'manuscripts_range', 'edition_label'];

    public function handle(ManuscriptCreator $creator, RecordCreator $records): int
    {
        $path = $this->argument('file');
        $path = File::exists($path) ? $path : base_path($path);
        if (! File::exists($path)) {
            $this->error("Data file not found: {$path}");

            return self::FAILURE;
        }
        $data = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        $dry = (bool) $this->option('dry-run');
        if ($this->option('undo')) {
            return $this->undo($data, $creator, $dry);
        }

        // works and people named by slug must all be there before anything is made
        $missing = $this->missingSlugs($data['copies']);
        if ($missing) {
            $this->error('Not on the site: ' . implode(', ', array_slice($missing, 0, 20)));

            return self::FAILURE;
        }

        $library = Library::where('slug', $data['library']['slug'])->first();
        $this->line(($library ? 'unchanged' : 'create') . ': library ' . $data['library']['name'] . '، ' . $data['library']['city']);
        if (! $library && $dry) {
            $this->reportCopies($data['copies'], null);
            $this->info('(dry run)');

            return self::SUCCESS;
        }

        return DB::transaction(function () use ($data, $dry, $library, $creator, $records) {
            $library ??= Library::create(collect($data['library'])->only(['slug', 'name', 'city', 'country', 'full_name'])->all() + ['manuscripts_count' => 0]);
            $volume = $this->volume($library, $data['volume'], $dry);
            if (! $volume) {
                $this->reportCopies($data['copies'], $library);
                $this->info('(dry run)');

                return self::SUCCESS;
            }
            $source = DB::table('source_volumes')->where('id', $volume->id)->first();
            if (! $source) {
                throw new \RuntimeException("The volume #{$volume->id} has no row in source_volumes: the volume sync did not run.");
            }

            $counts = ['create' => 0, 'unchanged' => 0, 'new_work' => 0, 'settled' => 0];
            foreach ($data['copies'] as $copy) {
                $have = $creator->duplicates($library->id, $copy['shelfmark']);
                if ($have->isNotEmpty()) {
                    $counts['unchanged']++;
                    // step 14.447 wrote the entry as a match awaiting review; a reviewer's word, if any, stays
                    $stale = SourceEntry::where('citable_type', Manuscript::class)->whereIn('citable_id', $have->pluck('id'))
                        ->where('source_volume_id', $source->id)->where('origin', SourceEntry::ORIGIN_MATCH)
                        ->where('status', SourceEntry::STATUS_UNVERIFIED)->whereNull('reviewed_by');
                    $counts['settled'] += $dry ? $stale->count()
                        : $stale->update(['origin' => SourceEntry::ORIGIN_CATALOGUE, 'status' => SourceEntry::STATUS_VERIFIED, 'updated_at' => now()]);

                    continue;
                }
                $counts['create']++;
                $counts['new_work'] += isset($copy['work']['slug']) ? 0 : 1;
                if ($dry) {
                    continue;
                }
                $manuscript = $creator->create($this->form($copy, $library));
                $this->describeWork($manuscript, $copy, $records);
                SourceEntry::create([
                    'citable_type' => Manuscript::class,
                    'citable_id' => $manuscript->id,
                    'source_id' => $source->source_id,
                    'source_volume_id' => $source->id,
                    'volume_number' => $volume->volume_number,
                    'page_start' => $copy['page'],
                    'page_end' => $copy['page_end'] ?? null,
                    'text_page' => $copy['page'],
                    'text_volume_number' => $volume->volume_number,
                    // the entry as printed: its first lines link the copy and its work on the page text (PrintedPage::marks)
                    'entry_text' => $copy['entry_text'] ?? null,
                    'copy_text' => $copy['copy_text'] ?? null,
                    'text_status' => isset($copy['entry_text']) || isset($copy['copy_text']) ? SourceEntry::TEXT_AUTO : SourceEntry::TEXT_NONE,
                    'role' => SourceEntry::ROLE_ENTRY,
                    // the copy is made from this very entry: its place in the catalogue is not in doubt (step 14.450)
                    'origin' => SourceEntry::ORIGIN_CATALOGUE,
                    'status' => SourceEntry::STATUS_VERIFIED,
                    'reviewed_at' => now(),
                ]);
            }
            if (! $dry) {
                $library->update(['manuscripts_count' => Manuscript::where('library_id', $library->id)->count()]);
                // the «منابع» page lists the sources from a day's cache (sources:shelf)
                Cache::forget('sources:shelf');
            }
            $this->info(sprintf('%d copies made (%d under new works), %d unchanged%s%s.', $counts['create'], $counts['new_work'],
                $counts['unchanged'], $counts['settled'] ? ", {$counts['settled']} entries settled as the catalogue's own" : '', $dry ? ' (dry run)' : ''));

            return self::SUCCESS;
        });
    }

    /** The catalogue's volume, made on the first run (it joins the source registry through the volume sync), with its cataloguers. */
    private function volume(Library $library, array $spec, bool $dry): ?CatalogVolume
    {
        $volume = CatalogVolume::where('library_id', $library->id)->where('facsimile_code', $spec['facsimile_code'])
            ->where('volume_number', $spec['volume_number'])->first();
        $this->line(($volume ? 'unchanged' : 'create') . ': volume ' . $spec['title']);
        if ($dry && ! $volume) {
            return null;
        }
        if (! $volume) {
            $volume = CatalogVolume::create(['library_id' => $library->id] + collect($spec)->only(self::VOLUME_COLUMNS)->all());
        }
        foreach ($spec['catalogers'] ?? [] as $c) {
            $cataloger = Cataloger::where('slug', $c['slug'])->first();
            if ($cataloger && ! $volume->catalogers()->whereKey($cataloger->id)->exists() && ! $dry) {
                $volume->catalogers()->attach($cataloger->id, ['role' => $c['role'] ?? 'cataloger']);
            }
        }

        return $volume;
    }

    /** The panel form's fields for one copy (Admin\ManuscriptController::rules). */
    private function form(array $copy, Library $library): array
    {
        $work = $copy['work'];
        $existing = isset($work['slug']);

        return array_filter([
            'library_id' => $library->id,
            'shelfmark' => $copy['shelfmark'],
            'work_mode' => $existing ? 'existing' : 'new',
            'work_id' => $existing ? Work::where('slug', $work['slug'])->value('id') : null,
            'work_title' => $existing ? null : $work['title'],
            'author_name' => $existing ? null : ($work['author_name'] ?? null),
            'author_id' => ! $existing && isset($work['author_slug']) ? Person::where('slug', $work['author_slug'])->value('id') : null,
            'scribe_name' => $copy['scribe_name'] ?? null,
            'copy_date_raw' => $copy['copy_date_raw'] ?? null,
            'folios' => $copy['folios'] ?? null,
            'pages' => $copy['pages'] ?? null,
            'range' => $copy['range'] ?? null,
            'lines' => $copy['lines'] ?? null,
            'dimensions' => $copy['dimensions'] ?? null,
            'binding' => $copy['binding'] ?? null,
            'script_names' => $copy['script_names'] ?? null,
            'incipit_text' => $copy['incipit_text'] ?? null,
            'explicit_text' => $copy['explicit_text'] ?? null,
            'source_kind' => Manuscript::PROVENANCE_CATALOGUE,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** A new work takes the catalogue's subject, language and description, and its subjects and languages are linked by name. */
    private function describeWork(Manuscript $manuscript, array $copy, RecordCreator $records): void
    {
        $work = $copy['work'];
        if (isset($work['slug'])) {
            return;
        }
        $model = $manuscript->work;
        $model->update(array_filter([
            'subject_summary' => $work['subject'] ?? null,
            'language_summary' => isset($work['language']) ? str_replace(' و ', '، ', $work['language']) : null,
            'description' => $work['description'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''));
        $records->linkTerms($model);
        $model->searchable();
    }

    /**
     * Takes back what the data made: each copy of the library under one of its shelfmarks that was made from the panel
     * form (its «create» audit entry), with its source entries and the new work made for it; then the volume and the
     * library, when no copy is left in them. A copy a reviewer has since changed is taken back all the same: undo is
     * for a rehearsal or a run gone wrong, before anyone has worked on the copies.
     */
    private function undo(array $data, ManuscriptCreator $creator, bool $dry): int
    {
        $library = Library::where('slug', $data['library']['slug'])->first();
        if (! $library) {
            $this->info('Nothing to undo: the library is not on the site.');

            return self::SUCCESS;
        }
        $shelfmarks = collect($data['copies'])->pluck('shelfmark')->all();
        $ids = Manuscript::where('library_id', $library->id)->whereIn('shelfmark', $shelfmarks)->pluck('id');
        $logs = \App\Models\AuditLog::where('auditable_type', Manuscript::class)->whereIn('auditable_id', $ids)
            ->where('action', 'create')->get()->filter(fn ($log) => ! empty($log->new_values['panel']));
        $this->line(sprintf('undo: %d copies%s', $logs->count(), $dry ? ' (dry run)' : ''));
        if ($dry) {
            return self::SUCCESS;
        }
        DB::transaction(function () use ($logs, $creator, $library, $data) {
            foreach ($logs as $log) {
                $creator->undo($log);
            }
            if (Manuscript::where('library_id', $library->id)->doesntExist()) {
                $volume = CatalogVolume::where('library_id', $library->id)->where('facsimile_code', $data['volume']['facsimile_code'])
                    ->where('volume_number', $data['volume']['volume_number'])->first();
                if ($volume) {
                    $sourceId = DB::table('source_volumes')->where('id', $volume->id)->value('source_id');
                    $volume->catalogers()->detach();
                    DB::table('source_pages')->where('source_volume_id', $volume->id)->delete();
                    DB::table('source_volumes')->where('id', $volume->id)->delete();
                    $volume->delete();
                    if ($sourceId && DB::table('source_volumes')->where('source_id', $sourceId)->doesntExist()
                        && DB::table('source_entries')->where('source_id', $sourceId)->doesntExist()) {
                        DB::table('sources')->where('id', $sourceId)->delete();
                    }
                }
                $library->delete();
                $this->line('undo: volume and library removed');
            }
        });
        Cache::forget('sources:shelf');

        return self::SUCCESS;
    }

    /** @return string[] the work and person slugs the data names that the site does not have */
    private function missingSlugs(array $copies): array
    {
        $works = collect($copies)->pluck('work.slug')->filter()->unique();
        $people = collect($copies)->pluck('work.author_slug')->filter()->unique();
        $haveWorks = Work::whereIn('slug', $works)->pluck('slug');
        $havePeople = Person::whereIn('slug', $people)->pluck('slug');

        return $works->diff($haveWorks)->merge($people->diff($havePeople))->values()->all();
    }

    private function reportCopies(array $copies, ?Library $library): void
    {
        $new = collect($copies)->filter(fn ($c) => ! isset($c['work']['slug']))->count();
        $this->line(sprintf('create: %d copies (%d under existing works, %d under new works)', count($copies), count($copies) - $new, $new));
    }
}

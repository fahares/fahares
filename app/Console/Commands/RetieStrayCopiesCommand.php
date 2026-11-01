<?php

namespace App\Console\Commands;

use App\Console\Commands\ImportFankhaCatalogReferencesCommand as References;
use App\Models\AuditLog;
use App\Models\Library;
use App\Sources\SourceEntrySync;
use App\Suggestions\LibraryMerger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Ties the copies left on another library's catalogue, or on none, to the volume their citation names (step 13.114):
 *  - two libraries held twice fold into the record that holds their copies, with LibraryMerger and an audit log
 *    entry as an approved duplicate would: «دانشکده الهیات مشهد» (62 copies, all cited «ف: 1–3» of its own catalogue
 *    but tied to Tehran's theology faculty) into «الهیات» of Mashhad, «دانشگاه تهران» (20 copies cited from the
 *    central library's catalogue and its microfilms) into the central library;
 *  - each copy of those two libraries cited «ف: V - P», untied or tied to another library's volume, goes to the volume
 *    most of its library's copies citing V hang on (at least nine in ten of them);
 *  - each copy of any library cited «فیلمها ف: V - P» goes to volume V of Tehran's microfilm catalogue, as the central
 *    library's own did in step 13.112: Majlis, Sepahsalar, Razavi, Marashi, Bu-Ali and Mashhad literature copies whose
 *    microfilm it describes;
 *  - the second volume of Tehran's theology catalogue (MTIF 21490) takes the faculty's library from the central one;
 *  - a copy on a microfilm volume whose cataloger is none of that volume's (Monzavi, from Meshkat's volumes it left in
 *    step 13.112) takes the volume's own, and volumes, catalogers and libraries are counted again.
 * Everything is found by slug, source code and MTIF id, never by local id. Safe to run again.
 */
class RetieStrayCopiesCommand extends Command
{
    protected $signature = 'sources:retie-stray-copies {--dry-run : Report what would change without saving}';

    protected $description = 'Tie the copies left on another library\'s catalogue to the volume their citation names';

    /** Folded library => kept library, by slug. */
    public const LIBRARIES = [
        'mshhd-danshkdh-alhyat-mshhd-341' => 'mshhd-alhyat-38',
        'thran-danshgah-thran-365' => 'thran-danshgah-3',
    ];

    /** MTIF id of a catalogue volume => the library it belongs to. */
    public const VOLUME_LIBRARIES = [21490 => 'thran-alhyat-40'];

    private const SHARE = 0.9;

    private bool $dry = false;

    private array $moved = [];

    public function handle(LibraryMerger $merger): int
    {
        DB::disableQueryLog();
        $this->dry = (bool) $this->option('dry-run');
        $this->moved = [];
        if ($this->dry) {
            $this->warn('Dry run: nothing is saved.');
        }

        $this->volumeLibraries();
        $groups = $this->mergeLibraries($merger);
        foreach ($groups as $ids) {
            $this->tieOwn($ids);
        }
        $this->tieMicrofilms();
        if ($this->dry) {
            return self::SUCCESS;
        }

        foreach (array_chunk(array_keys($this->moved), 1000) as $chunk) {
            SourceEntrySync::syncCited(DB::table('manuscripts')->whereIn('id', $chunk)->get(['id', 'metadata', 'raw_text', 'catalog_volume_id']));
        }
        $this->catalogers();
        LinkCatalogVolumesCommand::recountVolumesAndCatalogers();
        Cache::forget('sources:shelf');
        foreach (DB::table('source_volumes')->whereIn('id', array_unique(array_values($this->moved)))->distinct()->pluck('source_id') as $id) {
            Cache::forget("sources:copies:{$id}");
        }
        $this->info(count($this->moved) . ' copies tied anew.');

        return self::SUCCESS;
    }

    private function volumeLibraries(): void
    {
        foreach (self::VOLUME_LIBRARIES as $mtif => $slug) {
            $library = Library::where('slug', $slug)->value('id');
            $volume = DB::table('catalog_volumes')->where('mtif_entry_id', $mtif)->first();
            if (! $library || ! $volume || (int) $volume->library_id === $library) {
                $this->line("Volume MTIF {$mtif}: its library is in place.");

                continue;
            }
            $this->info("Volume #{$volume->id} «{$volume->title}»: library #{$volume->library_id} → #{$library} {$slug}");
            if (! $this->dry) {
                DB::table('catalog_volumes')->where('id', $volume->id)->update(['library_id' => $library, 'updated_at' => now()]);
            }
        }
    }

    /** @return array<int, int[]> each kept library's id => its id and, in a dry run, the folded one's */
    private function mergeLibraries(LibraryMerger $merger): array
    {
        $groups = [];
        foreach (self::LIBRARIES as $from => $to) {
            $target = Library::where('slug', $to)->firstOrFail();
            $groups[$target->id] ??= [$target->id];
            $source = Library::where('slug', $from)->first();
            if (! $source) {
                $this->line("Library {$from}: already folded.");

                continue;
            }
            $this->info(sprintf('Library #%d «%s» (%d copies) folds into #%d «%s»', $source->id, $source->name,
                DB::table('manuscripts')->where('library_id', $source->id)->count(), $target->id, $target->name));
            if ($this->dry) {
                $groups[$target->id][] = $source->id;

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
                    'new_values' => ['target_id' => $target->id, 'added_aliases' => $result['added_aliases'], 'command' => 'sources:retie-stray-copies'],
                ]);
            });
        }

        return $groups;
    }

    /** A library's copies cited «ف: V - P» that hang on no volume of its own go where most of its copies citing V hang. */
    private function tieOwn(array $libraries): void
    {
        $own = DB::table('catalog_volumes')->whereIn('library_id', $libraries)->pluck('id')->flip();
        $tied = [];
        $stray = [];
        DB::table('manuscripts')->whereIn('library_id', $libraries)->select('id', 'metadata', 'raw_text', 'catalog_volume_id')->orderBy('id')
            ->chunkById(5000, function ($rows) use (&$tied, &$stray, $own) {
                foreach ($rows as $m) {
                    $raw = References::rawCitation($m->metadata, $m->raw_text);
                    if ($raw === null || ! preg_match('/^\[\s*ف\s*:/u', $raw) || ! ($volume = References::parse($raw)['volume'])) {
                        continue;
                    }
                    if ($m->catalog_volume_id && isset($own[$m->catalog_volume_id])) {
                        $tied[$volume][$m->catalog_volume_id] = ($tied[$volume][$m->catalog_volume_id] ?? 0) + 1;
                    } else {
                        $stray[$volume][] = $m->id;
                    }
                }
            });

        $name = Library::find($libraries[0])->name;
        $left = 0;
        foreach ($stray as $volume => $ids) {
            $counts = $tied[$volume] ?? [];
            arsort($counts);
            $target = array_key_first($counts);
            if (! $target || $counts[$target] < self::SHARE * array_sum($counts)) {
                $left += count($ids);

                continue;
            }
            $this->line(sprintf('  %s «ف: %d»: %d copies → volume #%d', $name, $volume, count($ids), $target));
            $this->move($ids, $target);
        }
        $this->line("  {$name}: {$left} copies stay, citing a volume none of its copies hangs on");
    }

    /** Each copy cited «فیلمها ف: V - P», of whatever library, goes to volume V of Tehran's microfilm catalogue. */
    private function tieMicrofilms(): void
    {
        $source = FillEmptyCataloguesCommand::source(FillEmptyCataloguesCommand::MICROFILMS);
        $target = $source ? DB::table('source_volumes')->where('source_id', $source->id)->pluck('id', 'volume_number')->all() : [];
        if (count(array_intersect_key($target, [1 => 1, 2 => 1, 3 => 1])) < 3) {
            $this->warn('Microfilm catalogue: volumes 1 to 3 are not one each; no copy is tied.');

            return;
        }
        $moves = [];
        DB::table('manuscripts')->where(fn ($q) => $q->where('metadata->catalog_citation', 'like', '%لم%')
                ->orWhere(fn ($w) => $w->whereNull('metadata->catalog_citation')->where('raw_text', 'like', '%[ف%لم%')))
            ->select('id', 'library_id', 'metadata', 'raw_text', 'catalog_volume_id')->orderBy('id')
            ->chunkById(5000, function ($rows) use (&$moves, $target) {
                foreach ($rows as $m) {
                    $raw = References::rawCitation($m->metadata, $m->raw_text);
                    if ($raw === null || ! preg_match('/^\[\s*فی?لم/u', strtr($raw, ['ي' => 'ی']))) {
                        continue;
                    }
                    $volume = References::parse($raw)['volume'];
                    if (isset($target[$volume]) && in_array($volume, [1, 2, 3], true) && (int) $m->catalog_volume_id !== $target[$volume]) {
                        $moves[$volume][$m->library_id][] = $m->id;
                    }
                }
            });
        foreach ($moves as $volume => $libraries) {
            foreach ($libraries as $library => $ids) {
                $this->line(sprintf('  microfilm «ف: %d», library #%d: %d copies → volume #%d', $volume, $library, count($ids), $target[$volume]));
                $this->move($ids, $target[$volume]);
            }
        }
    }

    private function move(array $ids, int $volume): void
    {
        foreach ($ids as $id) {
            $this->moved[$id] = $volume;
        }
        if (! $this->dry) {
            foreach (array_chunk($ids, 1000) as $chunk) {
                DB::table('manuscripts')->whereIn('id', $chunk)->update(['catalog_volume_id' => $volume]);
            }
        }
    }

    /**
     * A copy moved here, or on a microfilm volume (where step 13.112 moved them), whose cataloger is none of its
     * volume's takes the one most of the volume's copies carry among its catalogers, or its first.
     */
    private function catalogers(): void
    {
        $source = FillEmptyCataloguesCommand::source(FillEmptyCataloguesCommand::MICROFILMS);
        $films = $source ? DB::table('source_volumes')->where('source_id', $source->id)->pluck('id')->all() : [];
        $copies = [];
        foreach ($this->moved as $id => $volume) {
            $copies[$volume][] = $id;
        }
        $fixed = 0;
        foreach (array_unique(array_merge(array_keys($copies), $films)) as $volume) {
            $own = DB::table('catalog_volume_cataloger')->where('catalog_volume_id', $volume)->orderBy('id')->pluck('cataloger_id')->all();
            if (! $own) {
                continue;
            }
            $main = DB::table('manuscripts')->where('catalog_volume_id', $volume)->whereIn('cataloger_id', $own)
                ->groupBy('cataloger_id')->orderByRaw('count(*) desc')->value('cataloger_id') ?? $own[0];
            $stale = DB::table('manuscripts')->where('catalog_volume_id', $volume)
                ->where(fn ($q) => $q->whereNull('cataloger_id')->orWhereNotIn('cataloger_id', $own));
            if (! in_array($volume, $films, true)) {
                $stale->whereIn('id', $copies[$volume]);
            }
            $fixed += $stale->update(['cataloger_id' => $main]);
        }
        $this->line("Catalogers: {$fixed} copies take their volume's.");
    }
}

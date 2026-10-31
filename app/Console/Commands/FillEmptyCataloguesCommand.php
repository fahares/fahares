<?php

namespace App\Console\Commands;

use App\Console\Commands\ImportFankhaCatalogReferencesCommand as References;
use App\Models\Source;
use App\Sources\SourceEntrySync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Gives the catalogue sources whose page listed no manuscript the copies they describe (step 13.112). Of the 18
 * catalogues production listed empty, these were second records of a catalogue whose copies hang elsewhere, or held
 * the copies of another volume:
 *  - six are folded with sources:merge-split --group into the source that has the copies (Astan Quds' alphabetical
 *    catalogue, its 1305 first volume, Malek's tenth volume, the Tehran faculties of theology and law under their own
 *    libraries' catalogues, Madrasa Razaviyya's catalogue into «آشنایی با چند نسخه خطی», where Fankha cites it);
 *  - Tehran's microfilm catalogue (ae08…, d788…, 09b1…): its third volume (۱۳۶۳, Daneshpajouh and Rastkar) was
 *    numbered 1 and carried MTIF's first volume (21479), which goes to the first volume before the three sources fold;
 *    then each copy of the central library cited «فیلمها ف: V - P» is tied to volume V, the 859 on Meshkat's volumes
 *    among them;
 *  - Majlis' brief catalogue: its 2,505 copies hung on a volume of the main Majlis catalogue
 *    («فهرست کتابخانه مجلس شورای اسلامی - فهرست مختصر …»), which moves to the brief catalogue's source and takes its
 *    title, MTIF record and cataloger.
 * Everything is found by source code (or the source a code was folded into) and MTIF id, never by local id. Safe to
 * run again: a step whose state is reached finds nothing to do.
 */
class FillEmptyCataloguesCommand extends Command
{
    protected $signature = 'sources:fill-empty-catalogues {--dry-run : Report what would change without saving}';

    protected $description = 'Fold or fill the catalogue sources that list no manuscript';

    /** Kept source first: the one whose volumes hold the copies. */
    public const GROUPS = [
        ['catalogue-520c926fbc43', 'catalogue-02c0a06ef7b7'],
        ['catalogue-86c8a806d584', 'catalogue-6faa450ea63a'],
        ['catalogue-7ac531af190e', 'catalogue-bce863de9555'],
        ['catalogue-7006195fe18b', 'catalogue-485922d6060f'],
        ['catalogue-2153c3d5f1cc', 'catalogue-52a9a0c18200'],
        ['catalogue-87e2efc29ff5', 'catalogue-26127a525428'],
        ['catalogue-ae0847c98256', 'catalogue-d788e304d962', 'catalogue-09b1ef810b10'],
    ];

    public const MICROFILMS = 'catalogue-ae0847c98256';

    public const MICROFILMS_FIRST = 'catalogue-09b1ef810b10';

    public const MICROFILMS_FIRST_MTIF = 21479;

    /** The central library of Tehran University (its slug), whose copies Fankha cites from its microfilm catalogue. */
    public const MICROFILMS_LIBRARY = 'thran-danshgah-3';

    public const BRIEF = 'catalogue-c799cf2caeda';

    public const BRIEF_MTIF = 35032;

    public const BRIEF_HELD_AS = 'فهرست کتابخانه مجلس شورای اسلامی - فهرست مختصر نسخه‌های خطی مجلس';

    private bool $dry = false;

    public function handle(): int
    {
        $this->dry = (bool) $this->option('dry-run');
        if ($this->dry) {
            $this->warn('Dry run: nothing is saved.');
        }

        $this->dry ? $this->microfilmNumbers() : DB::transaction(fn () => $this->microfilmNumbers());
        $this->call('sources:merge-split', ['--group' => array_map(fn ($g) => implode(',', $g), self::GROUPS), '--dry-run' => $this->dry]);
        $this->dry ? $this->brief() : DB::transaction(fn () => $this->brief());
        $this->retieMicrofilms();

        if (! $this->dry) {
            Cache::forget('sources:shelf');
            foreach ([self::MICROFILMS, self::BRIEF, 'catalogue-af2952129bfd', 'catalogue-e745d8f70fe2'] as $code) {
                if ($source = self::source($code)) {
                    Cache::forget("sources:copies:{$source->id}");
                    foreach (['works', 'manuscripts'] as $type) {
                        Cache::forget('sources:linked:' . md5(implode('|', [$source->id, $type, null, ''])));
                    }
                }
            }
        }

        return self::SUCCESS;
    }

    /** A source by its code, or the source it was folded into. */
    public static function source(string $code): ?Source
    {
        return Source::where('code', $code)->first() ?? Source::mergedInto($code);
    }

    /**
     * The microfilm catalogue's third volume carries MTIF's first (21479); the first volume, held by its own source
     * without an MTIF record, takes it. The third volume takes its own (21483) when merge-split folds its duplicate in.
     */
    private function microfilmNumbers(): void
    {
        $third = DB::table('source_volumes')->where('mtif_entry_id', self::MICROFILMS_FIRST_MTIF)->where('title', 'like', '% - جلد سوم')->first();
        $first = ($source = self::source(self::MICROFILMS_FIRST))
            ? DB::table('source_volumes')->where('source_id', $source->id)->where('volume_number', 1)->whereNull('mtif_entry_id')->first() : null;
        if (! $third || ! $first) {
            $this->line('Microfilm volumes: their MTIF records are in place.');

            return;
        }
        $this->info('Microfilm volumes: MTIF ' . self::MICROFILMS_FIRST_MTIF . " moves from #{$third->id} «{$third->title}» to volume 1 #{$first->id}.");
        if ($this->dry) {
            return;
        }
        foreach (['source_volumes', 'catalog_volumes'] as $table) {
            DB::table($table)->where('id', $third->id)->update(['mtif_entry_id' => null]);
            DB::table($table)->where('id', $first->id)->update(['mtif_entry_id' => self::MICROFILMS_FIRST_MTIF]);
        }
    }

    /** Majlis' brief catalogue: the volume of the main catalogue that holds its copies moves to it and takes its record. */
    private function brief(): void
    {
        $brief = self::source(self::BRIEF);
        $held = DB::table('catalog_volumes')->where('title', self::BRIEF_HELD_AS)->first();
        if (! $brief || ! $held) {
            $this->line('Brief Majlis catalogue: its copies are on its own source.');

            return;
        }
        $record = DB::table('source_volumes')->where('source_id', $brief->id)->where('mtif_entry_id', self::BRIEF_MTIF)->first();
        $entries = DB::table('source_entries')->where('source_volume_id', $held->id)->count();
        $this->info("Brief Majlis catalogue: volume #{$held->id} with {$entries} entries moves to #{$brief->id} {$brief->code}"
            . ($record ? ", its volume #{$record->id} «{$record->title}» folded into it" : ''));
        if ($this->dry) {
            return;
        }

        $title = $record->title ?? $brief->title . ' - 1';
        foreach (['source_volumes', 'catalog_volumes'] as $table) {
            DB::table($table)->where('id', $held->id)->update(['title' => $title, 'volume_number' => 1, 'updated_at' => now()]);
        }
        $from = DB::table('source_volumes')->where('id', $held->id)->value('source_id');
        DB::table('source_volumes')->where('id', $held->id)->update(['source_id' => $brief->id]);
        DB::table('source_entries')->where('source_volume_id', $held->id)->update(['source_id' => $brief->id]);
        DB::table('source_items')->where('source_volume_id', $held->id)->update(['source_id' => $brief->id, 'volume_number' => 1]);
        if ($record) {
            MergeSplitSourcesCommand::foldVolume($record->id, $held->id);
        }
        foreach ([$from, $brief->id] as $id) {
            DB::table('sources')->where('id', $id)->update(['volumes_count' => DB::table('source_volumes')->where('source_id', $id)->count(), 'updated_at' => now()]);
            Cache::forget("sources:copies:{$id}");
        }
    }

    /** Each central library copy cited «فیلمها ف: V - P» is tied to volume V of the microfilm catalogue, and its entry follows. */
    private function retieMicrofilms(): void
    {
        $library = DB::table('libraries')->where('slug', self::MICROFILMS_LIBRARY)->value('id');
        // in a dry run the three sources are not folded yet: their volumes are found by the number their titles give,
        // the one holding most copies where two give one
        $ids = $this->dry ? collect(self::GROUPS[6])->map(fn ($c) => self::source($c)?->id)->filter()->unique()->all()
            : array_filter([self::source(self::MICROFILMS)?->id]);
        $target = DB::table('source_volumes')->whereIn('source_id', $ids)->get()
            ->groupBy(fn ($v) => $this->dry ? MergeSplitSourcesCommand::volumeNumber($v->title) ?? $v->volume_number : $v->volume_number)
            ->map(fn ($vs) => $this->dry || $vs->count() === 1
                ? $vs->sortByDesc(fn ($v) => DB::table('manuscripts')->where('catalog_volume_id', $v->id)->count())->first()->id : null)
            ->filter()->all();
        if (! $library || count(array_intersect_key($target, [1 => 1, 2 => 1, 3 => 1])) < 3) {
            $this->warn('Microfilm catalogue: volumes 1 to 3 are not one each; no copy is tied.');

            return;
        }

        $moves = [];
        $cited = [];
        DB::table('manuscripts')->where('library_id', $library)->select('id', 'metadata', 'raw_text', 'catalog_volume_id')->orderBy('id')
            ->chunkById(5000, function ($rows) use (&$moves, &$cited, $target) {
                foreach ($rows as $m) {
                    $raw = References::rawCitation($m->metadata, $m->raw_text);
                    if ($raw === null || ! preg_match('/^\[\s*فی?لم/u', strtr($raw, ['ي' => 'ی']))) {
                        continue;
                    }
                    $volume = References::parse($raw)['volume'];
                    if (! in_array($volume, [1, 2, 3], true)) {
                        continue;
                    }
                    $cited[$volume][] = $m->id;
                    if ((int) $m->catalog_volume_id !== $target[$volume]) {
                        $moves[$volume][] = $m->id;
                    }
                }
            });
        foreach ([1, 2, 3] as $n) {
            $this->line(sprintf('  microfilm volume %d #%d: %d copies cite it, %d tied to it anew', $n, $target[$n],
                count($cited[$n] ?? []), count($moves[$n] ?? [])));
        }
        if ($this->dry) {
            return;
        }

        foreach ($moves as $n => $ids) {
            foreach (array_chunk($ids, 1000) as $chunk) {
                DB::table('manuscripts')->whereIn('id', $chunk)->update(['catalog_volume_id' => $target[$n]]);
            }
        }
        // every cited copy's entry, so the volume numbers merge-split shifted on the third volume are read again
        foreach (array_chunk(array_merge(...array_values($cited)), 1000) as $chunk) {
            SourceEntrySync::syncCited(DB::table('manuscripts')->whereIn('id', $chunk)->get(['id', 'metadata', 'raw_text', 'catalog_volume_id']));
        }
    }
}

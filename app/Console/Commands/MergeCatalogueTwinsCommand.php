<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Manuscript;
use App\Suggestions\RecordMerger;
use App\Support\MemoryLimit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One copy described twice (step 14.85). Fankha often gives one copy twice, once from each catalogue of its library:
 * Razavi's «رایانه» or «الفبائی» list beside its printed catalogue, two volumes of one catalogue, or one entry twice.
 * Both have the same plain shelfmark (no part number) and the same work, so fahares:link-plain-volumes counts them as
 * «a work twice» and leaves the volume unlinked.
 *
 * Copies of a library with one plain shelfmark and one work are twins unless something shows two copies: two leaf
 * ranges that differ, leaf counts more than MAX_FOLIO_GAP apart, page sizes more than MAX_SIZE_GAP cm apart or
 * formats that differ («رحلی», «خشتی»), lines
 * more than MAX_LINE_GAP apart, scribes with no name in common, dates more than MAX_YEAR_GAP years apart («قرن 10» and
 * 1228), explicits with hardly a word in common (two treatises the catalogue put under one title), one a photograph
 * and the other not, or a reviewer's «two copies» (distinct_from). A year a little off does not part them (1322 and
 * 1325 of one scribe, one leaf count and size are a catalogue's slip). An entry with «همان نسخه» is left to
 * fahares:link-copy-references (step 14.16), and a «مجموعه بدون شماره» has no shelfmark to share.
 *
 * The kept copy is the one with a leaf range, else the one from a printed catalogue, else the longer description. The
 * other is merged into it through RecordMerger, as an admin's merge: a field only it gives fills the kept copy, a field
 * the two give differently stays as the kept copy has it and the other value becomes an alternative reading (قول
 * دیگر) citing the other catalogue, and its catalogue entry moves to the kept copy. Each merge is logged (AuditLog
 * «merge»), and written to a backup that --revert undoes.
 *
 * Without --apply it only reports. Run fahares:link-plain-volumes --apply afterwards. Safe to run again.
 */
class MergeCatalogueTwinsCommand extends Command
{
    protected $signature = 'fahares:merge-catalogue-twins
        {--apply : Merge; without it the command only reports}
        {--library= : Only the copies of this library (its id, or its name as the copies give it)}
        {--show=20 : How many examples to list per decision}
        {--report= : Write every decision to this CSV file}
        {--backup= : Where to write the backup (default storage/app/private/backups/)}
        {--revert= : Undo the merges of a backup this command wrote}';

    protected $description = 'Merge the two descriptions of one copy that Fankha gives from two catalogues of its library';

    /** The most leaves two descriptions of one copy may differ by */
    public const MAX_FOLIO_GAP = 2;

    /** The most centimetres a side of the page may differ by */
    public const MAX_SIZE_GAP = 1.5;

    /** The most lines a page may differ by */
    public const MAX_LINE_GAP = 2;

    /** The most years two dates may lie apart (1322 and 1325 of one scribe are one date misread) */
    public const MAX_YEAR_GAP = 10;

    /** Catalogues that are lists (a line per copy), not printed descriptions */
    private const LISTS = '/^\[\s*(رایانه|الفبائی)/u';

    /** Names too common to show that two scribes are one */
    private const COMMON_NAMES = ['بن', 'ابن', 'بنت', 'محمد', 'علی', 'حسن', 'حسین', 'احمد', 'سید', 'میرزا', 'ملا', 'شیخ', 'آقا', 'حاج', 'عبد', 'الله', 'الدین'];

    private array $counts = [];

    private array $examples = [];

    private array $report = [];

    public function handle(): int
    {
        if ($file = $this->option('revert')) {
            return $this->revert($file);
        }
        [$this->counts, $this->examples, $this->report] = [[], [], []];
        $apply = (bool) $this->option('apply');
        // bootstrap/app.php sets 512M, over any -d; the reading takes ~360 MB and each merge keeps ~1.5 MB (step 14.85's run)
        MemoryLimit::raiseTo('2G');

        // copies with a plain shelfmark, by library, shelfmark and work
        $groups = [];
        $library = $this->option('library');
        DB::table('manuscripts')->whereNotNull('work_id')->whereNotNull('shelfmark')
            ->when($library !== null, fn ($q) => ctype_digit((string) $library) ? $q->where('library_id', (int) $library) : $q->where('library', $library))
            ->select(['id', 'library_id', 'library', 'shelfmark', 'raw_text', 'work_id', 'range_from_number', 'range_to_number', 'folios', 'lines', 'dimensions', 'scribe_name',
                'copy_date_from', 'copy_date_to', 'explicit_text', 'is_facsimile', 'metadata'])
            ->orderBy('id')->chunkById(5000, function ($rows) use (&$groups) {
                foreach ($rows as $row) {
                    $lib = $row->library_id ?? (trim((string) $row->library) !== '' ? 'n:' . trim($row->library) : null);
                    $key = $lib === null || Manuscript::collectionKeyOf($row->shelfmark, $row->raw_text) !== null ? null : Manuscript::plainVolumeKeyOf($row->shelfmark, $row->raw_text);
                    // «4مجموعه بدون شماره»: copies of a collection with no numbers have no shelfmark to share
                    // and an entry said to be the copy above is fahares:link-copy-references's (step 14.16)
                    if ($key === null || str_contains($row->shelfmark, 'بدون شماره') || preg_match('/همان\s+نسخه/u', (string) $row->raw_text)) {
                        continue;
                    }
                    $citation = (string) (json_decode((string) $row->metadata, true)['catalog_citation'] ?? '');
                    $groups["{$lib}|{$key}|{$row->work_id}"][] = [
                        'id' => (int) $row->id, 'shelfmark' => $row->shelfmark, 'citation' => $citation,
                        'range' => $row->range_from_number !== null ? [(int) $row->range_from_number, (int) ($row->range_to_number ?? $row->range_from_number)] : null,
                        'folios' => $row->folios !== null ? (int) $row->folios : null,
                        'lines' => $row->lines !== null ? (int) $row->lines : null,
                        'years' => $row->copy_date_from !== null ? [(int) $row->copy_date_from, (int) ($row->copy_date_to ?? $row->copy_date_from)] : null,
                        'explicit' => self::words($row->explicit_text),
                        'size' => self::size($row->dimensions ?: $row->raw_text),
                        'format' => self::format($row->raw_text),
                        'scribe' => self::names($row->scribe_name),
                        'facsimile' => (bool) $row->is_facsimile,
                        'length' => mb_strlen((string) $row->raw_text),
                    ];
                }
            });
        $groups = array_filter($groups, fn ($g) => count($g) > 1);

        $decisions = [];
        foreach ($groups as $copies) {
            usort($copies, fn ($a, $b) => self::rank($b) <=> self::rank($a) ?: $a['id'] <=> $b['id']);
            $kept = array_shift($copies);
            foreach ($copies as $copy) {
                $decisions[] = [$copy, $kept, self::apart($kept, $copy)];
            }
        }
        // A reviewer has said they are two copies
        $distinct = self::distinctPairs(array_map(fn ($d) => [$d[0]['id'], $d[1]['id']], array_filter($decisions, fn ($d) => $d[2] === null)));
        $merges = [];
        foreach ($decisions as [$copy, $kept, $why]) {
            $why ??= isset($distinct["{$copy['id']}-{$kept['id']}"]) ? 'distinct' : null;
            $this->decide($why ?? 'merge', $copy, $kept);
            if ($why === null) {
                $merges[] = [$copy['id'], $kept['id']];
            }
        }
        unset($groups, $decisions, $distinct);

        if ($apply && $merges) {
            $path = $this->option('backup') ?: storage_path('app/private/backups/merge-catalogue-twins-' . now()->format('Ymd_His') . '.json');
            @mkdir(dirname($path), 0775, true);
            $backup = ['command' => $this->getName(), 'merges' => []];
            $merger = app(RecordMerger::class);
            foreach ($merges as [$sourceId, $targetId]) {
                DB::transaction(function () use ($sourceId, $targetId, $merger, &$backup) {
                    $source = Manuscript::find($sourceId);
                    $target = Manuscript::find($targetId);
                    if (! $source || ! $target) {
                        return;
                    }
                    $result = $merger->mergeManuscript($source, $target);
                    // in the form of an admin's merge, which the audit log page can roll back one by one
                    $log = AuditLog::create(['user_id' => null, 'auditable_type' => Manuscript::class, 'auditable_id' => $target->id, 'action' => 'merge',
                        'old_values' => ['source_id' => $source->id] + $result, 'new_values' => ['target_id' => $target->id, 'corpus_fix' => 'step 14.85 merge-catalogue-twins']]);
                    $backup['merges'][] = ['source_id' => $source->id, 'target_id' => $target->id, 'log_id' => $log->id, 'result' => $result];
                });
                // written after each merge, so an interrupted run can still be reverted in full
                file_put_contents($path, json_encode($backup, JSON_UNESCAPED_UNICODE));
            }
            $this->info(sprintf('Merged %d copies. Backup: %s', count($backup['merges']), $path));
        } else {
            $this->info($apply ? 'Nothing to merge.' : 'Dry run: nothing merged.');
        }

        ksort($this->counts);
        foreach ($this->counts as $kind => $n) {
            $this->line(sprintf('  %-22s %8d', $kind, $n));
        }
        foreach ($this->examples as $kind => $lines) {
            $this->line("-- {$kind}");
            foreach ($lines as $line) {
                $this->line("   {$line}");
            }
        }
        if ($file = $this->option('report')) {
            $out = fopen($file, 'w');
            fputcsv($out, ['manuscript_id', 'kept_id', 'decision', 'shelfmark', 'citation', 'kept_citation']);
            foreach ($this->report as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
            $this->info("Report: {$file}");
        }

        return self::SUCCESS;
    }

    /** Why two descriptions are two copies, or null when nothing shows it */
    public static function apart(array $a, array $b): ?string
    {
        if ($a['facsimile'] !== $b['facsimile']) {
            return 'facsimile';
        }
        if ($a['range'] && $b['range'] && $a['range'] !== $b['range']) {
            return 'other-leaves';
        }
        if ($a['folios'] && $b['folios'] && abs($a['folios'] - $b['folios']) > self::MAX_FOLIO_GAP) {
            return 'other-folios';
        }
        if ($a['size'] && $b['size'] && (abs($a['size'][0] - $b['size'][0]) > self::MAX_SIZE_GAP || abs($a['size'][1] - $b['size'][1]) > self::MAX_SIZE_GAP)) {
            return 'other-size';
        }
        if ($a['format'] && $b['format'] && $a['format'] !== $b['format']) {
            return 'other-format';
        }
        if ($a['lines'] && $b['lines'] && abs($a['lines'] - $b['lines']) > self::MAX_LINE_GAP) {
            return 'other-lines';
        }
        if ($a['scribe'] && $b['scribe'] && ! array_intersect($a['scribe'], $b['scribe'])) {
            return 'other-scribe';
        }
        // «قرن 10» and 1228: dates whose spans lie more than a slip apart
        if ($a['years'] && $b['years'] && ($a['years'][0] - $b['years'][1] > self::MAX_YEAR_GAP || $b['years'][0] - $a['years'][1] > self::MAX_YEAR_GAP)) {
            return 'other-date';
        }
        // two explicits with hardly a word in common end two texts
        if (count($a['explicit']) >= 3 && count($b['explicit']) >= 3
            && count(array_intersect($a['explicit'], $b['explicit'])) < 0.3 * min(count($a['explicit']), count($b['explicit']))) {
            return 'other-text';
        }

        return null;
    }

    /** The words of a quoted text, «برابر» (as in the work's entry) and «موجود:» left out */
    public static function words(?string $text): array
    {
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', strtr((string) $text, ['ي' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا']));
        $words = preg_split('/[\s،,\.:؛\(\)«»×\-]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter($words, fn ($w) => mb_strlen($w) >= 2 && ! in_array($w, ['برابر', 'موجود', 'ناتمام'], true))));
    }

    /** The page's two sides in cm, smaller first («17×21/5سم» → [17, 21.5]), or null */
    public static function size(?string $text): ?array
    {
        $text = strtr((string) $text, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', 'x' => '×', '*' => '×']);
        // the dimensions column holds the size alone; the raw text names it «اندازه:»
        if (! preg_match('/^\s*(\d+(?:[\/\.]\d+)?)\s*×\s*(\d+(?:[\/\.]\d+)?)/u', $text, $m)
            && ! preg_match('/اندازه\s*:\s*(\d+(?:[\/\.]\d+)?)\s*×\s*(\d+(?:[\/\.]\d+)?)/u', $text, $m)) {
            return null;
        }
        $sides = [(float) str_replace('/', '.', $m[1]), (float) str_replace('/', '.', $m[2])];
        sort($sides);

        // a side under 3 cm is a misprint, not a page
        return $sides[0] >= 3 ? $sides : null;
    }

    /** The book's format, the first word of «قطع: رحلی کوچک» («رحلی»), or null */
    public static function format(?string $text): ?string
    {
        return preg_match('/قطع\s*:\s*(\p{L}+)/u', (string) $text, $m) ? $m[1] : null;
    }

    /** The distinctive names of a scribe («محمد ابراهیم بن محمد باقر» → [ابراهیم, باقر]) */
    public static function names(?string $scribe): array
    {
        $words = preg_split('/[\s،,\.\(\)\-]+/u', trim((string) $scribe), -1, PREG_SPLIT_NO_EMPTY);
        $words = array_map(fn ($w) => preg_replace('/^ال(?=\p{L}{3})/u', '', $w), $words);

        return array_values(array_unique(array_filter($words, fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, self::COMMON_NAMES, true))));
    }

    /** Which description to keep: one with leaves, then one from a printed catalogue, then the longer */
    private static function rank(array $c): array
    {
        return [$c['range'] !== null, $c['citation'] !== '' && ! preg_match(self::LISTS, $c['citation']), $c['length']];
    }

    /** @return array<string, true> «source-target» of the pairs a distinct_from relation parts */
    private static function distinctPairs(array $pairs): array
    {
        $found = [];
        foreach (array_chunk($pairs, 500) as $chunk) {
            $ids = array_merge(...$chunk);
            foreach (DB::table('manuscript_relations')->where('type', 'distinct_from')->whereIn('manuscript_id', $ids)->whereIn('related_manuscript_id', $ids)->get(['manuscript_id', 'related_manuscript_id']) as $r) {
                $found["{$r->manuscript_id}-{$r->related_manuscript_id}"] = $found["{$r->related_manuscript_id}-{$r->manuscript_id}"] = true;
            }
        }

        return $found;
    }

    private function decide(string $decision, array $copy, array $kept): void
    {
        $this->counts[$decision] = ($this->counts[$decision] ?? 0) + 1;
        $this->report[] = [$copy['id'], $kept['id'], $decision, $copy['shelfmark'], $copy['citation'], $kept['citation']];
        if (count($this->examples[$decision] ?? []) < (int) $this->option('show')) {
            $this->examples[$decision][] = "«{$copy['shelfmark']}» #{$copy['id']} {$copy['citation']} → #{$kept['id']} {$kept['citation']}";
        }
    }

    /** Undoes a run's merges, last first */
    private function revert(string $file): int
    {
        $backup = is_file($file) ? json_decode(file_get_contents($file), true) : null;
        if (($backup['command'] ?? null) !== $this->getName()) {
            $this->error("{$file}: not a backup of {$this->getName()}.");

            return self::FAILURE;
        }
        $merger = app(RecordMerger::class);
        $done = 0;
        foreach (array_reverse($backup['merges']) as $merge) {
            $target = Manuscript::find($merge['target_id']);
            if (! $target || Manuscript::whereKey($merge['source_id'])->exists()) {
                $this->warn("{$merge['source_id']} → {$merge['target_id']}: already split, or the kept copy is gone.");

                continue;
            }
            DB::transaction(function () use ($merger, $target, $merge) {
                $merger->undoManuscriptMerge($target, $merge['result']);
                AuditLog::create(['user_id' => null, 'auditable_type' => Manuscript::class, 'auditable_id' => $target->id, 'action' => 'rollback_merge',
                    'old_values' => ['rolled_back_log_id' => $merge['log_id'] ?? null, 'target_id' => $target->id],
                    'new_values' => ['restored_manuscript_id' => $merge['source_id'], 'corpus_fix' => 'step 14.85 merge-catalogue-twins --revert']]);
            });
            $done++;
        }
        $this->info("Reverted {$done} merges.");

        return self::SUCCESS;
    }
}

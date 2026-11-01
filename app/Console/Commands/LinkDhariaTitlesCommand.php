<?php

namespace App\Console\Commands;

use App\Models\Source;
use App\Models\SourceItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Links the titles Noor tags in al-Dhari'a's text to the al-Dhari'a entry of that title (step 13.129, plan
 * docs/plans/dharia-entity-links.md, step 1): a title (WB), the author's other work (TB), the title the work is entered
 * under elsewhere («يأتي بعنوان …», VB), its other name (EB), its translation (HB) or the work it translates (CB).
 *
 * A mark links an entry only when its title is that of one entry and no other (unified letters, «ال» dropped, as
 * MatchCatalogEditionCommand::norm; or without a leading «رسالة»/«كتاب»), or of several of which one stands on the page a
 * reference right after it gives («في (476:2)», page 476 of volume 2; «(ج 4 - ص 135)»). A reference that gives another
 * page than the one entry's leaves the mark unlinked. A title carrying the Arabic «ب»/«ل»/«ك» («بكتاب الزيادات»,
 * «لتجريد العقائد») is read without it when it names nothing as it is. Left unlinked: a title of one short word or a
 * common one («الرسالة», «الحاشية»), a source's sign («تش»), and an entry on the mark's own page (the entry itself).
 *
 * The links go to source_page_links (one row a mark), made again on every run but for those an editor rejected; only
 * the ENABLED kinds and methods are saved.
 */
class LinkDhariaTitlesCommand extends Command
{
    public const KINDS = ['VB', 'CB', 'HB', 'EB', 'TB', 'WB'];

    /**
     * The kinds and methods whose links are saved: those a hand check found right in 49 of 50 or more (step 13.129: VB
     * by a page reference, 50 of 50). A title alone, one entry's though it is, names another work of that name in about
     * one link in five (TB 44, EB 40, WB 38, CB 34 of 50), so those are counted and sampled (--all saves them) only.
     */
    public const ENABLED = ['VB' => ['ref+part']];

    /** One-word titles too common to name one work. */
    private const COMMON = ['رساله', 'حاشیه', 'شرح', 'دیوان', 'تعلیقه', 'تعلیقات', 'کتاب', 'منتخب', 'مجموعه', 'فهرست', 'تفسیر', 'اجازه',
        'منظومه', 'مقاله', 'فواید', 'فوائد', 'اربعین', 'ترجمه', 'مختصر', 'تکمله', 'ذیل', 'رجال', 'تاریخ', 'مزار', 'مقتل', 'کشکول', 'امالی',
        'نوادر', 'مسائل', 'رسائل', 'جوابات', 'اجوبه', 'خطب', 'دعا', 'ادعیه', 'زیارات', 'مناقب', 'فضائل', 'منشات', 'تذکره', 'سفرنامه'];

    protected $signature = 'sources:link-dharia-titles
        {--kinds= : Comma-separated Noor kinds to link (default VB,CB,HB,EB,TB,WB)}
        {--volume= : Only this volume}
        {--sample= : Write a random sample of the links of each kind, with their context, to this file}
        {--per-kind=50 : Links of each kind in the sample}
        {--all : Save the links of every method, not only the ENABLED ones (for measuring)}
        {--dry-run : Match and count without saving}';

    protected $description = "Link the titles tagged in al-Dhari'a's text to the al-Dhari'a entry of that title";

    public function handle(): int
    {
        $source = Source::where('code', 'dharia')->firstOrFail();
        $kinds = $this->option('kinds') ? array_map('trim', explode(',', $this->option('kinds'))) : self::KINDS;
        if (array_diff($kinds, self::KINDS)) {
            $this->error('Kinds are some of ' . implode(',', self::KINDS));

            return self::FAILURE;
        }

        // every entry by its title, and by its title without a leading «رسالة»/«كتاب»
        $byTitle = [];
        $items = SourceItem::where('source_id', $source->id)->whereIn('kind', ['entry', 'sub_entry'])
            ->get(['id', 'volume_number', 'page_start', 'page_end', 'title', 'author_text']);
        $byPage = [];
        foreach ($items as $item) {
            foreach (self::keys($item->title) as $key) {
                $byTitle[$key][$item->id] = $item;
            }
            for ($p = $item->page_start; $p <= min($item->page_end, $item->page_start + 5); $p++) {
                $byPage[$item->volume_number][$p][$item->id] = $item;
            }
        }

        $pages = DB::table('source_pages')->join('source_volumes', 'source_volumes.id', '=', 'source_pages.source_volume_id')
            ->where('source_volumes.source_id', $source->id)
            ->when($this->option('volume'), fn ($q, $v) => $q->where('source_volumes.volume_number', $v))
            ->orderBy('source_volumes.volume_number')->orderBy('source_pages.page')
            ->select('source_pages.id', 'source_pages.page', 'source_pages.text', 'source_volumes.volume_number');

        $links = [];
        $count = [];
        $pages->chunk(500, function ($chunk) use ($kinds, $byTitle, $byPage, &$links, &$count) {
            foreach ($chunk as $page) {
                foreach (self::marksOf($page->text) as $mark) {
                    if (! in_array($mark['kind'], $kinds, true)) {
                        continue;
                    }
                    [$item, $method] = self::match($mark, $byTitle, $page, $byPage);
                    $count[$mark['kind']][$method] = ($count[$mark['kind']][$method] ?? 0) + 1;
                    if ($item) {
                        $links[] = ['page' => $page, 'mark' => $mark, 'item' => $item, 'method' => $method];
                    }
                }
            }
        });

        $rows = [];
        foreach ($count as $kind => $methods) {
            arsort($methods);
            $rows[] = [$kind, array_sum($methods), implode(', ', array_map(fn ($m, $n) => "$m $n", array_keys($methods), $methods))];
        }
        $this->table(['kind', 'marks', 'by method'], $rows);
        $this->info(count($links) . ' links');

        if ($this->option('sample')) {
            $this->sample($links, (int) $this->option('per-kind'));
        }
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        if (! $this->option('all')) {
            $links = array_values(array_filter($links, fn ($l) => in_array($l['method'], self::ENABLED[$l['mark']['kind']] ?? [], true)));
        }
        $this->save($links, $kinds);

        return self::SUCCESS;
    }

    /** A title's comparison keys: as MatchCatalogEditionCommand::norm gives it, and without a leading «رسالة»/«كتاب». */
    public static function keys(string $title): array
    {
        $key = MatchCatalogEditionCommand::norm($title);
        $keys = [$key];
        if (preg_match('/^(?:رساله|کتاب) (.+)$/u', $key, $m)) {
            $keys[] = $m[1];
        }

        return array_values(array_filter(array_unique($keys), fn ($k) => self::linkable($k)));
    }

    /** Whether a title key can name one work: two words or more, or one of five letters or more that is not a common one. */
    private static function linkable(string $key): bool
    {
        if ($key === '') {
            return false;
        }
        if (str_contains($key, ' ')) {
            return true;
        }

        return mb_strlen($key) >= 5 && ! in_array($key, self::COMMON, true);
    }

    /**
     * The page's marks, in order, each with its ordinal (the n-th opening on the page), kind, text, and the plain text
     * just before and after it.
     *
     * @return list<array{ordinal: int, kind: string, text: string, before: string, after: string}>
     */
    public static function marksOf(string $text): array
    {
        $marks = [];
        preg_match_all('/\x{E020}([^\x{E021}]*)\x{E021}/u', $text, $opens, PREG_OFFSET_CAPTURE);
        foreach ($opens[0] as $n => [$open, $at]) {
            $kind = $opens[1][$n][0];
            $start = $at + strlen($open);
            $close = strpos($text, "\u{E022}", $start);
            $inner = $close === false ? '' : substr($text, $start, $close - $start);
            if ($close === false || str_contains($inner, "\u{E020}")) {
                continue;
            }
            $marks[] = [
                'ordinal' => $n,
                'kind' => $kind,
                'text' => trim($inner),
                'before' => mb_substr(\App\Support\PageMarks::strip(substr($text, 0, $at)), -120),
                'after' => mb_substr(\App\Support\PageMarks::strip(substr($text, $close + strlen("\u{E022}"))), 0, 80),
                'after_raw' => substr($text, $close + strlen("\u{E022}"), 400),
            ];
        }

        return $marks;
    }

    /**
     * The entry a mark names and how it was found, or null and why not.
     *
     * @return array{0: ?SourceItem, 1: string}
     */
    public static function match(array $mark, array $byTitle, object $page, array $byPage = []): array
    {
        $title = preg_replace('/^[\s()\[\]«»"\'،,.:؛]+|[\s()\[\]«»"\'،,.:؛]+$/u', '', $mark['text']);
        $method = 'title';
        $found = self::named($title, $byTitle);
        // the Arabic «ب»/«ل»/«ك» in front: «بكتاب الزيادات», «لتجريد العقائد», «بالمقمعة الحسينية»
        if (! $found && preg_match('/^[بلك]\x{200D}?\s*(\p{L}.{2,})$/u', $title, $m)) {
            $found = self::named($m[1], $byTitle);
            $method = 'title-prefix';
        }
        $ref = self::reference($mark['after_raw'], $mark['kind'] === 'VB' ? (int) $page->volume_number : null);
        if (! $found && $ref && ($key = self::keys($title)[0] ?? null)) {
            // a reference to an entry by part of its title: «قطب الدين في ص 909», the poet of «ديوان قطب الدين …» there
            $found = array_filter($byPage[$ref[0]][$ref[1]] ?? [], fn ($i) => str_contains(' ' . MatchCatalogEditionCommand::norm($i->title) . ' ', " {$key} "));
            $method = 'ref+part';
        }
        if (! $found) {
            return [null, $title === '' || ! self::keys($title) ? 'short' : 'none'];
        }

        if ($ref && $method !== 'ref+part') {
            $at = array_filter($found, fn ($i) => $i->volume_number === $ref[0] && $i->page_start <= $ref[1] && $i->page_end >= $ref[1]);
            if (count($at) !== 1) {
                return [null, count($found) === 1 ? 'reference-differs' : 'several'];
            }
            $found = $at;
            $method .= '+ref';
        }
        if (count($found) !== 1) {
            return [null, 'several'];
        }
        $item = reset($found);
        if ($item->volume_number === (int) $page->volume_number && $item->page_start <= $page->page && $item->page_end >= $page->page) {
            return [null, 'own-page'];
        }

        return [$item, $method];
    }

    /** The entries whose title (a key of it) is the mark's, by id. */
    private static function named(string $title, array $byTitle): array
    {
        $found = [];
        foreach (self::keys($title) as $key) {
            $found += $byTitle[$key] ?? [];
        }

        return $found;
    }

    /**
     * The place a reference right after the mark gives, before another mark: «(476:2)» or «65:16» (page:volume, as
     * al-Dhari'a writes it), «(ج 4 - ص 135)»; with $volume (a reference within the volume, VB) also «في ص 909».
     *
     * @return ?array{0: int, 1: int}  [volume, page]
     */
    public static function reference(string $after, ?int $volume = null): ?array
    {
        $after = mb_substr(explode("\u{E020}", $after)[0], 0, 40);
        $after = \App\Support\PageMarks::strip(MatchCatalogEditionCommand::digits($after));
        if (preg_match('/^[^.\n]*?ج\s*(\d{1,2})\s*[-–،,]?\s*ص\s*(\d{1,4})/u', $after, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }
        if (preg_match('/^[^.\n]*?(?<![\d:])(\d{1,4})\s*:\s*(\d{1,2})(?![\d:])/u', $after, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 26) {
            return [(int) $m[2], (int) $m[1]];
        }
        if ($volume && preg_match('/^\s*(?:في|فی)?\s*\(?\s*ص\s*(\d{1,4})/u', $after, $m)) {
            return [$volume, (int) $m[1]];
        }

        return null;
    }

    private function sample(array $links, int $perKind): void
    {
        mt_srand(13129);
        $out = [];
        foreach (collect($links)->groupBy(fn ($l) => $l['mark']['kind'] . ($l['method'] === 'ref+part' ? ' ref+part' : '')) as $kind => $group) {
            $out[] = "===== {$kind} ({$group->count()} links) =====";
            $picked = $group->count() <= $perKind ? $group : $group->shuffle(13129)->take($perKind);
            foreach ($picked->values() as $n => $l) {
                $m = $l['mark'];
                $head = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) SourceItem::whereKey($l['item']->id)->value('text'))), 0, 160);
                $out[] = sprintf("%s-%d [%s] ج %d ص %d: …%s [[%s]] %s…\n    → ذ %d:%d «%s»: %s…",
                    $kind, $n + 1, $l['method'], $l['page']->volume_number, $l['page']->page,
                    trim(preg_replace('/\s+/u', ' ', $m['before'])), $m['text'], trim(preg_replace('/\s+/u', ' ', $m['after'])),
                    $l['item']->page_start, $l['item']->volume_number, $l['item']->title, $head);
            }
        }
        file_put_contents($this->option('sample'), implode("\n", $out) . "\n");
        $this->info('Sample written to ' . $this->option('sample'));
    }

    private function save(array $links, array $kinds): void
    {
        $now = now();
        DB::transaction(function () use ($links, $kinds, $now) {
            $volume = $this->option('volume');
            $scope = DB::table('source_page_links')->whereIn('kind', $kinds)->where('target_type', SourceItem::class)
                ->when($volume, fn ($q) => $q->whereIn('source_page_id', DB::table('source_pages')
                    ->join('source_volumes', 'source_volumes.id', '=', 'source_pages.source_volume_id')
                    ->where('source_volumes.source_id', Source::where('code', 'dharia')->value('id'))
                    ->where('source_volumes.volume_number', $volume)->select('source_pages.id')));
            $rejected = (clone $scope)->whereNotNull('rejected_at')->get(['source_page_id', 'ordinal', 'text'])
                ->keyBy(fn ($r) => $r->source_page_id . ':' . $r->ordinal);
            $deleted = (clone $scope)->whereNull('rejected_at')->delete();
            $rows = [];
            foreach ($links as $l) {
                $key = $l['page']->id . ':' . $l['mark']['ordinal'];
                if (isset($rejected[$key])) {
                    continue;
                }
                $rows[] = [
                    'source_page_id' => $l['page']->id, 'ordinal' => $l['mark']['ordinal'], 'kind' => $l['mark']['kind'],
                    'text' => mb_substr($l['mark']['text'], 0, 500), 'target_type' => SourceItem::class, 'target_id' => $l['item']->id,
                    'method' => $l['method'], 'created_at' => $now, 'updated_at' => $now,
                ];
            }
            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('source_page_links')->insertOrIgnore($chunk);
            }
            $this->info("Saved " . count($rows) . " links (removed {$deleted} of the last run, kept " . $rejected->count() . ' rejected)');
        });
    }
}

<?php

namespace App\Support;

use App\Console\Commands\MatchCatalogEditionCommand;
use App\Helpers\TextNormalizer;
use App\Support\PageLinks\Titles;
use Illuminate\Support\Facades\DB;

/**
 * The related works recorded as text matched to the works' records (backlog item 6, step 14.104). A work's «وابسته
 * به» (related_work) names the works it is made on, and its «شروح و حواشی» (commentaries_and_glosses) the works made
 * on it, each as «عنوان = عنوان دیگر؛ پدیدآور (تولد-وفات)» (WorkRelationParser::parseEntries). An entry is matched
 * to the one other work that has one of its titles and the same author as Fankha writes him («سعدی چلپی، سعدالله بن
 * عیسی»): by the user's decision, only such pairs are linked, and weaker matches (title only, several works, another
 * author) and pairs whose two works have one title (most likely one work twice) are left.
 *
 * The type is read from the title of the work made on the other («شرح X», «X (ترجمه)», work_form translation) when
 * the base it names is the other's title; else, a commentary on another's commentary listed under the base text
 * («حاشية الروضة البهية» among the commentaries of «اللمعة») or a title naming no type, the pair is «related».
 */
class WorkRelationLinker
{
    public const CONFIDENCE_TYPED = 90;

    public const CONFIDENCE_RELATED = 70;

    /** @var array<string, array<int, string>> title key => work id => author key */
    private array $byTitle = [];

    /** @var array<int, list<string>> work id => its titles as written */
    private array $titles = [];

    /** @var array<int, array{title: string, form: ?string}> */
    private array $works = [];

    public static function key(?string $title): string
    {
        // «الرجعة (منتخب)»: the mark after the title is the type, not the title
        return MatchCatalogEditionCommand::norm(TextNormalizer::normalize(preg_replace('/\s*\([^()]*\)\s*$/u', '', (string) $title)));
    }

    public static function authorKey(?string $author): string
    {
        return preg_replace('/[\s،,]+/u', '', TextNormalizer::normalize((string) $author));
    }

    public function __construct()
    {
        foreach (DB::table('works')->select('id', 'clean_title', 'primary_title', 'alternative_titles', 'author_name', 'work_form')->lazyById(5000) as $w) {
            $titles = [$w->clean_title, $w->primary_title];
            foreach (json_decode($w->alternative_titles ?? '[]', true) ?: [] as $alt) {
                $titles[] = is_array($alt) ? ($alt['title'] ?? '') : (string) $alt;
            }
            $titles = array_values(array_unique(array_filter($titles, fn ($t) => is_string($t) && trim($t) !== '')));
            $this->titles[$w->id] = $titles;
            $this->works[$w->id] = ['title' => (string) $w->clean_title, 'form' => $w->work_form];
            $author = self::authorKey($w->author_name);
            foreach (array_unique(array_filter(array_map(fn ($t) => self::key($t), $titles))) as $k) {
                $this->byTitle[$k][$w->id] = $author;
            }
        }
    }

    /**
     * Every pair the related works recorded as text give, once: «child» is made on «parent». $each, when given, is
     * told every entry and how it was matched: (work id, side, how, entry).
     *
     * @return array{pairs: array<string, array{child: int, parent: int, type: string, confidence: int, sides: list<string>, text: string}>, count: array<string, int>}
     */
    public function pairs(?callable $each = null): array
    {
        $pairs = [];
        $count = [];
        $works = DB::table('works')
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereNotNull('related_work')->where('related_work', '<>', ''))
                ->orWhere(fn ($q) => $q->whereNotNull('commentaries_and_glosses')->whereNotIn('commentaries_and_glosses', ['', '[]'])))
            ->select('id', 'related_work', 'commentaries_and_glosses');
        foreach ($works->lazyById(2000) as $w) {
            $sides = ['related' => $w->related_work ? [$w->related_work] : [], 'children' => json_decode($w->commentaries_and_glosses ?? '[]', true) ?: []];
            foreach ($sides as $side => $texts) {
                foreach ($texts as $text) {
                    foreach (WorkRelationParser::parseEntries((string) $text) as $entry) {
                        [$how, $pair] = $this->match((int) $w->id, $side, $entry);
                        $count["{$side}: {$how}"] = ($count["{$side}: {$how}"] ?? 0) + 1;
                        if ($each) {
                            $each((int) $w->id, $side, $how, $entry);
                        }
                        if ($pair) {
                            $k = "{$pair['child']}>{$pair['parent']}";
                            // the same pair from both sides: a type read on one side wins over «related»
                            if (! isset($pairs[$k]) || ($pairs[$k]['type'] === 'related' && $pair['type'] !== 'related')) {
                                $pair['sides'] = array_values(array_unique([...($pairs[$k]['sides'] ?? []), $side]));
                                $pairs[$k] = $pair;
                            } else {
                                $pairs[$k]['sides'] = array_values(array_unique([...$pairs[$k]['sides'], $side]));
                            }
                        }
                    }
                }
            }
        }
        // a pair recorded both ways round says nothing sure about which is made on which
        foreach (array_keys($pairs) as $k) {
            [$a, $b] = explode('>', $k);
            if (isset($pairs["{$b}>{$a}"])) {
                unset($pairs[$k], $pairs["{$b}>{$a}"]);
                $count['both ways round'] = ($count['both ways round'] ?? 0) + 1;
            }
        }

        return ['pairs' => $pairs, 'count' => $count];
    }

    /**
     * The entry's work, and how it was found: «title+author» gives the pair, every other way none.
     *
     * @param  string  $side  related: the entry is what the work is made on; children: made on the work
     * @return array{0: string, 1: ?array}
     */
    private function match(int $workId, string $side, array $entry): array
    {
        $candidates = [];
        foreach ($entry['titles'] as $title) {
            foreach ($this->byTitle[self::key($title)] ?? [] as $id => $author) {
                if ($id !== $workId) {
                    $candidates[$id] = $author;
                }
            }
        }
        $author = $entry['author'] ? self::authorKey($entry['author']) : null;
        $same = $author ? array_keys(array_filter($candidates, fn ($a) => $a !== '' && $a === $author)) : [];
        if (count($same) !== 1) {
            return [match (true) {
                ! $candidates => 'no work of the title',
                count($same) > 1 => 'several works of the title and author',
                ! $author => 'no author in the text',
                default => 'another author',
            }, null];
        }

        [$child, $parent] = $side === 'related' ? [$workId, $same[0]] : [$same[0], $workId];
        $parentTitles = $this->titles[$parent];
        if ($side === 'related') {
            array_push($parentTitles, ...$entry['titles']);
        }
        $childTitle = $this->works[$child]['title'];
        $translation = $this->works[$child]['form'] === 'translation';
        if (! $translation && collect($parentTitles)->contains(fn ($t) => self::key($t) !== '' && self::key($t) === self::key($childTitle))) {
            return ['one title on both sides', null];
        }
        $made = WorkRelationParser::parseTitle($childTitle);
        $type = match (true) {
            $translation => 'translation',
            $made && collect($parentTitles)->contains(fn ($t) => Titles::agree($made['base'], $t)) => $made['type'],
            // «الاختصاص (ترجمه)» among the commentaries: the mark is the entry's own
            ! $made && $side === 'children' && $entry['type'] !== null => $entry['type'],
            default => 'related',
        };

        return ['title+author', ['child' => $child, 'parent' => $parent, 'type' => $type,
            'confidence' => $type === 'related' ? self::CONFIDENCE_RELATED : self::CONFIDENCE_TYPED,
            'text' => implode(' = ', $entry['titles']) . ($entry['author'] ? '؛ ' . $entry['author'] : '')]];
    }

    /**
     * Fankha's text as it is, escaped and in Persian digits, with the first title of each entry a relation links made a
     * link to that work's page (backlog item 6, point 6: the text is kept as written).
     *
     * @param  list<array{0: string, 1: \App\Models\Work}>  $links  the title as written and its work, in the text's order
     */
    public static function html(string $text, array $links): \Illuminate\Support\HtmlString
    {
        $html = '';
        $at = 0;
        foreach ($links as [$title, $work]) {
            $pos = mb_strpos($text, $title, $at);
            if ($pos === false) {
                continue;
            }
            $html .= e(Fa::digits(mb_substr($text, $at, $pos - $at)))
                . '<a href="' . e(route('works.show', $work)) . '" class="text-ink border-b border-line-strong hover:text-cinnabar hover:border-cinnabar transition" title="'
                . e('صفحه‌ی اثر: ' . $work->clean_title) . '">' . e(Fa::digits($title)) . '</a>';
            $at = $pos + mb_strlen($title);
        }

        return new \Illuminate\Support\HtmlString($html . e(Fa::digits(mb_substr($text, $at))));
    }

    /**
     * The entries of a work's related work recorded as text that a relation links, for its page: the title as
     * written => the work. Only relations made from these texts are read, both ways.
     *
     * @return array{related: list<array{0: string, 1: \App\Models\Work}>, children: array<int, list<array{0: string, 1: \App\Models\Work}>>}
     *     for html(): the related work's links, and each commentary line's by its index
     */
    public static function linkedEntries(\App\Models\Work $work): array
    {
        $out = ['related' => [], 'children' => []];
        $rows = DB::table('work_relations')->where('work_id', $work->id)->orWhere('related_work_id', $work->id)->get();
        if ($rows->isEmpty()) {
            return $out;
        }
        $others = \App\Models\Work::whereIn('id', $rows->flatMap(fn ($r) => [$r->work_id, $r->related_work_id])->reject(fn ($id) => $id == $work->id))
            ->get(['id', 'clean_title', 'primary_title', 'alternative_titles', 'slug'])->keyBy('id');
        $keys = fn (\App\Models\Work $w) => collect([$w->clean_title, $w->primary_title, ...collect($w->alternative_titles ?? [])->map(fn ($a) => is_array($a) ? ($a['title'] ?? '') : $a)])
            ->map(fn ($t) => self::key($t))->filter()->unique()->all();
        $find = function (array $entry, iterable $ids) use ($others, $keys) {
            $want = array_filter(array_map(fn ($t) => self::key($t), $entry['titles']));
            $hits = collect($ids)->map(fn ($id) => $others->get($id))->filter()->filter(fn ($w) => array_intersect($want, $keys($w)))->unique('id');

            return $hits->count() === 1 ? $hits->first() : null;
        };
        foreach (WorkRelationParser::parseEntries($work->related_work) as $entry) {
            if ($hit = $find($entry, $rows->where('work_id', $work->id)->pluck('related_work_id'))) {
                $out['related'][] = [$entry['titles'][0], $hit];
            }
        }
        foreach (array_values($work->commentaries_and_glosses ?? []) as $i => $text) {
            $entry = WorkRelationParser::parseEntries((string) $text)[0] ?? null;
            if ($entry && ($hit = $find($entry, $rows->where('related_work_id', $work->id)->pluck('work_id')))) {
                $out['children'][$i] = [[$entry['titles'][0], $hit]];
            }
        }

        return $out;
    }
}

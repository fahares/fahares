<?php

namespace App\Support;

use App\Console\Commands\MatchCatalogEditionCommand;
use App\Helpers\TextNormalizer;
use App\Support\PageLinks\Titles;
use Illuminate\Support\Facades\DB;

/**
 * The related works recorded as text matched to the works' records (backlog item 6, step 14.104). A work's «وابسته
 * به» names the works it is made on, and its «شروح و حواشی» the works made
 * on it, each as «عنوان = عنوان دیگر؛ پدیدآور (تولد-وفات)» (WorkRelationParser::parseEntries). An entry is matched
 * to the one other work that has one of its titles and the same author as Fankha writes him («سعدی چلپی، سعدالله بن
 * عیسی»): by the user's decision, only such pairs are linked, and weaker matches (title only, several works, another
 * author) and pairs whose two works have one title (most likely one work twice) are left.
 *
 * Step 14.229 adds the entries the same title and author are in, written another way (byRule): the author after the
 * title with no «؛» («تهذیب المنطق تفتازانی، مسعود بن عمر (722-792)»), or after another title («شرح التجرید
 * قوشچی، علی بن محمد»); the author under another name of his person's record, or under another form of the name
 * with the same year of death; and an entry naming no author, when one work of its title names this work back in its
 * own text. Where a rule finds several works, the one that names this work back is taken. Of several works of one
 * title and author, the entry's is the one that has every title of the entry as written, marks and all («حاشية شرح
 * التجريد الجديد (امور عامه)»), or, for the work a work is made on, the one that is not itself a selection or
 * translation of the others. Two works of one title are linked when they are plainly two: other authors, or the one
 * made on the other a selection, a commentary or in verse («مجالس المؤمنین (منتخب)»).
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

    /** @var array<int, array{keys: list<string>, died: ?int}> work id => its author's record: every name's key, the death year */
    private array $person = [];

    /** @var array<int, string> work id => its author as written */
    private array $author = [];

    /** @var array{related: array<int, array<string, true>>, children: array<int, array<string, true>>} the title keys each work's texts name */
    private array $named = ['related' => [], 'children' => []];

    /** Taken off the confidence of a pair a rule of step 14.229 found */
    public const CONFIDENCE_RULE_LESS = 5;

    /** The shortest author's key that may end a longer one («… قوشچی، علی بن محمد») */
    public const SUFFIX_MIN = 8;

    /** The forms of a work made on another that may carry its title, and the type they give (null: «related») */
    private const MADE_FORMS = ['selection' => 'abridgment', 'verse' => 'versification', 'commentary' => 'commentary',
        'compilation' => null, 'notes' => null, 'lecture_notes' => null];

    /** Parts of a name that say nothing of who it is */
    private const NAME_WORDS = ['بن', 'ابن', 'ابو', 'ابی', 'بنت', 'ملا', 'مولی', 'شیخ', 'سید', 'میرزا', 'حاج', 'حاجی', 'آقا', 'محمد', 'علی', 'حسن', 'حسین', 'احمد', 'الدین', 'الله', 'عبد'];

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
        foreach (DB::table('works')->select('id', 'clean_title', 'primary_title', 'alternative_titles', 'author_name', 'author_id', 'work_form')->lazyById(5000) as $w) {
            $titles = [$w->clean_title, $w->primary_title];
            foreach (json_decode($w->alternative_titles ?? '[]', true) ?: [] as $alt) {
                $titles[] = is_array($alt) ? ($alt['title'] ?? '') : (string) $alt;
            }
            $titles = array_values(array_unique(array_filter($titles, fn ($t) => is_string($t) && trim($t) !== '')));
            $this->titles[$w->id] = $titles;
            $this->works[$w->id] = ['title' => (string) $w->clean_title, 'form' => $w->work_form];
            $author = self::authorKey($w->author_name);
            $this->author[$w->id] = (string) $w->author_name;
            if ($w->author_id) {
                $authorOf[$w->id] = $w->author_id;
            }
            foreach (array_unique(array_filter(array_map(fn ($t) => self::key($t), $titles))) as $k) {
                $this->byTitle[$k][$w->id] = $author;
            }
        }
        $people = [];
        foreach (DB::table('people')->select('id', 'name', 'aliases', 'death_year_hijri')->lazyById(5000) as $p) {
            $names = [$p->name, ...array_filter(json_decode($p->aliases ?? '[]', true) ?: [], 'is_string')];
            $people[$p->id] = ['id' => $p->id, 'keys' => array_values(array_unique(array_filter(array_map(fn ($n) => self::authorKey($n), $names)))),
                'died' => $p->death_year_hijri ? (int) $p->death_year_hijri : null];
        }
        foreach ($authorOf ?? [] as $id => $personId) {
            if (isset($people[$personId])) {
                $this->person[$id] = $people[$personId];
            }
        }
    }

    /**
     * The starts of a title that may be the title itself, the rest being its author written after it with no «؛»:
     * [the start as written, the rest], longest start first.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function heads(string $title): array
    {
        $heads = [];
        preg_match_all('/\s+/u', $title, $m, PREG_OFFSET_CAPTURE);
        foreach (array_reverse($m[0]) as [$space, $at]) {
            if ($at > 0 && ($rest = trim(substr($title, $at + strlen($space)))) !== '') {
                $heads[] = [substr($title, 0, $at), $rest];
            }
        }

        return $heads;
    }

    /** The title's key with the mark after it kept: «الرجعة (منتخب)» is not «الرجعة» */
    private static function markKey(?string $title): string
    {
        return MatchCatalogEditionCommand::norm(TextNormalizer::normalize((string) $title));
    }

    /** An entry's key, as work_text_relations.entry_key keeps it (steps 14.270 and 14.285): its titles as written and its author, without the life dates */
    public static function entryKey(array $entry): string
    {
        return implode(' = ', $entry['titles']) . ($entry['author'] ? '؛ ' . $entry['author'] : '');
    }

    /** Whether the work is one this linker has read */
    public function has(int $id): bool
    {
        return isset($this->works[$id]);
    }

    /**
     * The relation an entry of a work gives when a decision, not the title and author, says which work it names
     * (step 14.270): which is made on which, and the type as match() reads it.
     *
     * @return array{child: int, parent: int, type: string}
     */
    public function relationOf(int $workId, string $side, array $entry, int $found): array
    {
        [$child, $parent] = $side === 'related' ? [$workId, $found] : [$found, $workId];

        return ['child' => $child, 'parent' => $parent, 'type' => self::typeOf($this->works[$child]['title'] ?? '', $this->works[$child]['form'] ?? null,
            $this->titles[$child] ?? [], [...$this->titles[$parent] ?? [], ...($side === 'related' ? $entry['titles'] : [])], $side, $entry)];
    }

    /**
     * The same for two works read on their own, with no index of every work: the panel's decision on one entry (step 14.274).
     *
     * @return array{child: int, parent: int, type: string}
     */
    public static function relationBetween(\App\Models\Work $host, string $side, array $entry, \App\Models\Work $found): array
    {
        [$child, $parent] = $side === 'related' ? [$host, $found] : [$found, $host];
        $titles = fn (\App\Models\Work $w) => array_values(array_filter([$w->clean_title, $w->primary_title,
            ...collect($w->alternative_titles ?? [])->map(fn ($a) => is_array($a) ? ($a['title'] ?? '') : (string) $a)->all()]));

        return ['child' => $child->id, 'parent' => $parent->id, 'type' => self::typeOf((string) $child->clean_title, $child->work_form,
            $titles($child), [...$titles($parent), ...($side === 'related' ? $entry['titles'] : [])], $side, $entry)];
    }

    /** The type of a relation a decision gives, as match() reads it: the form, the title, the entry's mark, the work's own mark */
    public static function typeOf(string $childTitle, ?string $form, array $childTitles, array $parentTitles, string $side, array $entry): string
    {
        $made = WorkRelationParser::parseTitle($childTitle);

        return match (true) {
            $form === 'translation' => 'translation',
            $made && collect($parentTitles)->contains(fn ($t) => Titles::agree($made['base'], $t)) => $made['type'],
            ! $made && $side === 'children' && ($entry['type'] ?? null) !== null => $entry['type'],
            default => self::markOf($childTitles) ?? self::MADE_FORMS[(string) $form] ?? 'related',
        };
    }

    /** The type the mark after one of the work's titles gives: «مجالس المؤمنین (منتخب)» → abridgment */
    private function markType(int $id): ?string
    {
        return self::markOf($this->titles[$id] ?? []);
    }

    /** @param  list<string>  $titles */
    private static function markOf(array $titles): ?string
    {
        foreach ($titles as $title) {
            if (preg_match('/\(([^()]+)\)\s*$/u', $title, $m) && ($type = WorkRelationParser::MARKS[TextNormalizer::normalize($m[1])] ?? null)) {
                return $type;
            }
        }

        return null;
    }

    /** Two works of one title are two works, not one recorded twice: other authors, or the one made on the other marked so */
    private function twoWorks(int $child, int $parent): bool
    {
        [$a, $b] = [self::authorKey($this->author[$child]), self::authorKey($this->author[$parent])];
        $onePerson = $a === $b || (($this->person[$child]['id'] ?? 0) === ($this->person[$parent]['id'] ?? -1))
            || str_ends_with($a, $b) || str_ends_with($b, $a)
            || count(array_intersect(self::nameWords($this->author[$child]), self::nameWords($this->author[$parent]))) >= 2;
        $form = $this->works[$child]['form'];

        return ($a !== '' && $b !== '' && ! $onePerson)
            || (array_key_exists((string) $form, self::MADE_FORMS) && $form !== $this->works[$parent]['form'])
            || $this->markType($child) !== null;
    }

    /** @return list<string> the parts of a name that tell it from another */
    private static function nameWords(?string $name): array
    {
        $words = preg_split('/[\s،,()]+/u', TextNormalizer::normalize((string) $name), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter($words, fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, self::NAME_WORDS, true))));
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
        // The catalogue's entries, which since step 14.285 are rows of work_text_relations (WorkCatalogueEntries) and
        // no longer two fields of the work: a row of «وابسته به» is one entry, a line of «شروح و حواشی» may hold several.
        $texts = DB::table('work_text_relations')->where('source', WorkCatalogueEntries::SOURCE)->where('type', '<>', WorkCatalogueEntries::NOTE)
            ->select('id', 'work_id', 'side', 'position', 'text')->orderBy('work_id')->orderBy('side', 'desc')->orderBy('position')->orderBy('id');
        $rows = [];
        foreach ($texts->get() as $w) {
            foreach (WorkRelationParser::parseEntries((string) $w->text) as $entry) {
                $rows[$w->work_id][$w->side][] = $entry;
                foreach ($entry['titles'] as $title) {
                    $this->named[$w->side][$w->work_id][self::key($title)] = true;
                    // the author perhaps written after the title: every start of it is named too
                    foreach ($entry['author'] === null && str_contains($title, '،') ? self::heads($title) : [] as [$head]) {
                        $this->named[$w->side][$w->work_id][self::key($head)] = true;
                    }
                }
            }
        }
        foreach ($rows as $id => $sides) {
            foreach ($sides as $side => $entries) {
                // the entries one work has the title and author of first: a work they take is no other entry's
                $found = array_map(fn ($entry) => $this->match($id, $side, $entry), $entries);
                $taken = [];
                foreach ($found as [, $pair]) {
                    if ($pair) {
                        $taken[$side === 'related' ? $pair['parent'] : $pair['child']] = true;
                    }
                }
                foreach ($entries as $n => $entry) {
                    [$how, $pair] = $found[$n][1] ? $found[$n] : $this->match($id, $side, $entry, $taken);
                    $count["{$side}: {$how}"] = ($count["{$side}: {$how}"] ?? 0) + 1;
                    if ($each) {
                        $each($id, $side, $how, $entry, $pair);
                    }
                    if ($pair) {
                        $k = "{$pair['child']}>{$pair['parent']}";
                        // the same pair from both sides: a type read on one side wins over «related», then the plain match over a rule
                        if (! isset($pairs[$k]) || ($pairs[$k]['type'] === 'related' && $pair['type'] !== 'related')
                            || ($pairs[$k]['type'] === $pair['type'] && $pair['confidence'] > $pairs[$k]['confidence'])) {
                            $pair['sides'] = array_values(array_unique([...($pairs[$k]['sides'] ?? []), $side]));
                            $pairs[$k] = $pair;
                        } else {
                            $pairs[$k]['sides'] = array_values(array_unique([...$pairs[$k]['sides'], $side]));
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
    private function match(int $workId, string $side, array $entry, ?array $taken = null): array
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
        $how = 'title+author';
        if (count($same) !== 1) {
            $left = match (true) {
                ! $candidates => 'no work of the title',
                count($same) > 1 => 'several works of the title and author',
                ! $author => 'no author in the text',
                default => 'another author',
            };
            // the rules run once every entry of the work has had its plain match: $taken is given then
            [$how, $same] = ($taken === null ? null : $this->byRule($workId, $side, $entry, $candidates, $author, $same, $taken)) ?? [$left, []];
            if (count($same) !== 1) {
                return [$how, null];
            }
        }

        [$child, $parent] = $side === 'related' ? [$workId, $same[0]] : [$same[0], $workId];
        $parentTitles = $this->titles[$parent];
        if ($side === 'related') {
            array_push($parentTitles, ...$entry['titles']);
        }
        $childTitle = $this->works[$child]['title'];
        $translation = $this->works[$child]['form'] === 'translation';
        $oneTitle = ! $translation && collect($parentTitles)->contains(fn ($t) => self::key($t) !== '' && self::key($t) === self::key($childTitle));
        if ($oneTitle) {
            if ($taken === null || ! $this->twoWorks($child, $parent)) {
                return ['one title on both sides', null];
            }
            $how = $how === 'title+author' ? 'one title, two works' : "{$how}, one title";
        }
        $made = WorkRelationParser::parseTitle($childTitle);
        $type = match (true) {
            $translation => 'translation',
            $made && collect($parentTitles)->contains(fn ($t) => Titles::agree($made['base'], $t)) => $made['type'],
            // «الاختصاص (ترجمه)» among the commentaries: the mark is the entry's own
            ! $made && $side === 'children' && $entry['type'] !== null => $entry['type'],
            $oneTitle => $this->markType($child) ?? self::MADE_FORMS[$this->works[$child]['form']] ?? 'related',
            default => 'related',
        };

        return [$how, ['child' => $child, 'parent' => $parent, 'type' => $type, 'how' => $how,
            'confidence' => ($type === 'related' ? self::CONFIDENCE_RELATED : self::CONFIDENCE_TYPED) - ($how === 'title+author' ? 0 : self::CONFIDENCE_RULE_LESS),
            'text' => implode(' = ', $entry['titles']) . ($entry['author'] ? '؛ ' . $entry['author'] : '')]];
    }

    /**
     * The entry's work by the rules of step 14.229, for an entry the plain match left: [the rule, the one work], or
     * null when no rule finds one. A rule that finds several works keeps the one naming this work back in its own text.
     *
     * @param  array<int, string>  $candidates  the works of the entry's titles => their author keys
     * @param  list<int>  $same  the candidates of the entry's author, when several
     * @param  array<int, true>  $taken  the works the work's other entries are matched to
     * @return array{0: string, 1: list<int>}|null
     */
    private function byRule(int $workId, string $side, array $entry, array $candidates, ?string $author, array $same, array $taken): ?array
    {
        $candidates = array_diff_key($candidates, $taken);
        $mine = array_fill_keys(array_filter(array_map(fn ($t) => self::key($t), $this->titles[$workId] ?? [])), true);
        $back = $side === 'children' ? 'related' : 'children';
        // a work is made on one work: when another work of this one's title lists it too, which of them it names is not told
        $listedElsewhere = function (int $id, array $named) use ($workId) {
            $its = array_fill_keys(array_filter(array_map(fn ($t) => self::key($t), $this->titles[$id] ?? [])), true);
            foreach (array_keys($named) as $key) {
                foreach (array_keys($this->byTitle[$key] ?? []) as $other) {
                    if ($other !== $workId && $other !== $id && array_intersect_key($this->named['children'][$other] ?? [], $its)) {
                        return true;
                    }
                }
            }

            return false;
        };
        $namesBack = fn (array $ids) => array_values(array_filter($ids, fn ($id) => ($named = array_intersect_key($this->named[$back][$id] ?? [], $mine))
            && ! ($side === 'children' && $listedElsewhere($id, $named))));
        $one = function (string $rule, array $ids) use ($namesBack) {
            if (count($ids) > 1) {
                [$rule, $ids] = ["{$rule}, named back", $namesBack($ids)];
            }

            return count($ids) === 1 ? [$rule, array_values($ids)] : null;
        };

        if (count($same) > 1) {
            $same = array_values(array_diff($same, array_keys($taken)));
            // the one that has every title of the entry as written, marks and all
            $want = array_filter(array_map(fn ($t) => self::markKey($t), $entry['titles']));
            $all = array_values(array_filter($same, fn ($id) => ! array_diff($want, array_map(fn ($t) => self::markKey($t), $this->titles[$id]))));
            if (count($same) > 1 && count($all) === 1) {
                return ['title+author, every title', $all];
            }
            // what a work is made on: the one of them that is not itself a selection or translation of the others
            $titles = array_fill_keys(array_filter(array_map(fn ($t) => self::key($t), $entry['titles'])), true);
            $base = array_values(array_filter($same, fn ($id) => ! $this->works[$id]['form'] && $this->markType($id) === null
                && ! array_intersect_key($this->named['related'][$id] ?? [], $titles)));
            if ($side === 'related' && count($same) > 1 && count($base) === 1 && ! preg_grep('/\([^()]+\)\s*$/u', $entry['titles'])) {
                return ['title+author, the base', $base];
            }

            return $one('title+author', $same);
        }
        if ($author) {
            $words = self::nameWords($entry['author']);
            $rules = [
                // «شرح التجرید قوشچی، علی بن محمد»: another title before the author
                'author after another title' => fn ($a, $id) => mb_strlen($a) >= self::SUFFIX_MIN && str_ends_with($author, $a),
                'another name of the person' => fn ($a, $id) => in_array($author, $this->person[$id]['keys'] ?? [], true),
                'same year of death' => fn ($a, $id) => $entry['died'] && $entry['died'] === ($this->person[$id]['died'] ?? null)
                    && array_intersect($words, self::nameWords($this->author[$id])),
            ];
            foreach ($rules as $rule => $is) {
                if ($ids = array_keys(array_filter($candidates, $is, ARRAY_FILTER_USE_BOTH))) {
                    return $one($rule, $ids);
                }
            }

            return null;
        }

        // «حاشية رياض المسائل (4عنوان)» stands for several works, and no one of them is the entry's
        if (preg_grep('/\(\s*\d+\s*عنوان\s*\)/u', $entry['titles'])) {
            return null;
        }
        // «تهذیب المنطق تفتازانی، مسعود بن عمر (722-792)»: the author after the title, with no «؛»
        $ids = [];
        foreach ($entry['titles'] as $title) {
            foreach (str_contains($title, '،') ? self::heads($title) : [] as [$head, $rest]) {
                $name = self::authorKey(preg_replace('/\s*\([^()]*\d[^()]*\)\s*$/u', '', $rest));
                foreach ($name === '' ? [] : array_diff_key(($this->byTitle[self::key($head)] ?? []) + $candidates, $taken) as $id => $a) {
                    if ($id !== $workId && $a === $name) {
                        $ids[$id] = true;
                    }
                }
            }
        }
        if ($ids) {
            return $one('author in the title', array_keys($ids));
        }
        $ids = $namesBack(array_keys($candidates));

        return count($ids) === 1 ? ['named back', $ids] : null;
    }
}

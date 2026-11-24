<?php

namespace App\Support;

use App\Helpers\TextNormalizer;
use App\Models\Work;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The related works a catalogue records as text under a work, «وابسته به» (what the work is made on) and «شروح و
 * حواشی» (what is made on it), kept as rows of work_text_relations with source «catalogue» (backlog item 6, steps
 * 14.283 and 14.285). The fields works.related_work and works.commentaries_and_glosses they came from are gone: an
 * importer hands the two texts to sync(), which writes one row for each entry.
 *
 * A row may be tied to a work of the site (linked_work_id, link_status linked): by the rules («auto», tie(), from the
 * relations works:link-related makes), by the judged decisions («model», works:link-decisions) or by a reviewer
 * («review», the panel's «پیوند آثار مرتبط», where «proposed» and «rejected» are a candidate and not a tie). The work's
 * page reads page(): the rows tied to no work as text, the catalogue's words beside a tied work where they differ
 * from its record, and the notes.
 */
class WorkCatalogueEntries
{
    public const SOURCE = 'catalogue';

    /** The type of a row that is what a field says after its entries, not an entry */
    public const NOTE = 'note';

    /** The type of a row of «وابسته به» that is only the name of the author of the text the work is made on (step 14.337) */
    public const AUTHOR = 'author';

    /** The types of the rows that are no entry of a work */
    public const NOT_ENTRIES = [self::NOTE, self::AUTHOR];

    /** @var array{0: string, 1: array<string, array<int, ?int>>}|null the people by the key of each of their names, and what they were read from */
    private static ?array $people = null;

    /**
     * The rows the two texts give, in their place: every entry, a field the parser reads no entry in as one row as
     * written, and what «وابسته به» says after several entries as a note.
     *
     * @return list<array{side: string, position: int, type: string, text: string, entry_key: ?string}>
     */
    public static function fromTexts(Work $work, ?string $related, array $children): array
    {
        $clean = fn ($text) => trim(preg_replace(['/<!--.*?-->/su', '/\s+/u'], ' ', (string) $text));
        $type = fn (array $entry) => WorkRelationLinker::typeOf((string) $work->clean_title, $work->work_form, self::titlesOf($work), $entry['titles'], 'related', $entry);
        $rows = [];

        $field = $clean($related);
        $entries = WorkRelationParser::parseEntries($related);
        $slices = self::slices($field, $entries);
        foreach ($entries as $n => $entry) {
            // a field of one entry is kept as written, its note with it
            $text = count($entries) === 1 ? $field : ($slices[$n]
                ?? implode(' = ', $entry['titles']) . ($entry['author'] ? '؛ ' . $entry['author'] . ($entry['born'] || $entry['died'] ? " ({$entry['born']}-{$entry['died']})" : '') : ''));
            $rows[] = ['side' => 'related', 'position' => $n, 'type' => $type($entry), 'text' => $text, 'entry_key' => WorkRelationLinker::entryKey($entry)];
        }
        if ($field !== '' && ! $entries) {
            // a long text with no «؛»: the parser takes it for a note
            $rows[] = ['side' => 'related', 'position' => 0, 'type' => $type(['titles' => [], 'type' => null]), 'text' => $field, 'entry_key' => null];
        } elseif (count($entries) > 1) {
            $last = end($entries);
            $needle = $last['author'] ?? end($last['titles']);
            if (($at = mb_strrpos($field, $needle)) !== false) {
                $rest = preg_replace(['/^\s*\([^()]*\)/u', '/^[\s؛;،.\-]+|[\s؛;،.\-]+$/u'], '', mb_substr($field, $at + mb_strlen($needle)));
                if (mb_strlen($rest) > 15) {
                    $rows[] = ['side' => 'related', 'position' => 999, 'type' => self::NOTE, 'text' => $rest, 'entry_key' => null];
                }
            }
        }
        foreach (array_values($children) as $i => $text) {
            $text = $clean($text);
            if ($text === '') {
                continue;
            }
            $entry = WorkRelationParser::parseEntries($text)[0] ?? null;
            $rows[] = ['side' => 'children', 'position' => $i, 'type' => $entry['type'] ?? 'related', 'text' => $text, 'entry_key' => $entry ? WorkRelationLinker::entryKey($entry) : null];
        }

        return $rows;
    }

    /**
     * Writes the work's two texts as its rows of the catalogue: a row whose side and text are there already is kept,
     * with whatever it is tied to; the others are added, and the rows no longer given removed. Then the rules tie
     * what the work's relations say (tie()). A side given as false is left as it is.
     *
     * @param  string|false|null  $related  the text of «وابسته به»
     * @param  array|false  $children  the lines of «شروح و حواشی»
     * @return array{added: int, removed: int, kept: int}
     */
    public static function sync(Work $work, string|false|null $related, array|false $children): array
    {
        $sides = array_keys(array_filter(['related' => $related !== false, 'children' => $children !== false]));
        $have = DB::table('work_text_relations')->where('work_id', $work->id)->where('source', self::SOURCE)->whereIn('side', $sides)->orderBy('id')->get()
            ->groupBy(fn ($r) => "{$r->side}>{$r->text}")->map(fn ($g) => $g->all())->all();
        $count = ['added' => 0, 'removed' => 0, 'kept' => 0];
        $now = now();
        foreach (self::fromTexts($work, $related ?: null, $children ?: []) as $row) {
            $key = "{$row['side']}>{$row['text']}";
            if (isset($have[$key]) && ($kept = array_shift($have[$key]))) {
                DB::table('work_text_relations')->where('id', $kept->id)->update(['position' => $row['position'], 'type' => $row['type'], 'entry_key' => $row['entry_key']]);
                $count['kept']++;
            } else {
                DB::table('work_text_relations')->insert($row + ['work_id' => $work->id, 'source' => self::SOURCE, 'created_at' => $now, 'updated_at' => $now]);
                $count['added']++;
            }
        }
        $gone = collect($have)->flatten(1)->pluck('id');
        $count['removed'] = DB::table('work_text_relations')->whereIn('id', $gone)->delete();
        self::tie($work);

        return $count;
    }

    /** Whether the work's rows of «وابسته به» are what this text gives: an importer's «is the field still as it was» */
    public static function relatedIs(Work $work, ?string $text): bool
    {
        $have = DB::table('work_text_relations')->where('work_id', $work->id)->where('source', self::SOURCE)->where('side', 'related')
            ->orderBy('position')->orderBy('id')->pluck('text')->all();

        return $have === array_column(self::fromTexts($work, $text, []), 'text');
    }

    /**
     * Ties the work's rows by the rules: a row whose entry is one of the works the work is related to (work_relations),
     * by a title of the entry or, the author written after the title with no «؛», by the start of it. Of two entries
     * of one work, the one naming its author has it, else the first. A row tied by a decision or a reviewer is left,
     * and its work is no other row's; so is a candidate a reviewer rejected. A row the rules tied before and no longer
     * do is untied.
     *
     * @return int the rows changed
     */
    public static function tie(Work $work): int
    {
        $rows = DB::table('work_text_relations')->where('work_id', $work->id)->where('source', self::SOURCE)->where('type', '<>', self::NOTE)
            ->orderBy('side')->orderBy('position')->orderBy('id')->get();
        if ($rows->isEmpty()) {
            return 0;
        }
        // the rows that are only the name of the base text's author: no entry of a work, tied to the person
        $authors = 0;
        foreach ($rows as $row) {
            $author = $row->side === 'related' && ! ($row->link_status === 'linked' && $row->link_source !== 'auto') ? self::authorOnly($row->text) : null;
            if ($author) {
                if ($row->type !== self::AUTHOR || (int) $row->linked_person_id !== (int) $author['person'] || $row->link_status !== null) {
                    $authors += DB::table('work_text_relations')->where('id', $row->id)->update(['type' => self::AUTHOR, 'linked_person_id' => $author['person'],
                        'linked_work_id' => null, 'link_status' => null, 'link_source' => null, 'differs' => false]);
                }
                $row->type = self::AUTHOR;
            } elseif ($row->type === self::AUTHOR) {
                $authors += DB::table('work_text_relations')->where('id', $row->id)->update(['type' => 'related', 'linked_person_id' => null]);
                $row->type = 'related';
            }
        }
        $rows = $rows->where('type', '<>', self::AUTHOR)->values();
        $relations = DB::table('work_relations')->where('work_id', $work->id)->orWhere('related_work_id', $work->id)->get(['work_id', 'related_work_id']);
        $others = Work::whereIn('id', $relations->flatMap(fn ($r) => [$r->work_id, $r->related_work_id])->reject(fn ($id) => $id == $work->id)->unique())
            ->get(['id', 'clean_title', 'primary_title', 'alternative_titles', 'author_name'])->keyBy('id');
        $keys = fn (Work $w) => array_values(array_unique(array_filter(array_map(fn ($t) => WorkRelationLinker::key($t), self::titlesOf($w)))));
        $marked = fn (array $titles) => array_values(array_filter(array_map(fn ($t) => WorkRelationLinker::markKey(preg_replace('/\(\s*\d+\s*عنوان\s*\)/u', '', $t)), $titles)));
        // $several: the second pass, for the entries whose title several related works have (step 14.296)
        $find = function (array $entry, Collection $ids, array $entryOf, bool $several) use ($others, $keys, $marked) {
            $want = array_filter(array_map(fn ($t) => WorkRelationLinker::key($t), $entry['titles']));
            $works = $ids->map(fn ($id) => $others->get($id))->filter()->unique('id');
            $hits = $works->filter(fn ($w) => array_intersect($want, $keys($w)));
            if ($several) {
                if ($entry['author'] === null && ! $entry['unread']) {
                    // «اعتقادات صدوق (ترجمه) سرکانی، محمد صادق (-11)»: the author after the title with no «؛»
                    foreach ($entry['titles'] as $title) {
                        foreach (str_contains($title, '،') ? WorkRelationLinker::heads($title) : [] as [$head, $rest]) {
                            $name = WorkRelationLinker::authorKey(preg_replace('/\s*\([^()]*\d[^()]*\)\s*$/u', '', $rest));
                            $named = $name === '' ? collect() : $works->filter(fn ($w) => in_array(WorkRelationLinker::key($head), $keys($w), true) && WorkRelationLinker::authorKey($w->author_name) === $name);
                            if ($named->count() === 1) {
                                return [$head, $named->first()];
                            }
                        }
                    }
                }
                if ($hits->count() < 2) {
                    return null;
                }
                // a work that has every title of the entry as written, «(ترجمه)» and all
                $full = fn ($w) => ! array_diff($marked($entry['titles']), $marked(self::titlesOf($w)));
                $author = WorkRelationLinker::authorKey($entry['author']);
                if ($entry['author'] !== null) {
                    // the entry's author says which
                    $same = $hits->filter(fn ($w) => WorkRelationLinker::authorKey($w->author_name) === $author);
                    // «… = شرح اعتقادات صدوق مفید، محمد بن محمد (336-413)»: another title read before the author
                    $same = $same->isNotEmpty() ? $same : $hits->filter(fn ($w) => mb_strlen($a = WorkRelationLinker::authorKey($w->author_name)) >= WorkRelationLinker::SUFFIX_MIN && str_ends_with($author, $a));
                    // several works of the title and the author: those with every title of the entry; then the one whose
                    // own title it is, not another name of it («الاربعین فی اصول الدین» is also a name of «جواهر القرآن»);
                    // then the first of those no entry has yet, as the catalogue lists one entry for each
                    $same = $same->count() > 1 && $same->filter($full)->isNotEmpty() ? $same->filter($full) : $same;
                    $own = $same->filter(fn ($w) => array_intersect($want, [WorkRelationLinker::key($w->clean_title), WorkRelationLinker::key($w->primary_title)]));
                    $same = $same->count() > 1 && $own->count() === 1 ? $own : $same;
                    $hits = $same->count() > 1 ? $same->reject(fn ($w) => isset($entryOf[$w->id]))->sortBy('id')->take(1) : $same;
                } elseif ($entry['unread'] || $entry['many']) {
                    // «… (4عنوان)» stands for several works and is no one of them, and an author the entry names in a
                    // way not read is no reason to take a work
                    $hits = collect();
                } else {
                    // an entry with no author is a work with none that has every title of the entry («الحاشية المتوسطة =
                    // حاشية شرح الكافية» is not any gloss of the second title, «تهذیب المنطق (ترجمه)» not its «(مختصر)»):
                    // the first that no entry has yet, as the catalogue lists one entry for each
                    $hits = $hits->filter(fn ($w) => WorkRelationLinker::authorKey($w->author_name) === '' && ! isset($entryOf[$w->id]) && $full($w))->sortBy('id')->take(1)
                        ->filter(fn ($w) => $entry['only'] === null || $entry['only'] === $w->id);
                }

                return $hits->count() === 1 ? [$entry['titles'][0], $hits->first()] : null;
            }
            if ($hits->count() === 1) {
                return [$entry['titles'][0], $hits->first()];
            }
            foreach ($hits->isEmpty() && $entry['author'] === null ? $entry['titles'] : [] as $title) {
                foreach (str_contains($title, '،') ? WorkRelationLinker::heads($title) : [] as [$head]) {
                    $hits = $works->filter(fn ($w) => in_array(WorkRelationLinker::key($head), $keys($w), true));
                    if ($hits->count() === 1) {
                        return [$head, $hits->first()];
                    }
                }
            }

            return null;
        };
        $fixed = fn ($row) => $row->link_status === 'linked' && $row->link_source !== 'auto';
        // «شرح بیست باب؛ کرمانی، علاء الدین بن محمود»: an author written with no life dates reads as a second title
        $read = function ($row) {
            $parts = WorkRelationParser::parseEntries($row->text);
            if (! ($entry = $parts[0] ?? null)) {
                return null;
            }
            // «(4عنوان)», «ناشناس (6 عنوان مختلف)», «(همراه با 12 ترجمه ناشناس دیگر)»: the entry stands for several works
            $entry['many'] = self::several($row->text);
            $entry['unread'] = false;
            // a row waiting for a reviewer is given a work with no author only when it is the one proposed
            $entry['only'] = $row->link_status === 'proposed' ? (int) $row->linked_work_id : null;
            if ($row->side === 'children' && $entry['author'] === null && count($parts) > 1) {
                $entry['unread'] = true;
                if (count($parts) === 2 && $parts[1]['author'] === null && count($parts[1]['titles']) === 1) {
                    // «نراقی، احمد بن محمد مهدی (1185- (1245)»: life dates the parser does not read are no part of the name
                    [$entry['author'], $entry['unread']] = [trim(preg_replace('/\s*\((?=[^()]*\d).*$/su', '', $parts[1]['titles'][0])), false];
                }
            }
            // «؛ ناشناس» is the catalogue's word for no author
            if ($entry['author'] !== null && WorkRelationLinker::authorKey($entry['author']) === WorkRelationLinker::authorKey('ناشناس')) {
                $entry['author'] = null;
            }

            return $entry;
        };

        // the tie each row should have: row id => [the work's id, whether the catalogue's words differ]
        $ties = [];
        foreach (['related' => $relations->where('work_id', $work->id)->pluck('related_work_id'), 'children' => $relations->where('related_work_id', $work->id)->pluck('work_id')] as $side => $ids) {
            // a work is one entry's: the rows a decision or a reviewer tied have theirs
            $entryOf = $rows->where('side', $side)->filter($fixed)->mapWithKeys(fn ($row) => [$row->linked_work_id => [$row->id, true]])->all();
            // first the entries whose title one related work has, then those whose title several have
            foreach ([false, true] as $several) {
                foreach ($rows->where('side', $side)->reject($fixed) as $row) {
                    $entry = isset($ties[$row->id]) ? null : $read($row);
                    $hit = $entry ? $find($entry, $ids, $entryOf, $several) : null;
                    if (! $hit || ($row->link_status === 'rejected' && (int) $row->linked_work_id === $hit[1]->id)) {
                        continue;
                    }
                    $names = $entry['author'] !== null && WorkRelationLinker::authorKey($entry['author']) === WorkRelationLinker::authorKey($hit[1]->author_name);
                    if ($side === 'children' && isset($entryOf[$hit[1]->id]) && ! ($names && ! $entryOf[$hit[1]->id][1])) {
                        continue;
                    }
                    if ($side === 'children') {
                        unset($ties[$entryOf[$hit[1]->id][0] ?? 0]);
                        $entryOf[$hit[1]->id] = [$row->id, $names];
                    }
                    $ties[$row->id] = [$hit[1]->id, self::wordsDiffer($entry, $hit[0], $hit[1])];
                }
            }
        }

        $changed = 0;
        foreach ($rows->reject($fixed) as $row) {
            if ($tie = $ties[$row->id] ?? null) {
                $changed += DB::table('work_text_relations')->where('id', $row->id)->where(fn ($q) => $q->where('linked_work_id', '<>', $tie[0])->orWhereNull('linked_work_id')
                    ->orWhere('link_status', '<>', 'linked')->orWhereNull('link_status')->orWhere('differs', '<>', $tie[1]))
                    ->update(['linked_work_id' => $tie[0], 'link_status' => 'linked', 'link_source' => 'auto', 'differs' => $tie[1],
                        'why' => null, 'confidence' => null, 'decided_by' => null, 'decided_at' => null]);
            } elseif ($row->link_status === 'linked') {
                $changed += DB::table('work_text_relations')->where('id', $row->id)->update(['linked_work_id' => null, 'link_status' => null, 'link_source' => null, 'differs' => false]);
            }
        }

        return $changed + $authors;
    }

    /**
     * Whether an entry stands for several works and is no one of them: «حاشیة شرح الاشارات؛ ناشناس (17 عنوان مختلف)»,
     * «شرح الکافیة (4عنوان)», «آداب المریدین (ترجمه) (سه ترجمه ناشناس)», «… (به همراه 5 حاشیه ناشناس دیگر رک: دنا 5/4)».
     */
    public static function several(string $text): bool
    {
        return (bool) preg_match('/\([^()]*(?:عنوان|ناشناس|همراه)[^()]*\)/u', $text);
    }

    /** The title to search the works for, of an entry that stands for several: its first title, with no mark after it */
    public static function searchTitle(string $text): string
    {
        $title = trim(preg_split('/[؛;=]/u', $text)[0]);
        do {
            $title = trim(preg_replace('/\s*\([^()]*\)\s*$/u', '', $before = $title));
        } while ($title !== $before);

        return $title;
    }

    /**
     * Whether a row of «وابسته به» is only a name, the author of the text the work is made on, with no title: «علی بن
     * حسین (ع)، امام چهارم (38-94)», «منسوب به علی بن ابی طالب (ع)، امام اول (-40ق)», «کشی، ابراهیم». It is one only when
     * the whole of it is the name of a person: «آب حیات استرآبادی، محمد جعفر (1198-1263)» is a title with its author
     * after it and an entry. A name with no life dates must also be one person's, and no work's title.
     *
     * @return array{name: string, person: ?int}|null the name, and the one person of that name (none when several have it)
     */
    public static function authorOnly(string $text): ?array
    {
        if (preg_match('/[؛;=«»]/u', $text)) {
            return null;
        }
        $name = trim(preg_replace('/^\s*منسوب به\s+/u', '', $text));
        $died = null;
        if ($dated = (bool) preg_match('/^(.*?)\s*\(([^()]*\d[^()]*)\)\s*$/su', $name, $m)) {
            $name = trim($m[1]);
            $died = preg_match('/-\s*(\d+)/u', TextNormalizer::normalize($m[2]), $d) ? (int) $d[1] : null;
        }
        // a name: «family, given», «given son of», an imam's «(ع)»
        if ($name === '' || mb_strlen($name) > 80 || ! preg_match('/،|\sبن\s|\(ع\)/u', $name)) {
            return null;
        }
        if (! ($ids = self::people()[WorkRelationLinker::authorKey($name)] ?? [])) {
            return null;
        }
        $ofYear = $died ? array_filter($ids, fn ($year) => $year === $died) : [];
        $ids = count($ids) > 1 && $ofYear ? $ofYear : $ids;
        $person = count($ids) === 1 ? array_key_first($ids) : null;
        if (! $dated && (! $person || DB::table('works')->where('clean_title', $name)->exists())) {
            return null;
        }

        return ['name' => $name, 'person' => $person];
    }

    /** The people by the key of each of their names => [id => year of death]; read again when the table has changed */
    private static function people(): array
    {
        $now = implode('|', (array) DB::table('people')->selectRaw('COUNT(*) n, MAX(id) i, MAX(updated_at) u')->first());
        if ((self::$people[0] ?? null) !== $now) {
            $map = [];
            foreach (DB::table('people')->select('id', 'name', 'aliases', 'death_year_hijri')->lazyById(5000) as $p) {
                foreach ([$p->name, ...array_filter(json_decode($p->aliases ?? '[]', true) ?: [], 'is_string')] as $name) {
                    if (($key = WorkRelationLinker::authorKey($name)) !== '') {
                        $map[$key][$p->id] = $p->death_year_hijri ? (int) $p->death_year_hijri : null;
                    }
                }
            }
            self::$people = [$now, $map];
        }

        return self::$people[1];
    }

    /**
     * For the relations section of the work's page: the catalogue's words for a tied work where they differ from its
     * record («printed», by the work's id), the rows tied to no work («unlinked»; one that stands for several works
     * has «search», the title to search the works for, step 14.345), the notes, and the rows that are only the base
     * text's author («authors»: the catalogue's words and the person's id).
     *
     * @return array{printed: array<int, string>, unlinked: list<array{side: string, type: string, text: string, search?: string}>, notes: list<string>, authors: list<array{text: string, person: ?int}>}
     */
    public static function page(Work $work): array
    {
        $out = ['printed' => [], 'unlinked' => [], 'notes' => [], 'authors' => []];
        $rows = DB::table('work_text_relations')->where('work_id', $work->id)->where('source', self::SOURCE)
            ->orderBy('side', 'desc')->orderBy('position')->orderBy('id')->get(['side', 'type', 'text', 'linked_work_id', 'linked_person_id', 'link_status', 'differs']);
        foreach ($rows as $row) {
            if ($row->type === self::NOTE) {
                $out['notes'][] = $row->text;
            } elseif ($row->type === self::AUTHOR) {
                $out['authors'][] = ['text' => $row->text, 'person' => $row->linked_person_id];
            } elseif ($row->link_status !== 'linked') {
                $out['unlinked'][] = ['side' => $row->side, 'type' => $row->type, 'text' => $row->text]
                    + (self::several($row->text) && ($title = self::searchTitle($row->text)) !== '' ? ['search' => $title] : []);
            } elseif ($row->differs) {
                $out['printed'][$row->linked_work_id] = $row->text;
            }
        }

        return $out;
    }

    /**
     * Whether the catalogue's words for a work are not that work's title and author: none of the entry's titles (nor
     * the linked start of one) is the work's or the start of it, or its author is another name. An author written
     * after the title with no «؛», or after another title, is the work's own and no difference.
     *
     * @param  string  $linked  the part of the entry's first title that names the work
     */
    public static function wordsDiffer(array $entry, string $linked, Work $work): bool
    {
        $keys = array_map(fn ($t) => WorkRelationLinker::key($t), self::titlesOf($work));
        $key = WorkRelationLinker::key($linked);
        $title = array_intersect(array_map(fn ($t) => WorkRelationLinker::key($t), $entry['titles']), $keys) || in_array($key, $keys, true)
            || (str_contains($key, ' ') && collect($keys)->contains(fn ($k) => str_starts_with($k, "{$key} ")));
        $own = WorkRelationLinker::authorKey($work->author_name);
        $named = $entry['author'] ?? (str_starts_with($entry['titles'][0], $linked) && $linked !== $entry['titles'][0]
            ? preg_replace('/\s*\([^()]*\d[^()]*\)\s*$/u', '', mb_substr($entry['titles'][0], mb_strlen($linked))) : null);
        $name = WorkRelationLinker::authorKey($named);

        return ! $title || ($name !== '' && $name !== $own && ! ($own !== '' && mb_strlen($own) >= WorkRelationLinker::SUFFIX_MIN && str_ends_with($name, $own)));
    }

    /**
     * The part of an entry's first title that names the work, for wordsDiffer(): the title, or the start of it that
     * is the work's title (or the start of the work's title, in two words or more) when the author follows it.
     */
    public static function linkedPart(array $entry, Work $work): string
    {
        $keys = array_map(fn ($t) => WorkRelationLinker::key($t), self::titlesOf($work));
        $text = $entry['titles'][0];
        if (! array_intersect(array_map(fn ($t) => WorkRelationLinker::key($t), $entry['titles']), $keys)) {
            foreach (str_contains($text, '،') ? WorkRelationLinker::heads($text) : [] as [$head]) {
                $key = WorkRelationLinker::key($head);
                if (in_array($key, $keys, true) || (str_contains($key, ' ') && collect($keys)->contains(fn ($k) => str_starts_with($k, "{$key} ")))) {
                    return $head;
                }
            }
        }

        return $text;
    }

    /** @return list<string> the work's titles as written */
    public static function titlesOf(Work $work): array
    {
        return array_values(array_filter([$work->clean_title, $work->primary_title,
            ...collect($work->alternative_titles ?? [])->map(fn ($a) => is_array($a) ? ($a['title'] ?? '') : (string) $a)->all()]));
    }

    /**
     * Each entry of a field of several, as the field writes it: from its first title to the next entry's, its author's
     * life dates as written («(سده 1م)», which the parser reads no years in). An entry whose slice does not read back
     * as itself is left out, and written out again from its parts.
     *
     * @return array<int, string>
     */
    private static function slices(string $field, array $entries): array
    {
        $starts = [];
        $at = 0;
        foreach ($entries as $entry) {
            if (($start = mb_strpos($field, $entry['titles'][0], $at)) === false) {
                return [];
            }
            $starts[] = $start;
            $at = $start + mb_strlen($entry['titles'][0]);
        }
        $slices = [];
        foreach ($entries as $n => $entry) {
            if (isset($starts[$n + 1])) {
                $slice = mb_substr($field, $starts[$n], $starts[$n + 1] - $starts[$n]);
            } else {
                // the last entry ends with its author and life dates: what follows is the field's note
                $needle = $entry['author'] ?? end($entry['titles']);
                $end = mb_strpos($field, $needle, $starts[$n]);
                $tail = $end === false ? '' : mb_substr($field, $end + mb_strlen($needle));
                $slice = $end === false ? mb_substr($field, $starts[$n])
                    : mb_substr($field, $starts[$n], $end + mb_strlen($needle) - $starts[$n]) . (preg_match('/^\s*\([^()]*\)/u', $tail, $m) ? $m[0] : '');
            }
            // by a pattern, not trim(): trim() works on bytes and would cut the first byte of a letter that shares it with «؛»
            $slice = preg_replace('/^[\s؛;]+|[\s؛;]+$/u', '', $slice);
            $read = WorkRelationParser::parseEntries($slice);
            if (count($read) === 1 && $read[0]['titles'] === $entry['titles'] && $read[0]['author'] === $entry['author']) {
                $slices[$n] = $slice;
            }
        }

        return $slices;
    }
}

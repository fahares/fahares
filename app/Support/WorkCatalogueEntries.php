<?php

namespace App\Support;

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
        $relations = DB::table('work_relations')->where('work_id', $work->id)->orWhere('related_work_id', $work->id)->get(['work_id', 'related_work_id']);
        $others = Work::whereIn('id', $relations->flatMap(fn ($r) => [$r->work_id, $r->related_work_id])->reject(fn ($id) => $id == $work->id)->unique())
            ->get(['id', 'clean_title', 'primary_title', 'alternative_titles', 'author_name'])->keyBy('id');
        $keys = fn (Work $w) => array_values(array_unique(array_filter(array_map(fn ($t) => WorkRelationLinker::key($t), self::titlesOf($w)))));
        $find = function (array $entry, Collection $ids) use ($others, $keys) {
            $want = array_filter(array_map(fn ($t) => WorkRelationLinker::key($t), $entry['titles']));
            $works = $ids->map(fn ($id) => $others->get($id))->filter()->unique('id');
            $hits = $works->filter(fn ($w) => array_intersect($want, $keys($w)));
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

        // the tie each row should have: row id => [the work's id, whether the catalogue's words differ]
        $ties = [];
        foreach (['related' => $relations->where('work_id', $work->id)->pluck('related_work_id'), 'children' => $relations->where('related_work_id', $work->id)->pluck('work_id')] as $side => $ids) {
            // a work is one entry's: the rows a decision or a reviewer tied have theirs
            $entryOf = $rows->where('side', $side)->filter($fixed)->mapWithKeys(fn ($row) => [$row->linked_work_id => [$row->id, true]])->all();
            foreach ($rows->where('side', $side)->reject($fixed) as $row) {
                $entry = WorkRelationParser::parseEntries($row->text)[0] ?? null;
                $hit = $entry ? $find($entry, $ids) : null;
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

        return $changed;
    }

    /**
     * For the relations section of the work's page: the catalogue's words for a tied work where they differ from its
     * record («printed», by the work's id), the rows tied to no work («unlinked»), and the notes.
     *
     * @return array{printed: array<int, string>, unlinked: list<array{side: string, type: string, text: string}>, notes: list<string>}
     */
    public static function page(Work $work): array
    {
        $out = ['printed' => [], 'unlinked' => [], 'notes' => []];
        $rows = DB::table('work_text_relations')->where('work_id', $work->id)->where('source', self::SOURCE)
            ->orderBy('side', 'desc')->orderBy('position')->orderBy('id')->get(['side', 'type', 'text', 'linked_work_id', 'link_status', 'differs']);
        foreach ($rows as $row) {
            if ($row->type === self::NOTE) {
                $out['notes'][] = $row->text;
            } elseif ($row->link_status !== 'linked') {
                $out['unlinked'][] = ['side' => $row->side, 'type' => $row->type, 'text' => $row->text];
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

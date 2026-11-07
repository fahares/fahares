<?php

namespace App\Console\Commands;

use App\Models\Person;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\SourceItem;
use App\Models\Work;
use App\Sources\Bibliography\ItemMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Ties a person to his life in a book of lives (step 14.83): a work tied to its author's life there (sources:link-items,
 * sources:link-earlier-edition) gives that life to the work's author, when the life is his by its heading — the name
 * (and the death year) of the work's author, which ItemMatcher::matchLife reads on that one life. A tie by the work's
 * title in another man's life («title_in_text», «author_in_entry») gives nothing. Each tie is a source_entries row of
 * the person (role entry, origin match, legacy_type person_life, one a person and source); of a person's lives in
 * one source the one the most works give, then the surest. It follows the works' ties: verified when one of them is,
 * none when all are rejected; a tie surer than FLOOR, or verified, is needed. Running it again updates its own ties and never one a person has reviewed.
 */
class TieLivesToPeopleCommand extends Command
{
    public const LEGACY_TYPE = 'person_life';

    public const METHOD = 'via_works';

    /** The least confidence of a work's tie that gives its author the life, but a tie a person has verified. */
    public const FLOOR = 45;

    /** ItemMatcher::matchLife's methods that read the life's heading as the author's name. */
    private const BY_NAME = ['author_life', 'author_life_title', 'author_opening', 'author_opening_title'];

    protected $signature = 'sources:tie-lives-to-people
        {--source=* : codes of the books of lives (default: all of them)}
        {--report= : Write every tie to this JSON file}
        {--dry-run : Count without saving}';

    protected $description = 'Tie people to their lives in the books of lives, from the works tied to their authors\' lives';

    public function handle(ItemMatcher $matcher): int
    {
        $sources = Source::where('kind', Source::KIND_BIOGRAPHICAL)
            ->when($this->option('source'), fn ($q, $codes) => $q->whereIn('code', $codes))->get()->keyBy('id');
        $ties = SourceEntry::where('origin', SourceEntry::ORIGIN_MATCH)->where('citable_type', Work::class)
            ->whereIn('source_id', $sources->keys())->whereNotNull('source_item_id')->where('role', '!=', SourceEntry::ROLE_CITATION)
            ->get(['id', 'citable_id', 'source_id', 'source_item_id', 'confidence', 'status', 'match_method']);
        $works = Work::whereIn('id', $ties->pluck('citable_id')->unique())->whereNotNull('author_id')
            ->get(['id', 'primary_title', 'alternative_titles', 'author_id', 'author_name'])->keyBy('id');
        $people = Person::whereIn('id', $works->pluck('author_id')->unique())->get(['id', 'name', 'death_year_hijri'])->keyBy('id');
        $items = SourceItem::whereIn('id', $ties->pluck('source_item_id')->unique())
            ->get(['id', 'source_id', 'source_volume_id', 'volume_number', 'entry_number', 'title', 'text', 'page_start', 'page_end'])->keyBy('id');

        // each work's tie that reads the life as its author's: [person][source][item] => the ties
        $by = [];
        $counts = ['ties' => $ties->count(), 'no_author' => 0, 'not_by_name' => 0, 'by_name' => 0];
        foreach ($ties as $tie) {
            $work = $works[$tie->citable_id] ?? null;
            $item = $items[$tie->source_item_id] ?? null;
            if (! $work || ! $item) {
                $counts['no_author']++;

                continue;
            }
            $person = $people[$work->author_id];
            $openings = (bool) config('bibliography.sources.' . $sources[$tie->source_id]->code . '.text.openings');
            $titles = LinkSourceItemsCommand::titleForms(array_merge([$work->primary_title], array_column($work->alternative_titles ?? [], 'title')), '');
            $found = $matcher->matchLife($titles, $work->author_name ?: $person->name, $person->death_year_hijri,
                [['id' => $item->id, 'entry_number' => $item->entry_number, 'title' => $item->title, 'text' => $item->text]], $openings);
            if (! $found || ! in_array($found['method'], self::BY_NAME, true)) {
                $counts['not_by_name']++;

                continue;
            }
            $counts['by_name']++;
            $by[$person->id][$tie->source_id][$item->id][] = $tie;
        }

        // of a person's lives in a source, the one the most works give, then the surest
        $decisions = [];
        foreach ($by as $personId => $ofSource) {
            foreach ($ofSource as $sourceId => $ofItem) {
                uasort($ofItem, fn ($a, $b) => [count($b), collect($b)->max('confidence')] <=> [count($a), collect($a)->max('confidence')]);
                $itemId = array_key_first($ofItem);
                $from = collect($ofItem[$itemId])->reject(fn ($t) => $t->status === SourceEntry::STATUS_REJECTED);
                // a work tied by the family name alone (30, 35) is often of another man on the page: not enough for a person
                if ($from->isEmpty() || ($from->max('confidence') < self::FLOOR && ! $from->contains('status', SourceEntry::STATUS_VERIFIED))) {
                    continue;
                }
                $decisions[] = ['person_id' => $personId, 'source_id' => $sourceId, 'item_id' => $itemId, 'works' => $from->pluck('citable_id')->unique()->values()->all(),
                    'confidence' => min(90, (int) $from->max('confidence') + 5 * ($from->pluck('citable_id')->unique()->count() - 1)),
                    'verified' => $from->contains('status', SourceEntry::STATUS_VERIFIED), 'others' => count($ofItem) - 1];
            }
        }
        $counts['people'] = count($by);
        $counts['ties_to_make'] = count($decisions);
        $counts['with_other_lives'] = collect($decisions)->where('others', '>', 0)->count();
        foreach ($counts as $key => $n) {
            $this->line(sprintf('  %-18s %6d', $key, $n));
        }
        foreach (collect($decisions)->groupBy(fn ($d) => $sources[$d['source_id']]->code)->sortKeys() as $code => $of) {
            $this->line(sprintf('    %-16s %5d', $code, $of->count()));
        }
        if ($path = $this->option('report')) {
            file_put_contents($path, json_encode($decisions, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
        if ($this->option('dry-run')) {
            $this->warn('Dry run: nothing is saved.');

            return self::SUCCESS;
        }

        $saved = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'kept_reviewed' => 0, 'removed' => 0];
        DB::transaction(function () use ($decisions, $items, $sources, &$saved) {
            $kept = [];
            foreach ($decisions as $d) {
                // the legacy key's index is too small for a source's id: person and source in one number
                $key = ['legacy_type' => self::LEGACY_TYPE, 'legacy_id' => $d['person_id'] * 1000000 + $d['source_id'], 'legacy_index' => 0];
                $kept[$d['person_id'] . ':' . $d['source_id']] = true;
                $row = SourceEntry::where('legacy_type', self::LEGACY_TYPE)->where('citable_type', Person::class)
                    ->where('citable_id', $d['person_id'])->where('source_id', $d['source_id'])->first();
                if ($row && $row->reviewed_at) {
                    $saved['kept_reviewed']++;

                    continue;
                }
                $item = $items[$d['item_id']];
                $values = [
                    'citable_type' => Person::class, 'citable_id' => $d['person_id'], 'source_id' => $d['source_id'],
                    'source_volume_id' => $item->source_volume_id, 'source_item_id' => $item->id, 'volume_number' => $item->volume_number,
                    'page_start' => $item->page_start, 'page_end' => $item->page_end > $item->page_start ? $item->page_end : null,
                    'entry_number' => $item->entry_number, 'role' => SourceEntry::ROLE_ENTRY, 'origin' => SourceEntry::ORIGIN_MATCH,
                    'match_method' => self::METHOD, 'confidence' => $d['confidence'],
                    'status' => $d['verified'] ? SourceEntry::STATUS_VERIFIED : SourceEntry::STATUS_UNVERIFIED,
                    'citation_text' => $sources[$d['source_id']]->title, 'text_status' => SourceEntry::TEXT_NONE,
                ];
                if ($row) {
                    $row->fill($values + $key);
                    $saved[$row->isDirty() ? 'updated' : 'unchanged']++;
                    $row->save();
                } else {
                    SourceEntry::create($values + $key);
                    $saved['created']++;
                }
            }
            // the ties no work gives any more, of the sources read, but those a person has reviewed
            SourceEntry::where('legacy_type', self::LEGACY_TYPE)->whereIn('source_id', $sources->keys())->whereNull('reviewed_at')->get(['id', 'citable_id', 'source_id'])
                ->reject(fn ($r) => isset($kept[$r->citable_id . ':' . $r->source_id]))
                ->each(function ($r) use (&$saved) {
                    $r->delete();
                    $saved['removed']++;
                });
        });
        $this->info(collect($saved)->map(fn ($n, $k) => "{$k} {$n}")->implode(', ') . '.');

        return self::SUCCESS;
    }
}

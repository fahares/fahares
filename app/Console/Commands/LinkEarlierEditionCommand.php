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
 * Ties the works Fankha cites in an earlier book or printing the site does not have to the entry of the source it
 * has (plan docs/plans/old-printing-citations.md, item 116): «نسخه‌های منزوی 490/1» is Monzavi's «فهرست نسخه‌های
 * خطی فارسی», and his «فهرستواره» names that book's pages in its entries («نسخه‌ها 490/1 (1 نسخه)»). An entry that
 * names the cited page and is headed by the work's title is the work; an entry headed by the title alone, anywhere
 * in the source, likely is. Each tie is a source_entries row (origin match, unverified) beside Fankha's citation,
 * which stays as it is; a work already tied to the source or cited in it is left alone. Running it again updates
 * its own ties and never one a person has reviewed.
 */
class LinkEarlierEditionCommand extends Command
{
    public const LEGACY_TYPE = 'earlier_edition_link';

    /** Methods of this command, after those of ItemMatcher it rests on. */
    public const ADDRESS_TITLE = 'edition_address';

    public const TITLE = 'edition_title';

    /** ItemMatcher's methods that name the work by its heading: with the cited page they make a sure tie. */
    private const HEADING_METHODS = ['title_author', 'title_exact', 'full_title', 'title_only', 'other_title'];

    /**
     * Of those, the ones where the entry also names the author. A heading alone, anywhere in the source, is often
     * another work of the title («کلیات», «شمع و پروانه»).
     */
    private const AUTHOR_METHODS = ['title_author', 'full_title', 'other_title'];

    protected $signature = 'sources:link-earlier-edition
        {source : code of the source the site has (sources.code)}
        {edition : key of the earlier book in the source\'s config entry «earlier»}
        {--report= : Write every decision to this JSON file}
        {--dry-run : Match and count without saving}';

    protected $description = 'Tie the works cited in an earlier book or printing to the entries of the source the site has';

    public function handle(ItemMatcher $matcher): int
    {
        $source = Source::where('code', $this->argument('source'))->first();
        $config = config('bibliography.sources.' . $this->argument('source') . '.earlier.' . $this->argument('edition'));
        if (! $source || ! $config) {
            $this->error('No such source, or no «earlier.' . $this->argument('edition') . '» in its config/bibliography.php entry.');

            return self::FAILURE;
        }
        $items = SourceItem::where('source_id', $source->id)->where('kind', '!=', 'preface')
            ->get(['id', 'source_volume_id', 'volume_number', 'entry_number', 'title', 'title_normalized', 'text', 'page_start', 'page_end'])->keyBy('id');
        $index = self::addressIndex($items, $config['cited_as']);
        $byTitle = $items->groupBy('title_normalized');
        $this->line(sprintf('%d entries; %d pages of the earlier book named in them', $items->count(), count($index)));

        // works the source is cited for or tied to already, but by this command
        $linked = SourceEntry::where('citable_type', Work::class)->where('source_id', $source->id)
            ->where(fn ($q) => $q->whereNull('legacy_type')->orWhere('legacy_type', '!=', self::LEGACY_TYPE))->pluck('citable_id')->flip();
        $citations = SourceEntry::where('origin', SourceEntry::ORIGIN_BIBLIOGRAPHY)->where('citable_type', Work::class)
            ->where(fn ($q) => collect($config['like'])->each(fn ($like) => $q->orWhere('citation_text', 'like', $like)))->orderBy('id')->get();

        $decisions = [];
        foreach ($citations as $citation) {
            if (! preg_match('/' . $config['pattern'] . '/u', MatchCatalogEditionCommand::digits((string) $citation->citation_text))) {
                continue;
            }
            $decisions[] = $linked->has($citation->citable_id)
                ? ['citation_id' => $citation->id, 'work_id' => $citation->citable_id, 'match' => null, 'reason' => 'already_in_source']
                : $this->decide($matcher, $citation, $config, $index, $byTitle, $items);
        }

        $counts = [];
        foreach ($decisions as $d) {
            $key = $d['match'] ? $d['match']['method'] : 'none:' . $d['reason'];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        ksort($counts);
        $this->line(count($decisions) . ' citations');
        foreach ($counts as $key => $n) {
            $this->line(sprintf('  %-28s %4d', $key, $n));
        }
        if ($path = $this->option('report')) {
            file_put_contents($path, json_encode($decisions, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
        if ($this->option('dry-run')) {
            $this->warn('Dry run: nothing is saved.');

            return self::SUCCESS;
        }

        $saved = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'kept_reviewed' => 0, 'removed' => 0];
        DB::transaction(function () use ($decisions, $source, $items, &$saved) {
            foreach ($decisions as $d) {
                $key = ['legacy_type' => self::LEGACY_TYPE, 'legacy_id' => $d['citation_id'], 'legacy_index' => 0];
                $row = SourceEntry::where($key)->first();
                if ($row && $row->status !== SourceEntry::STATUS_UNVERIFIED) {
                    $saved['kept_reviewed']++;

                    continue;
                }
                if (! $d['match']) {
                    if ($row) {
                        $row->delete();
                        $saved['removed']++;
                    }

                    continue;
                }
                $item = $items[$d['match']['id']];
                $entry = $d['match']['role'] === SourceEntry::ROLE_ENTRY;
                $values = [
                    'citable_type' => Work::class, 'citable_id' => $d['work_id'], 'source_id' => $source->id,
                    'source_volume_id' => $item->source_volume_id, 'source_item_id' => $item->id, 'volume_number' => $item->volume_number,
                    'page_start' => $item->page_start, 'page_end' => $item->page_end > $item->page_start ? $item->page_end : null,
                    'entry_number' => $item->entry_number, 'role' => $d['match']['role'], 'origin' => SourceEntry::ORIGIN_MATCH,
                    'match_method' => $d['match']['method'], 'confidence' => $d['match']['confidence'],
                    'citation_text' => $d['citation_text'],
                    'entry_text' => $entry ? $item->text : null, 'text_status' => $entry ? SourceEntry::TEXT_AUTO : SourceEntry::TEXT_NONE,
                    'text_volume_number' => $entry ? $item->volume_number : null, 'text_page' => $entry ? $item->page_start : null,
                ];
                if ($row && $row->text_status === SourceEntry::TEXT_EDITED) {
                    unset($values['entry_text'], $values['text_status']);
                }
                if ($row) {
                    $row->fill($values);
                    $saved[$row->isDirty() ? 'updated' : 'unchanged']++;
                    $row->save();
                } else {
                    SourceEntry::create($values + $key + ['status' => SourceEntry::STATUS_UNVERIFIED]);
                    $saved['created']++;
                }
            }
        });
        $this->info(implode(', ', array_map(fn ($k, $n) => "{$k} {$n}", array_keys($saved), $saved)) . '.');

        return self::SUCCESS;
    }

    /**
     * The entries that name each page of the earlier book: «نسخه‌ها 490/1»، «نسخه‌ها (2) 1709/2 و 1711/2». The book
     * numbers its pages on across its volumes, so they are keyed by the page alone.
     *
     * @return array<int, list<int>> page => item ids
     */
    public static function addressIndex(iterable $items, string $citedAs): array
    {
        $index = [];
        foreach ($items as $item) {
            $text = MatchCatalogEditionCommand::digits((string) $item->text);
            preg_match_all('/' . $citedAs . '\s*(?:\(\d+\)\s*)?((?:\d+(?:\s*\/\s*\d+)?(?:\s*(?:و|،|-)\s*(?=\d))?)+)/u', $text, $m);
            foreach ($m[1] as $run) {
                preg_match_all('/(\d+)(?:\s*\/\s*\d+)?/u', $run, $pages);
                foreach ($pages[1] as $page) {
                    $index[(int) $page][] = $item->id;
                }
            }
        }

        return array_map(fn ($ids) => array_values(array_unique($ids)), $index);
    }

    /**
     * The pages a citation names after the earlier book's name: «490/1», «4369», «2809/4 و 1263/2», «1219/2-1220», «2:
     * 1191». The earlier book numbers its pages on across its volumes, so the page alone tells the place.
     *
     * @return list<int>
     */
    public static function citedPages(string $citation, string $pattern): array
    {
        $text = MatchCatalogEditionCommand::digits($citation);
        if (! preg_match('/(?:' . $pattern . ')[\s:،]*(?:ص\s*)?((?:\d+(?:\s*[\/:]\s*\d+)?(?:\s*(?:و|،|-)\s*(?=\d))?)+)/u', $text, $m)) {
            return [];
        }
        $pages = [];
        preg_match_all('/(\d+)(?:\s*([\/:])\s*(\d+))?/u', $m[1], $pairs, PREG_SET_ORDER);
        foreach ($pairs as $p) {
            // «2: 1191» is volume and page; «490/1» page and volume
            $pages[] = ($p[2] ?? '') === ':' && (int) $p[1] <= 6 && (int) $p[3] > 6 ? (int) $p[3] : (int) $p[1];
        }

        return array_values(array_unique($pages));
    }

    private function decide(ItemMatcher $matcher, SourceEntry $citation, array $config, array $index, $byTitle, $items): array
    {
        $work = Work::find($citation->citable_id);
        $person = $work?->author_id ? Person::find($work->author_id) : null;
        $author = $work?->author_name ?: $person?->name;
        $death = $person?->death_year_hijri;
        $titles = LinkSourceItemsCommand::titleForms(array_merge([$work?->primary_title], array_column($work?->alternative_titles ?? [], 'title')),
            (string) $citation->citation_text);
        $pages = self::citedPages((string) $citation->citation_text, $config['pattern']);
        $shape = fn ($ids) => collect($ids)->map(fn ($id) => $items[$id])
            ->map(fn ($i) => ['id' => $i->id, 'entry_number' => $i->entry_number, 'title' => $i->title, 'text' => $i->text])->values()->all();
        $base = ['citation_id' => $citation->id, 'work_id' => $citation->citable_id, 'citation_text' => $citation->citation_text,
            'title' => $work?->primary_title, 'author' => $author, 'pages' => $pages, 'match' => null];
        if (! $work) {
            return $base + ['reason' => 'no_work'];
        }

        // the entries that name the cited page, headed by the work's title
        $named = array_values(array_unique(array_merge([], ...array_map(fn ($p) => $index[$p] ?? [], $pages))));
        $found = $named ? $matcher->match($titles, $author, $death, $shape($named)) : null;
        if ($found && $found['role'] === SourceEntry::ROLE_ENTRY && in_array($found['method'], self::HEADING_METHODS, true)) {
            return array_merge($base, ['match' => ['method' => self::ADDRESS_TITLE, 'confidence' => $found['method'] === 'title_only' ? 85 : 95,
                'by' => $found['method']] + $found, 'item' => $items[$found['id']]->title, 'reason' => null]);
        }
        // the entries headed by the work's title anywhere in the source
        $headed = collect($titles)->map(fn ($t) => MatchCatalogEditionCommand::norm((string) $t))->filter()->unique()
            ->flatMap(fn ($t) => $byTitle->get($t, collect())->pluck('id'))->unique()->values()->all();
        $titled = $headed ? $matcher->match($titles, $author, $death, $shape($headed)) : null;
        if ($titled && $titled['role'] === SourceEntry::ROLE_ENTRY && $titled['method'] !== 'ambiguous') {
            $confidence = in_array($titled['method'], self::AUTHOR_METHODS, true) ? min($titled['confidence'], 70) : 40;

            return array_merge($base, ['match' => ['method' => self::TITLE, 'confidence' => $confidence,
                'by' => $titled['method']] + $titled, 'item' => $items[$titled['id']]->title, 'reason' => null]);
        }
        // an entry naming the page under another heading is no sign: a page of the earlier book holds many works
        $ambiguous = $found ?? $titled;
        if ($ambiguous && $ambiguous['method'] === 'ambiguous') {
            return array_merge($base, ['match' => ['confidence' => 30, 'by' => 'ambiguous'] + $ambiguous,
                'item' => $items[$ambiguous['id']]->title, 'reason' => null]);
        }

        return $base + ['reason' => ! $pages ? 'no_page' : ($named ? 'no_title_among_' . count($named) : 'page_not_named')];
    }
}

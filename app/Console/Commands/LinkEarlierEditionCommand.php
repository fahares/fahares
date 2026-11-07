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
 * has (plan docs/plans/old-printing-citations.md, item 116). How the earlier page is placed in the source is the
 * config entry's «places»:
 * - index: the source names the earlier book's pages in its entries. «نسخه‌های منزوی 490/1» is Monzavi's «فهرست
 *   نسخه‌های خطی فارسی», and his «فهرستواره» writes «نسخه‌ها 490/1 (1 نسخه)»; an entry that names the cited page
 *   and is headed by the work's title is the work.
 * - anchors: both printings keep one order, so pages known in both («روضات الجنات 552» of the lithograph is 6/209)
 *   place any other page between them, and the work is looked for in the pages around it.
 * - parts: a table of where each part of the earlier printing begins in this one (al-A'yan's 56 parts in the
 *   ten volumes); the work is looked for in its part.
 * - centuries: a book of lives in a volume or more a century (Tabaqat A'lam al-Shi'a), whose pages this printing keeps;
 *   the citation names the century, by its part's name («نقباء البشر 245/1») or as «قرن 13», or else the author's
 *   death year tells it. The work is looked for in its author's life on the cited page of the century's volumes, and
 *   then anywhere in them.
 * - title: nothing places the page; the work is looked for by its heading.
 * Then, wherever it was not found, by its heading (a bibliography) or its author's life naming it (a book of lives)
 * anywhere in the source. Each tie is a source_entries row (origin match, unverified) beside Fankha's citation,
 * which stays as it is; a work already tied to the source or cited in it is left alone. Running it again updates
 * its own ties and never one a person has reviewed.
 */
class LinkEarlierEditionCommand extends Command
{
    public const LEGACY_TYPE = 'earlier_edition_link';

    /** Methods of this command, after those of ItemMatcher it rests on. */
    public const ADDRESS_TITLE = 'edition_address';

    public const TITLE = 'edition_title';

    public const PLACE = 'edition_place';

    public const CENTURY = 'edition_century';

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

    /** Whether the source is a book of lives, whose entries are persons (ItemMatcher::matchLife). */
    private bool $lives = false;

    /** @var array<int, int> volume => the pages of the volumes before it */
    private array $offsets = [];

    /** @var array<int, array{0: int, 1: int}> volume => its first and last page */
    private array $ranges = [];

    public function handle(ItemMatcher $matcher): int
    {
        $source = Source::where('code', $this->argument('source'))->first();
        $config = config('bibliography.sources.' . $this->argument('source') . '.earlier.' . $this->argument('edition'));
        if (! $source || ! $config) {
            $this->error('No such source, or no «earlier.' . $this->argument('edition') . '» in its config/bibliography.php entry.');

            return self::FAILURE;
        }
        $this->lives = $source->kind === Source::KIND_BIOGRAPHICAL;
        $items = SourceItem::where('source_id', $source->id)->where('kind', '!=', 'preface')
            ->get(['id', 'source_volume_id', 'volume_number', 'entry_number', 'title', 'title_normalized', 'text', 'page_start', 'page_end'])->keyBy('id');
        $index = isset($config['cited_as']) ? self::addressIndex($items, $config['cited_as']) : [];
        $byTitle = $items->groupBy('title_normalized');
        // the source's pages numbered on across its volumes, for a place between two anchors
        $this->offsets = $this->ranges = [];
        $total = 0;
        foreach ($items->groupBy('volume_number')->sortKeys() as $volume => $of) {
            $this->offsets[$volume] = $total;
            $this->ranges[$volume] = [(int) $of->min('page_start'), (int) $of->max('page_end')];
            $total += $of->max('page_end');
        }
        $this->line(sprintf('%d entries; %d pages of the earlier book named in them', $items->count(), count($index)));

        // works the source is cited for or tied to already, but by this command
        $linked = SourceEntry::where('citable_type', Work::class)->where('source_id', $source->id)
            ->where(fn ($q) => $q->whereNull('legacy_type')->orWhere('legacy_type', '!=', self::LEGACY_TYPE))->pluck('citable_id')->flip();
        $citations = SourceEntry::where('origin', SourceEntry::ORIGIN_BIBLIOGRAPHY)->where('citable_type', Work::class)
            ->where(fn ($q) => collect($config['like'])->each(fn ($like) => $q->orWhere('citation_text', 'like', $like)))
            ->where(fn ($q) => $q->whereNull('source_id')->orWhere('source_id', '!=', $source->id))
            ->where(fn ($q) => $q->whereNull('locations')->orWhere('locations', 'not like', '%"source_id":' . $source->id . ',%'))->orderBy('id')->get();

        $decisions = [];
        foreach ($citations as $citation) {
            $text = MatchCatalogEditionCommand::digits((string) $citation->citation_text);
            if (! preg_match('/' . $config['pattern'] . '/u', $text) || (isset($config['except']) && preg_match('/' . $config['except'] . '/u', $text))) {
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

    /**
     * Where a citation of the earlier printing is in this one, by the config entry's «places» (see the class), or
     * null when the citation is not of the earlier printing: al-A'yan's «228/2» is of the ten volumes.
     *
     * @return array{pages: list<int>, window: ?array{0: int, 1: int}, earlier: string}|null
     */
    public function place(string $citation, array $config, ?int $death = null): ?array
    {
        $text = MatchCatalogEditionCommand::digits($citation);
        $after = '(?:' . $config['pattern'] . ')[^0-9\/]{0,12}?';
        switch ($config['places'] ?? 'index') {
            case 'anchors':
                // a page without its volume; «روضات الجنات 106/7» is of this printing
                if (! preg_match('/' . $after . '(\d++)(?!\s*[\/:]\s*\d)/u', $text, $m)) {
                    return null;
                }
                $at = self::between((int) $m[1], array_map(fn ($a) => [$a[0], $this->global($a[1], $a[2])], $config['anchors']));

                return ['pages' => [(int) $m[1]], 'window' => [(int) round($at) - $config['window'], (int) round($at) + $config['window']],
                    'earlier' => (string) $m[1]];
            case 'parts':
                // «223/45», «30: 111», «285: 25»: the number that is a part of the earlier printing and no volume of this one
                if (! preg_match('/' . $after . '(\d+)\s*([\/:])\s*(\d+)/u', $text, $m)) {
                    return null;
                }
                [$a, $b] = [(int) $m[1], (int) $m[3]];
                $isPart = fn ($n) => $n > max(array_keys($this->offsets) ?: [0]) && isset($config['parts'][$n]);
                $part = $isPart($a) && $isPart($b) ? ($m[2] === '/' ? $b : $a) : ($isPart($b) ? $b : ($isPart($a) ? $a : null));
                if ($part === null) {
                    return null;
                }
                $from = $this->global(...$config['parts'][$part]) - 3;
                $next = $config['parts'][$part + 1] ?? null;

                return ['pages' => [$part === $a ? $b : $a], 'window' => [$from, $next ? $this->global(...$next) + 1 : PHP_INT_MAX],
                    'earlier' => ($part === $a ? $b : $a) . '/' . $part];
            case 'centuries':
                return $this->century($text, $config, $death);
            case 'title':
                return ['pages' => [], 'window' => null, 'earlier' => ''];
            default:
                return ['pages' => self::citedPages($citation, $config['pattern']), 'window' => null, 'earlier' => ''];
        }
    }

    /**
     * The century a citation of a book of lives by centuries names (see «centuries» in the class), and the cited page
     * in each of its volumes that has it: windows of two pages either side, and the century's volumes whole.
     *
     * @return array{pages: list<int>, window: ?array{0: int, 1: int}, windows: list<array{0: int, 1: int}>, century: ?array{0: int, 1: int}, earlier: string}|null
     */
    private function century(string $text, array $config, ?int $death): ?array
    {
        if (! preg_match('/(?:' . $config['pattern'] . ')(.{0,60})/u', $text, $m)) {
            return null;
        }
        $century = null;
        foreach ($config['names'] as $name => $of) {
            if (preg_match('/' . $name . '/u', $text)) {
                $century = $of;
                break;
            }
        }
        $after = $m[1];
        if (preg_match('/(?:قرن|سده|ق)\s*(\d{1,2})(?!\d)/u', $after, $c)) {
            $century ??= (int) $c[1];
            $after = str_replace($c[0], ' ', $after);
        }
        $century ??= $death ? intdiv($death - 1, 100) + 1 : null;
        $volumes = $config['centuries'][$century] ?? [];
        // the numbers after the name, up to the next source's name: each may be the page («245/1» is page and part)
        preg_match_all('/\d+/u', preg_split('/[؛;،,]\s*\D{3,}/u', $after)[0], $numbers);
        $pages = array_values(array_unique(array_map('intval', $numbers[0])));
        $windows = [];
        foreach ($pages as $page) {
            foreach ($volumes as $volume) {
                [$first, $last] = $this->ranges[$volume] ?? [0, -1];
                if ($page >= $first && $page <= $last) {
                    $windows[] = [$this->global($volume, $page) - 2, $this->global($volume, $page) + 2];
                }
            }
        }
        $whole = $volumes && isset($this->ranges[$volumes[0]], $this->ranges[end($volumes)])
            ? [$this->global($volumes[0], $this->ranges[$volumes[0]][0]), $this->global(end($volumes), $this->ranges[end($volumes)][1])] : null;

        return ['pages' => $pages, 'window' => $windows[0] ?? null, 'windows' => $windows, 'century' => $whole,
            'earlier' => ($century ? "قرن {$century}" : '') . ($pages ? ' ' . implode('، ', $pages) : '')];
    }

    /** A page of the source numbered on across its volumes. */
    private function global(int $volume, int $page): int
    {
        return ($this->offsets[$volume] ?? 0) + $page;
    }

    /**
     * Where a page of the earlier printing falls among the anchors [earlier page, page here]: on the line through the
     * two around it, or the two at that end.
     *
     * @param  list<array{0: int, 1: int}>  $anchors
     */
    public static function between(int $page, array $anchors): float
    {
        usort($anchors, fn ($a, $b) => $a[0] <=> $b[0]);
        $i = 1;
        while ($i < count($anchors) - 1 && $anchors[$i][0] < $page) {
            $i++;
        }
        [$a, $b] = [$anchors[$i - 1], $anchors[$i]];

        return $a[1] + ($page - $a[0]) * ($b[1] - $a[1]) / max(1, $b[0] - $a[0]);
    }

    private function decide(ItemMatcher $matcher, SourceEntry $citation, array $config, array $index, $byTitle, $items): array
    {
        $work = Work::find($citation->citable_id);
        $person = $work?->author_id ? Person::find($work->author_id) : null;
        $author = $work?->author_name ?: $person?->name;
        $death = $person?->death_year_hijri;
        $titles = LinkSourceItemsCommand::titleForms(array_merge([$work?->primary_title], array_column($work?->alternative_titles ?? [], 'title')),
            (string) $citation->citation_text);
        $place = $this->place((string) $citation->citation_text, $config, $death);
        $pages = $place['pages'] ?? [];
        $shape = fn ($ids) => collect($ids)->map(fn ($id) => $items[$id])
            ->map(fn ($i) => ['id' => $i->id, 'entry_number' => $i->entry_number, 'title' => $i->title, 'text' => $i->text])->values()->all();
        $base = ['citation_id' => $citation->id, 'work_id' => $citation->citable_id, 'citation_text' => $citation->citation_text,
            'title' => $work?->primary_title, 'author' => $author, 'pages' => $pages, 'window' => $place['window'] ?? null, 'match' => null];
        if (! $work || ! $place) {
            return $base + ['reason' => ! $work ? 'no_work' : 'not_earlier'];
        }
        $tie = function (array $found, string $method, int $confidence) use ($base, $items, $config, $byTitle) {
            $item = $this->lives ? $items[$found['id']] : self::seeAlso($items[$found['id']], $config, $byTitle);
            $by = $found['method'] . ($item->id !== $found['id'] ? '+see' : '');

            return array_merge($base, ['match' => ['method' => $method, 'confidence' => $confidence, 'by' => $by, 'id' => $item->id] + $found,
                'item' => $item->title, 'reason' => null]);
        };
        $find = fn (array $ids) => $ids ? ($this->lives ? $matcher->matchLife($titles, $author, $death, $shape($ids))
            : $matcher->match($titles, $author, $death, $shape($ids))) : null;

        // the entries that name the cited page, headed by the work's title
        $named = array_values(array_unique(array_merge([], ...array_map(fn ($p) => $index[$p] ?? [], $index ? $pages : []))));
        $found = $named ? $matcher->match($titles, $author, $death, $shape($named)) : null;
        if ($found && $found['role'] === SourceEntry::ROLE_ENTRY && in_array($found['method'], self::HEADING_METHODS, true)) {
            return $tie($found, self::ADDRESS_TITLE, $found['method'] === 'title_only' ? 85 : 95);
        }
        // the entries of the pages the earlier one falls in
        $within = fn (array $window) => $items->filter(fn ($i) => $this->global($i->volume_number, $i->page_end) >= $window[0]
            && $this->global($i->volume_number, $i->page_start) <= $window[1])->keys()->all();
        foreach ($place['windows'] ?? array_filter([$place['window']]) as $window) {
            $placed = $find($within($window));
            if ($placed && $placed['method'] !== 'ambiguous' && ($this->lives || $placed['role'] === SourceEntry::ROLE_ENTRY)) {
                // the author's life naming the work, or the heading with its author: likely; a life by more than one
                // part of the name, or by the death year, less so; by one part alone, often another man of the name
                $sure = in_array($placed['method'], array_merge(self::AUTHOR_METHODS, ['author_life_title']), true);

                return $tie($placed, self::PLACE, $sure ? 70 : ($placed['confidence'] >= 75 ? 45 : 30));
            }
        }
        // a book of lives by centuries: anywhere in the century's volumes, only the author's life naming the work; a life
        // by the name alone is there most often another man of the family (Tabaqat, step 14.81)
        if ($this->lives && ($whole = $place['century'] ?? null) && ($placed = $find($within($whole))) && $placed['method'] === 'author_life_title') {
            return $tie($placed, self::CENTURY, 55);
        }
        // the entries headed by the work's title, or the lives naming it with its author, anywhere in the source
        $headed = $this->lives ? $items->keys()->all() : collect($titles)->map(fn ($t) => MatchCatalogEditionCommand::norm((string) $t))->filter()->unique()
            ->flatMap(fn ($t) => $byTitle->get($t, collect())->pluck('id'))->unique()->values()->all();
        $titled = $find($headed);
        if ($titled && $this->lives && $titled['method'] === 'author_life_title') {
            return $tie($titled, self::TITLE, 40);
        }
        if ($titled && ! $this->lives && $titled['role'] === SourceEntry::ROLE_ENTRY && $titled['method'] !== 'ambiguous') {
            return $tie($titled, self::TITLE, in_array($titled['method'], self::AUTHOR_METHODS, true) ? min($titled['confidence'], 70) : 40);
        }
        // an entry naming the page under another heading is no sign: a page of the earlier book holds many works
        $ambiguous = $this->lives ? null : ($found ?? $titled);
        if ($ambiguous && $ambiguous['method'] === 'ambiguous') {
            return $tie($ambiguous, 'ambiguous', 30);
        }

        return $base + ['reason' => ($place['window'] || ! empty($place['century'])) ? 'not_in_window' : (! $index ? 'no_title' : (! $pages ? 'no_page'
            : ($named ? 'no_title_among_' . count($named) : 'page_not_named')))];
    }

    /**
     * The entry a cross-reference sends to: Mushar's «طاقدیس: رجوع شود به «لسان الغیب …»» is no entry of its own.
     * Without the config entry's «see» pattern, or with no one entry of that heading, the entry itself.
     */
    private static function seeAlso(SourceItem $item, array $config, $byTitle): SourceItem
    {
        if (empty($config['see']) || mb_strlen($item->text) > 300
            || ! preg_match('/' . $config['see'] . '/u', MatchCatalogEditionCommand::digits($item->text), $m)) {
            return $item;
        }
        $to = $byTitle->get(MatchCatalogEditionCommand::norm($m[1]), collect());

        return $to->count() === 1 ? $to->first() : $item;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Person;
use App\Models\SourceEntry;
use App\Models\SourceItem;
use App\Models\SourceVolume;
use App\Models\Work;
use App\Sources\Bibliography\ItemMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Ties the works Fankha cites in a bibliography («الذریعة 402/3») to the printed entry of that page that is the work
 * (plan docs/plans/dharia-text.md, link (a)). Each tie is its own source_entries row (origin match, role entry or
 * mention, unverified) next to Fankha's citation, which stays as it is; an entry tie carries the entry's text.
 * Running it again updates the ties it made and never one a person has reviewed.
 */
class LinkSourceItemsCommand extends Command
{
    public const LEGACY_TYPE = 'source_item_link';

    protected $signature = 'sources:link-items
        {volume : facsimile_code of the volume (source_volumes row)}
        {--volume= : Volume number, when the code has several}
        {--report= : Write every decision to this JSON file}
        {--dry-run : Match and count without saving}';

    protected $description = 'Tie the works a bibliography is cited for to the printed entries on the cited pages';

    public function handle(ItemMatcher $matcher): int
    {
        $volumes = SourceVolume::where('facsimile_code', $this->argument('volume'))
            ->when($this->option('volume'), fn ($q, $v) => $q->where('volume_number', $v))->get();
        if ($volumes->count() !== 1) {
            $this->error("Expected one volume {$this->argument('volume')}, found {$volumes->count()} (use --volume)");

            return self::FAILURE;
        }
        $volume = $volumes[0];
        $items = SourceItem::where('source_volume_id', $volume->id)->orderBy('position')
            ->get(['id', 'entry_number', 'title', 'text', 'page_start', 'page_end']);
        if ($items->isEmpty()) {
            $this->error('No entries for this volume: run sources:segment first.');

            return self::FAILURE;
        }

        $decisions = [];
        $citations = SourceEntry::where('source_id', $volume->source_id)->where('origin', SourceEntry::ORIGIN_BIBLIOGRAPHY)
            ->where('citable_type', Work::class)->orderBy('id')->get();
        foreach ($citations as $citation) {
            $places = $citation->locations ?: [['source_id' => $citation->source_id, 'volume' => $citation->volume_number, 'page' => $citation->page_start]];
            foreach (array_values($places) as $index => $place) {
                if ((int) ($place['source_id'] ?? 0) !== $volume->source_id || (int) ($place['volume'] ?? 0) !== $volume->volume_number) {
                    continue;
                }
                $decisions[] = $this->decide($matcher, $citation, $index, (int) $place['page'], $items);
            }
        }

        $counts = [];
        foreach ($decisions as $d) {
            $key = $d['match'] ? $d['match']['role'] . ':' . $d['match']['method'] : 'none:' . $d['reason'];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        ksort($counts);
        $this->line(sprintf('v%d: %d citations', $volume->volume_number, count($decisions)));
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
        DB::transaction(function () use ($decisions, $volume, $items, &$saved) {
            $texts = $items->keyBy('id');
            foreach ($decisions as $d) {
                $key = ['legacy_type' => self::LEGACY_TYPE, 'legacy_id' => $d['citation_id'], 'legacy_index' => $d['index']];
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
                $item = $texts[$d['match']['id']];
                $entry = $d['match']['role'] === SourceEntry::ROLE_ENTRY;
                $values = [
                    'citable_type' => Work::class, 'citable_id' => $d['work_id'], 'source_id' => $volume->source_id,
                    'source_volume_id' => $volume->id, 'source_item_id' => $item->id, 'volume_number' => $volume->volume_number,
                    'page_start' => $item->page_start, 'page_end' => $item->page_end > $item->page_start ? $item->page_end : null,
                    'entry_number' => $item->entry_number, 'role' => $d['match']['role'], 'origin' => SourceEntry::ORIGIN_MATCH,
                    'match_method' => $d['match']['method'], 'confidence' => $d['match']['confidence'],
                    'citation_text' => $d['citation_text'],
                    'entry_text' => $entry ? $item->text : null, 'text_status' => $entry ? SourceEntry::TEXT_AUTO : SourceEntry::TEXT_NONE,
                    'text_volume_number' => $entry ? $volume->volume_number : null, 'text_page' => $entry ? $item->page_start : null,
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

    /** One cited place: the work, what it was matched to and why. */
    private function decide(ItemMatcher $matcher, SourceEntry $citation, int $index, int $page, $items): array
    {
        $work = Work::find($citation->citable_id);
        $person = $work?->author_id ? Person::find($work->author_id) : null;
        $titles = array_merge([$work?->primary_title], array_column($work?->alternative_titles ?? [], 'title'));
        $onPage = $items->filter(fn ($i) => $i->page_start <= $page && $i->page_end >= $page)
            ->map(fn ($i) => ['id' => $i->id, 'entry_number' => $i->entry_number, 'title' => $i->title, 'text' => $i->text])->values()->all();
        // «الذریعة 424/1 ش 2175»: the entry number Fankha gives, when its citation has one place
        $number = preg_match('/(?:ش|رقم|شماره)\s*\.?\s*(\d+)/u', MatchCatalogEditionCommand::digits((string) $citation->citation_text), $m)
            && count($citation->locations ?? []) <= 1 ? (int) $m[1] : null;
        $match = $work && $onPage ? $matcher->match($titles, $work->author_name ?: $person?->name, $person?->death_year_hijri, $onPage, $number) : null;

        return [
            'citation_id' => $citation->id, 'index' => $index, 'work_id' => $citation->citable_id, 'page' => $page,
            'citation_text' => $citation->citation_text, 'title' => $work?->primary_title, 'author' => $work?->author_name ?: $person?->name,
            'death' => $person?->death_year_hijri, 'match' => $match,
            'item' => $match ? collect($onPage)->firstWhere('id', $match['id'])['title'] ?? null : null,
            'reason' => ! $work ? 'no_work' : (! $onPage ? 'no_entries_on_page' : 'no_match'),
            'page_titles' => array_column($onPage, 'title'),
        ];
    }
}

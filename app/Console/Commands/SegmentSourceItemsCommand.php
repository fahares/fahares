<?php

namespace App\Console\Commands;

use App\Models\SourceItem;
use App\Models\SourceVolume;
use App\Sources\Bibliography\NumberedEntrySegmenter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Splits the page texts of a source volume (source_pages) into its printed entries, source_items (plan
 * docs/plans/dharia-text.md), or takes them from a file of entries where the source's own text marks them (--entries:
 * the headings of Noor's Monzavi, scripts/sources/noor_monzavi.py). Running it again updates the entries in place by their key, so links to them stay;
 * an entry whose text a person edited keeps it, and one no longer found is removed only when nothing links to it.
 */
class SegmentSourceItemsCommand extends Command
{
    protected $signature = 'sources:segment
        {volume : facsimile_code of the volume (source_volumes row)}
        {--volume= : Volume number, when the code has several}
        {--origin=ablibrary : Where the page texts came from, kept on each entry}
        {--entries= : A .jsonl or .jsonl.gz file of the entries of the volume, instead of splitting its page texts}
        {--dry-run : Count the entries and list the anomalies without saving}';

    protected $description = 'Split a source volume\'s page texts into its printed entries';

    public function handle(NumberedEntrySegmenter $segmenter): int
    {
        $volumes = SourceVolume::where('facsimile_code', $this->argument('volume'))
            ->when($this->option('volume'), fn ($q, $v) => $q->where('volume_number', $v))->get();
        if ($volumes->count() !== 1) {
            $this->error("Expected one volume {$this->argument('volume')}, found {$volumes->count()} (use --volume)");

            return self::FAILURE;
        }
        $volume = $volumes[0];
        $pages = DB::table('source_pages')->where('source_volume_id', $volume->id)->orderBy('page')->pluck('text', 'page')->all();
        if (! $pages) {
            $this->error('No page texts for this volume: run sources:import-pages first.');

            return self::FAILURE;
        }

        $items = $this->option('entries') ? self::readEntries($this->option('entries')) : $segmenter->segment($pages);
        if (! $items) {
            $this->error('No entries found in ' . $this->option('entries'));

            return self::FAILURE;
        }
        $kinds = array_count_values(array_column($items, 'kind'));
        $anomalies = array_filter($items, fn ($i) => isset($i['flags']['sequence']));
        $this->line(sprintf('v%d: %d pages, %d entries (%s), %d with footnotes, %d numbering anomalies', $volume->volume_number,
            count($pages), count($items), implode(', ', array_map(fn ($k, $n) => "{$k} {$n}", array_keys($kinds), $kinds)),
            count(array_filter($items, fn ($i) => $i['footnotes'] !== null)), count($anomalies)));
        foreach ($anomalies as $a) {
            $this->line(sprintf('  p%d: %d after %d (%s) «%s»', $a['page_start'], $a['entry_number'], $a['flags']['previous_number'],
                $a['flags']['sequence'], $a['title']));
        }
        if ($this->option('dry-run')) {
            $this->warn('Dry run: nothing is saved.');

            return self::SUCCESS;
        }

        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'kept_edited' => 0, 'removed' => 0, 'kept_linked' => 0];
        DB::transaction(function () use ($items, $volume, &$counts) {
            $existing = SourceItem::where('source_volume_id', $volume->id)->get()->keyBy('item_key');
            $ids = [];
            foreach ($items as $position => $item) {
                $values = [
                    'source_id' => $volume->source_id,
                    'volume_number' => $volume->volume_number,
                    'position' => $position + 1,
                    'entry_number' => $item['entry_number'],
                    'kind' => $item['kind'],
                    'page_start' => $item['page_start'],
                    'page_end' => $item['page_end'],
                    'title' => mb_substr($item['title'], 0, 500),
                    'title_normalized' => mb_substr($item['title_normalized'], 0, 500),
                    'flags' => $item['flags'] ?: null,
                    'metadata' => $item['page_breaks'] ? ['page_breaks' => $item['page_breaks']] : null,
                ];
                $text = ['text' => $item['text'], 'footnotes' => $item['footnotes'], 'text_origin' => $this->option('origin')];
                $row = $existing->get($item['key']);
                if ($row === null) {
                    $row = SourceItem::create($values + $text + ['source_volume_id' => $volume->id, 'item_key' => $item['key']]);
                    $counts['created']++;
                } else {
                    if ($row->text_status === SourceItem::TEXT_EDITED) {
                        $counts['kept_edited']++;
                    } else {
                        $values += $text;
                    }
                    $row->fill($values);
                    $counts[$row->isDirty() ? 'updated' : 'unchanged']++;
                    $row->save();
                }
                $ids[$item['key']] = $row->id;
            }
            // parents, once every entry has its id
            foreach ($items as $item) {
                SourceItem::whereKey($ids[$item['key']])->update(['parent_id' => $item['parent_key'] !== null ? ($ids[$item['parent_key']] ?? null) : null]);
            }
            foreach ($existing as $key => $row) {
                if (isset($ids[$key])) {
                    continue;
                }
                if ($row->links()->exists()) {
                    $row->update(['flags' => array_merge($row->flags ?? [], ['not_found' => true])]);
                    $counts['kept_linked']++;
                } else {
                    $row->delete();
                    $counts['removed']++;
                }
            }
        });
        $this->info(implode(', ', array_map(fn ($k, $n) => "{$k} {$n}", array_keys($counts), $counts)) . '.');

        return self::SUCCESS;
    }

    /**
     * The entries of a file, one JSON object a line: key, kind, title, text, page_start, page_end, and optionally
     * page_breaks (offsets into the text where a page begins) and parent_key, in the form the segmenter gives.
     */
    public static function readEntries(string $path): array
    {
        // whole, not gzfile(), which cuts lines at 8 KB
        $content = file_get_contents($path);
        $lines = explode("\n", str_ends_with($path, '.gz') ? gzdecode($content) : $content);
        $items = [];
        foreach ($lines ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $e = json_decode($line, true);
            $items[] = [
                'key' => $e['key'],
                'kind' => $e['kind'] ?? SourceItem::KIND_ENTRY,
                'entry_number' => $e['entry_number'] ?? null,
                'title' => $e['title'],
                'title_normalized' => MatchCatalogEditionCommand::norm($e['title']),
                'text' => $e['text'],
                'footnotes' => $e['footnotes'] ?? null,
                'page_start' => (int) $e['page_start'],
                'page_end' => (int) $e['page_end'],
                'page_breaks' => $e['page_breaks'] ?? [],
                'parent_key' => $e['parent_key'] ?? null,
                'flags' => [],
            ];
        }

        return $items;
    }
}

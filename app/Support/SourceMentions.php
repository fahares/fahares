<?php

namespace App\Support;

use App\Console\Commands\LinkDhariaTitlesCommand;
use App\Models\SourceItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where the site's sources name a record (step 13.136): the marks of their page texts linked to it (source_page_links,
 * steps 13.129 and 13.133), the reverse of the link on the page: for a person's page where he is named (step 13.136),
 * for an entry's page the entries that refer to it (13.140), for a copy's page where it is cited (13.155). Each
 * with the page, the entry whose text holds the mark and the words after or before it, and the words around the mark.
 */
class SourceMentions
{
    /**
     * The mentions of a person or an entry, by source, in the order of the volumes and pages.
     *
     * @return Collection<string, Collection<int, array{place: string, url: string, entry: ?SourceItem, before: string, name: string, after: string}>>
     */
    public static function of(Model $record, int $limit = 200): Collection
    {
        $rows = DB::table('source_page_links as l')
            ->join('source_pages as p', 'p.id', '=', 'l.source_page_id')
            ->join('source_volumes as v', 'v.id', '=', 'p.source_volume_id')
            ->join('sources as s', 's.id', '=', 'v.source_id')
            ->where('l.target_type', $record::class)->where('l.target_id', $record->getKey())->whereNull('l.rejected_at')
            ->orderBy('s.rank')->orderBy('v.volume_number')->orderBy('p.page')->orderBy('l.ordinal')->limit($limit)
            ->get(['l.ordinal', ...(self::hasSpans() ? ['l.offset', 'l.span'] : []), 'p.page', 'p.text', 'v.id as volume_id', 'v.volume_number', 's.id as source_id', 's.code', 's.title', 's.short_name', 's.unit']);
        if ($rows->isEmpty()) {
            return collect();
        }

        // the entries the pages are part of, their texts without the marks
        $entries = SourceItem::with('source')->whereIn('source_volume_id', $rows->pluck('volume_id')->unique())
            ->whereIn('kind', [SourceItem::KIND_ENTRY, SourceItem::KIND_SUB_ENTRY, SourceItem::KIND_ADDENDUM])
            ->where(function ($q) use ($rows) {
                foreach ($rows as $row) {
                    $q->orWhere(fn ($q) => $q->where('source_volume_id', $row->volume_id)->where('page_start', '<=', $row->page)->where('page_end', '>=', $row->page));
                }
            })->get(['id', 'source_id', 'source_volume_id', 'volume_number', 'item_key', 'title', 'page_start', 'page_end', 'text']);
        $bare = fn (string $s) => preg_replace('/[\s\p{M}]+/u', '', $s);

        return $rows->map(function ($row) use ($entries, $bare) {
            // a mark, or words of no mark (a reference to the source itself, step 13.155) where they start
            $mark = $row->ordinal !== null ? collect(LinkDhariaTitlesCommand::marksOf($row->text))->firstWhere('ordinal', $row->ordinal) : [
                'text' => $row->span,
                'before' => mb_substr(PageMarks::strip(mb_substr($row->text, max(0, $row->offset - 200), min(200, $row->offset))), -120),
                'after' => mb_substr(PageMarks::strip(mb_substr($row->text, $row->offset + mb_strlen((string) $row->span), 200)), 0, 80),
            ];
            $on = $entries->filter(fn ($e) => $e->source_volume_id === $row->volume_id && $e->page_start <= $row->page && $e->page_end >= $row->page);
            // of the page's entries, the one that holds the mark with the words after it («… الطوسي المتوفى سنة 672»), or
            // before it, for a short entry that ends with the mark («( ديوان حسيني سادات ) مر بعنوان أمير حسيني في (ص 100).»)
            $holding = $on;
            foreach ([($mark['text'] ?? '') . mb_substr($mark['after'] ?? '', 0, 30), mb_substr($mark['before'] ?? '', -20) . ($mark['text'] ?? '')] as $needle) {
                if ($holding->count() !== 1) {
                    $holding = $on->filter(fn ($e) => str_contains($bare((string) $e->text), $bare($needle)));
                }
            }

            return [
                'source' => $row->short_name ?: $row->title,
                'place' => Fa::digits('ج ' . $row->volume_number . '، ' . ($row->unit === 'ستون' ? 'ستون ' : 'ص ') . $row->page),
                'url' => route('source-pages.show', [$row->code, $row->volume_number, $row->page]) . ($row->ordinal !== null ? '#m' . $row->ordinal : '#r' . $row->offset),
                'entry' => $holding->count() === 1 ? $holding->first() : null,
                'before' => self::words($mark['before'] ?? '', -60),
                'name' => $mark['text'] ?? '',
                'after' => self::words($mark['after'] ?? '', 60),
            ];
        })->groupBy('source');
    }

    /**
     * Whether source_page_links has the words of no mark (step 13.155): the code runs before its migration on a deploy,
     * so it asks once a process.
     */
    public static function hasSpans(): bool
    {
        static $has = null;

        return $has ??= Schema::hasColumn('source_page_links', 'span');
    }

    /** About $length letters of the text from its end (negative) or start, cut at a word and on one line. */
    private static function words(string $text, int $length): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= abs($length)) {
            return $text;
        }

        return $length < 0
            ? preg_replace('/^\S*\s/u', '', mb_substr($text, $length))
            : preg_replace('/\s\S*$/u', '', mb_substr($text, 0, $length));
    }
}

<?php

namespace App\Support;

use App\Console\Commands\LinkDhariaTitlesCommand;
use App\Models\Person;
use App\Models\SourceItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Where the site's sources name a record (step 13.136): the marks of their page texts linked to it (source_page_links,
 * steps 13.129 and 13.133), the reverse of the link on the page. For a person's page: the page, the entry whose text
 * holds the name and the words after it, and the words around his name.
 */
class SourceMentions
{
    /**
     * The mentions of a person, by source, in the order of the volumes and pages.
     *
     * @return Collection<string, Collection<int, array{place: string, url: string, entry: ?SourceItem, before: string, name: string, after: string}>>
     */
    public static function forPerson(Person $person, int $limit = 200): Collection
    {
        $rows = DB::table('source_page_links as l')
            ->join('source_pages as p', 'p.id', '=', 'l.source_page_id')
            ->join('source_volumes as v', 'v.id', '=', 'p.source_volume_id')
            ->join('sources as s', 's.id', '=', 'v.source_id')
            ->where('l.target_type', Person::class)->where('l.target_id', $person->id)->whereNull('l.rejected_at')
            ->orderBy('s.rank')->orderBy('v.volume_number')->orderBy('p.page')->orderBy('l.ordinal')->limit($limit)
            ->get(['l.ordinal', 'p.page', 'p.text', 'v.id as volume_id', 'v.volume_number', 's.id as source_id', 's.code', 's.title', 's.short_name', 's.unit']);
        if ($rows->isEmpty()) {
            return collect();
        }

        // the entries the pages are part of, their texts without the marks
        $entries = SourceItem::with('source')->whereIn('source_volume_id', $rows->pluck('volume_id')->unique())
            ->where('kind', SourceItem::KIND_ENTRY)
            ->where(function ($q) use ($rows) {
                foreach ($rows as $row) {
                    $q->orWhere(fn ($q) => $q->where('source_volume_id', $row->volume_id)->where('page_start', '<=', $row->page)->where('page_end', '>=', $row->page));
                }
            })->get(['id', 'source_id', 'source_volume_id', 'volume_number', 'item_key', 'title', 'page_start', 'page_end', 'text']);
        $bare = fn (string $s) => preg_replace('/[\s\p{M}]+/u', '', $s);

        return $rows->map(function ($row) use ($entries, $bare) {
            $mark = collect(LinkDhariaTitlesCommand::marksOf($row->text))->firstWhere('ordinal', $row->ordinal);
            $on = $entries->filter(fn ($e) => $e->source_volume_id === $row->volume_id && $e->page_start <= $row->page && $e->page_end >= $row->page);
            // of the page's entries, the one that holds the name with the words after it («… الطوسي المتوفى سنة 672»)
            $needle = $bare(($mark['text'] ?? '') . mb_substr($mark['after'] ?? '', 0, 30));
            $holding = $on->count() > 1 ? $on->filter(fn ($e) => str_contains($bare((string) $e->text), $needle)) : $on;

            return [
                'source' => $row->short_name ?: $row->title,
                'place' => Fa::digits('ج ' . $row->volume_number . '، ' . ($row->unit === 'ستون' ? 'ستون ' : 'ص ') . $row->page),
                'url' => route('source-pages.show', [$row->code, $row->volume_number, $row->page]) . '#m' . $row->ordinal,
                'entry' => $holding->count() === 1 ? $holding->first() : null,
                'before' => self::words($mark['before'] ?? '', -60),
                'name' => $mark['text'] ?? '',
                'after' => self::words($mark['after'] ?? '', 60),
            ];
        })->groupBy('source');
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

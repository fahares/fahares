<?php

namespace App\Sources;

use App\Jobs\SyncWorkBibliographies;
use App\Models\Source;
use App\Support\BibliographyLinks;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * A change to how a source is cited (step 14.57, docs/plans/source-name-patterns.md), and what it does to the
 * citations: the strings read before and after the change (with $dry, inside a transaction taken back), and for the
 * works' bibliographies their entries made again by SourceEntrySync::syncBibliography, a large change in the queue.
 * Only the strings a form's old or new pattern finds can read otherwise, so only they are read.
 */
class CitationFormEditor
{
    public const SAMPLE = 12;

    /** More works than this are made again in the queue. */
    public const QUEUE_ABOVE = 1500;

    /**
     * @param  Closure(): void  $change  writes the form
     * @param  list<string>  $patterns  the form's pattern before and after, which find the strings to read
     * @return array{context: string, read: int, gained: int, taken: array<string, int>, lost: int, moved: int, other: array<string, int>, unread: int, samples: array, works: list<int>, queued?: bool}
     */
    public function run(Source $cited, Closure $change, array $patterns, string $context, ?string $citing, bool $dry): array
    {
        $strings = $this->strings($patterns, $context, $citing);
        $read = fn () => array_map(fn ($text) => $this->cites($text, $context, $citing), $strings);

        $before = $read();
        DB::beginTransaction();
        try {
            $change();
            CitationForms::forget();
            $after = $read();
            $effect = $this->compare($cited->code, $strings, $before, $after, $patterns[count($patterns) - 1] ?? null) + ['context' => $context];
            if ($dry) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        } finally {
            CitationForms::forget();
        }

        return $effect;
    }

    /** Makes the entries of the works whose strings read otherwise again; a large set in the queue. */
    public function sync(array $works): bool
    {
        if (count($works) > self::QUEUE_ABOVE) {
            foreach (array_chunk($works, 1000) as $chunk) {
                SyncWorkBibliographies::dispatch($chunk)->afterCommit();
            }

            return true;
        }
        SyncWorkBibliographies::run($works);

        return false;
    }

    /** The columns of the works' bibliography entries a sync writes, for the undo. */
    public static function snapshot(array $works): array
    {
        return collect(array_chunk($works, 1000))->flatMap(fn ($ids) => DB::table('source_entries')->where('legacy_type', 'work_bibliography')
            ->whereIn('legacy_id', $ids)->get(['id', 'source_id', 'source_volume_id', 'volume_number', 'page_start', 'locations'])
            ->map(fn ($r) => (array) $r))->all();
    }

    /**
     * The strings either pattern finds: the works' bibliography strings, which are Fankha's; or the text of the citing
     * source's pages, which a page-text form needs.
     *
     * @return array<int|string, string> entry id (or page id) => text
     */
    private function strings(array $patterns, string $context, ?string $citing): array
    {
        $regex = collect($patterns)->filter()->map(fn ($p) => '(?:' . $p . ')')->implode('|');
        if ($regex === '') {
            return [];
        }
        $regex = '/(?<![\p{L}\p{M}])(?:' . $regex . ')/u';
        $out = [];
        $query = $context === 'page_text'
            ? DB::table('source_pages')->whereIn('source_volume_id', DB::table('source_volumes')->whereIn('source_id',
                DB::table('sources')->where('code', $citing ?? '')->select('id'))->select('id'))->select('id', 'text as body')
            : DB::table('source_entries')->where('legacy_type', 'work_bibliography')->select('id', 'citation_text as body');
        // by id, not by offset: an offset over source_entries reads every row before it again
        foreach ($query->lazyById(5000) as $r) {
            if (@preg_match($regex, CitationForms::normalize((string) $r->body))) {
                $out[$r->id] = (string) $r->body;
            }
        }

        return $out;
    }

    /** @return list<array{source: string, volume: int, page: int}> */
    private function cites(string $text, string $context, ?string $citing): array
    {
        $segments = $context === 'page_text' ? BibliographyLinks::inProse($text, $citing) : BibliographyLinks::segments($text);

        return array_values(array_filter(array_column($segments, 'cite')));
    }

    private function compare(string $code, array $strings, array $before, array $after, ?string $pattern): array
    {
        $effect = ['read' => count($strings), 'gained' => 0, 'taken' => [], 'lost' => 0, 'moved' => 0, 'other' => [], 'unread' => 0, 'samples' => [], 'works' => []];
        $sample = function (string $kind, $id, ?array $cite = null, ?string $other = null) use (&$effect, $strings) {
            if (count($effect['samples'][$kind] ?? []) < self::SAMPLE) {
                $effect['samples'][$kind][] = ['text' => mb_substr($strings[$id], 0, 300), 'cite' => $cite, 'other' => $other];
            }
        };
        $finds = $pattern ? '/(?<![\p{L}\p{M}])(?:' . $pattern . ')/u' : null;
        $changed = [];
        foreach ($strings as $id => $text) {
            $was = collect($before[$id])->where('source', $code)->values();
            $now = collect($after[$id])->where('source', $code)->values();
            if ($before[$id] != $after[$id]) {
                $changed[] = $id;
            }
            if ($was->isEmpty() && $now->isNotEmpty()) {
                $effect['gained']++;
                // the source the string was read as before
                $from = collect($before[$id])->pluck('source')->diff(collect($after[$id])->pluck('source'))->first();
                if ($from) {
                    $effect['taken'][$from] = ($effect['taken'][$from] ?? 0) + 1;
                }
                $sample($from ? 'taken' : 'gained', $id, $now->first(), $from);
            } elseif ($was->isNotEmpty() && $now->isEmpty()) {
                $effect['lost']++;
                $sample('lost', $id, $was->first());
            } elseif ($was != $now) {
                $effect['moved']++;
                $sample('moved', $id, $now->first());
            } elseif ($now->isEmpty() && $finds && @preg_match($finds, CitationForms::normalize($text))) {
                // the name is there: another source has the string, or its numbers are not read
                if ($other = collect($after[$id])->pluck('source')->first()) {
                    $effect['other'][$other] = ($effect['other'][$other] ?? 0) + 1;
                    $sample('other', $id, null, $other);
                } else {
                    $effect['unread']++;
                    $sample('unread', $id);
                }
            }
        }
        if ($changed) {
            $effect['works'] = DB::table('source_entries')->whereIn('id', $changed)->where('legacy_type', 'work_bibliography')
                ->distinct()->pluck('legacy_id')->map(fn ($id) => (int) $id)->all();
        }

        return $effect;
    }
}

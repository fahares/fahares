<?php

namespace App\Sources;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The sources a citation is read against (step 14.57, docs/plans/source-name-patterns.md), as BibliographyLinks wants
 * them: [code => [pattern, label, volumes, unit, series_volumes, url, viewer, text, …]]. Their names and reading rules
 * come from source_citation_forms; what shows a citation (url, viewer, text) stays in config/bibliography.php under the
 * source's code. While the table has no form, config/bibliography.php is read as it is (a deploy brings the code up
 * before it migrates; tests that set config keep it).
 *
 * A form of no citing source is any source's; one of the citing source overrides it for the source it cites. The
 * patterns of config/bibliography.php are imported as forms of no citing source, since they are read in the page
 * texts of other sources too (BibliographyLinks::inProse).
 */
class CitationForms
{
    /** The reading rules a form may hold; the rest of a config entry is how a citation is shown. */
    public const READING = ['volumes', 'series_volumes', 'pairs_after_name', 'pairs_by_fit', 'other_edition', 'unit'];

    private const CACHE_KEY = 'citation-forms:v1';

    /** @var array<string, array<string, array>> citing code => sources, for this request */
    private static array $memo = [];

    private static ?bool $ready = null;

    /**
     * The sources as one citing source reads them; Fankha's by default, whose strings are the works' bibliographies.
     *
     * @return array<string, array>
     */
    public static function sources(?string $citing = 'fankha', string $context = 'bibliography'): array
    {
        $key = ($citing ?? '') . '|' . $context;
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }
        $config = config('bibliography.sources', []);
        $rows = self::rows();
        if (! $rows) {
            return self::$memo[$key] = $config;
        }
        $citingId = $citing === null ? null : (collect($rows)->firstWhere('citing_code', $citing)['citing_source_id']
            ?? DB::table('sources')->where('code', $citing)->value('id'));
        // the most particular form of each cited source wins: any source's in the bibliographies, any source's in this
        // context, the citing source's in the bibliographies, the citing source's in this context
        $rank = fn ($f) => ($f['citing_source_id'] === null ? 0 : 2) + ($f['context'] === $context && $context !== 'bibliography' ? 1 : 0);
        $forms = collect($rows)
            ->filter(fn ($f) => ($f['context'] === 'bibliography' || $f['context'] === $context)
                && ($f['citing_source_id'] === null || ($citingId && $f['citing_source_id'] === (int) $citingId)))
            ->sortBy(fn ($f) => [$rank($f), $f['id']])
            ->keyBy('cited_code');
        $order = array_flip(array_keys($config));
        $forms = $forms->sortBy(fn ($f, $code) => [$order[$code] ?? PHP_INT_MAX, $f['id']]);

        $sources = [];
        foreach ($forms as $code => $f) {
            $base = $config[$code] ?? [];
            $entry = array_diff_key($base, array_flip(['pattern'])) + ['label' => $f['label']];
            foreach (self::READING as $rule) {
                if (array_key_exists($rule, $f['options'])) {
                    $entry[$rule] = $f['options'][$rule];
                }
            }
            $entry['volumes'] ??= max(1, $f['volumes_count']);
            $entry['pattern'] = self::pattern($f['regex'], $f['names']);
            // a source the site shows nothing of: its citations are read, not linked
            if (! isset($entry['url']) && ! isset($entry['viewer']) && ! isset($entry['text'])) {
                $entry['plain'] = $f['bounds'];
            }
            $sources[$code] = $entry;
        }
        // a source of config/bibliography.php not in the registry has no form to read it with: it is read from config
        // as it is, in its place (sources:import-citation-forms leaves it there)
        $registered = DB::table('sources')->whereIn('code', array_keys($config))->pluck('code')->all();
        foreach (array_diff(array_keys($config), $registered) as $code) {
            $sources[$code] = $config[$code];
        }
        uksort($sources, fn ($a, $b) => [$order[$a] ?? PHP_INT_MAX, $a] <=> [$order[$b] ?? PHP_INT_MAX, $b]);

        return self::$memo[$key] = $sources;
    }

    /** The regex of a form: its own, or one of its names, longest first, evened out as the citations are. */
    public static function pattern(?string $regex, array $names): string
    {
        if (filled($regex)) {
            return $regex;
        }
        $names = collect($names)->map(fn ($n) => self::normalize((string) $n))->filter()->unique()
            ->sortByDesc(fn ($n) => mb_strlen($n))->map(fn ($n) => str_replace(' ', '\s?', preg_quote($n, '/')));

        return $names->isEmpty() ? '(?!)' : '(?:' . $names->implode('|') . ')';
    }

    /** The spelling citations are matched in (BibliographyLinks::normalize). */
    public static function normalize(string $s): string
    {
        $s = strtr($s, ['ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', "\u{200C}" => ' ', 'ـ' => '']);
        $s = preg_replace('/[\x{064B}-\x{0652}]/u', '', $s);

        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    /**
     * The sources of config/bibliography.php in the registry that have no form yet: while there is one, a form saved in
     * the panel would leave it unread, so the panel waits for sources:import-citation-forms.
     *
     * @return list<string>
     */
    public static function awaitingImport(): array
    {
        if (! self::ready()) {
            return array_keys(config('bibliography.sources', []));
        }
        $codes = DB::table('sources')->whereIn('code', array_keys(config('bibliography.sources', [])))->pluck('id', 'code');
        $formed = DB::table('source_citation_forms')->whereIn('cited_source_id', $codes->values())->whereNull('citing_source_id')
            ->where('context', 'bibliography')->pluck('cited_source_id')->all();

        return $codes->reject(fn ($id) => in_array($id, $formed))->keys()->all();
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        self::$memo = [];
        \App\Support\BibliographyLinks::forget();
    }

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('source_citation_forms');
    }

    /** Every form with what it is read with, from the cache. */
    private static function rows(): array
    {
        if (! self::ready()) {
            return [];
        }

        return Cache::rememberForever(self::CACHE_KEY, function () {
            $forms = DB::table('source_citation_forms as f')->join('sources as s', 's.id', '=', 'f.cited_source_id')
                ->leftJoin('sources as c', 'c.id', '=', 'f.citing_source_id')
                ->orderBy('f.id')
                ->get(['f.*', 's.code as cited_code', 's.title', 's.short_name', 's.volumes_count', 'c.code as citing_code']);
            $bounds = DB::table('source_volumes')->whereIn('source_id', $forms->pluck('cited_source_id')->unique())
                ->whereNotNull('volume_number')->get(['source_id', 'volume_number', 'page_first', 'page_last', 'pages_count'])
                ->groupBy('source_id');

            return $forms->map(fn ($f) => [
                'id' => (int) $f->id,
                'cited_source_id' => (int) $f->cited_source_id,
                'citing_source_id' => $f->citing_source_id === null ? null : (int) $f->citing_source_id,
                'cited_code' => $f->cited_code,
                'citing_code' => $f->citing_code,
                'context' => $f->context,
                'names' => json_decode($f->names ?? '[]', true) ?: [],
                'regex' => $f->regex,
                'options' => json_decode($f->options ?? '[]', true) ?: [],
                'label' => $f->short_name ?: $f->title,
                'volumes_count' => (int) $f->volumes_count,
                // the pages each volume holds, where the registry knows them
                'bounds' => self::bounds($bounds[$f->cited_source_id] ?? collect()),
            ])->all();
        });
    }

    private static function bounds($volumes): array
    {
        $bounds = ['first_pages' => [], 'last_pages' => []];
        foreach ($volumes as $v) {
            $first = $v->page_first ? (int) $v->page_first : 1;
            $last = $v->page_last ? (int) $v->page_last : ($v->pages_count ? $first + (int) $v->pages_count - 1 : null);
            if ($first !== 1) {
                $bounds['first_pages'][(int) $v->volume_number] = $first;
            }
            if ($last) {
                $bounds['last_pages'][(int) $v->volume_number] = $last;
            }
        }

        return $bounds;
    }
}

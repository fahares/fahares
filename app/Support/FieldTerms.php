<?php

namespace App\Support;

use App\Helpers\TextNormalizer;
use App\Http\Controllers\SearchController;
use App\Models\Manuscript;
use App\Models\Work;
use Illuminate\Support\Facades\Log;
use Meilisearch\Client as MeiliClient;
use Meilisearch\Contracts\SearchQuery;

/**
 * Words searched each in its own field, all together (backlog item 83, step 14.100): «کافی» in the titles, «محمد»
 * as the scribe and «اصفهان» as the place of copying. The engine searches one set of words at a time, so each field
 * is searched on its own and the records found by all of them narrow the search, as a filter on their ids.
 *
 * The fields are searched from the one with the fewest matches up, each within the ids of the ones before it; the ids
 * of a field are taken up to CAP, so only a first field (the narrowest) with more matches than CAP is cut.
 */
class FieldTerms
{
    public const CAP = 10000;

    /** @var array<string, array{ids: int[], broad: string[], failed: bool}> the resolved ids, by type and terms */
    private static array $resolved = [];

    /** The terms of the request's `f[field]`, normalized; only the fields of the tab (SearchController::FIELD_SCOPES) */
    public static function clean(mixed $value, string $type): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $terms = [];
        foreach (SearchController::FIELD_SCOPES[$type] ?? [] as $field => $_) {
            $term = $value[$field] ?? null;
            if (is_string($term) && ($term = TextNormalizer::normalize(mb_substr(trim($term), 0, 100))) !== '') {
                $terms[$field] = $term;
            }
        }

        return $terms ?: null;
    }

    /**
     * The ids of the records every field's words match; the fields that had more matches than CAP; and whether the
     * engine failed (no ids then)
     *
     * @param array<string, string> $terms
     * @return array{ids: int[], broad: string[], failed: bool}
     */
    public static function resolve(string $type, array $terms): array
    {
        $key = $type . '|' . json_encode($terms, JSON_UNESCAPED_UNICODE);

        return self::$resolved[$key] ??= config('scout.driver') === 'meilisearch'
            ? self::fromEngine($type, $terms)
            : self::fromRecords($type, $terms);
    }

    /** Forget the resolved ids (between tests, and in the queue, where one process runs many exports) */
    public static function flush(): void
    {
        self::$resolved = [];
    }

    private static function fromEngine(string $type, array $terms): array
    {
        $index = SearchController::INDEXES[$type];
        $attrs = fn (string $field) => SearchController::FIELD_SCOPES[$type][$field][1];
        try {
            $meili = app(MeiliClient::class);
            // How many each field matches alone, in one round trip, to start from the narrowest
            $counts = $meili->multiSearch(array_map(fn ($field) => (new SearchQuery)->setIndexUid($index)->setQuery($terms[$field])
                ->setAttributesToSearchOn($attrs($field))->setMatchingStrategy('all')->setLimit(0), array_keys($terms)))['results'] ?? [];
            $order = array_combine(array_keys($terms), array_map(fn ($r) => (int) ($r['estimatedTotalHits'] ?? 0), $counts));
            asort($order);

            $ids = null;
            $broad = [];
            foreach ($order as $field => $total) {
                if ($ids === null && $total > self::CAP) {
                    $broad[] = $field;
                }
                $options = ['attributesToSearchOn' => $attrs($field), 'matchingStrategy' => 'all', 'limit' => self::CAP, 'attributesToRetrieve' => ['id']];
                if ($ids !== null) {
                    $options['filter'] = 'id IN [' . implode(', ', $ids) . ']';
                }
                $ids = array_map(fn ($hit) => (int) $hit['id'], $meili->index($index)->search($terms[$field], $options)->getHits());
                if (! $ids) {
                    break;
                }
            }

            return ['ids' => $ids ?? [], 'broad' => $broad, 'failed' => false];
        } catch (\Throwable $e) {
            Log::warning("Meilisearch {$type} field terms failed: " . $e->getMessage());

            return ['ids' => [], 'broad' => [], 'failed' => true];
        }
    }

    /**
     * Without the engine (Scout's collection driver, as in the tests): the records whose indexed fields contain every
     * word of their term
     */
    private static function fromRecords(string $type, array $terms): array
    {
        $model = ['works' => Work::class, 'manuscripts' => Manuscript::class][$type];
        $ids = [];
        $model::query()->chunkById(500, function ($records) use ($type, $terms, &$ids) {
            foreach ($records as $record) {
                $doc = $record->toSearchableArray();
                foreach ($terms as $field => $term) {
                    $text = implode(' ', array_map(fn ($a) => is_array($doc[$a] ?? null) ? implode(' ', \Illuminate\Support\Arr::flatten($doc[$a])) : (string) ($doc[$a] ?? ''),
                        SearchController::FIELD_SCOPES[$type][$field][1]));
                    foreach (preg_split('/\s+/u', $term) as $word) {
                        if (! str_contains($text, $word)) {
                            continue 3;
                        }
                    }
                }
                $ids[] = (int) $record->id;
            }
        });

        return ['ids' => array_slice($ids, 0, self::CAP), 'broad' => count($ids) > self::CAP ? [array_key_first($terms)] : [], 'failed' => false];
    }
}

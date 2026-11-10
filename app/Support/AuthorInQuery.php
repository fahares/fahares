<?php

namespace App\Support;

use App\Helpers\TextNormalizer;
use App\Models\Person;
use Illuminate\Support\Facades\Log;
use Meilisearch\Client as MeiliClient;
use Meilisearch\Contracts\FederationOptions;
use Meilisearch\Contracts\SearchQuery;

/**
 * A works search that names a title and its author, «قانون ابن سینا» (step 14.128). The engine ranks by the
 * distance of the words, and the title and the author's name are two values of the work's names, far apart: a
 * rare work with both in one title («کلیات قانون ابن سینا») came first and Avicenna's Canon fifth. When the first or
 * last words of the query are exactly the name or an alias of a known author, the rest is searched once more among
 * that author's works, and the two searches are ranked together with the second one weighted up.
 */
class AuthorInQuery
{
    /** The longest name looked for at either end of the query, in words */
    private const MAX_NAME_WORDS = 3;

    /** Only an author of this many works is taken for one: «ناصری» is a surname, not the author of «اخلاق ناصری» */
    public const MIN_WORKS = 5;

    /** How much a match among the named author's works weighs against one of the whole query */
    public const WEIGHT = 1.2;

    /**
     * The title words and the ids of the authors the query names, or null. A phrase, an alternative or a word left
     * out keeps the plain search.
     *
     * @return array{title: string, author_ids: int[]}|null
     */
    public static function split(string $searchQuery): ?array
    {
        if (preg_match('/["|]|(^|\s)-/u', $searchQuery)) {
            return null;
        }
        $words = explode(' ', $searchQuery);
        $count = count($words);
        if ($count < 2) {
            return null;
        }

        // The name at the end first, «قانون ابن سینا», then at the start, «ابن سینا قانون»; the longest name first
        $candidates = [];
        for ($k = min(self::MAX_NAME_WORDS, $count - 1); $k >= 1; $k--) {
            $candidates[] = [implode(' ', array_slice($words, $count - $k)), implode(' ', array_slice($words, 0, $count - $k))];
            $candidates[] = [implode(' ', array_slice($words, 0, $k)), implode(' ', array_slice($words, $k))];
        }

        try {
            $queries = array_map(fn ($c) => (new SearchQuery)->setIndexUid('people_index')->setQuery($c[0])->setMatchingStrategy('all')
                ->setFilter(['is_author = true', 'works_count >= ' . self::MIN_WORKS])->setLimit(20)->setAttributesToRetrieve(['id']), $candidates);
            $results = app(MeiliClient::class)->multiSearch($queries)['results'] ?? [];
        } catch (\Throwable $e) {
            Log::warning('Author in query failed: ' . $e->getMessage());

            return null;
        }

        $ids = array_merge(...array_map(fn ($r) => array_column($r['hits'] ?? [], 'id'), $results ?: [[]]));
        $people = $ids ? Person::whereKey($ids)->get(['id', 'name', 'aliases', 'wrong_names'])->keyBy('id') : collect();

        foreach ($candidates as $i => [$name, $title]) {
            $authors = [];
            foreach (array_column($results[$i]['hits'] ?? [], 'id') as $id) {
                if (($person = $people->get($id)) && in_array($name, self::names($person), true)) {
                    $authors[] = (int) $id;
                }
            }
            if ($authors) {
                return ['title' => $title, 'author_ids' => $authors];
            }
        }

        return null;
    }

    /** The whole query, and its title words among the named authors' works, weighted up */
    public static function queries(string $index, string $searchQuery, array $split, ?array $searchAttrs, ?string $filter, ?callable $tune = null): array
    {
        $byAuthor = 'author_id IN [' . implode(',', $split['author_ids']) . ']';
        $make = function (string $words, array $filters, float $weight) use ($index, $searchAttrs, $tune) {
            $query = (new SearchQuery)->setIndexUid($index)->setQuery($words)->setMatchingStrategy('all')
                ->setFederationOptions((new FederationOptions)->setWeight($weight));
            $searchAttrs && $query->setAttributesToSearchOn($searchAttrs);
            $filters && $query->setFilter($filters);

            return $tune ? $tune($query) : $query;
        };

        return [
            $make($searchQuery, array_values(array_filter([$filter])), 1.0),
            $make($split['title'], array_values(array_filter([$filter, $byAuthor])), self::WEIGHT),
        ];
    }

    /** The forms of a person's name a query may use: the whole name, the part before the comma, the aliases */
    private static function names(Person $person): array
    {
        $names = [$person->name, explode('،', $person->name)[0], ...$person->searchAliases()];

        return array_values(array_unique(array_map(fn ($n) => TextNormalizer::normalize((string) $n), $names)));
    }
}

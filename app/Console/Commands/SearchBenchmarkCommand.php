<?php

namespace App\Console\Commands;

use App\Helpers\TextNormalizer;
use App\Http\Controllers\SearchController;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Work;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Meilisearch\Client as MeiliClient;

/**
 * Runs the benchmark queries of config/search_benchmark.php against the live indexes, the way the search
 * page sends them, and reports the rank each expected hit was found at (backlog item 83, phase 0).
 */
class SearchBenchmarkCommand extends Command
{
    protected $signature = 'fahares:search-benchmark
                            {--failed : Show only the cases that failed}';

    protected $description = 'Check that well-known searches find what they should, near the top';

    /** Benchmark field => the record's value, per tab; compared on the record, since the index holds search variants */
    private const FIELDS = [
        'works' => ['title' => 'primary_title', 'author' => 'author_name'],
        'manuscripts' => ['title' => 'work.primary_title', 'author' => 'work.author_name', 'library' => 'library', 'shelfmark' => 'shelfmark', 'scribe' => 'scribe_name'],
        'people' => ['name' => 'name'],
        'sources' => ['title' => 'title', 'text' => 'text'],
    ];

    private const MODELS = ['works' => Work::class, 'manuscripts' => Manuscript::class, 'people' => Person::class, 'sources' => \App\Models\SourceItem::class];

    private const INDEXES = SearchController::INDEXES;

    /** How deep a hit is looked for, to tell «a bit too low» from «not found» */
    private const DEPTH = 20;

    public function handle(MeiliClient $meili): int
    {
        $rows = [];
        $passed = 0;
        $cases = config('search_benchmark');

        foreach ($cases as $case) {
            $type = $case['type'];
            $options = ['matchingStrategy' => 'all', 'limit' => self::DEPTH];
            if ($attrs = SearchController::scopeAttributes($type, $case['scope'] ?? 'titles_names')) {
                $options['attributesToSearchOn'] = $attrs;
            }
            $options['attributesToRetrieve'] = ['id'];
            $ids = array_column($meili->index(self::INDEXES[$type])->search(TextNormalizer::normalize($case['q']), $options)->getHits(), 'id');
            $records = self::MODELS[$type]::whereKey($ids)->get()->keyBy('id');

            $rank = null;
            foreach ($ids as $i => $id) {
                if (($record = $records->get($id)) && $this->matches($record, $case)) {
                    $rank = $i + 1;
                    break;
                }
            }
            $ok = $rank !== null && $rank <= $case['top'];
            $passed += $ok ? 1 : 0;

            if (! $ok || ! $this->option('failed')) {
                $expected = collect($case['expect'])->map(fn ($v, $k) => "{$k}: {$v}")->implode('، ')
                    . (isset($case['min_manuscripts']) ? "، ≥{$case['min_manuscripts']} نسخه" : '');
                $rows[] = [$ok ? 'OK' : 'FAIL', $case['q'], $type, $expected, "≤{$case['top']}", $rank ?? '—'];
            }
        }

        $this->table(['', 'Query', 'Tab', 'Expected', 'Want', 'Rank'], $rows);
        $this->line(sprintf('Passed %d of %d.', $passed, count($cases)));

        return $passed === count($cases) ? self::SUCCESS : self::FAILURE;
    }

    private function matches(Model $record, array $case): bool
    {
        foreach ($case['expect'] as $field => $want) {
            $have = TextNormalizer::normalize((string) data_get($record, self::FIELDS[$case['type']][$field]));
            $prefix = str_starts_with($want, '^');
            $want = TextNormalizer::normalize(ltrim($want, '^'));
            if ($prefix ? ! str_starts_with($have, $want) : ! str_contains($have, $want)) {
                return false;
            }
        }

        return ($record->manuscripts_count ?? PHP_INT_MAX) >= ($case['min_manuscripts'] ?? 0);
    }
}

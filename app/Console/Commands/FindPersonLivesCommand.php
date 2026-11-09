<?php

namespace App\Console\Commands;

use App\Models\Person;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Sources\Bibliography\ItemMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Finds the lives in the books of lives of people no work ties to one (backlog item 86, step 14.116): a life whose
 * heading carries a distinctive part of the person's name and whose heading or opening carries his death year
 * (±1). Only people with an exact death year (death_from = death_to) are read: a century is no evidence. Of a
 * person's candidates in one source, a lone one with two name parts is «strong»; the rest are left for judgement.
 * It writes a report only; the judged ties are saved by sources:tie-person-lives.
 */
class FindPersonLivesCommand extends Command
{
    private const ZONE = 300;

    protected $signature = 'sources:find-person-lives
        {--source=* : codes of the books of lives (default: all of them)}
        {--report= : JSON file of the candidates}';

    protected $description = 'Find candidate lives for people by name and death year';

    public function handle(): int
    {
        $sources = Source::where('kind', Source::KIND_BIOGRAPHICAL)
            ->when($this->option('source'), fn ($q, $codes) => $q->whereIn('code', $codes))->get()->keyBy('id');
        // people already tied to a life of a source, by works or by hand
        $tied = SourceEntry::where('citable_type', Person::class)->whereIn('source_id', $sources->keys())
            ->get(['citable_id', 'source_id'])->map(fn ($r) => $r->citable_id . ':' . $r->source_id)->flip();

        $people = Person::whereNotNull('death_year_hijri')->whereColumn('death_from', 'death_to')
            ->get(['id', 'slug', 'name', 'aliases', 'death_year_hijri', 'works_count', 'manuscripts_count']);
        $this->info($people->count() . ' people with an exact death year');

        $candidates = [];
        foreach ($sources as $source) {
            // distinctive name part of a heading => its lives
            $index = [];
            $lives = [];
            DB::table('source_items')->where('source_id', $source->id)->where('kind', 'entry')
                ->select(['id', 'item_key', 'volume_number', 'page_start', 'title', 'text'])->orderBy('id')
                ->chunk(2000, function ($rows) use (&$index, &$lives) {
                    foreach ($rows as $r) {
                        $lives[$r->id] = ['key' => $r->item_key, 'vol' => $r->volume_number, 'page' => $r->page_start,
                            'title' => (string) $r->title, 'zone' => mb_substr((string) $r->text, 0, self::ZONE)];
                        foreach (self::parts($r->title) as $part) {
                            $index[$part][] = $r->id;
                        }
                    }
                });
            $found = 0;
            foreach ($people as $p) {
                if (isset($tied[$p->id . ':' . $source->id])) {
                    continue;
                }
                $names = array_values(array_unique(array_filter(array_merge([$p->name], (array) ($p->aliases ?? [])), 'is_string')));
                $ids = [];
                foreach ($names as $name) {
                    foreach (self::parts($name) as $part) {
                        foreach ($index[$part] ?? [] as $id) {
                            $ids[$id] = true;
                        }
                    }
                }
                $hits = [];
                foreach (array_keys($ids) as $id) {
                    $life = $lives[$id];
                    $n = max(array_map(fn ($name) => ItemMatcher::authorHits($name, $life['title']), $names));
                    if ($n >= 1 && ItemMatcher::deathIn($p->death_year_hijri, $life['title'] . ' ' . $life['zone'])) {
                        $hits[] = ['item_id' => $id, 'item_key' => $life['key'], 'volume' => $life['vol'], 'page' => $life['page'],
                            'hits' => $n, 'title' => $life['title'], 'zone' => $life['zone']];
                    }
                }
                if (! $hits) {
                    continue;
                }
                usort($hits, fn ($a, $b) => $b['hits'] <=> $a['hits']);
                $strong = count($hits) === 1 && $hits[0]['hits'] >= 2;
                $candidates[] = ['person_id' => $p->id, 'person_slug' => $p->slug, 'name' => $p->name, 'aliases' => $p->aliases,
                    'death' => $p->death_year_hijri, 'works' => $p->works_count, 'manuscripts' => $p->manuscripts_count,
                    'source' => $source->code, 'strong' => $strong, 'lives' => array_slice($hits, 0, 6)];
                $found++;
            }
            $this->line(sprintf('  %-16s %6d lives, %5d people with candidates', $source->code, count($lives), $found));
            unset($index, $lives);
            gc_collect_cycles();
        }
        $strong = collect($candidates)->where('strong', true)->count();
        $this->info(count($candidates) . " person-source pairs; {$strong} strong");
        if ($path = $this->option('report')) {
            file_put_contents($path, json_encode($candidates, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }

        return self::SUCCESS;
    }

    /** A name's parts that tell it from others, as ItemMatcher::authorHits counts them. */
    private static function parts(?string $name): array
    {
        $parts = preg_split('/\s+/u', ItemMatcher::norm(str_replace(['،', '،', ',', '(', ')', '[', ']', '«', '»', ':'], ' ', (string) $name)));

        return array_values(array_unique(array_filter($parts, fn ($p) => mb_strlen($p) > 2
            && ! in_array($p, ['بن', 'ابن', 'محمد', 'علی', 'حسن', 'حسین', 'احمد', 'ابو', 'ابی', 'سید', 'شیخ', 'میرزا', 'ملا', 'مولی',
                'الدین', 'دین', 'عبد', 'عبدالله', 'امیر', 'حاج', 'آقا', 'اقا', 'خان', 'بیگ', 'قاضی', 'السید', 'الشیخ', 'المیرزا'], true))));
    }
}

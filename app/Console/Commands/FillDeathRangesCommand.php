<?php

namespace App\Console\Commands;

use App\Helpers\TextNormalizer;
use App\Models\Person;
use App\Support\FankhaWorkLocator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Gives every person a death range, certainty and hedge word (death_from, death_to, death_certainty, death_hedge_raw;
 * backlog item 96). The life dates come from a decision file of the corpus script fankha_v2_deaths_export.py: a work's
 * author and translator with the dates the catalogue writes after their names («1040 - 1124 ؟ قمری»), found on the
 * site by title and page (FankhaWorkLocator). The site's author is works.author_id, its translator the one translator
 * of work_people, and the name must be the same. A person's dates are the text most of their works give, among those
 * whose range holds the person's death_year_hijri; texts that disagree with it are left out. life_dates_raw is filled
 * only where it is empty. Then every person's columns are made from life_dates_raw and death_year_hijri
 * (Person::deathColumns), as saving a person does from then on; a run only rewrites what differs and can be repeated.
 */
class FillDeathRangesCommand extends Command
{
    protected $signature = 'fahares:fill-death-ranges
        {--file= : Decision file (database/data/fankha_v2_deaths_20261031.json)}
        {--apply : Write the columns; without it the command only counts}
        {--show=12 : How many examples to list per kind}';

    protected $description = 'Fill people\'s life dates and death_from/to, certainty and hedge word';

    private array $counts = [];

    private array $examples = [];

    public function handle(): int
    {
        $path = $this->option('file') ?: database_path('data/fankha_v2_deaths_20261031.json');
        $plan = File::exists($path) ? json_decode(File::get($path), true) : null;
        if (! $plan || ! isset($plan['works'])) {
            $this->error("Decision file missing or invalid: {$path}");

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $this->counts = [];
        $this->examples = [];

        $texts = $this->texts($plan, new FankhaWorkLocator(array_merge($plan['works'], $plan['peers'] ?? [])));

        DB::table('people')->select(['id', 'name', 'death_year_hijri', 'life_dates_raw', 'death_from', 'death_to', 'death_certainty', 'death_hedge_raw'])
            ->orderBy('id')->chunkById(5000, function ($people) use ($texts, $apply) {
                $updates = [];
                foreach ($people as $p) {
                    $year = $p->death_year_hijri === null ? null : (int) $p->death_year_hijri;
                    $raw = $p->life_dates_raw;
                    if (($raw === null || $raw === '') && isset($texts[$p->id])) {
                        $raw = $this->choose($p, $year, $texts[$p->id]);
                    }
                    $cols = ['life_dates_raw' => $raw === '' ? null : $raw] + Person::deathColumns($raw, $year);
                    $this->tally($cols, $year);
                    $current = ['life_dates_raw' => $p->life_dates_raw, 'death_from' => $p->death_from === null ? null : (int) $p->death_from,
                        'death_to' => $p->death_to === null ? null : (int) $p->death_to, 'death_certainty' => $p->death_certainty, 'death_hedge_raw' => $p->death_hedge_raw];
                    if ($current !== $cols) {
                        $this->count('people:changed');
                        $updates[$p->id] = $cols;
                    }
                }
                if ($apply && $updates) {
                    DB::transaction(function () use ($updates) {
                        foreach ($updates as $id => $cols) {
                            DB::table('people')->where('id', $id)->update($cols);
                        }
                    });
                }
            });

        $this->info($apply ? 'Written.' : 'Dry run: nothing written.');
        ksort($this->counts);
        foreach ($this->counts as $kind => $n) {
            $this->line(sprintf('  %-28s %8d', $kind, $n));
        }
        foreach ($this->examples as $kind => $lines) {
            $this->line("-- {$kind}");
            foreach ($lines as $line) {
                $this->line("   {$line}");
            }
        }

        return self::SUCCESS;
    }

    /** @return array<int, array<int, string>> person id => the life dates their works give, in the file's order */
    private function texts(array $plan, FankhaWorkLocator $locator): array
    {
        $ids = [];
        foreach ($plan['works'] as $w) {
            $ids[] = $locator->find((int) $w['key']['vol'], (int) $w['key']['ordinal'], (string) $w['title'], (int) ($w['provenance']['page_start'] ?? 0));
        }
        $found = array_values(array_filter($ids));
        $authors = [];
        $translators = [];
        foreach (array_chunk($found, 5000) as $chunk) {
            $authors += DB::table('works')->whereIn('id', $chunk)->whereNotNull('author_id')->pluck('author_id', 'id')->all();
            foreach (DB::table('work_people')->whereIn('work_id', $chunk)->where('role', 'translator')->whereNotNull('person_id')->get(['work_id', 'person_id']) as $row) {
                $translators[$row->work_id][] = (int) $row->person_id;
            }
        }
        $people = [];
        foreach (array_chunk(array_unique(array_merge(array_values($authors), array_merge([], ...array_values($translators)))), 5000) as $chunk) {
            $people += DB::table('people')->whereIn('id', $chunk)->pluck('name', 'id')->all();
        }

        $texts = [];
        foreach ($plan['works'] as $i => $w) {
            $label = "v{$w['key']['vol']}-{$w['key']['ordinal']} «{$w['title']}»";
            if (! $ids[$i]) {
                $this->count('works:not_found', $label);
                continue;
            }
            $this->count('works:found');
            $site = ['author' => isset($authors[$ids[$i]]) ? [(int) $authors[$ids[$i]]] : [], 'translator' => $translators[$ids[$i]] ?? []];
            foreach (['author', 'translator'] as $role) {
                if (empty($w[$role]['death_raw'])) {
                    continue;
                }
                if (count($site[$role]) !== 1) {
                    $this->count("{$role}:not_on_site", $label);
                    continue;
                }
                $personId = $site[$role][0];
                if (! self::sameName($people[$personId] ?? '', (string) $w[$role]['name'])) {
                    $this->count("{$role}:other_name", "{$label}: «{$w[$role]['name']}» ≠ #{$personId} «" . ($people[$personId] ?? '') . '»');
                    continue;
                }
                $this->count("{$role}:texts");
                $texts[$personId][] = mb_substr(trim($w[$role]['death_raw']), 0, 120);
            }
        }

        return $texts;
    }

    /**
     * «عبد الله» and «عبدالله», «کرکی ، حسین» and «کرکی، حسین», «(کرمانی؟)» and «(کرمانی)» are one name, and so is a
     * name and the site's longer form of it («علی بن ابی طالب (ع)، امام اول»).
     */
    public static function sameName(string $site, string $v2): bool
    {
        $key = fn (string $n) => preg_replace('/[\s\x{200c}،,؟?.\-]+/u', '', TextNormalizer::normalize($n));
        [$a, $b] = [$key($site), $key($v2)];

        return $b !== '' && str_starts_with($a, $b);
    }

    /** The life dates most of a person's works give among those that hold their death year; null when none does. */
    private function choose(object $person, ?int $year, array $raws): ?string
    {
        $groups = [];
        foreach ($raws as $raw) {
            $cols = Person::deathColumns($raw, null);
            $holds = $cols['death_from'] !== null || $cols['death_to'] !== null;
            if ($holds && $year !== null) {
                $holds = ($cols['death_from'] ?? PHP_INT_MIN) <= $year && $year <= ($cols['death_to'] ?? PHP_INT_MAX);
            }
            if (! $holds) {
                $this->count('texts:left_out', "#{$person->id} «{$person->name}» ({$year}): {$raw}");
                continue;
            }
            $key = json_encode($cols);
            $groups[$key] ??= ['raw' => $raw, 'n' => 0];
            $groups[$key]['n']++;
        }
        if (! $groups) {
            return null;
        }
        if (count($groups) > 1) {
            $this->count('people:texts_disagree', "#{$person->id} «{$person->name}»: " . implode(' | ', array_map(fn ($g) => "{$g['raw']} ×{$g['n']}", $groups)));
        }
        // array_values keeps the first-seen order, so a tie goes to the earlier work
        $best = array_reduce(array_values($groups), fn ($carry, $g) => $carry === null || $g['n'] > $carry['n'] ? $g : $carry);

        return $best['raw'];
    }

    private function tally(array $cols, ?int $year): void
    {
        [$from, $to] = [$cols['death_from'], $cols['death_to']];
        if ($cols['life_dates_raw'] !== null) {
            $this->count('people:life_dates');
        }
        if ($from === null && $to === null) {
            return;
        }
        $this->count('people:ranged');
        $this->count('range:' . ($from === null || $to === null ? 'open' : ($from === $to ? 'one_year' : 'span')));
        if ($year === null) {
            $this->count('range:without_death_year');
        }
        if ($cols['death_hedge_raw'] !== null) {
            $this->count('range:hedged');
        }
    }

    private function count(string $kind, ?string $example = null): void
    {
        $this->counts[$kind] = ($this->counts[$kind] ?? 0) + 1;
        if ($example !== null && count($this->examples[$kind] ?? []) < (int) $this->option('show')) {
            $this->examples[$kind][] = $example;
        }
    }
}

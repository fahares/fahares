<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Work;
use App\Models\WorkPerson;
use App\Support\PersonNameMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the catalogue's doubt about a work's author (step 14.38). Fankha writes «؟ آخوند خراسانی، محمد کاظم …» when the
 * attribution is uncertain; v1 dropped the mark. A linked author whose author line in the work's own text opens with a
 * hedge (PersonNameMatcher::HEDGES) gets that certainty on its work_people row, which the work page shows as «(؟)»; a
 * row a reviewer made or already gave a certainty is left. With --file, works with no author get the hedged name from
 * the decision file as a name-only author row with its certainty, and a description that v1 filled with that author line
 * loses it. Every write is logged as corpus_update.
 */
class MarkHedgedAuthorsCommand extends Command
{
    protected $signature = 'fahares:mark-hedged-authors
        {--file= : Also give these works with no author their hedged author name (database/data/fankha_hedged_authors_*.json)}
        {--apply : Write; without it the command only counts}
        {--show=8 : How many examples to list}';

    protected $description = "Keep the catalogue's «؟» on a work's author as the author row's certainty";

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $counts = ['marked' => 0, 'left_reviewed' => 0, 'added' => 0, 'add_skipped' => 0];
        $examples = [];

        DB::table('works as w')->join('work_people as p', fn ($j) => $j->on('p.work_id', '=', 'w.id')->on('p.person_id', '=', 'w.author_id'))
            ->where('p.role', WorkPerson::AUTHOR)->whereNull('p.certainty')
            ->whereRaw("w.raw_text regexp '\\n[[:space:]]*(؟|محتملا|ظاهرا|گویا|شاید|احتمالا)'")
            ->select(['w.id', 'w.raw_text', 'p.id as row_id', 'p.suggestion_id', 'w.author_name'])->orderBy('w.id')
            ->chunk(1000, function ($rows) use ($apply, &$counts, &$examples) {
                foreach ($rows as $r) {
                    $hedge = self::hedgeOf($r->raw_text);
                    if ($hedge === null) {
                        continue;
                    }
                    if ($r->suggestion_id) {
                        $counts['left_reviewed']++;
                        continue;
                    }
                    $counts['marked']++;
                    if (count($examples) < (int) $this->option('show')) {
                        $examples[] = "#{$r->id} {$hedge} {$r->author_name}";
                    }
                    if ($apply) {
                        $this->write($r->id, $r->row_id, $hedge);
                    }
                }
            });

        if ($path = $this->option('file')) {
            foreach (json_decode((string) file_get_contents($path), true)['items'] ?? [] as $c) {
                $work = Work::where('slug', $c['work_slug'])->first();
                if (! $work || $work->author_id || filled($work->author_name) || $work->authorEntries()->exists()) {
                    $counts['add_skipped']++;
                    continue;
                }
                $counts['added']++;
                if ($apply) {
                    DB::transaction(function () use ($work, $c) {
                        // the description v1 filled with the author line and its transliteration loses them, if it is unchanged
                        // a description that was the author line and nothing more is emptied («description_to»: null, step 14.65)
                        $description = ($c['description_from'] ?? null) === $work->description && array_key_exists('description_to', $c) ? $c['description_to'] : $work->description;
                        DB::table('works')->where('id', $work->id)->update(['author_name' => $c['name'], 'description' => $description, 'updated_at' => now()]);
                        DB::table('work_people')->insert(['work_id' => $work->id, 'person_id' => null, 'name' => $c['name'], 'role' => WorkPerson::AUTHOR,
                            'position' => 0, 'certainty' => PersonNameMatcher::certainty($c['hedge']), 'hedge_raw' => $c['hedge'], 'created_at' => now(), 'updated_at' => now()]);
                        AuditLog::create(['user_id' => null, 'auditable_type' => Work::class, 'auditable_id' => $work->id, 'action' => 'corpus_update',
                            'old_values' => ['author_name' => null, 'description' => $work->description],
                            'new_values' => ['author_name' => $c['name'], 'description' => $description, 'author_certainty' => PersonNameMatcher::certainty($c['hedge']), 'hedge_raw' => $c['hedge'], 'corpus_fix' => basename($this->option('file'))]]);
                    });
                    $work->searchable();
                }
            }
        }

        $this->info($apply ? 'Written.' : 'Dry run: nothing written.');
        foreach ($counts as $k => $n) {
            $this->line(sprintf('  %-16s %6d', $k, $n));
        }
        foreach ($examples as $e) {
            $this->line("   {$e}");
        }

        return self::SUCCESS;
    }

    /** The hedge word that opens the author line (the third line) of a work's Fankha text, or null (step 14.65: a «؟» touching the name too). */
    public static function hedgeOf(?string $raw): ?string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", preg_replace('/<!--\s*page:\s*\d+\s*-->/u', ' ', (string) $raw)))));
        $line = $lines[2] ?? '';
        foreach (array_keys(PersonNameMatcher::HEDGES) as $hedge) {
            // «؟علی بن موسی»: the question mark may touch the name; a word needs its space («شایدی» is no hedge)
            if (preg_match('/^' . preg_quote($hedge, '/') . ($hedge === '؟' ? '' : '(?=\s|$)') . '/u', $line)) {
                return $hedge;
            }
        }

        return null;
    }

    private function write(int $workId, int $rowId, string $hedge): void
    {
        DB::transaction(function () use ($workId, $rowId, $hedge) {
            DB::table('work_people')->where('id', $rowId)->update(['certainty' => PersonNameMatcher::certainty($hedge), 'hedge_raw' => $hedge, 'updated_at' => now()]);
            AuditLog::create(['user_id' => null, 'auditable_type' => Work::class, 'auditable_id' => $workId, 'action' => 'corpus_update',
                'old_values' => ['author_certainty' => null],
                'new_values' => ['author_certainty' => PersonNameMatcher::certainty($hedge), 'hedge_raw' => $hedge, 'corpus_fix' => 'mark-hedged-authors (step 14.38)']]);
        });
    }
}

<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Manuscript;
use App\Models\Work;
use App\Suggestions\RecordMerger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Applies structural fixes the corpus text check found (item 87, step 13.55): copies the OCR put under the wrong work
 * move to the right one, and copies loaded twice fold into their first row. Works are found by the natural key in
 * their slug (`-v4-220`), copies by city, library and shelfmark, never by id. Each change goes through the reviewers'
 * own path (RecordMerger) and is logged like an approved suggestion, so it shows in the record's history and can be
 * undone. Without --apply it only reports.
 */
class ApplyCorpusStructureCommand extends Command
{
    protected $signature = 'fahares:apply-corpus-structure
                            {--file= : Decision file (database/data/fankha_column_check_20261029.json)}
                            {--apply : Write the changes; without it the command only reports what it would do}';

    protected $description = 'Move copies to the right work and fold duplicate copies, from a corpus check decision file';

    public function handle(RecordMerger $merger): int
    {
        $path = $this->option('file') ?: database_path('data/fankha_column_check_20261029.json');
        $plan = File::exists($path) ? json_decode(File::get($path), true) : null;
        if (! $plan) {
            $this->error("Decision file missing or invalid: {$path}");
            return 1;
        }
        $apply = (bool) $this->option('apply');
        $problems = [];
        $moves = [];
        $merges = [];

        foreach ($plan['moves'] ?? [] as $group) {
            [$from, $to] = [$this->work($group['from']), $this->work($group['to'])];
            if (! $from || ! $to) {
                $problems[] = "{$group['from']} → {$group['to']}: work not found or not unique";
                continue;
            }
            foreach ($group['copies'] as [$city, $library, $shelfmark]) {
                $rows = Manuscript::where('work_id', $from->id)->where(compact('city', 'library', 'shelfmark'))->get();
                $done = Manuscript::where('work_id', $to->id)->where(compact('city', 'library', 'shelfmark'))->exists();
                if ($rows->count() === 1) {
                    $moves[] = [$rows->first(), $to];
                    $this->line("move #{$rows->first()->id} {$city}، {$library}، {$shelfmark}: «{$from->clean_title}» → «{$to->clean_title}»");
                } elseif (! $rows->count() && $done) {
                    $this->line("already moved: {$city}، {$library}، {$shelfmark}");
                } else {
                    $problems[] = "{$city}، {$library}، {$shelfmark}: {$rows->count()} copies under «{$from->clean_title}»";
                }
            }
        }

        foreach ($plan['duplicates'] ?? [] as $group) {
            $work = $this->work($group['work']);
            if (! $work) {
                $problems[] = "{$group['work']}: work not found or not unique";
                continue;
            }
            foreach ($group['copies'] as [$seq, $shelfmark]) {
                $rows = Manuscript::where('work_id', $work->id)->where('sequence_number', $seq)->where('shelfmark', $shelfmark)->orderBy('id')->get();
                if ($rows->count() === 2) {
                    [$kept, $source] = [$rows[0], $rows[1]];
                    $merges[] = [$source, $kept];
                    $this->line("fold #{$source->id} into #{$kept->id}: {$seq}. {$shelfmark} «{$work->clean_title}»");
                } elseif ($rows->count() === 1) {
                    $this->line("already single: {$seq}. {$shelfmark}");
                } else {
                    $problems[] = "{$seq}. {$shelfmark}: {$rows->count()} rows in «{$work->clean_title}»";
                }
            }
        }

        $this->info(($apply ? 'Applying ' : 'Would apply ') . count($moves) . ' moves and ' . count($merges) . ' folds.');
        foreach ($problems as $line) {
            $this->warn($line);
        }
        if (! $apply || (! $moves && ! $merges)) {
            return 0;
        }

        $note = basename($path);
        DB::transaction(function () use ($moves, $merges, $merger, $note) {
            foreach ($moves as [$manuscript, $target]) {
                $result = $merger->moveManuscript($manuscript, $target);
                $this->log(Manuscript::class, $manuscript->id, 'verify', $result['old'], $result['new'] + ['corpus_fix' => $note]);
            }
            foreach ($merges as [$source, $kept]) {
                $result = $merger->mergeManuscript($source, $kept);
                $this->log(Manuscript::class, $kept->id, 'merge', ['source_id' => $source->id] + $result, ['target_id' => $kept->id, 'corpus_fix' => $note]);
            }
        });
        $this->info('Done.');

        return 0;
    }

    /** The one work whose slug ends with the natural key («-v4-220»). */
    private function work(string $key): ?Work
    {
        $rows = Work::where('slug', 'like', "%{$key}")->get()->filter(fn ($w) => str_ends_with($w->slug, $key));

        return $rows->count() === 1 ? $rows->first() : null;
    }

    private function log(string $type, int $id, string $action, array $old, array $new): void
    {
        AuditLog::create([
            'user_id' => null,
            'auditable_type' => $type,
            'auditable_id' => $id,
            'action' => $action,
            'old_values' => $old,
            'new_values' => $new,
        ]);
    }
}

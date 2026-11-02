<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Work;
use App\Suggestions\ProtectedColumns;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Moves the catalogue's word on a work's entry out of its description (item 87, step 14.7): a description that is
 * only «غیر همانند:», «ظاهرا غیر همانند:», «ناشناخته:» or «همانندی غیر معلوم:» becomes the work's entry_status, and
 * the description is emptied. A description with anything else in it, or one a reviewed correction protects, is left.
 * Every move is logged as corpus_update and the work is reindexed (the index carries the description). Safe to run
 * again.
 */
class MoveEntryStatusCommand extends Command
{
    protected $signature = 'fahares:move-entry-status {--apply : Write; without it the command only counts}';

    protected $description = "Move «غیر همانند» and the like from a work's description to its entry status";

    /** The whole description, and the status it names */
    private const LABELS = [
        '/^(?:ظاهرا|ظاهراً)\s*غیر\s*همانند\s*:?$/u' => 'probably_heterogeneous',
        '/^غیر\s*همانند\s*:?$/u' => 'heterogeneous',
        '/^ناشناخته\s*:?$/u' => 'unidentified',
        '/^همانندی\s*غیر\s*معلوم\s*:?$/u' => 'similarity_unknown',
    ];

    public function handle(): int
    {
        $guard = ProtectedColumns::for(Work::class);
        $writes = [];
        $counts = [];
        DB::table('works')->select(['id', 'description', 'entry_status'])
            ->whereRaw("description regexp '^[[:space:]]*(ظاهرا|ظاهراً|غیر|ناشناخته|همانندی)'")
            ->orderBy('id')->each(function ($row) use ($guard, &$writes, &$counts) {
                $status = self::statusOf($row->description);
                if ($status === null) {
                    return;
                }
                if ($guard->strip($row->id, ['description' => 1]) === []) {
                    $counts['protected'] = ($counts['protected'] ?? 0) + 1;
                    return;
                }
                $counts[$status] = ($counts[$status] ?? 0) + 1;
                $writes[$row->id] = [$row->description, $status];
            });

        foreach ($counts as $kind => $n) {
            $this->line(sprintf('  %-24s %6d', $kind, $n));
        }
        $this->info(($this->option('apply') ? 'Writing ' : 'Would write ') . count($writes) . ' works.');
        if (! $this->option('apply') || ! $writes) {
            return self::SUCCESS;
        }
        foreach (array_chunk($writes, 500, true) as $batch) {
            DB::transaction(function () use ($batch) {
                foreach ($batch as $id => [$description, $status]) {
                    DB::table('works')->where('id', $id)->update(['description' => null, 'entry_status' => $status, 'updated_at' => now()]);
                    AuditLog::create(['user_id' => null, 'auditable_type' => Work::class, 'auditable_id' => $id, 'action' => 'corpus_update',
                        'old_values' => ['description' => $description, 'entry_status' => null],
                        'new_values' => ['description' => null, 'entry_status' => $status, 'corpus_fix' => 'move-entry-status (step 14.7)']]);
                }
            });
        }
        foreach (array_chunk(array_keys($writes), 100) as $ids) {
            Work::whereIn('id', $ids)->get()->searchable();
            gc_collect_cycles();
        }
        $this->info('Done.');

        return self::SUCCESS;
    }

    /** The status a description names when it is nothing but its label, else null. */
    public static function statusOf(?string $description): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', str_replace("\u{200c}", ' ', (string) $description)));
        foreach (self::LABELS as $pattern => $status) {
            if (preg_match($pattern, $text)) {
                return $status;
            }
        }

        return null;
    }
}

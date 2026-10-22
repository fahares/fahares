<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\ManuscriptCatalogReference as Ref;
use Illuminate\Console\Command;

/**
 * Returns a bulk verification of the catalogue-reference queue (backlog item 51) to review, like the queue's
 * «برگرداندن این دسته» button: the batch's rows still verified by its reviewer at its time become unverified again.
 */
class RevertCatalogBulkVerifyCommand extends Command
{
    protected $signature = 'catalogs:revert-bulk-verify
        {batch? : Audit log id of the bulk verification (default: the latest one not reverted)}
        {--list : List the bulk verifications instead}
        {--force : Do not ask for confirmation}';

    protected $description = 'Revert a bulk verification of machine-matched catalogue references';

    public function handle(): int
    {
        if ($this->option('list')) {
            $reverted = AuditLog::where('auditable_type', AuditLog::class)->where('action', Ref::ACTION_BULK_REVERT)->pluck('auditable_id')->all();
            $this->table(['batch', 'reviewer', 'reviewed_at', 'count', 'filters', 'reverted'], AuditLog::with('user')
                ->where('auditable_type', Ref::class)->where('action', Ref::ACTION_BULK_VERIFY)->latest('id')->get()
                ->map(fn ($log) => [$log->id, $log->user?->email, $log->new_values['reviewed_at'] ?? '', $log->new_values['count'] ?? 0,
                    json_encode($log->new_values['filters'] ?? [], JSON_UNESCAPED_UNICODE), in_array($log->id, $reverted) ? 'yes' : '']));

            return self::SUCCESS;
        }

        $batch = $this->argument('batch') ? AuditLog::find($this->argument('batch')) : Ref::lastBulkVerify();
        if (! $batch || ! Ref::isBulkVerify($batch)) {
            $this->error('No such bulk verification.');

            return self::FAILURE;
        }

        $this->info("Batch #{$batch->id}: {$batch->new_values['count']} references verified by {$batch->user?->email} at {$batch->new_values['reviewed_at']}.");
        if (! $this->option('force') && ! $this->confirm('Return them to the review queue?')) {
            return self::SUCCESS;
        }

        try {
            [$reverted, $kept] = Ref::revertBulkVerify($batch);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("Reverted {$reverted}; kept {$kept} reviewed again since.");

        return self::SUCCESS;
    }
}

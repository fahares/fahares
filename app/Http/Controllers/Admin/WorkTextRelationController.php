<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Work;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A work's relation with a work the site has no record of (work_text_relations, step 14.280), taken off from the
 * work's own page by a reviewer. The row is kept in the audit log, whose rollback brings it back. To correct one,
 * it is removed and suggested again.
 */
class WorkTextRelationController extends Controller
{
    public function destroy(Request $request, int $relation)
    {
        // only a row added by hand: the catalogue's own entries are not taken off from the page
        $row = DB::table('work_text_relations')->where('id', $relation)->where('source', 'suggestion')->first();
        abort_unless($row, 404);
        DB::transaction(function () use ($row, $request) {
            DB::table('work_text_relations')->where('id', $row->id)->delete();
            AuditLog::create(['user_id' => auth()->id(), 'auditable_type' => Work::class, 'auditable_id' => $row->work_id, 'action' => 'verify',
                'old_values' => ['removed_text_relation' => (array) $row], 'new_values' => ['text_relation' => null], 'ip_address' => $request->ip()]);
        });

        return back()->with('success', 'رابطه برداشته شد؛ از «گزارش ممیزی» بازگرداندنی است.');
    }
}

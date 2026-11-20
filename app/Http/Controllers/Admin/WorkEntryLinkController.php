<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Work;
use App\Models\WorkEntryLink;
use App\Support\WorkRelationLinker;
use App\Support\WorkRelationParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The panel's «پیوند آثار مرتبط» (backlog item 6, step 14.274): entries of a work's «وابسته به» or «شروح و حواشی»
 * that may name a work of the site, proposed with less than full confidence (work_entry_links of source «review»).
 * «پیوند بده» makes the work's page link the entry and, where the two works have no relation yet, relates them
 * (work_relations of source «review»); «اثر دیگری است» leaves the entry as text; «بازگشایی» undoes either.
 */
class WorkEntryLinkController extends Controller
{
    public const SIDES = ['related' => 'اثر میزبان بر این مدخل ساخته شده است', 'children' => 'این مدخل بر اثر میزبان ساخته شده است'];

    public function index(Request $request)
    {
        $done = $request->query('view') === 'done';
        $links = WorkEntryLink::where('source', WorkEntryLink::SOURCE_REVIEW)
            ->whereIn('status', $done ? [WorkEntryLink::LINKED, WorkEntryLink::REJECTED] : [WorkEntryLink::PROPOSED])
            ->with(['work', 'linkedWork', 'decider'])
            ->orderBy($done ? 'decided_at' : 'id', $done ? 'desc' : 'asc')->paginate(25)->withQueryString();
        $counts = WorkEntryLink::where('source', WorkEntryLink::SOURCE_REVIEW)->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');

        return view('admin.work-entry-links.index', compact('links', 'counts', 'done'));
    }

    public function link(Request $request, WorkEntryLink $link)
    {
        abort_unless($link->source === WorkEntryLink::SOURCE_REVIEW && $link->status === WorkEntryLink::PROPOSED, 404);
        [$host, $found] = [$link->work, $link->linkedWork];
        $entry = $host ? $this->entry($host, $link) : null;
        if (! $found || ! $entry) {
            return back()->withErrors(['link' => 'اثر یا مدخل دیگر وجود ندارد.']);
        }
        $pair = WorkRelationLinker::relationBetween($host, $link->side, $entry, $found);
        if (DB::table('work_relations')->where('work_id', $pair['parent'])->where('related_work_id', $pair['child'])->exists()) {
            return back()->withErrors(['link' => 'این دو اثر در جهت وارونه به هم پیوند دارند؛ نخست آن رابطه را بازبینی کنید.']);
        }
        DB::transaction(function () use ($link, $pair, $request) {
            $related = DB::table('work_relations')->where('work_id', $pair['child'])->where('related_work_id', $pair['parent'])->exists();
            if (! $related) {
                DB::table('work_relations')->insert(['work_id' => $pair['child'], 'related_work_id' => $pair['parent'], 'type' => $pair['type'],
                    'source' => WorkEntryLink::SOURCE_REVIEW, 'created_at' => now(), 'updated_at' => now()]);
            }
            $link->update(['status' => WorkEntryLink::LINKED, 'decided_by' => auth()->id(), 'decided_at' => now()]);
            AuditLog::create(['user_id' => auth()->id(), 'auditable_type' => Work::class, 'auditable_id' => $link->work_id, 'action' => 'update',
                'new_values' => ['entry_link_id' => $link->id, 'entry' => $link->entry, 'linked_work_id' => $link->linked_work_id,
                    'relation' => $related ? null : $pair], 'ip_address' => $request->ip()]);
        });
        // the work made on the other feeds the search with its titles (Work::baseTitles)
        Work::find($pair['child'])?->searchable();

        return back()->with('success', "مدخل به «{$found->clean_title}» پیوند خورد.");
    }

    public function reject(WorkEntryLink $link)
    {
        abort_unless($link->source === WorkEntryLink::SOURCE_REVIEW && $link->status === WorkEntryLink::PROPOSED, 404);
        $link->update(['status' => WorkEntryLink::REJECTED, 'decided_by' => auth()->id(), 'decided_at' => now()]);

        return back()->with('success', 'ثبت شد: اثر دیگری است؛ مدخل همان‌طور که هست می‌ماند.');
    }

    public function reopen(Request $request, WorkEntryLink $link)
    {
        abort_unless($link->source === WorkEntryLink::SOURCE_REVIEW && $link->status !== WorkEntryLink::PROPOSED, 404);
        $ids = [$link->work_id, $link->linked_work_id];
        DB::transaction(function () use ($link, $ids, $request) {
            if ($link->status === WorkEntryLink::LINKED) {
                // the relation the decision made goes with it, unless another confirmed entry of the two works stands on it
                $others = WorkEntryLink::where('id', '<>', $link->id)->where('status', WorkEntryLink::LINKED)
                    ->where(fn ($q) => $q->where(fn ($q) => $q->where('work_id', $ids[0])->where('linked_work_id', $ids[1]))
                        ->orWhere(fn ($q) => $q->where('work_id', $ids[1])->where('linked_work_id', $ids[0])))->exists();
                if (! $others) {
                    DB::table('work_relations')->where('source', WorkEntryLink::SOURCE_REVIEW)->whereIn('work_id', $ids)->whereIn('related_work_id', $ids)->delete();
                }
                AuditLog::create(['user_id' => auth()->id(), 'auditable_type' => Work::class, 'auditable_id' => $link->work_id, 'action' => 'update',
                    'old_values' => ['entry_link_id' => $link->id, 'entry' => $link->entry, 'linked_work_id' => $link->linked_work_id], 'ip_address' => $request->ip()]);
            }
            $link->update(['status' => WorkEntryLink::PROPOSED, 'decided_by' => null, 'decided_at' => null]);
        });
        Work::whereIn('id', $ids)->get()->each->searchable();

        return back()->with('success', 'مورد به صف بازگشت.');
    }

    /** The entry of the host's text the row is about, by its key */
    private function entry(Work $host, WorkEntryLink $link): ?array
    {
        $texts = $link->side === 'related' ? [(string) $host->related_work] : ($host->commentaries_and_glosses ?? []);
        foreach ($texts as $text) {
            foreach (WorkRelationParser::parseEntries((string) $text) as $entry) {
                if (WorkRelationLinker::entryKey($entry) === $link->entry) {
                    return $entry;
                }
            }
        }

        return null;
    }
}

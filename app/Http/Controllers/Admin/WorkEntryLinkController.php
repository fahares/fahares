<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Work;
use App\Models\WorkEntryLink;
use App\Support\WorkCatalogueEntries;
use App\Support\WorkRelationLinker;
use App\Support\WorkRelationParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The panel's «پیوند آثار مرتبط» (backlog item 6, steps 14.274 and 14.285): entries of a work's «وابسته به» or «شروح و
 * حواشی» that may name a work of the site, proposed with less than full confidence (rows of work_text_relations with
 * link_source «review»). «پیوند بده» ties the entry to the work and, where the two works have no relation yet, relates
 * them (work_relations of source «review»); «اثر دیگری است» leaves the entry as text; «بازگشایی» undoes either.
 */
class WorkEntryLinkController extends Controller
{
    public const SIDES = ['related' => 'اثر میزبان بر این مدخل ساخته شده است', 'children' => 'این مدخل بر اثر میزبان ساخته شده است'];

    public function index(Request $request)
    {
        $done = $request->query('view') === 'done';
        $links = WorkEntryLink::review()
            ->whereIn('link_status', $done ? [WorkEntryLink::LINKED, WorkEntryLink::REJECTED] : [WorkEntryLink::PROPOSED])
            ->with(['work', 'linkedWork', 'decider'])
            ->orderBy($done ? 'decided_at' : 'id', $done ? 'desc' : 'asc')->paginate(25)->withQueryString();
        $counts = WorkEntryLink::review()->selectRaw('link_status, COUNT(*) n')->groupBy('link_status')->pluck('n', 'link_status');
        // what each candidate is itself made on, as the catalogue writes it
        $madeOn = DB::table('work_text_relations')->where('source', WorkCatalogueEntries::SOURCE)->where('side', 'related')->where('type', '<>', WorkCatalogueEntries::NOTE)
            ->whereIn('work_id', $links->getCollection()->pluck('linked_work_id')->filter())->orderBy('position')->get(['work_id', 'text'])
            ->groupBy('work_id')->map(fn ($rows) => $rows->pluck('text')->implode('؛ '));

        return view('admin.work-entry-links.index', compact('links', 'counts', 'done', 'madeOn'));
    }

    public function link(Request $request, WorkEntryLink $link)
    {
        abort_unless($link->link_source === WorkEntryLink::SOURCE_REVIEW && $link->link_status === WorkEntryLink::PROPOSED, 404);
        [$host, $found] = [$link->work, $link->linkedWork];
        $entry = WorkRelationParser::parseEntries($link->text)[0] ?? null;
        if (! $host || ! $found || ! $entry) {
            return back()->withErrors(['link' => 'اثر یا مدخل دیگر وجود ندارد.']);
        }
        $pair = WorkRelationLinker::relationBetween($host, $link->side, $entry, $found);
        if (DB::table('work_relations')->where('work_id', $pair['parent'])->where('related_work_id', $pair['child'])->exists()) {
            return back()->withErrors(['link' => 'این دو اثر در جهت وارونه به هم پیوند دارند؛ نخست آن رابطه را بازبینی کنید.']);
        }
        DB::transaction(function () use ($link, $pair, $entry, $found, $request) {
            $related = DB::table('work_relations')->where('work_id', $pair['child'])->where('related_work_id', $pair['parent'])->exists();
            if (! $related) {
                DB::table('work_relations')->insert(['work_id' => $pair['child'], 'related_work_id' => $pair['parent'], 'type' => $pair['type'],
                    'source' => WorkEntryLink::SOURCE_REVIEW, 'created_at' => now(), 'updated_at' => now()]);
            }
            $link->update(['link_status' => WorkEntryLink::LINKED, 'decided_by' => auth()->id(), 'decided_at' => now(),
                'differs' => WorkCatalogueEntries::wordsDiffer($entry, WorkCatalogueEntries::linkedPart($entry, $found), $found)]);
            AuditLog::create(['user_id' => auth()->id(), 'auditable_type' => Work::class, 'auditable_id' => $link->work_id, 'action' => 'update',
                'new_values' => ['entry_link_id' => $link->id, 'entry' => $link->text, 'linked_work_id' => $link->linked_work_id,
                    'relation' => $related ? null : $pair], 'ip_address' => $request->ip()]);
        });
        // the work made on the other feeds the search with its titles (Work::baseTitles)
        Work::find($pair['child'])?->searchable();

        return back()->with('success', "مدخل به «{$found->clean_title}» پیوند خورد.");
    }

    public function reject(WorkEntryLink $link)
    {
        abort_unless($link->link_source === WorkEntryLink::SOURCE_REVIEW && $link->link_status === WorkEntryLink::PROPOSED, 404);
        $link->update(['link_status' => WorkEntryLink::REJECTED, 'decided_by' => auth()->id(), 'decided_at' => now()]);

        return back()->with('success', 'ثبت شد: اثر دیگری است؛ مدخل همان‌طور که هست می‌ماند.');
    }

    public function reopen(Request $request, WorkEntryLink $link)
    {
        abort_unless($link->link_source === WorkEntryLink::SOURCE_REVIEW && in_array($link->link_status, [WorkEntryLink::LINKED, WorkEntryLink::REJECTED], true), 404);
        $ids = [$link->work_id, $link->linked_work_id];
        DB::transaction(function () use ($link, $ids, $request) {
            if ($link->link_status === WorkEntryLink::LINKED) {
                // the relation the decision made goes with it, unless another entry a reviewer confirmed stands on it
                $others = WorkEntryLink::review()->where('id', '<>', $link->id)->where('link_status', WorkEntryLink::LINKED)
                    ->where(fn ($q) => $q->where(fn ($q) => $q->where('work_id', $ids[0])->where('linked_work_id', $ids[1]))
                        ->orWhere(fn ($q) => $q->where('work_id', $ids[1])->where('linked_work_id', $ids[0])))->exists();
                if (! $others) {
                    DB::table('work_relations')->where('source', WorkEntryLink::SOURCE_REVIEW)->whereIn('work_id', $ids)->whereIn('related_work_id', $ids)->delete();
                }
                AuditLog::create(['user_id' => auth()->id(), 'auditable_type' => Work::class, 'auditable_id' => $link->work_id, 'action' => 'update',
                    'old_values' => ['entry_link_id' => $link->id, 'entry' => $link->text, 'linked_work_id' => $link->linked_work_id], 'ip_address' => $request->ip()]);
            }
            $link->update(['link_status' => WorkEntryLink::PROPOSED, 'differs' => false, 'decided_by' => null, 'decided_at' => null]);
        });
        Work::whereIn('id', $ids)->get()->each->searchable();

        return back()->with('success', 'مورد به صف بازگشت.');
    }
}

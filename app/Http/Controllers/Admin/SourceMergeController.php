<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Source;
use App\Sources\SourceMerger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The merge of two sources in the admin panel (step 14.50, backlog item 70): what sources:merge-split does to a
 * group, through the same SourceMerger, for two sources the admin picks. A preview shows how the volumes pair and
 * what moves; the merge is logged with a snapshot of every row it changes, from which it is undone.
 */
class SourceMergeController extends Controller
{
    public function show(Request $request)
    {
        $sources = Source::orderBy('title')->get(['id', 'code', 'title', 'kind', 'volumes_count', 'library_id']);
        $keep = Source::with('volumes', 'library')->find($request->integer('keep'));
        $fold = Source::with('volumes', 'library')->find($request->integer('fold'));
        $plan = $counts = $warnings = null;
        if ($keep && $fold && $keep->id !== $fold->id) {
            $plan = app(SourceMerger::class)->plan(collect([$keep, $fold]));
            $counts = app(SourceMerger::class)->counts($plan);
            $warnings = $this->warnings($keep, $fold, $counts);
        }

        return view('admin.sources.merge', compact('sources', 'keep', 'fold', 'plan', 'counts', 'warnings'));
    }

    public function store(Request $request, SourceMerger $merger)
    {
        $validated = $request->validate([
            'keep' => 'required|integer|exists:sources,id',
            'fold' => 'required|integer|exists:sources,id|different:keep',
            'title' => 'required|string|max:500',
            'confirm' => 'accepted',
        ], ['fold.different' => 'یک منبع با خودش ادغام نمی‌شود.', 'confirm.accepted' => 'ادغام را تأیید کنید.']);
        $keep = Source::with('volumes')->findOrFail($validated['keep']);
        $fold = Source::with('volumes')->findOrFail($validated['fold']);
        $plan = $merger->plan(collect([$keep, $fold]));
        $counts = $merger->counts($plan);
        if ($counts['entries'] > SourceMerger::PANEL_LIMIT) {
            return back()->withInput()->with('error', 'این ادغام بیش از ' . \App\Support\Fa::num(SourceMerger::PANEL_LIMIT)
                . ' مدخل را جابه‌جا می‌کند و باید با دستور sources:merge-split انجام شود.');
        }
        $title = trim(preg_replace('/\s+/u', ' ', $validated['title']));

        DB::transaction(function () use ($merger, $plan, $title, $keep, $fold, $counts, $request) {
            $snapshot = $merger->snapshot($plan);
            $merger->apply($plan, $title);
            AuditLog::create([
                'user_id' => auth()->id(),
                'auditable_type' => Source::class,
                'auditable_id' => $keep->id,
                'action' => 'merge',
                'old_values' => [
                    'kind' => 'source_merge',
                    'kept_title' => $keep->title,
                    'folded_id' => $fold->id,
                    'folded_code' => $fold->code,
                    'folded_title' => $fold->title,
                    'snapshot' => SourceMerger::pack($snapshot),
                ],
                'new_values' => ['title' => $title, 'merged_code' => $fold->code] + $counts,
                'ip_address' => $request->ip(),
            ]);
        });

        return redirect()->route('admin.sources.show', $keep)
            ->with('success', "منبع «{$fold->title}» در این منبع ادغام شد. کد {$fold->code} به این منبع می‌رسد.");
    }

    /** Takes a merge back, from the source's page. */
    public function undo(Request $request, AuditLog $log)
    {
        try {
            DB::transaction(fn () => self::undoLog($log, $request));
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
        $restored = Source::find($log->old_values['folded_id'] ?? 0);

        return redirect()->route('admin.sources.show', $log->auditable_id)
            ->with('success', 'ادغام برگردانده شد' . ($restored ? " و منبع «{$restored->title}» دوباره جداست." : '.'));
    }

    /** Undoes a merge's log, in the caller's transaction; also called from the audit log's rollback. */
    public static function undoLog(AuditLog $log, Request $request): void
    {
        $old = $log->old_values ?? [];
        if ($log->action !== 'merge' || $log->auditable_type !== Source::class || ($old['kind'] ?? null) !== 'source_merge') {
            throw new InvalidArgumentException('این رخداد ادغام منبع نیست.');
        }
        if ($log->isRolledBack()) {
            throw new InvalidArgumentException("ادغام #{$log->id} پیش‌تر برگردانده شده است.");
        }
        if (! Source::find($log->auditable_id)) {
            throw new InvalidArgumentException('منبع ماندنی این ادغام دیگر نیست؛ شاید خودش در منبع دیگری ادغام شده باشد.');
        }
        if (Source::find($old['folded_id']) || Source::where('code', $old['folded_code'])->exists()) {
            throw new InvalidArgumentException("منبعی با کد {$old['folded_code']} هم‌اکنون هست.");
        }
        $later = AuditLog::where('auditable_type', Source::class)->where('action', 'merge')->where('id', '>', $log->id)
            ->where(fn ($q) => $q->where('auditable_id', $log->auditable_id)->orWhere('old_values->folded_id', $log->auditable_id))
            ->get()->first(fn (AuditLog $l) => ! $l->isRolledBack());
        if ($later) {
            throw new InvalidArgumentException("ادغام #{$later->id} بعداً همین منبع را تغییر داده است؛ نخست آن را برگردانید.");
        }

        app(SourceMerger::class)->undo(SourceMerger::unpack($old['snapshot']));
        AuditLog::create([
            'user_id' => auth()->id(),
            'auditable_type' => Source::class,
            'auditable_id' => $log->auditable_id,
            'action' => 'rollback_merge',
            'old_values' => ['rolled_back_log_id' => $log->id],
            'new_values' => ['restored_source_id' => $old['folded_id'], 'restored_code' => $old['folded_code']],
            'ip_address' => $request->ip(),
        ]);
    }

    /** What the admin should look at before merging. */
    private function warnings(Source $keep, Source $fold, array $counts): array
    {
        $warnings = [];
        if ($keep->kind !== $fold->kind) {
            $warnings[] = 'نوع دو منبع یکی نیست: ' . Source::KIND_LABELS[$keep->kind] . ' و ' . Source::KIND_LABELS[$fold->kind] . '. نوع منبع ماندنی می‌ماند.';
        }
        if ($keep->library_id !== $fold->library_id) {
            $warnings[] = 'کتابخانه‌ی دو منبع یکی نیست. کتابخانه‌ی منبع ماندنی می‌ماند و جلدها کتابخانه‌ی خودشان را نگه می‌دارند.';
        }
        if ($fold->edition_of_id === $keep->id || $keep->edition_of_id === $fold->id) {
            $warnings[] = 'یکی از این دو، چاپ دیگری از دیگری است. چاپ‌های دیگر معمولاً منبع جدا می‌مانند.';
        }
        if ($counts['entries'] > SourceMerger::PANEL_LIMIT) {
            $warnings[] = 'این ادغام بیش از ' . \App\Support\Fa::num(SourceMerger::PANEL_LIMIT) . ' مدخل را جابه‌جا می‌کند و از پنل انجام نمی‌شود؛ دستور sources:merge-split را به کار ببرید.';
        }

        return $warnings;
    }
}

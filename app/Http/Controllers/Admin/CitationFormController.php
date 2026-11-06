<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Source;
use App\Models\SourceCitationForm;
use App\Sources\CitationFormEditor;
use App\Sources\CitationForms;
use App\Sources\SourceMerger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * How a source is cited in the others, in the admin panel (step 14.57, docs/plans/source-name-patterns.md): a form
 * per citing source (or any source) and place, its names, how its numbers are read; a preview of what it does to the
 * citations, and its undo from the audit log.
 */
class CitationFormController extends Controller
{
    /** What a form is, for the audit log and its undo. */
    private const ATTRIBUTES = ['cited_source_id', 'citing_source_id', 'context', 'names', 'regex', 'options', 'notes', 'examples'];

    public function create(Request $request, Source $source)
    {
        $form = new SourceCitationForm(['cited_source_id' => $source->id, 'context' => 'bibliography',
            'citing_source_id' => $request->integer('citing') ?: null]);

        return $this->page($source, $form);
    }

    public function edit(Source $source, SourceCitationForm $form)
    {
        abort_unless($form->cited_source_id === $source->id, 404);

        return $this->page($source, $form);
    }

    public function store(Request $request, Source $source, CitationFormEditor $editor)
    {
        $values = $this->values($request, $source, null);
        $made = null;
        $change = function () use ($values, &$made) {
            $made = SourceCitationForm::create($values);
        };

        return $this->run($request, $source, $editor, $change, null, $values, 'create', function () use (&$made) {
            return $made;
        });
    }

    public function update(Request $request, Source $source, SourceCitationForm $form, CitationFormEditor $editor)
    {
        abort_unless($form->cited_source_id === $source->id, 404);
        $values = $this->values($request, $source, $form);
        $old = self::state($form);
        if ($old == array_merge($old, $values)) {
            return redirect()->route('admin.sources.citations.edit', [$source, $form])->with('success', 'تغییری داده نشد.');
        }

        return $this->run($request, $source, $editor, fn () => $form->update($values), $form, $values, 'update', fn () => $form);
    }

    public function destroy(Request $request, Source $source, SourceCitationForm $form, CitationFormEditor $editor)
    {
        abort_unless($form->cited_source_id === $source->id, 404);

        return $this->run($request, $source, $editor, fn () => $form->delete(), $form, null, 'remove', fn () => $form);
    }

    public function undo(Request $request, AuditLog $log)
    {
        try {
            DB::transaction(fn () => self::undoLog($log, $request));
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'تغییر الگوی ارجاع برگردانده شد.');
    }

    /** Undoes a citation form's change, in the caller's transaction; also called from the audit log's rollback. */
    public static function undoLog(AuditLog $log, Request $request): void
    {
        if ($log->auditable_type !== SourceCitationForm::class || ! in_array($log->action, ['create', 'update', 'remove'], true)) {
            throw new InvalidArgumentException('این رخداد تغییر الگوی ارجاع نیست.');
        }
        if ($log->isRolledBack()) {
            throw new InvalidArgumentException("رخداد #{$log->id} پیش‌تر برگردانده شده است.");
        }
        $later = AuditLog::where('auditable_type', SourceCitationForm::class)->where('auditable_id', $log->auditable_id)
            ->where('id', '>', $log->id)->whereIn('action', ['create', 'update', 'remove'])->get()->first(fn (AuditLog $l) => ! $l->isRolledBack());
        if ($later) {
            throw new InvalidArgumentException("رخداد #{$later->id} بعداً همین الگو را تغییر داده است؛ نخست آن را برگردانید.");
        }
        $old = $log->old_values ?? [];
        $before = collect($old)->only(self::ATTRIBUTES)->all();
        match ($log->action) {
            'create' => SourceCitationForm::whereKey($log->auditable_id)->delete(),
            'update' => SourceCitationForm::findOrFail($log->auditable_id)->update($before),
            'remove' => SourceCitationForm::forceCreate(['id' => $log->auditable_id] + $before),
        };
        CitationForms::forget();
        if (! empty($old['snapshot'])) {
            SourceMerger::restoreColumns('source_entries', SourceMerger::unpack($old['snapshot']));
        }
        AuditLog::create([
            'user_id' => auth()->id(),
            'auditable_type' => SourceCitationForm::class,
            'auditable_id' => $log->auditable_id,
            'action' => 'rollback_' . $log->action,
            'old_values' => ['rolled_back_log_id' => $log->id, 'cited_source_id' => $log->new_values['cited_source_id'] ?? $before['cited_source_id'] ?? null],
            'new_values' => $before ?: ['removed' => true],
            'ip_address' => $request->ip(),
        ]);
    }

    /**
     * Runs a change of a form through CitationFormEditor: «preview» shows what it does to the citations and keeps the
     * form; otherwise it is saved, the works' entries are made again, and it is logged with what it changed.
     */
    private function run(Request $request, Source $source, CitationFormEditor $editor, \Closure $change, ?SourceCitationForm $form,
        ?array $values, string $action, \Closure $saved)
    {
        $context = $values['context'] ?? $form->context;
        $citing = DB::table('sources')->where('id', $values['citing_source_id'] ?? $form?->citing_source_id)->value('code')
            ?? ($context === 'bibliography' ? 'fankha' : null);
        $patterns = [$form?->pattern(), $values ? CitationForms::pattern($values['regex'] ?? null, $values['names'] ?? []) : null];
        $back = $form?->exists ? route('admin.sources.citations.edit', [$source, $form]) : route('admin.sources.citations.create', [$source, 'citing' => $values['citing_source_id'] ?? null]);

        if ($request->boolean('preview')) {
            $effect = $editor->run($source, $change, $patterns, $context, $citing, dry: true);

            return redirect($back)->withInput()->with('citation_preview', collect($effect)->except('works')->all() + ['works' => count($effect['works'])]);
        }

        $log = null;
        DB::transaction(function () use ($editor, $source, $change, $patterns, $context, $citing, $form, $values, $action, $saved, $request, &$log, &$effect) {
            $old = $form ? self::state($form) : null;
            $effect = $editor->run($source, $change, $patterns, $context, $citing, dry: false);
            // the entries' columns as they still are, for the undo, before the works are made again
            $snapshot = $context === 'bibliography' ? CitationFormEditor::snapshot($effect['works']) : [];
            $effect['queued'] = $context === 'bibliography' && $effect['works'] ? $editor->sync($effect['works']) : false;
            $model = $saved();
            $log = AuditLog::create([
                'user_id' => auth()->id(),
                'auditable_type' => SourceCitationForm::class,
                'auditable_id' => $model->id,
                'action' => $action,
                'old_values' => ($old ?? []) + ($snapshot ? ['snapshot' => SourceMerger::pack($snapshot)] : []) ?: null,
                'new_values' => ($action === 'remove' ? ['cited_source_id' => $source->id] : self::state($model->fresh() ?? $model))
                    + ['effect' => collect($effect)->only(['read', 'gained', 'taken', 'lost', 'moved', 'other', 'unread', 'queued'])->all() + ['works' => count($effect['works'])]],
                'ip_address' => $request->ip(),
            ]);
        });
        SourceMerger::forget([$source->id]);

        $message = match ($action) {
            'create' => 'الگوی ارجاع افزوده شد.',
            'remove' => 'الگوی ارجاع حذف شد.',
            default => 'الگوی ارجاع ذخیره شد.',
        };
        $message .= $effect['works'] ? ' مآخذ ' . \App\Support\Fa::num(count($effect['works'])) . ' اثر ' . ($effect['queued'] ? 'در صف دوباره ساخته می‌شود.' : 'دوباره ساخته شد.') : '';

        return redirect()->route('admin.sources.show', $source)->with('success', $message)->with('citation_effect', collect($effect)->except(['works', 'samples'])->all() + ['works' => count($effect['works'])]);
    }

    private function page(Source $source, SourceCitationForm $form)
    {
        $sources = Source::orderBy('title')->get(['id', 'code', 'title', 'short_name']);
        $others = SourceCitationForm::with('citing:id,code,title')->where('cited_source_id', $source->id)->whereKeyNot($form->id ?? 0)->get();
        $history = $form->exists ? AuditLog::with('user:id,name')->where('auditable_type', SourceCitationForm::class)
            ->where('auditable_id', $form->id)->latest('id')->limit(20)->get() : collect();

        return view('admin.sources.citation-form', compact('source', 'form', 'sources', 'others', 'history'));
    }

    /** The form as the request gives it; a regex only from a full admin, the rules it does not edit kept. */
    private function values(Request $request, Source $source, ?SourceCitationForm $form): array
    {
        $v = $request->validate([
            'citing_source_id' => ['nullable', 'integer', 'exists:sources,id'],
            'context' => ['required', Rule::in(array_keys(SourceCitationForm::CONTEXTS))],
            'names' => 'nullable|string|max:5000',
            'regex' => 'nullable|string|max:2000',
            'volumes' => 'nullable|integer|min:1|max:1000',
            'column' => 'boolean',
            'pairs_after_name' => 'boolean',
            'series_volumes' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:5000',
            'examples' => 'nullable|string|max:5000',
        ], ['context.in' => 'جای ارجاع درست نیست.']);
        $lines = fn (?string $text) => array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/u', (string) $text)))));
        $names = $lines($v['names'] ?? null);
        $regex = $request->user()->role === \App\Auth\Permissions::SUPER_ROLE ? (filled($v['regex'] ?? null) ? trim($v['regex']) : null) : $form?->regex;
        $errors = [];
        if (! $names && ! $regex) {
            $errors['names'] = 'دست‌کم یک نام لازم است.';
        }
        if ($regex !== null && @preg_match('/' . $regex . '/u', '') === false) {
            $errors['regex'] = 'عبارت باقاعده درست نیست.';
        }
        if ($v['context'] === 'page_text' && empty($v['citing_source_id'])) {
            $errors['citing_source_id'] = 'الگوی متن صفحه‌ها برای یک منبع ارجاع‌دهنده است.';
        }
        if ((int) ($v['citing_source_id'] ?? 0) === $source->id && $v['context'] === 'bibliography') {
            $errors['citing_source_id'] = 'منبع در مآخذ خودش ارجاع داده نمی‌شود.';
        }
        $series = [];
        foreach (preg_split('/[،,\s]+/u', strtr((string) ($v['series_volumes'] ?? ''), array_combine(['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'], range(0, 9))), -1, PREG_SPLIT_NO_EMPTY) as $pair) {
            if (! preg_match('/^(\d+)[:=](\d+)$/', $pair, $m)) {
                $errors['series_volumes'] = 'هر جفت به شکل «۳:۱» باشد: شماره‌ای که منبع ارجاع‌دهنده می‌نویسد، و جلد خود منبع.';
                break;
            }
            $series[(int) $m[1]] = (int) $m[2];
        }
        if ($errors) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }
        $options = collect($form?->options ?? [])->except(['volumes', 'unit', 'pairs_after_name', 'series_volumes'])->all();
        if (! empty($v['volumes'])) {
            $options['volumes'] = (int) $v['volumes'];
        }
        if ($request->boolean('column')) {
            $options['unit'] = 'ستون';
        }
        if ($request->boolean('pairs_after_name')) {
            $options['pairs_after_name'] = true;
        }
        if ($series) {
            $options['series_volumes'] = $series;
        }

        return [
            'cited_source_id' => $source->id,
            'citing_source_id' => $v['citing_source_id'] ?? null,
            'context' => $v['context'],
            'names' => $names ?: null,
            'regex' => $regex,
            'options' => $options ?: null,
            'notes' => filled($v['notes'] ?? null) ? trim($v['notes']) : null,
            'examples' => $lines($v['examples'] ?? null) ?: null,
        ];
    }

    private static function state(SourceCitationForm $form): array
    {
        return collect($form->only(self::ATTRIBUTES))->map(fn ($v, $k) => in_array($k, ['cited_source_id', 'citing_source_id'], true) && $v !== null ? (int) $v : $v)->all();
    }
}

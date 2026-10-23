<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SourceEntry;
use App\Sources\Extractors\Extractors;
use App\Sources\TextRefresh;
use Illuminate\Http\Request;

/**
 * The printed text of one entry (step 13.6): the reviewer sees it beside the text its source's pages show at the
 * entry's address and the pages themselves, as many as the entry runs over, and takes the one found or writes it
 * by hand. A text written by hand is the reviewer's: no automatic reading replaces it.
 */
class SourceEntryController extends Controller
{
    /** Pages shown around the entry at most, and added by «صفحه‌ی بعد» or «صفحه‌ی پیش». */
    private const MAX_PAGES = 12;

    public function text(Request $request, SourceEntry $entry)
    {
        $entry->load(['source.library', 'sourceVolume', 'citable']);
        abort_unless($entry->citable, 404);
        $start = (int) ($entry->page_start ?: 1);
        $from = max(1, $request->integer('from') ?: $start);
        $to = max($from, min($from + self::MAX_PAGES - 1, $request->integer('to') ?: max($start, (int) $entry->page_end)));
        $pages = $entry->source_volume_id ? Extractors::pageRange($entry->source_volume_id, $from, $to) : [];
        $found = Extractors::canRead($entry) ? Extractors::extract($entry) : null;

        return view('admin.source_entries.text', compact('entry', 'pages', 'from', 'to', 'found') + ['readable' => Extractors::canRead($entry)]);
    }

    public function update(Request $request, SourceEntry $entry)
    {
        $data = $request->validate([
            'mode' => 'required|in:found,manual',
            'entry_text' => 'nullable|string|max:20000',
            'copy_text' => 'nullable|string|max:20000',
        ]);
        $old = $entry->only(TextRefresh::COLUMNS);

        if ($data['mode'] === 'found') {
            if (! TextRefresh::apply($entry)) {
                return back()->with('error', 'متن در نشانی کنونی این مدخل در صفحه‌های مأخذ پیدا نشد.');
            }
        } else {
            $clean = fn (?string $t) => ($t = trim(str_replace(["\r\n", "\r"], "\n", (string) $t))) === '' ? null : $t;
            $entry->forceFill([
                'entry_text' => $clean($data['entry_text'] ?? null),
                'copy_text' => $clean($data['copy_text'] ?? null),
                'text_volume_number' => $entry->volumeNumber(),
                'text_page' => $entry->page_start,
                'text_status' => SourceEntry::TEXT_EDITED,
            ])->save();
        }

        $at = fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i:s') : $v;
        AuditLog::create([
            'user_id' => $request->user()->id,
            'auditable_type' => SourceEntry::class,
            'auditable_id' => $entry->id,
            'action' => 'update',
            'old_values' => array_map($at, $old),
            'new_values' => array_map($at, $entry->fresh()->only(TextRefresh::COLUMNS)),
            'ip_address' => $request->ip(),
        ]);

        return redirect()->to($this->recordUrl($entry))->with('success', 'متن مأخذ به‌روز شد.');
    }

    private function recordUrl(SourceEntry $entry): string
    {
        return match ($entry->citable_type) {
            \App\Models\Manuscript::class => route('manuscripts.show', $entry->citable_id),
            \App\Models\Work::class => route('works.show', $entry->citable_id),
            default => route('admin.dashboard'),
        };
    }
}

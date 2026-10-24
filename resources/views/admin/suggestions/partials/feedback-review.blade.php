{{-- Review of site feedback: the page it came from, the message, screenshots; resolve with an answer, reject, or convert (see partials/convert) --}}
@php
    $payload = $suggestion->payload ?? [];
    $input = 'admin-field w-full';
@endphp
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    <div class="lg:col-span-2 p-4 sm:p-5 bg-surface border border-line space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="rubric text-base">بازخورد سایت: {{ $suggestion->field_label }}</h3>
            @if($suggestion->place_url)
                <a href="{{ $suggestion->place_url }}" target="_blank" class="font-bold text-cinnabar hover:underline">صفحه‌ی گزارش‌شده ↗</a>
            @endif
        </div>
        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-muted">
            <div><dt class="inline font-bold">صفحه:</dt> <dd class="inline">{{ $payload['title'] ?? '—' }}</dd></div>
            <div><dt class="inline font-bold">نشانی:</dt> <dd class="inline font-mono" dir="ltr">{{ $payload['url'] ?? '—' }}</dd></div>
            @if(! empty($payload['agent']))
                <div class="sm:col-span-2"><dt class="inline font-bold">مرورگر:</dt> <dd class="inline font-mono break-all" dir="ltr">{{ $payload['agent'] }}</dd></div>
            @endif
        </dl>
        <div class="p-4 bg-paper border border-gold text-sm text-ink leading-relaxed whitespace-pre-line break-words">{{ $suggestion->suggested_value }}</div>
        @if(! empty($payload['files']))
            <div class="grid grid-cols-2 gap-3">
                @foreach($payload['files'] as $i => $path)
                    <a href="{{ route('notes.file', [$suggestion, $i]) }}" target="_blank"><img src="{{ route('notes.file', [$suggestion, $i]) }}" alt="تصویر پیوست {{ $i + 1 }}" class="w-full rounded-sm border border-line-strong"></a>
                @endforeach
            </div>
        @endif
    </div>

    <div class="space-y-6">
        @if($suggestion->status === 'pending')
            <div class="p-4 sm:p-5 bg-surface border border-emerald-600/40 space-y-3">
                <h3 class="rubric text-base !text-emerald-800 dark:!text-emerald-300">رسیدگی شد</h3>
                <form action="{{ route('admin.suggestions.resolve', $suggestion) }}" method="POST" class="space-y-3">
                    @csrf
                    <textarea name="public_note" rows="3" placeholder="پاسخ به فرستنده (برای او فرستاده می‌شود؛ اختیاری)" class="{{ $input }}"></textarea>
                    <input type="text" name="admin_notes" placeholder="یادداشت داخلی (فقط داوران)" class="{{ $input }}">
                    <button type="submit" class="w-full py-2.5 px-4 rounded-sm bg-emerald-700 hover:bg-emerald-800 text-white font-bold transition cursor-pointer">ثبت «رسیدگی شد»</button>
                </form>
            </div>
            <div class="p-4 sm:p-5 bg-surface border border-cinnabar/40 space-y-3">
                <h3 class="rubric text-base">رد</h3>
                <form action="{{ route('admin.suggestions.reject', $suggestion) }}" method="POST" class="space-y-3">
                    @csrf
                    <textarea name="public_note" rows="2" placeholder="توضیح برای فرستنده (اختیاری)" class="{{ $input }}"></textarea>
                    <input type="text" name="admin_notes" placeholder="یادداشت داخلی (فقط داوران)" class="{{ $input }}">
                    <button type="submit" onclick="return confirm('این بازخورد رد شود؟')" class="w-full py-2.5 px-4 rounded-sm border border-cinnabar text-cinnabar hover:bg-cinnabar/5 font-bold transition cursor-pointer">رد بازخورد</button>
                </form>
            </div>
        @else
            <div class="p-4 sm:p-5 bg-paper border border-line-strong text-xs space-y-2">
                <p><span class="font-bold">پاسخ به فرستنده:</span> <span class="whitespace-pre-line">{{ $suggestion->public_note ?: '—' }}</span></p>
                <p><span class="font-bold">یادداشت داخلی:</span> {{ $suggestion->admin_notes ?: '—' }}</p>
            </div>
        @endif
    </div>
</div>

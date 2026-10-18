{{-- Review of site feedback: the page it came from, the message, screenshots; resolve with an answer, reject, or convert (see partials/convert) --}}
@php
    $payload = $suggestion->payload ?? [];
    $input = 'w-full p-2.5 rounded-xl border border-line-strong bg-paper text-ink';
@endphp
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 p-6 rounded-2xl bg-surface border border-line shadow-xs space-y-4 text-xs">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-sm font-bold text-ink">بازخورد سایت: {{ $suggestion->field_label }}</h3>
            @if($suggestion->place_url)
                <a href="{{ $suggestion->place_url }}" target="_blank" class="font-bold text-cinnabar hover:underline">صفحه‌ی گزارش‌شده ↗</a>
            @endif
        </div>
        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-stone-500">
            <div><dt class="inline font-bold">صفحه:</dt> <dd class="inline">{{ $payload['title'] ?? '—' }}</dd></div>
            <div><dt class="inline font-bold">نشانی:</dt> <dd class="inline font-mono" dir="ltr">{{ $payload['url'] ?? '—' }}</dd></div>
            @if(! empty($payload['agent']))
                <div class="sm:col-span-2"><dt class="inline font-bold">مرورگر:</dt> <dd class="inline font-mono break-all" dir="ltr">{{ $payload['agent'] }}</dd></div>
            @endif
        </dl>
        <div class="p-4 rounded-xl bg-amber-500/10 border border-amber-500/20 text-sm text-stone-900 dark:text-amber-100 leading-relaxed whitespace-pre-line break-words">{{ $suggestion->suggested_value }}</div>
        @if(! empty($payload['files']))
            <div class="grid grid-cols-2 gap-3">
                @foreach($payload['files'] as $i => $path)
                    <a href="{{ route('notes.file', [$suggestion, $i]) }}" target="_blank"><img src="{{ route('notes.file', [$suggestion, $i]) }}" alt="تصویر پیوست {{ $i + 1 }}" class="w-full rounded-lg border border-line-strong"></a>
                @endforeach
            </div>
        @endif
    </div>

    <div class="space-y-6">
        @if($suggestion->status === 'pending')
            <div class="p-6 rounded-2xl bg-surface border border-emerald-500/30 shadow-xs space-y-3 text-xs">
                <h3 class="text-sm font-bold text-emerald-700 dark:text-emerald-400">رسیدگی شد</h3>
                <form action="{{ route('admin.suggestions.resolve', $suggestion) }}" method="POST" class="space-y-3">
                    @csrf
                    <textarea name="public_note" rows="3" placeholder="پاسخ به فرستنده (برای او فرستاده می‌شود؛ اختیاری)" class="{{ $input }}"></textarea>
                    <input type="text" name="admin_notes" placeholder="یادداشت داخلی (فقط داوران)" class="{{ $input }}">
                    <button type="submit" class="w-full py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold">ثبت «رسیدگی شد»</button>
                </form>
            </div>
            <div class="p-6 rounded-2xl bg-surface border border-red-500/30 shadow-xs space-y-3 text-xs">
                <h3 class="text-sm font-bold text-red-600 dark:text-red-400">رد</h3>
                <form action="{{ route('admin.suggestions.reject', $suggestion) }}" method="POST" class="space-y-3">
                    @csrf
                    <textarea name="public_note" rows="2" placeholder="توضیح برای فرستنده (اختیاری)" class="{{ $input }}"></textarea>
                    <input type="text" name="admin_notes" placeholder="یادداشت داخلی (فقط داوران)" class="{{ $input }}">
                    <button type="submit" onclick="return confirm('این بازخورد رد شود؟')" class="w-full py-2.5 rounded-xl border border-red-500 text-red-600 hover:bg-red-50 dark:hover:bg-red-950/20 font-bold">رد بازخورد</button>
                </form>
            </div>
        @else
            <div class="p-6 rounded-2xl bg-paper border border-line-strong text-xs space-y-2">
                <p><span class="font-bold">پاسخ به فرستنده:</span> <span class="whitespace-pre-line">{{ $suggestion->public_note ?: '—' }}</span></p>
                <p><span class="font-bold">یادداشت داخلی:</span> {{ $suggestion->admin_notes ?: '—' }}</p>
            </div>
        @endif
    </div>
</div>

{{-- Review of site feedback: the page it came from, the message, screenshots; resolve with an answer, reject, or convert
     (see partials/convert). $part 'main' is the message in the desk's main column, 'panel' the decision (step 14.72). --}}
@php
    $payload = $suggestion->payload ?? [];
    $input = 'admin-field w-full';
@endphp
@if($part === 'main')
    <x-ui.card class="space-y-4">
        <h3 class="rubric text-base">بازخورد سایت: {{ $suggestion->field_label }}</h3>
        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-muted">
            <div><dt class="inline font-bold">صفحه:</dt> <dd class="inline">{{ $payload['title'] ?? '—' }}</dd></div>
            <div><dt class="inline font-bold">نشانی:</dt> <dd class="inline font-mono" dir="ltr">{{ $payload['url'] ?? '—' }}</dd></div>
            @if(! empty($payload['agent']))
                <div class="sm:col-span-2"><dt class="inline font-bold">مرورگر:</dt> <dd class="inline font-mono break-all" dir="ltr">{{ $payload['agent'] }}</dd></div>
            @endif
        </dl>
        <div class="p-4 bg-paper border border-gold text-sm text-ink leading-relaxed whitespace-pre-line break-words">{{ $suggestion->suggested_value }}</div>
        @foreach(['section' => 'درباره‌ی نمودار', 'question' => 'برای پاسخ به چه پرسشی', 'example' => 'نمونه یا توضیح'] as $key => $label)
            @if(! empty($payload[$key]))
                <p class="text-ink leading-relaxed whitespace-pre-line break-words"><span class="font-bold text-muted">{{ $label }}:</span> {{ $payload[$key] }}</p>
            @endif
        @endforeach
        @if(! empty($payload['files']))
            <div class="grid grid-cols-2 gap-3">
                @foreach($payload['files'] as $i => $path)
                    <a href="{{ route('notes.file', [$suggestion, $i]) }}" target="_blank"><img src="{{ route('notes.file', [$suggestion, $i]) }}" alt="تصویر پیوست {{ $i + 1 }}" class="w-full rounded-sm border border-line-strong"></a>
                @endforeach
            </div>
        @endif
    </x-ui.card>
@elseif($suggestion->status === 'pending')
    <x-admin.decision :suggestion="$suggestion" approve-label="رسیدگی شد">
        <x-slot:approve>
            <form action="{{ route('admin.suggestions.resolve', $suggestion) }}" method="POST" class="space-y-3">
                @csrf
                <textarea name="public_note" rows="3" placeholder="پاسخ به فرستنده (برای او فرستاده می‌شود؛ اختیاری)" aria-label="پاسخ به فرستنده" class="{{ $input }}"></textarea>
                <input type="text" name="admin_notes" placeholder="یادداشت داخلی (فقط داوران)" aria-label="یادداشت داخلی" class="{{ $input }}">
                <p class="px-3 py-2 bg-paper border border-line text-xs text-ink leading-6" data-approve-summary><b>با این کار:</b> بازخورد «رسیدگی‌شده» ثبت می‌شود و پاسخ، اگر نوشته شود، برای فرستنده می‌رود.</p>
                <button type="submit" class="w-full py-2.5 px-4 rounded-sm bg-emerald-700 hover:bg-emerald-800 text-white font-bold transition cursor-pointer">ثبت «رسیدگی شد»</button>
            </form>
        </x-slot:approve>
        <x-slot:reject>
            <form action="{{ route('admin.suggestions.reject', $suggestion) }}" method="POST" class="space-y-3">
                @csrf
                <textarea name="public_note" rows="2" placeholder="توضیح برای فرستنده (اختیاری)" aria-label="توضیح برای فرستنده" class="{{ $input }}"></textarea>
                <input type="text" name="admin_notes" placeholder="یادداشت داخلی (فقط داوران)" aria-label="یادداشت داخلی" class="{{ $input }}">
                <button type="submit" onclick="return confirm('این بازخورد رد شود؟')" class="w-full py-2.5 px-4 rounded-sm border border-cinnabar text-cinnabar hover:bg-cinnabar/5 font-bold transition cursor-pointer">رد بازخورد</button>
            </form>
        </x-slot:reject>
    </x-admin.decision>
@else
    <x-ui.card class="space-y-2 !border-line-strong text-xs">
        <h3 class="font-naskh text-lg font-bold text-ink">نتیجه‌ی داوری</h3>
        <p><span class="font-bold">پاسخ به فرستنده:</span> <span class="whitespace-pre-line">{{ $suggestion->public_note ?: '—' }}</span></p>
        <p><span class="font-bold">یادداشت داخلی:</span> {{ $suggestion->admin_notes ?: '—' }}</p>
        <p class="text-muted">داوری به دست {{ $suggestion->reviewer?->name ?: 'سامانه' }}، @jdate($suggestion->reviewed_at, 'datetime')</p>
    </x-ui.card>
@endif

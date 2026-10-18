{{-- Review of a research note: the reviewer can correct its fields, drop uploads, publish, edit later or withdraw --}}
@php
    $definition = \App\Suggestions\NoteTypes::TYPES[$suggestion->field_name] ?? null;
    $payload = $suggestion->payload ?? [];
    $original = $suggestion->resolution['original_payload'] ?? null;
    $input = 'w-full p-2.5 rounded-xl border border-line-strong bg-paper text-ink';
    $statusLabel = ['pending' => 'در انتظار داوری', 'approved' => 'منتشرشده', 'withdrawn' => 'برداشته‌شده از صفحه', 'rejected' => 'ردشده'][$suggestion->status] ?? $suggestion->status;
@endphp
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 p-6 rounded-2xl bg-surface border border-emerald-500/30 shadow-xs space-y-4 text-xs">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-sm font-bold text-ink">یادداشت پژوهشی: {{ $definition['label'] ?? $suggestion->field_name }}</h3>
            <span class="px-2.5 py-0.5 rounded-full bg-stone-100 dark:bg-stone-800 text-ink/80 font-bold">{{ $statusLabel }}</span>
        </div>

        @if($errors->any())
            <div class="p-3 rounded-xl bg-red-50 dark:bg-red-950/30 border border-red-300 dark:border-red-800 text-red-700 dark:text-red-300 font-bold">{{ $errors->first() }}</div>
        @endif

        @if($definition && $suggestion->status !== 'rejected')
            <form action="{{ route('admin.suggestions.note', $suggestion) }}" method="POST" class="space-y-3">
                @csrf
                @foreach($definition['fields'] as $field)
                    <div class="space-y-1">
                        <label class="block font-bold text-ink">
                            {{ \App\Suggestions\NoteTypes::FIELDS[$field] }}@if(in_array($field, $definition['required'], true))<span class="text-red-600"> *</span>@endif
                        </label>
                        @if($field === 'text')
                            <textarea name="note[text]" rows="5" class="{{ $input }}">{{ old('note.text', $payload['text'] ?? '') }}</textarea>
                        @else
                            <input type="text" name="note[{{ $field }}]" value="{{ old("note.$field", $payload[$field] ?? '') }}" @if($field === 'url') dir="ltr" @endif class="{{ $input }}">
                        @endif
                    </div>
                @endforeach

                @if(! empty($payload['files']))
                    <div class="space-y-2">
                        <span class="block font-bold text-ink">تصاویر بارگذاری‌شده:</span>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                            @foreach($payload['files'] as $i => $path)
                                <label class="block space-y-1">
                                    <a href="{{ route('notes.file', [$suggestion, $i]) }}" target="_blank"><img src="{{ route('notes.file', [$suggestion, $i]) }}" alt="تصویر {{ $i + 1 }}" class="w-full h-28 object-cover rounded-lg border border-line-strong"></a>
                                    <span class="flex items-center gap-1 text-red-600"><input type="checkbox" name="remove_files[]" value="{{ $i }}" class="rounded"> حذف این تصویر</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="space-y-1">
                    <label class="block font-bold text-ink">یادداشت عمومی <span class="font-normal text-stone-400">(پیشنهاددهنده آن را می‌بیند و زیر یادداشت در صفحه نمایش داده می‌شود؛ اختیاری)</span>:</label>
                    <textarea name="public_note" rows="2" class="{{ $input }}">{{ old('public_note', $suggestion->public_note) }}</textarea>
                </div>
                <div class="space-y-1">
                    <label class="block font-bold text-ink">یادداشت داخلی <span class="font-normal text-stone-400">(فقط داوران)</span>:</label>
                    <input type="text" name="admin_notes" value="{{ old('admin_notes', $suggestion->admin_notes) }}" class="{{ $input }}">
                </div>

                <button type="submit" class="w-full py-3 px-4 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm shadow-md transition">
                    {{ $suggestion->status === 'pending' ? 'تأیید و انتشار در صفحه‌ی رکورد' : ($suggestion->status === 'withdrawn' ? 'ذخیره و انتشار دوباره' : 'ذخیره‌ی ویرایش') }}
                </button>
            </form>
        @else
            <div class="p-4 rounded-xl bg-stone-50 dark:bg-stone-900 whitespace-pre-line">{{ $suggestion->suggested_value }}</div>
        @endif

        @if($original && $original != $payload)
            <details class="text-stone-500">
                <summary class="cursor-pointer font-bold">متن اصلی پژوهشگر</summary>
                <dl class="mt-2 space-y-1">
                    @foreach($original as $key => $value)
                        @continue($key === 'files')
                        <div><dt class="inline font-bold">{{ \App\Suggestions\NoteTypes::FIELDS[$key] ?? $key }}:</dt> <dd class="inline whitespace-pre-line">{{ $value }}</dd></div>
                    @endforeach
                </dl>
            </details>
        @endif
    </div>

    <div class="space-y-6">
        @if($suggestion->status === 'pending')
            <div class="p-6 rounded-2xl bg-surface border border-red-500/30 shadow-xs space-y-3 text-xs">
                <h3 class="text-sm font-bold text-red-600 dark:text-red-400">رد یادداشت</h3>
                <form action="{{ route('admin.suggestions.reject', $suggestion) }}" method="POST" class="space-y-3">
                    @csrf
                    <textarea name="public_note" rows="3" placeholder="علت رد (پیشنهاددهنده می‌بیند)" class="{{ $input }}"></textarea>
                    <textarea name="admin_notes" rows="2" placeholder="یادداشت داخلی (فقط داوران)" class="{{ $input }}"></textarea>
                    <p class="text-stone-400">تصاویر بارگذاری‌شده‌ی یادداشت ردشده از سرور پاک می‌شوند.</p>
                    <button type="submit" onclick="return confirm('این یادداشت رد شود؟')" class="w-full py-2.5 rounded-xl border border-red-500 text-red-600 hover:bg-red-50 dark:hover:bg-red-950/20 font-bold">رد یادداشت</button>
                </form>
            </div>
        @elseif($suggestion->status === 'approved')
            <div class="p-6 rounded-2xl bg-surface border border-line shadow-xs space-y-3 text-xs">
                <h3 class="text-sm font-bold text-ink">برداشتن از صفحه</h3>
                <p class="text-stone-500">مثلاً وقتی پیوند یادداشت از کار افتاده است. بعداً می‌توان آن را دوباره منتشر کرد.</p>
                <form action="{{ route('admin.suggestions.withdraw', $suggestion) }}" method="POST" class="space-y-3">
                    @csrf
                    <input type="text" name="admin_notes" placeholder="علت (یادداشت داخلی)" class="{{ $input }}">
                    <button type="submit" onclick="return confirm('این یادداشت از صفحه‌ی رکورد برداشته شود؟')" class="w-full py-2.5 rounded-xl border border-stone-400 text-ink/80 hover:bg-stone-50 dark:hover:bg-stone-800 font-bold">برداشتن از صفحه</button>
                </form>
            </div>
        @endif
        @if($suggestion->rationale_citation)
            <div class="p-6 rounded-2xl bg-surface border border-line text-xs space-y-2">
                <h4 class="font-bold text-ink">توضیح پژوهشگر:</h4>
                <p class="whitespace-pre-line text-ink/80">{{ $suggestion->rationale_citation }}</p>
            </div>
        @endif
    </div>
</div>

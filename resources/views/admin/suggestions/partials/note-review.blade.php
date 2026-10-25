{{-- Review of a research note: the reviewer can correct its fields, drop uploads, publish, edit later or withdraw --}}
@php
    $definition = \App\Suggestions\NoteTypes::TYPES[$suggestion->field_name] ?? null;
    $payload = $suggestion->payload ?? [];
    $original = $suggestion->resolution['original_payload'] ?? null;
    $input = 'admin-field w-full';
    $statusLabel = ['pending' => 'در انتظار داوری', 'approved' => 'منتشرشده', 'withdrawn' => 'برداشته‌شده از صفحه', 'rejected' => 'ردشده'][$suggestion->status] ?? $suggestion->status;
@endphp
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    <div class="lg:col-span-2 p-4 sm:p-5 bg-surface border border-emerald-600/40 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="rubric text-base">یادداشت پژوهشی: {{ $definition['label'] ?? $suggestion->field_name }}</h3>
            <span class="px-2.5 py-0.5 rounded-full border border-line bg-paper text-ink">{{ $statusLabel }}</span>
        </div>

        @if($errors->any())
            <div class="px-4 py-3 bg-surface border border-s-4 border-cinnabar/50 text-cinnabar font-bold">{{ $errors->first() }}</div>
        @endif

        @if($definition && $suggestion->status !== 'rejected')
            <form action="{{ route('admin.suggestions.note', $suggestion) }}" method="POST" class="space-y-3">
                @csrf
                @foreach($definition['fields'] as $field)
                    <div class="space-y-1">
                        <label class="block font-bold text-ink">
                            {{ \App\Suggestions\NoteTypes::FIELDS[$field] }}@if(in_array($field, $definition['required'], true))<span class="text-cinnabar"> *</span>@endif
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
                                    <a href="{{ route('notes.file', [$suggestion, $i]) }}" target="_blank"><img src="{{ route('notes.file', [$suggestion, $i]) }}" alt="تصویر {{ $i + 1 }}" class="w-full h-28 object-cover rounded-sm border border-line-strong"></a>
                                    <span class="flex items-center gap-1 text-cinnabar"><input type="checkbox" name="remove_files[]" value="{{ $i }}" class="accent-cinnabar"> حذف این تصویر</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="space-y-1">
                    <label class="block font-bold text-ink">یادداشت عمومی <span class="font-normal text-muted">(پیشنهاددهنده آن را می‌بیند و زیر یادداشت در صفحه نمایش داده می‌شود؛ اختیاری)</span>:</label>
                    <textarea name="public_note" rows="2" class="{{ $input }}">{{ old('public_note', $suggestion->public_note) }}</textarea>
                </div>
                <div class="space-y-1">
                    <label class="block font-bold text-ink">یادداشت داخلی <span class="font-normal text-muted">(فقط داوران)</span>:</label>
                    <input type="text" name="admin_notes" value="{{ old('admin_notes', $suggestion->admin_notes) }}" class="{{ $input }}">
                </div>

                <button type="submit" class="w-full py-2.5 px-4 rounded-sm bg-emerald-700 hover:bg-emerald-800 text-white font-bold transition cursor-pointer">
                    {{ $suggestion->status === 'pending' ? 'تأیید و انتشار در صفحه‌ی رکورد' : ($suggestion->status === 'withdrawn' ? 'ذخیره و انتشار دوباره' : 'ذخیره‌ی ویرایش') }}
                </button>
            </form>
        @else
            <div class="p-4 bg-paper border border-line whitespace-pre-line leading-7">{{ $suggestion->suggested_value }}</div>
        @endif

        @if($original && $original != $payload)
            <details class="text-muted">
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
            <div class="p-4 sm:p-5 bg-surface border border-cinnabar/40 space-y-3">
                <h3 class="rubric text-base">رد یادداشت</h3>
                <form action="{{ route('admin.suggestions.reject', $suggestion) }}" method="POST" class="space-y-3">
                    @csrf
                    <textarea name="public_note" rows="3" placeholder="علت رد (پیشنهاددهنده می‌بیند)" class="{{ $input }}"></textarea>
                    <textarea name="admin_notes" rows="2" placeholder="یادداشت داخلی (فقط داوران)" class="{{ $input }}"></textarea>
                    <p class="text-muted">تصاویر بارگذاری‌شده‌ی یادداشت ردشده از سرور پاک می‌شوند.</p>
                    <button type="submit" onclick="return confirm('این یادداشت رد شود؟')" class="w-full py-2.5 px-4 rounded-sm border border-cinnabar text-cinnabar hover:bg-cinnabar/5 font-bold transition cursor-pointer">رد یادداشت</button>
                </form>
            </div>
        @elseif($suggestion->status === 'approved')
            <div class="p-4 sm:p-5 bg-surface border border-line space-y-3">
                <h3 class="rubric text-base">برداشتن از صفحه</h3>
                <p class="text-muted">مثلاً وقتی پیوند یادداشت از کار افتاده است. بعداً می‌توان آن را دوباره منتشر کرد.</p>
                <form action="{{ route('admin.suggestions.withdraw', $suggestion) }}" method="POST" class="space-y-3">
                    @csrf
                    <input type="text" name="admin_notes" placeholder="علت (یادداشت داخلی)" class="{{ $input }}">
                    <button type="submit" onclick="return confirm('این یادداشت از صفحه‌ی رکورد برداشته شود؟')" class="w-full py-2.5 px-4 rounded-sm border border-line-strong text-ink hover:border-cinnabar hover:text-cinnabar font-bold transition cursor-pointer">برداشتن از صفحه</button>
                </form>
            </div>
        @endif
        @if($suggestion->rationale_citation)
            <div class="p-4 sm:p-5 bg-surface border border-line space-y-2">
                <h3 class="rubric text-base">توضیح پژوهشگر</h3>
                <p class="whitespace-pre-line text-ink">{{ $suggestion->rationale_citation }}</p>
            </div>
        @endif
    </div>
</div>

{{-- Review of a research note: the reviewer can correct its fields, drop uploads, publish, edit later or withdraw.
     $part 'main' is the note in the desk's main column, 'panel' the decision beside it (step 14.72); the note's fields
     belong to the form in the panel through form="note-form". --}}
@php
    $definition = \App\Suggestions\NoteTypes::TYPES[$suggestion->field_name] ?? null;
    $payload = $suggestion->payload ?? [];
    $original = $suggestion->resolution['original_payload'] ?? null;
    $input = 'admin-field w-full';
    $editable = $definition && $suggestion->status !== 'rejected';
@endphp
@if($part === 'main')
    <x-ui.card class="space-y-4">
        @if($errors->any())
            <div class="px-4 py-3 bg-surface border border-s-4 border-cinnabar/50 text-cinnabar font-bold">{{ $errors->first() }}</div>
        @endif

        @if($editable)
            <p class="text-xs text-muted">متن یادداشت پیش از انتشار ویرایش‌پذیر است.</p>
            @foreach($definition['fields'] as $noteField)
                <div class="space-y-1">
                    <label for="note-{{ $noteField }}" class="block font-bold text-ink">
                        {{ \App\Suggestions\NoteTypes::FIELDS[$noteField] }}@if(in_array($noteField, $definition['required'], true))<span class="text-cinnabar"> *</span>@endif
                    </label>
                    @if($noteField === 'text')
                        <textarea id="note-{{ $noteField }}" form="note-form" name="note[text]" rows="6" class="{{ $input }} leading-7">{{ old('note.text', $payload['text'] ?? '') }}</textarea>
                    @else
                        <input id="note-{{ $noteField }}" form="note-form" type="text" name="note[{{ $noteField }}]" value="{{ old("note.$noteField", $payload[$noteField] ?? '') }}" @if($noteField === 'url') dir="ltr" @endif class="{{ $input }}">
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
                                <span class="flex items-center gap-1 text-cinnabar"><input type="checkbox" form="note-form" name="remove_files[]" value="{{ $i }}" class="accent-cinnabar"> حذف این تصویر</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif
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
    </x-ui.card>
@else
    @if($suggestion->status === 'pending')
        <x-admin.decision :suggestion="$suggestion" approve-label="انتشار">
            <x-slot:approve>
                @if($editable)
                    @include('admin.suggestions.partials.note-form', ['button' => 'تأیید و انتشار در صفحه‌ی رکورد'])
                @endif
            </x-slot:approve>
            <x-slot:reject>
                <form action="{{ route('admin.suggestions.reject', $suggestion) }}" method="POST" class="space-y-3">
                    @csrf
                    <textarea name="public_note" rows="3" placeholder="علت رد (پیشنهاددهنده می‌بیند)" aria-label="علت رد" class="{{ $input }}"></textarea>
                    <textarea name="admin_notes" rows="2" placeholder="یادداشت داخلی (فقط داوران)" aria-label="یادداشت داخلی" class="{{ $input }}"></textarea>
                    <p class="text-xs text-muted">تصاویر بارگذاری‌شده‌ی یادداشت ردشده از سرور پاک می‌شوند.</p>
                    <button type="submit" onclick="return confirm('این یادداشت رد شود؟')" class="w-full py-2.5 px-4 rounded-sm border border-cinnabar text-cinnabar hover:bg-cinnabar/5 font-bold transition cursor-pointer">رد یادداشت</button>
                </form>
            </x-slot:reject>
        </x-admin.decision>
    @else
        <x-ui.card class="space-y-3 !border-line-strong">
            <h3 class="font-naskh text-lg font-bold text-ink">نتیجه‌ی داوری</h3>
            <p class="text-xs text-muted">داوری به دست {{ $suggestion->reviewer?->name ?: 'سامانه' }}، @jdate($suggestion->reviewed_at, 'datetime')</p>
            @if($editable)
                @include('admin.suggestions.partials.note-form', ['button' => $suggestion->status === 'withdrawn' ? 'ذخیره و انتشار دوباره' : 'ذخیره‌ی ویرایش'])
            @else
                <p><span class="font-bold">یادداشت عمومی:</span> <span class="whitespace-pre-line">{{ $suggestion->public_note ?: '—' }}</span></p>
                <p><span class="font-bold">یادداشت داخلی:</span> {{ $suggestion->admin_notes ?: '—' }}</p>
            @endif
        </x-ui.card>
        @if($suggestion->status === 'approved')
            <x-ui.card class="space-y-3">
                <h3 class="rubric text-base">برداشتن از صفحه</h3>
                <p class="text-muted">مثلاً وقتی پیوند یادداشت از کار افتاده است. بعداً می‌توان آن را دوباره منتشر کرد.</p>
                <form action="{{ route('admin.suggestions.withdraw', $suggestion) }}" method="POST" class="space-y-3">
                    @csrf
                    <input type="text" name="admin_notes" placeholder="علت (یادداشت داخلی)" aria-label="علت برداشتن" class="{{ $input }}">
                    <button type="submit" onclick="return confirm('این یادداشت از صفحه‌ی رکورد برداشته شود؟')" class="w-full py-2.5 px-4 rounded-sm border border-line-strong text-ink hover:border-cinnabar hover:text-cinnabar font-bold transition cursor-pointer">برداشتن از صفحه</button>
                </form>
            </x-ui.card>
        @endif
        @if(in_array($suggestion->status, ['approved', 'rejected'], true))
            {{-- Takes back the decision, e.g. to ask the submitter about the note before deciding again --}}
            <x-ui.card class="space-y-3">
                <h3 class="rubric text-base">بازگشت به صف داوری</h3>
                <p class="text-muted">
                    @if($suggestion->status === 'approved')
                        یادداشت تا داوری دوباره از صفحه‌ی رکورد برداشته می‌شود و می‌توان از پیشنهاددهنده پرسید. پیام قبلی به پیشنهاددهنده پس گرفته نمی‌شود.
                    @else
                        اگر رد به اشتباه بوده، یادداشت دوباره در انتظار داوری قرار می‌گیرد. پرونده‌های پیوست هنگام رد حذف شده‌اند.
                    @endif
                </p>
                <form action="{{ route('admin.suggestions.reopen', $suggestion) }}" method="POST">
                    @csrf
                    <button type="submit" onclick="return confirm('این یادداشت به صف داوری بازگردد؟')" class="w-full py-2.5 px-4 rounded-sm border border-line-strong text-ink hover:border-cinnabar hover:text-cinnabar font-bold transition cursor-pointer">{{ $suggestion->status === 'rejected' ? 'لغو رد و بازگشت به صف داوری' : 'لغو تأیید و بازگشت به صف داوری' }}</button>
                </form>
            </x-ui.card>
        @endif
    @endif
@endif

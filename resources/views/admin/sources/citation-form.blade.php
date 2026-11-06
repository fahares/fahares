@extends('layouts.admin')

@php
    $fa = fn ($n) => \App\Support\Fa::num($n);
    $preview = session('citation_preview');
    $o = (array) ($form->options ?? []);
    $sent = $preview || $errors->any();
    $val = fn (string $key, $default) => $sent ? old($key, $default) : $default;
    $isSuper = auth()->user()->role === \App\Auth\Permissions::SUPER_ROLE;
    $codes = $sources->pluck('title', 'code');
    $sourceName = fn ($code) => $codes[$code] ?? $code;
    $series = collect($o['series_volumes'] ?? [])->map(fn ($to, $from) => "{$from}:{$to}")->implode('، ');
    $title = $form->exists ? 'الگوی ارجاع' : 'الگوی ارجاع تازه';
@endphp

@section('title', $title . ' — ' . $source->title)
@section('page_title', $title)
@section('breadcrumb')
    <a href="{{ route('admin.sources.index') }}" class="hover:text-cinnabar">منابع</a>
    <span>/</span>
    <a href="{{ route('admin.sources.show', $source) }}" class="hover:text-cinnabar">{{ \Illuminate\Support\Str::limit($source->title, 60) }}</a>
    <span>/</span>
    <span>{{ $form->exists ? '#' . \App\Support\Fa::digits($form->id) : 'تازه' }}</span>
@endsection

@section('content')
<div class="space-y-6 text-[13px] max-w-4xl">
    <x-admin.notice class="text-xs">
        این الگو می‌گوید «{{ $source->short_name ?: $source->title }}» در منبعی دیگر چگونه ارجاع داده می‌شود: با چه نام‌هایی، و جلد و صفحه‌اش چگونه خوانده می‌شود.
        الگوی «هر منبع» در مآخذ آثار فنخا و در متن صفحه‌های همه‌ی منابع به کار می‌رود. الگوی ویژه‌ی یک منبع، فقط در همان منبع و بر الگوی «هر منبع» برتری دارد.
        هر تغییر را پیش از ذخیره با «پیش‌نمایش اثر» ببینید.
    </x-admin.notice>

    @if($waiting = \App\Sources\CitationForms::awaitingImport())
        <x-admin.notice tone="warn" class="text-xs">
            الگوهای پیشین هنوز از <span dir="ltr">config/bibliography.php</span> به جدول نیامده‌اند (<span dir="ltr">{{ implode('، ', $waiting) }}</span>).
            تا دستور <span dir="ltr">sources:import-citation-forms</span> اجرا نشود، الگو ذخیره نمی‌شود.
        </x-admin.notice>
    @endif

    @if($preview)
        @include('admin.sources.partials.citation-effect', ['effect' => $preview, 'done' => false])
    @endif

    <x-ui.card>
        <form action="{{ $form->exists ? route('admin.sources.citations.update', [$source, $form]) : route('admin.sources.citations.store', $source) }}" method="POST" class="space-y-5">
            @csrf
            @if($form->exists) @method('PUT') @endif

            <div class="grid gap-4 md:grid-cols-2">
                <x-admin.field label="منبع ارجاع‌دهنده" for="citing_source_id" error="citing_source_id" hint="منبعی که ارجاع در آن است. «هر منبع» یعنی الگوی پیش‌فرض.">
                    <select name="citing_source_id" id="citing_source_id" class="admin-field w-full">
                        <option value="">هر منبع</option>
                        @foreach($sources as $s)
                            @continue($s->id === $source->id)
                            <option value="{{ $s->id }}" @selected((int) $val('citing_source_id', $form->citing_source_id) === $s->id)>{{ $s->short_name ?: $s->title }}</option>
                        @endforeach
                    </select>
                </x-admin.field>
                <x-admin.field label="جای ارجاع" for="context" error="context" hint="«متن صفحه‌ها» برای منبعی است که متن صفحه‌هایش روی سایت است، مانند الذریعة.">
                    <select name="context" id="context" class="admin-field w-full">
                        @foreach(\App\Models\SourceCitationForm::CONTEXTS as $key => $label)
                            <option value="{{ $key }}" @selected($val('context', $form->context) === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </x-admin.field>
            </div>

            <x-admin.field label="نام‌ها" for="names" error="names"
                           hint="هر نام در یک سطر، همان‌گونه که منبع ارجاع‌دهنده می‌نویسد: «دنا»، «فهرستواره دستنوشته‌های ایران». ی و ي، ک و ك، ة و ه، همزه و نیم‌فاصله یکسان خوانده می‌شوند. نام بلندتر بر کوتاه‌تر برتری دارد.">
                <textarea name="names" id="names" rows="4" class="admin-field w-full leading-7">{{ $val('names', implode("\n", (array) $form->names)) }}</textarea>
            </x-admin.field>

            @if($isSuper || $form->regex)
                <x-admin.field label="عبارت باقاعده (اختیاری)" for="regex" error="regex"
                               :hint="$isSuper ? 'اگر پر باشد به‌جای نام‌ها به کار می‌رود. روی متن یکسان‌شده سنجیده می‌شود: «ه» به‌جای «ة»، «ا» به‌جای «أ».' : 'فقط مدیر کل آن را تغییر می‌دهد.'">
                    <input type="text" name="regex" id="regex" value="{{ $val('regex', $form->regex) }}" dir="ltr" class="admin-field w-full font-mono text-xs" @disabled(! $isSuper)>
                </x-admin.field>
            @endif

            <div class="grid gap-4 md:grid-cols-3">
                <x-admin.field label="شمار جلد" for="volumes" error="volumes" :hint="'خالی: شمار جلدهای منبع (' . $fa($source->volumes_count) . ').'">
                    <input type="number" min="1" name="volumes" id="volumes" value="{{ $val('volumes', $o['volumes'] ?? null) }}" class="admin-field w-full" dir="ltr">
                </x-admin.field>
                <x-admin.field label="شماره‌ی سری جلدها" for="series_volumes" error="series_volumes" hint="شماره‌ای که منبع ارجاع‌دهنده می‌نویسد و جلد خود منبع، مانند «۳:۱، ۴:۲».">
                    <input type="text" name="series_volumes" id="series_volumes" value="{{ $val('series_volumes', $series) }}" class="admin-field w-full" dir="ltr">
                </x-admin.field>
                <div class="space-y-2 pt-6">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="hidden" name="column" value="0">
                        <input type="checkbox" name="column" value="1" class="accent-cinnabar" @checked($val('column', ($o['unit'] ?? null) === 'ستون'))>
                        ستون‌شمار است، نه صفحه‌شمار
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="hidden" name="pairs_after_name" value="0">
                        <input type="checkbox" name="pairs_after_name" value="1" class="accent-cinnabar" @checked($val('pairs_after_name', ! empty($o['pairs_after_name'])))>
                        پس از نام، جلد پیش از صفحه می‌آید: «۱: ۲۵۴»، «۳-۲۴۵»
                    </label>
                </div>
            </div>
            <p class="text-xs text-muted leading-6 -mt-2">
                ریخت‌های «صفحه/جلد» (۸۰۹/۱)، «ج ۱، ص ۱۸۵» و «ج ۲ ستون ۱۵۱۱» همیشه خوانده می‌شوند، و «جلد/صفحه» هم وقتی فقط همان جا بیفتد.
                صفحه‌ی تنها پس از نام، در منبع یک‌جلدی یا منبعی که صفحه‌هایش در جلدها پیوسته است، جلدش را خودش می‌گوید.
                @if(! empty($o['other_edition'])) این الگو چاپ دیگری را هم کنار می‌گذارد (<span dir="ltr">{{ $o['other_edition'] }}</span>)، که از اینجا تغییر نمی‌کند. @endif
            </p>

            <div class="grid gap-4 md:grid-cols-2">
                <x-admin.field label="نمونه‌ها" for="examples" hint="چند ارجاع واقعی، هر کدام در یک سطر، برای وارسی بعدی.">
                    <textarea name="examples" id="examples" rows="3" class="admin-field w-full">{{ $val('examples', implode("\n", (array) $form->examples)) }}</textarea>
                </x-admin.field>
                <x-admin.field label="یادداشت" for="notes">
                    <textarea name="notes" id="notes" rows="3" class="admin-field w-full">{{ $val('notes', $form->notes) }}</textarea>
                </x-admin.field>
            </div>

            <div class="pt-3 flex flex-wrap items-center justify-between gap-2 border-t border-line">
                <x-ui.btn :href="route('admin.sources.show', $source)" variant="ghost">بازگشت به منبع</x-ui.btn>
                <span class="flex gap-2">
                    <x-ui.btn type="submit" name="preview" value="1">پیش‌نمایش اثر</x-ui.btn>
                    <x-ui.btn type="submit" variant="primary">{{ $form->exists ? 'ذخیره' : 'افزودن الگو' }}</x-ui.btn>
                </span>
            </div>
        </form>
        @if($form->exists)
            <form action="{{ route('admin.sources.citations.destroy', [$source, $form]) }}" method="POST" class="mt-3 flex justify-end gap-2"
                  onsubmit="return confirm('این الگو حذف شود؟ مآخذی که فقط با آن به این منبع وصل‌اند جدا می‌شوند.')">
                @csrf
                @method('DELETE')
                <x-ui.btn type="submit" name="preview" value="1" size="sm" variant="ghost">پیش‌نمایش حذف</x-ui.btn>
                <x-ui.btn type="submit" size="sm" variant="ghost">حذف الگو</x-ui.btn>
            </form>
        @endif
    </x-ui.card>

    @if($others->isNotEmpty())
        <x-ui.section title="الگوهای دیگر همین منبع" :count="$others->count()">
            <ul class="bg-surface border border-line divide-y divide-line text-xs">
                @foreach($others as $other)
                    <li class="px-4 py-2 flex flex-wrap gap-x-3">
                        <x-ui.chip>{{ $other->citing ? $other->citing->title : 'هر منبع' }}</x-ui.chip>
                        <span>{{ $other->names ? implode('، ', $other->names) : 'عبارت باقاعده' }}</span>
                        <a href="{{ route('admin.sources.citations.edit', [$source, $other]) }}" class="ms-auto text-cinnabar">ویرایش</a>
                    </li>
                @endforeach
            </ul>
        </x-ui.section>
    @endif

    @if($history->isNotEmpty())
        @include('admin.sources.partials.history', ['history' => $history])
    @endif
</div>
@endsection

@extends('layouts.admin')

@section('title', 'ادغام دو منبع')
@section('page_title', 'ادغام دو منبع')
@section('breadcrumb')
    <a href="{{ route('admin.sources.index') }}" class="hover:text-cinnabar">منابع</a>
    <span>/</span>
    <span>ادغام</span>
@endsection

@php
    $fa = fn ($n) => \App\Support\Fa::num($n);
    $option = fn ($s) => $s->title . ' — ' . "\u{2066}" . $s->code . "\u{2069}" . ' (' . $fa($s->volumes_count) . ' جلد)';
@endphp

@section('content')
<div class="space-y-5 text-[13px] max-w-5xl">
    <x-admin.notice class="text-xs">
        دو منبعی را که در واقع یک کتاب‌اند یکی کنید، مانند دو املای یک فهرست یا فهرستی که هر جلدش منبع جدایی شده است.
        جلدها با شناسه‌ی خودشان به منبع ماندنی می‌روند و شماره‌ای را می‌گیرند که عنوانشان می‌گوید. اگر دو جلد یک شماره داشته باشند، مدخل‌ها، نسخه‌ها، بخش‌ها و فهرست‌نگاران جلد تکراری به جلد ماندنی می‌روند و جلد تکراری حذف می‌شود.
        کد منبع ادغام‌شده به منبع ماندنی هدایت می‌شود. همه‌ی ردیف‌های تغییرکرده در لاگ ممیزی نگه داشته می‌شوند و ادغام برگشت‌پذیر است.
    </x-admin.notice>

    <x-ui.card>
        <form method="GET" action="{{ route('admin.sources.merge') }}" class="grid gap-4 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] items-end">
            <x-admin.field label="منبع ماندنی" for="keep">
                <select name="keep" id="keep" class="admin-field w-full" required>
                    <option value="">انتخاب کنید</option>
                    @foreach($sources as $s)
                        <option value="{{ $s->id }}" @selected($keep?->id === $s->id)>{{ $option($s) }}</option>
                    @endforeach
                </select>
            </x-admin.field>
            <x-admin.field label="منبعی که در آن ادغام می‌شود" for="fold">
                <select name="fold" id="fold" class="admin-field w-full" required>
                    <option value="">انتخاب کنید</option>
                    @foreach($sources as $s)
                        <option value="{{ $s->id }}" @selected($fold?->id === $s->id)>{{ $option($s) }}</option>
                    @endforeach
                </select>
            </x-admin.field>
            <x-ui.btn type="submit">پیش‌نمایش</x-ui.btn>
        </form>
        @if($keep && $fold && $keep->id === $fold->id)
            <p class="mt-3 text-xs font-bold text-cinnabar">یک منبع با خودش ادغام نمی‌شود.</p>
        @endif
    </x-ui.card>

    @if($plan)
        @foreach($warnings as $warning)
            <x-admin.notice tone="warn" class="text-xs">{{ $warning }}</x-admin.notice>
        @endforeach

        <x-ui.section title="آنچه جابه‌جا می‌شود">
            <dl class="grid grid-cols-2 sm:grid-cols-4 gap-px bg-line border border-line text-center">
                @foreach(['volumes' => 'جلد منتقل‌شونده', 'drops' => 'جلد تکراری', 'entries' => 'مدخل', 'copies' => 'نسخه',
                    'parts' => 'بخش', 'catalogers' => 'فهرست‌نگار', 'items' => 'مدخل چاپی', 'pages' => 'صفحه‌ی متنی'] as $key => $name)
                    <div class="bg-surface px-3 py-3">
                        <dt class="text-xs text-muted">{{ $name }}</dt>
                        <dd class="text-lg font-bold tabular-nums text-ink">{{ $fa($counts[$key]) }}</dd>
                    </div>
                @endforeach
            </dl>
            @if($counts['evidence'] || $counts['editions'])
                <p class="mt-2 text-xs text-muted">
                    همچنین {{ $fa($counts['evidence']) }} شاهد فیلد که به منبع ادغام‌شده ارجاع می‌دهند، و {{ $fa($counts['editions']) }} منبع که چاپ دیگری از آن‌اند، به منبع ماندنی وصل می‌شوند.
                </p>
            @endif
        </x-ui.section>

        <x-ui.section title="جلدهای منبع پس از ادغام" :count="count($plan['stays'])">
            <x-admin.table>
                <x-slot:head>
                    <x-admin.th>شماره</x-admin.th>
                    <x-admin.th>جلد</x-admin.th>
                    <x-admin.th>از منبع</x-admin.th>
                    <x-admin.th>مدخل</x-admin.th>
                </x-slot:head>
                @foreach($plan['stays'] as $v)
                    <x-admin.row>
                        <x-admin.cell main class="tabular-nums font-bold">
                            {{ $v->new !== null ? $fa($v->new) : '—' }}
                            @if($v->old !== $v->new)<span class="text-xs font-normal text-amber-700 dark:text-amber-300">(پیش‌تر {{ $v->old !== null ? $fa($v->old) : 'بی‌شماره' }})</span>@endif
                        </x-admin.cell>
                        <x-admin.cell label="جلد">{{ \App\Support\Fa::digits($v->volume->title) }} <span class="text-xs text-muted tabular-nums">#{{ \App\Support\Fa::digits($v->volume->id) }}</span></x-admin.cell>
                        <x-admin.cell label="از منبع" class="text-xs">{{ $v->source->id === $plan['kept']->id ? 'ماندنی' : 'ادغام‌شونده' }}</x-admin.cell>
                        <x-admin.cell label="مدخل" class="tabular-nums">{{ $fa($v->entries) }}</x-admin.cell>
                    </x-admin.row>
                @endforeach
            </x-admin.table>
        </x-ui.section>

        @if($plan['drops'])
            <x-ui.section title="جلدهای تکراری که در جلد ماندنی ادغام می‌شوند" :count="count($plan['drops'])">
                <ul class="bg-surface border border-line divide-y divide-line text-xs">
                    @foreach($plan['drops'] as [$d, $k])
                        <li class="px-4 py-2.5">
                            جلد {{ $fa($d->new) }}: «{{ \App\Support\Fa::digits($d->volume->title) }}» <span class="text-muted tabular-nums">#{{ \App\Support\Fa::digits($d->volume->id) }}</span>
                            ← «{{ \App\Support\Fa::digits($k->volume->title) }}» <span class="text-muted tabular-nums">#{{ \App\Support\Fa::digits($k->volume->id) }}</span>
                            <span class="text-muted">· {{ $fa($d->entries) }} مدخل جابه‌جا می‌شود</span>
                        </li>
                    @endforeach
                </ul>
            </x-ui.section>
        @endif

        @if($counts['entries'] <= \App\Sources\SourceMerger::PANEL_LIMIT)
            <x-ui.card>
                <form method="POST" action="{{ route('admin.sources.merge.store') }}" class="space-y-4"
                      onsubmit="return confirm('دو منبع ادغام شوند؟ از سابقه‌ی منبع ماندنی برگشت‌پذیر است.')">
                    @csrf
                    <input type="hidden" name="keep" value="{{ $keep->id }}">
                    <input type="hidden" name="fold" value="{{ $fold->id }}">
                    <x-admin.field label="عنوان منبع پس از ادغام" for="title" error="title" hint="پیشنهاد: عنوان منبع ماندنی، بدون شماره‌ی جلد.">
                        <input type="text" name="title" id="title" value="{{ old('title', $plan['title']) }}" required class="admin-field w-full font-bold">
                    </x-admin.field>
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input type="checkbox" name="confirm" value="1" required class="accent-cinnabar mt-1">
                        <span>«{{ $fold->title }}» (<span dir="ltr">{{ $fold->code }}</span>) در «{{ $keep->title }}» (<span dir="ltr">{{ $keep->code }}</span>) ادغام شود.</span>
                    </label>
                    @error('confirm')<p class="text-xs font-bold text-cinnabar">{{ $message }}</p>@enderror
                    <div class="pt-3 flex flex-wrap items-center justify-between gap-2 border-t border-line">
                        <x-ui.btn :href="route('admin.sources.merge', ['keep' => $fold->id, 'fold' => $keep->id])" variant="ghost">جابه‌جا کردن دو منبع</x-ui.btn>
                        <x-ui.btn type="submit" variant="primary">ادغام</x-ui.btn>
                    </div>
                </form>
            </x-ui.card>
        @endif
    @endif
</div>
@endsection

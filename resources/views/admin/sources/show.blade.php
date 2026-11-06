@extends('layouts.admin')

@section('title', $source->title)
@section('page_title', $source->title)
@section('breadcrumb')
    <a href="{{ route('admin.sources.index') }}" class="hover:text-cinnabar">منابع</a>
    <span>/</span>
    <span>#{{ \App\Support\Fa::digits($source->id) }}</span>
@endsection
@section('page_actions')
    @can('sources.merge')
        <x-ui.btn :href="route('admin.sources.merge', ['keep' => $source->id])" size="sm" title="منبع دیگری که همین کتاب است در این منبع ادغام شود">ادغام منبع دیگر در این</x-ui.btn>
    @endcan
    <x-ui.btn :href="route('sources.show', $source->code)" target="_blank" size="sm">صفحه در سایت ↗</x-ui.btn>
@endsection

@php
    $fa = fn ($n) => \App\Support\Fa::num($n);
    $merged = (array) ($source->metadata['merged_codes'] ?? []);
@endphp

@section('content')
<div class="space-y-6 text-[13px]">
<div class="grid gap-5 lg:grid-cols-2 items-start">
    <x-ui.card>
        <dl class="grid grid-cols-[max-content_minmax(0,1fr)] gap-x-4 gap-y-1.5 text-xs">
            <dt class="text-muted">کد</dt><dd dir="ltr" class="text-right">{{ $source->code }}</dd>
            @if($merged)
                <dt class="text-muted">کدهای ادغام‌شده</dt><dd dir="ltr" class="text-right">{{ implode('، ', $merged) }}</dd>
            @endif
            <dt class="text-muted">جلدها</dt><dd class="tabular-nums">{{ $fa($volumes->count()) }}</dd>
            <dt class="text-muted">مدخل‌ها</dt><dd class="tabular-nums">{{ $fa($entries->sum() + $unplaced) }}@if($unplaced) <span class="text-muted">({{ $fa($unplaced) }} بی‌جلد)</span>@endif</dd>
            <dt class="text-muted">متن صفحه‌ها</dt>
            <dd>
                @if($pages->sum())
                    <span class="text-emerald-700 dark:text-emerald-300">خواندنی، {{ $fa($pages->sum()) }} صفحه</span>
                @else
                    <span class="text-muted">ندارد</span>
                @endif
                @if($source->extractor)<span class="text-muted"> · استخراج‌گر <span dir="ltr">{{ $source->extractor }}</span></span>@endif
            </dd>
            @if($source->editionOf)
                <dt class="text-muted">چاپ دیگرِ</dt><dd><a href="{{ route('admin.sources.show', $source->editionOf) }}" class="hover:text-cinnabar">{{ $source->editionOf->title }}</a></dd>
            @endif
            @foreach($source->editions as $edition)
                <dt class="text-muted">چاپ دیگر</dt><dd><a href="{{ route('admin.sources.show', $edition) }}" class="hover:text-cinnabar">{{ $edition->title }}</a>@if($edition->edition_label) <span class="text-muted">({{ $edition->edition_label }})</span>@endif</dd>
            @endforeach
        </dl>
    </x-ui.card>

    <x-ui.card>
        <form action="{{ route('admin.sources.update', $source) }}" method="POST" class="space-y-4">
            @csrf
            @method('PUT')
            <x-admin.field label="عنوان" for="title" error="title">
                <textarea name="title" id="title" rows="2" required class="admin-field w-full font-bold leading-7">{{ old('title', $source->title) }}</textarea>
            </x-admin.field>
            <x-admin.field label="نام کوتاه" for="short_name" error="short_name" hint="نامی که در فهرست‌ها و ارجاع‌ها کوتاه‌تر می‌آید.">
                <input type="text" name="short_name" id="short_name" value="{{ old('short_name', $source->short_name) }}" class="admin-field w-full">
            </x-admin.field>
            <div class="grid grid-cols-2 gap-4">
                <x-admin.field label="نوع" for="kind" error="kind">
                    <select name="kind" id="kind" class="admin-field w-full">
                        @foreach(\App\Models\Source::KIND_LABELS as $key => $label)
                            <option value="{{ $key }}" @selected(old('kind', $source->kind) === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </x-admin.field>
                <x-admin.field label="رتبه" for="rank" error="rank">
                    <input type="number" name="rank" id="rank" min="0" max="999" value="{{ old('rank', $source->rank) }}" required class="admin-field w-full" dir="ltr">
                </x-admin.field>
            </div>
            <p class="text-xs text-muted leading-6 -mt-2">
                رتبه‌ی کمتر یعنی منبع معتبرتر، و در فهرست مأخذ یک رکورد زودتر می‌آید. پیش‌فرض هر نوع:
                @foreach(\App\Models\Source::KIND_RANKS as $key => $rank){{ \App\Models\Source::KIND_LABELS[$key] }} {{ $fa($rank) }}@if(! $loop->last)، @endif @endforeach
            </p>
            <x-admin.field label="کتابخانه" for="library_id" error="library_id" hint="فهرستی که از یک کتابخانه است. فهرست چندکتابخانه‌ای کتابخانه ندارد؛ بخش‌هایش کتابخانه دارند.">
                <select name="library_id" id="library_id" class="admin-field w-full">
                    <option value="">بی‌کتابخانه</option>
                    @foreach($libraries as $lib)
                        <option value="{{ $lib->id }}" @selected((int) old('library_id', $source->library_id) === $lib->id)>{{ $lib->name }} ({{ $lib->city }})</option>
                    @endforeach
                </select>
            </x-admin.field>
            <x-admin.form-actions :cancel="route('admin.sources.index')" />
        </form>
    </x-ui.card>
</div>

    <div class="space-y-6 min-w-0">
        <x-ui.section title="جلدها" :count="$volumes->count()">
            <x-admin.table :is-empty="$volumes->isEmpty()" empty="این منبع جلدی ندارد.">
                <x-slot:head>
                    <x-admin.th>جلد</x-admin.th>
                    <x-admin.th>کتابخانه و فهرست‌نگاران</x-admin.th>
                    <x-admin.th>بخش</x-admin.th>
                    <x-admin.th>نسخه</x-admin.th>
                    <x-admin.th>مدخل</x-admin.th>
                    <x-admin.th>صفحه‌ی متنی</x-admin.th>
                    <x-admin.th><span class="sr-only">کنش‌ها</span></x-admin.th>
                </x-slot:head>
                @foreach($volumes as $volume)
                    @php $cv = $catalogVolumes[$volume->id] ?? null; @endphp
                    <x-admin.row>
                        <x-admin.cell main class="md:min-w-56 md:max-w-sm">
                            <a href="{{ route('admin.sources.volume', [$source, $volume->id]) }}" class="font-bold text-ink hover:text-cinnabar">
                                {{ $volume->volume_number ? 'جلد ' . $fa($volume->volume_number) : 'بی‌شماره' }}
                            </a>
                            <span class="text-xs text-muted tabular-nums">#{{ \App\Support\Fa::digits($volume->id) }}</span>
                            {{-- the volume's own words, without the source's title it repeats --}}
                            <span class="block text-xs text-muted">{{ \App\Support\Fa::digits(trim(\Illuminate\Support\Str::startsWith($volume->title, $source->title) ? preg_replace('/^[\s\-–:،]+/u', '', \Illuminate\Support\Str::after($volume->title, $source->title)) : $volume->title) ?: $volume->title) }}</span>
                        </x-admin.cell>
                        <x-admin.cell label="کتابخانه" class="text-xs">
                            {{ $cv?->library ? $cv->library->name . '، ' . $cv->library->city : '—' }}
                            @if($cv?->catalogers->isNotEmpty())
                                <span class="block text-muted">{{ $cv->catalogers->map(fn ($c) => $c->name . ($c->pivot->role !== 'cataloger' ? ' (' . (\App\Http\Controllers\Admin\SourceController::ROLES[$c->pivot->role] ?? $c->pivot->role) . ')' : ''))->implode('، ') }}</span>
                            @endif
                        </x-admin.cell>
                        <x-admin.cell label="بخش" class="tabular-nums">{{ $cv?->parts_count ? $fa($cv->parts_count) : '—' }}</x-admin.cell>
                        <x-admin.cell label="نسخه" class="tabular-nums">{{ $fa($cv?->manuscripts_count ?? 0) }}</x-admin.cell>
                        <x-admin.cell label="مدخل" class="tabular-nums">{{ $fa($entries[$volume->id] ?? 0) }}</x-admin.cell>
                        <x-admin.cell label="صفحه‌ی متنی" class="tabular-nums">{{ isset($pages[$volume->id]) ? $fa($pages[$volume->id]) : '—' }}</x-admin.cell>
                        <x-admin.cell actions>
                            <x-ui.btn :href="route('admin.sources.volume', [$source, $volume->id])" size="sm">جلد و بخش‌ها</x-ui.btn>
                        </x-admin.cell>
                    </x-admin.row>
                @endforeach
            </x-admin.table>
        </x-ui.section>

        @include('admin.sources.partials.history', ['history' => $history])
    </div>
</div>
@endsection

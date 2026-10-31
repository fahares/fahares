@extends('layouts.admin')

@section('title', 'صفحه‌ی خانه')
@section('page_title', 'صفحه‌ی خانه')
@section('page_actions')
    <x-ui.btn :href="route('home')" target="_blank" size="sm">دیدن صفحه‌ی خانه ↗</x-ui.btn>
@endsection

@php
    $box = 'bg-surface border border-line';
    $input = 'admin-field w-full';
    $label = 'block font-bold text-ink mb-1';
    $btn = 'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-sm text-xs font-semibold border border-line-strong bg-surface text-ink hover:border-cinnabar hover:text-cinnabar transition cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed';
    $btnPri = 'inline-flex items-center gap-1.5 px-4 py-2 rounded-sm text-xs font-semibold bg-cinnabar text-on-cinnabar hover:opacity-90 transition cursor-pointer';
@endphp

@section('content')
<div class="space-y-6 text-[13px]">

    <p class="text-muted max-w-3xl leading-7">متن‌ها، اسلایدهای بنر، تصویرهای برگزیده و فهرست‌نگارانی که در صفحه‌ی خانه نمایش داده می‌شوند. تغییرها بی‌درنگ روی سایت دیده می‌شوند.</p>

    {{-- Jumps to the page's sections --}}
    <nav class="flex flex-wrap gap-1.5 text-xs" aria-label="بخش‌های این صفحه">
        @foreach(['texts' => 'متن‌ها', 'hero' => 'تصویر سربرگ', 'slides' => 'اسلایدها', 'treasures' => 'از گنجینه‌ها', 'catalogers' => 'فهرست‌نگاران'] as $anchor => $title)
            <a href="#{{ $anchor }}" class="px-3 py-1 rounded-sm border border-line bg-surface text-ink hover:border-cinnabar hover:text-cinnabar transition">{{ $title }}</a>
        @endforeach
    </nav>

    @if($errors->any())
        <x-admin.notice tone="error" class="space-y-1">
            @foreach($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </x-admin.notice>
    @endif

    {{-- Texts --}}
    <section id="texts" class="{{ $box }} p-4 sm:p-5 space-y-4 scroll-mt-24">
        <h2 class="rubric text-lg">متن‌ها</h2>
        <form action="{{ route('admin.home.settings') }}" method="POST" class="grid gap-4 md:grid-cols-2">
            @csrf
            @method('PUT')
            @foreach($settings as $key => $s)
                @php $field = str_replace('.', '__', $key); @endphp
                <div class="{{ $key === 'home.lede' ? 'md:col-span-2' : '' }}">
                    <label for="{{ $field }}" class="{{ $label }}">{{ $s['label'] }}</label>
                    @if($key === 'home.lede')
                        <textarea name="{{ $field }}" id="{{ $field }}" rows="3" class="{{ $input }}" placeholder="{{ $s['default'] }}">{{ old($field, $s['value'] === $s['default'] ? '' : $s['value']) }}</textarea>
                    @else
                        <input type="{{ $key === 'home.slide_interval' ? 'number' : 'text' }}" name="{{ $field }}" id="{{ $field }}" class="{{ $input }}" placeholder="{{ $s['default'] }}" value="{{ old($field, $s['value'] === $s['default'] ? '' : $s['value']) }}">
                    @endif
                </div>
            @endforeach
            <p class="md:col-span-2 text-xs text-muted">خانه‌ی خالی یعنی متن پیش‌فرض (که کم‌رنگ نشان داده شده) به کار می‌رود.</p>
            <div class="md:col-span-2"><button type="submit" class="{{ $btnPri }}">ذخیره‌ی متن‌ها</button></div>
        </form>
    </section>

    {{-- Slides and treasures --}}
    @foreach(\App\Models\HomeItem::SECTIONS as $section => $sectionLabel)
        @php $list = $items->get($section, collect()); @endphp
        <section id="{{ $section }}" class="{{ $box }} p-4 sm:p-5 space-y-5 scroll-mt-24">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="rubric text-lg">{{ $sectionLabel }}</h2>
                <span class="text-xs text-muted leading-6">
                    @if($section === 'hero')
                        تصویر پهن بالای صفحه، پشت برگه‌ی جست‌وجو. فقط نخستین تصویر فعال نمایش داده می‌شود. دست‌کم ۱۲۰۰ پیکسل عرض، و بخش مهم تصویر در میانه باشد. حداکثر ۲ مگابایت.
                    @elseif($section === 'slides')
                        زیر برگه‌ی جست‌وجو می‌چرخند. نسبت ۱۶ به ۵، مثلاً ۱۱۴۶×۳۵۸ یا ۱۲۸۰×۴۰۰ پیکسل؛ نوشته‌ها کمی دورتر از لبه باشند. حداکثر ۲ مگابایت.
                    @else
                        نسبت پیشنهادی ۳ به ۴ (عمودی). حداکثر ۲ مگابایت. سه یا شش تصویر بهتر چیده می‌شود.
                    @endif
                </span>
            </div>

            @forelse($list as $item)
                <div class="grid gap-4 md:grid-cols-[220px_minmax(0,1fr)] border-t border-line pt-5 {{ $item->is_active ? '' : 'opacity-60' }}">
                    <div class="space-y-2">
                        <img src="{{ $item->image_url }}" alt="" class="w-full rounded-sm border border-line-strong {{ ['hero' => 'aspect-[4/1]', 'slides' => 'aspect-[3/1]'][$section] ?? 'aspect-[3/4]' }} object-cover">
                        <div class="flex gap-1.5">
                            <form action="{{ route('admin.home.items.move', $item) }}" method="POST">@csrf<input type="hidden" name="direction" value="up"><button class="{{ $btn }}" title="جابه‌جایی به جلو" {{ $loop->first ? 'disabled' : '' }}>↑</button></form>
                            <form action="{{ route('admin.home.items.move', $item) }}" method="POST">@csrf<input type="hidden" name="direction" value="down"><button class="{{ $btn }}" title="جابه‌جایی به عقب" {{ $loop->last ? 'disabled' : '' }}>↓</button></form>
                            <form action="{{ route('admin.home.items.destroy', $item) }}" method="POST" onsubmit="return confirm('این تصویر حذف شود؟')">@csrf @method('DELETE')<button class="{{ $btn }}">حذف</button></form>
                        </div>
                    </div>
                    @include('admin.home.partials.item-form', ['item' => $item, 'section' => $section])
                </div>
            @empty
                <p class="text-muted border-t border-line pt-5">هنوز تصویری افزوده نشده است.</p>
            @endforelse

            <details class="border-t border-line pt-5" {{ $list->isEmpty() ? 'open' : '' }}>
                <summary class="cursor-pointer font-bold text-cinnabar">+ افزودن تصویر</summary>
                <div class="mt-4">
                    @include('admin.home.partials.item-form', ['item' => null, 'section' => $section])
                </div>
            </details>
        </section>
    @endforeach

    {{-- Home catalogers --}}
    <section id="catalogers" class="{{ $box }} p-4 sm:p-5 space-y-5 scroll-mt-24">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="rubric text-lg">فهرست‌نگاران صفحه‌ی خانه</h2>
            <span class="text-xs text-muted leading-6">به همین ترتیب نمایش داده می‌شوند. شش نفر در یک ردیف جا می‌گیرند. عکس هر فهرست‌نگار از <a href="{{ route('admin.catalogers.index') }}" class="text-cinnabar hover:underline">صفحه‌ی فهرست‌نگاران</a> عوض می‌شود.</span>
        </div>

        @if($homeCatalogers->isEmpty())
            <p class="text-muted">هیچ فهرست‌نگاری انتخاب نشده و این بخش در صفحه‌ی خانه پنهان است.</p>
        @else
            <ol class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($homeCatalogers as $c)
                    <li class="flex items-center gap-2 p-3 bg-paper border border-line">
                        <span class="text-muted tabular-nums w-5">{{ \App\Support\Fa::digits($loop->iteration) }}</span>
                        <div class="w-10 h-12 rounded-t-full overflow-hidden bg-surface border border-line shrink-0">
                            @if($c->avatar_url)<img src="{{ $c->avatar_url }}" alt="" class="w-full h-full object-cover sepia">@endif
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="font-bold text-ink truncate">{{ $c->name }}</p>
                            @unless($c->avatar_url)<p class="text-xs text-amber-800 dark:text-amber-300">بدون عکس</p>@endunless
                        </div>
                        <form action="{{ route('admin.home.catalogers.move', $c) }}" method="POST">@csrf<input type="hidden" name="direction" value="up"><button class="{{ $btn }}" title="جابه‌جایی به جلو" {{ $loop->first ? 'disabled' : '' }}>↑</button></form>
                        <form action="{{ route('admin.home.catalogers.move', $c) }}" method="POST">@csrf<input type="hidden" name="direction" value="down"><button class="{{ $btn }}" title="جابه‌جایی به عقب" {{ $loop->last ? 'disabled' : '' }}>↓</button></form>
                        <form action="{{ route('admin.home.catalogers.remove', $c) }}" method="POST">@csrf @method('DELETE')<button class="{{ $btn }}" title="برداشتن از صفحه‌ی خانه">✕</button></form>
                    </li>
                @endforeach
            </ol>
        @endif

        <form action="{{ route('admin.home.catalogers.add') }}" method="POST" class="flex flex-wrap items-end gap-3 border-t border-line pt-5">
            @csrf
            <div class="min-w-[260px] flex-1 max-w-md">
                <label for="cataloger_id" class="{{ $label }}">افزودن فهرست‌نگار</label>
                <select name="cataloger_id" id="cataloger_id" class="{{ $input }}" required>
                    <option value="">انتخاب کنید…</option>
                    @foreach($otherCatalogers as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}{{ $c->avatar_url ? '' : ' (بدون عکس)' }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="{{ $btnPri }}">افزودن</button>
        </form>
    </section>
</div>
@endsection

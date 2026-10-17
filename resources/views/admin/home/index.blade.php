@extends('layouts.admin')

@section('title', 'صفحه‌ی خانه')
@section('page_title', 'صفحه‌ی خانه')
@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-[#B38A50]">پیشخوان</a>
    <span>/</span>
    <span class="text-stone-400">صفحه‌ی خانه</span>
@endsection

@php
    $box = 'bg-white dark:bg-[#15192C] rounded-2xl border border-stone-200 dark:border-stone-800 shadow-xs';
    $input = 'w-full p-2.5 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 focus:border-[#B38A50] outline-none';
    $label = 'block font-bold text-stone-700 dark:text-stone-300 mb-1';
    $btn = 'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold border border-stone-300 dark:border-stone-700 hover:border-[#B38A50] hover:text-[#B38A50] transition cursor-pointer';
    $btnPri = 'inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-xs font-bold bg-[#B38A50] text-white hover:bg-[#9C753F] transition cursor-pointer';
@endphp

@section('content')
<div class="space-y-8 text-xs">

    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 dark:border-stone-800 pb-4">
        <p class="text-stone-500 max-w-3xl">متن‌ها، اسلایدهای بنر، تصویرهای برگزیده و فهرست‌نگارانی که در صفحه‌ی خانه نمایش داده می‌شوند. تغییرها بی‌درنگ روی سایت دیده می‌شوند.</p>
        <a href="{{ route('home') }}" target="_blank" class="{{ $btn }}">دیدن صفحه‌ی خانه ↗</a>
    </div>

    <nav class="flex flex-wrap gap-2">
        <a href="#texts" class="{{ $btn }}">متن‌ها</a>
        <a href="#slides" class="{{ $btn }}">اسلایدهای بنر</a>
        <a href="#treasures" class="{{ $btn }}">از گنجینه‌ها</a>
        <a href="#catalogers" class="{{ $btn }}">فهرست‌نگاران</a>
    </nav>

    @if($errors->any())
        <div class="p-4 rounded-xl border border-red-300 bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 space-y-1">
            @foreach($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    {{-- Texts --}}
    <section id="texts" class="{{ $box }} p-6 space-y-4 scroll-mt-24">
        <h2 class="text-sm font-black text-stone-900 dark:text-stone-100">متن‌ها</h2>
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
            <p class="md:col-span-2 text-stone-400">خانه‌ی خالی یعنی متن پیش‌فرض (که کم‌رنگ نشان داده شده) به کار می‌رود.</p>
            <div class="md:col-span-2"><button type="submit" class="{{ $btnPri }}">ذخیره‌ی متن‌ها</button></div>
        </form>
    </section>

    {{-- Slides and treasures --}}
    @foreach(\App\Models\HomeItem::SECTIONS as $section => $sectionLabel)
        @php $list = $items->get($section, collect()); @endphp
        <section id="{{ $section }}" class="{{ $box }} p-6 space-y-5 scroll-mt-24">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-sm font-black text-stone-900 dark:text-stone-100">{{ $sectionLabel }}</h2>
                <span class="text-stone-400">
                    @if($section === 'slides')
                        نسبت پیشنهادی ۳ به ۱، مثلاً ۱۲۰۰×۴۰۰ پیکسل. حداکثر ۲ مگابایت.
                    @else
                        نسبت پیشنهادی ۳ به ۴ (عمودی). حداکثر ۲ مگابایت. سه یا شش تصویر بهتر چیده می‌شود.
                    @endif
                </span>
            </div>

            @forelse($list as $item)
                <div class="grid gap-4 md:grid-cols-[220px_minmax(0,1fr)] border-t border-stone-100 dark:border-stone-800 pt-5 {{ $item->is_active ? '' : 'opacity-60' }}">
                    <div class="space-y-2">
                        <img src="{{ $item->image_url }}" alt="" class="w-full rounded-lg border border-stone-200 dark:border-stone-700 {{ $section === 'slides' ? 'aspect-[3/1]' : 'aspect-[3/4]' }} object-cover">
                        <div class="flex gap-1.5">
                            <form action="{{ route('admin.home.items.move', $item) }}" method="POST">@csrf<input type="hidden" name="direction" value="up"><button class="{{ $btn }}" title="جابه‌جایی به جلو" {{ $loop->first ? 'disabled' : '' }}>↑</button></form>
                            <form action="{{ route('admin.home.items.move', $item) }}" method="POST">@csrf<input type="hidden" name="direction" value="down"><button class="{{ $btn }}" title="جابه‌جایی به عقب" {{ $loop->last ? 'disabled' : '' }}>↓</button></form>
                            <form action="{{ route('admin.home.items.destroy', $item) }}" method="POST" onsubmit="return confirm('این تصویر حذف شود؟')">@csrf @method('DELETE')<button class="{{ $btn }} hover:!border-red-500 hover:!text-red-600">حذف</button></form>
                        </div>
                    </div>
                    @include('admin.home.partials.item-form', ['item' => $item, 'section' => $section])
                </div>
            @empty
                <p class="text-stone-400 border-t border-stone-100 dark:border-stone-800 pt-5">هنوز تصویری افزوده نشده است.</p>
            @endforelse

            <details class="border-t border-stone-100 dark:border-stone-800 pt-5" {{ $list->isEmpty() ? 'open' : '' }}>
                <summary class="cursor-pointer font-bold text-[#B38A50]">+ افزودن تصویر</summary>
                <div class="mt-4">
                    @include('admin.home.partials.item-form', ['item' => null, 'section' => $section])
                </div>
            </details>
        </section>
    @endforeach

    {{-- Home catalogers --}}
    <section id="catalogers" class="{{ $box }} p-6 space-y-5 scroll-mt-24">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-sm font-black text-stone-900 dark:text-stone-100">فهرست‌نگاران صفحه‌ی خانه</h2>
            <span class="text-stone-400">به همین ترتیب نمایش داده می‌شوند. شش نفر در یک ردیف جا می‌گیرند. عکس هر فهرست‌نگار از <a href="{{ route('admin.catalogers.index') }}" class="text-[#B38A50] hover:underline">صفحه‌ی فهرست‌نگاران</a> عوض می‌شود.</span>
        </div>

        @if($homeCatalogers->isEmpty())
            <p class="text-stone-400">هیچ فهرست‌نگاری انتخاب نشده و این بخش در صفحه‌ی خانه پنهان است.</p>
        @else
            <ol class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($homeCatalogers as $c)
                    <li class="flex items-center gap-3 p-3 rounded-xl border border-stone-200 dark:border-stone-700">
                        <span class="font-mono text-stone-400 w-5">{{ $loop->iteration }}</span>
                        <div class="w-10 h-12 rounded-t-full overflow-hidden bg-stone-100 dark:bg-stone-800 shrink-0">
                            @if($c->avatar_url)<img src="{{ $c->avatar_url }}" alt="" class="w-full h-full object-cover sepia">@endif
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="font-bold text-stone-900 dark:text-stone-100 truncate">{{ $c->name }}</p>
                            @unless($c->avatar_url)<p class="text-amber-600">بدون عکس</p>@endunless
                        </div>
                        <form action="{{ route('admin.home.catalogers.move', $c) }}" method="POST">@csrf<input type="hidden" name="direction" value="up"><button class="{{ $btn }}" title="جابه‌جایی به جلو" {{ $loop->first ? 'disabled' : '' }}>↑</button></form>
                        <form action="{{ route('admin.home.catalogers.move', $c) }}" method="POST">@csrf<input type="hidden" name="direction" value="down"><button class="{{ $btn }}" title="جابه‌جایی به عقب" {{ $loop->last ? 'disabled' : '' }}>↓</button></form>
                        <form action="{{ route('admin.home.catalogers.remove', $c) }}" method="POST">@csrf @method('DELETE')<button class="{{ $btn }}" title="برداشتن از صفحه‌ی خانه">✕</button></form>
                    </li>
                @endforeach
            </ol>
        @endif

        <form action="{{ route('admin.home.catalogers.add') }}" method="POST" class="flex flex-wrap items-end gap-3 border-t border-stone-100 dark:border-stone-800 pt-5">
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

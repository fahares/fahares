@extends('layouts.app')

@section('title', 'کتابخانه‌ها و گنجینه‌های نسخ خطی ایران و جهان | فهارس')
@section('meta_description', 'فهرست ' . \App\Support\Fa::num(\App\Support\SiteStats::all()['libraries']) . ' کتابخانه، موزه و مرکز اسنادی ایران و جهان، همراه با شمار نسخه‌های خطی هر کدام در فهارس')

@section('content')
@php use App\Support\Fa; @endphp
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

    <header class="text-center grid justify-items-center gap-2 pb-2">
        <p class="text-xs font-bold text-cinnabar">گنجینه‌ها</p>
        <h1 class="font-naskh text-[28px] sm:text-[38px] font-bold leading-[1.6] text-ink text-balance">کتابخانه‌ها و مراکز نگهداری</h1>
        <p class="text-sm text-muted max-w-2xl leading-relaxed">{{ Fa::num(\App\Support\SiteStats::all()['libraries']) }} کتابخانه، موزه و مجموعه‌ی اسناد در ایران و جهان، به ترتیب شمار نسخه‌ها.</p>

        <form action="{{ route('libraries.index') }}" method="GET" class="w-full max-w-2xl mt-3 flex flex-col sm:flex-row gap-2">
            <div class="relative flex-1">
                <label for="library-q" class="sr-only">نام کتابخانه</label>
                <input id="library-q" type="text" name="q" value="{{ $search }}" placeholder="نام کتابخانه یا مرکز"
                    class="w-full pr-10 pl-4 py-2.5 bg-surface rounded-sm border border-line-strong text-sm text-ink placeholder:text-muted focus:border-cinnabar focus:ring-4 focus:ring-cinnabar/15 outline-none">
                <svg class="absolute right-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-muted pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
            </div>
            <label for="library-city" class="sr-only">شهر</label>
            <select id="library-city" name="city" class="sm:w-44 px-3 py-2.5 bg-surface rounded-sm border border-line-strong text-sm text-ink">
                <option value="">همه‌ی شهرها</option>
                @foreach($cities as $c)
                    <option value="{{ $c }}" {{ $city === $c ? 'selected' : '' }}>{{ $c }}</option>
                @endforeach
            </select>
            <button type="submit" class="px-5 py-2.5 bg-cinnabar text-on-cinnabar font-semibold rounded-sm text-sm hover:opacity-90 transition cursor-pointer">پالایش</button>
        </form>
    </header>

    <div class="jadval" aria-hidden="true"></div>

    @if($libraries->isEmpty())
        <p class="py-12 text-center text-muted">کتابخانه‌ای با این مشخصات پیدا نشد.</p>
    @else
        <ul class="grid md:grid-cols-2 gap-x-12">
            @foreach($libraries as $lib)
                <li class="border-b border-line">
                    <a href="{{ route('libraries.show', $lib) }}" class="flex items-baseline gap-2 py-2.5 group">
                        <x-library-logo :library="$lib" size="sm" :decorative="true" class="self-center" />
                        <span class="min-w-0">
                            <span class="font-semibold text-ink group-hover:text-cinnabar transition">{{ $lib->display_name }}</span>
                            <span class="block text-xs text-muted">
                                {{ $lib->city ?? 'ایران' }}{{ $lib->country && $lib->country !== 'ایران' ? '، ' . $lib->country : '' }}{{ $lib->full_name ? ' · ' . $lib->short_name : '' }}
                            </span>
                        </span>
                        <span class="leader" aria-hidden="true"></span>
                        <span class="text-xs text-muted tabular-nums whitespace-nowrap">{{ Fa::num($lib->manuscripts_count) }} نسخه</span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    @if($libraries->hasPages())
        <div class="pt-4">{{ $libraries->links() }}</div>
    @endif

</div>
@endsection

@extends('layouts.app')

@section('title', 'فهرست‌نگاران و میراث‌پژوهان نسخه‌های خطی | فهارس')
@section('meta_description', 'شناسنامه و کارنامه پژوهشی فهرست‌نگاران برجسته و استادان کتاب‌شناسی نسخه‌های خطی همراه با مجلدات فهارس و نسخه‌های توصیف‌شده')

@section('content')
@php use App\Support\Fa; @endphp
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

    <header class="text-center grid justify-items-center gap-2 pb-2">
        <p class="text-xs font-bold text-cinnabar">پاسداران فهرست</p>
        <h1 class="font-naskh text-[28px] sm:text-[38px] font-bold leading-[1.6] text-ink text-balance">فهرست‌نگاران و کتاب‌شناسان</h1>
        <p class="text-sm text-muted max-w-2xl leading-relaxed">
            استادان و پژوهشگرانی که نسخه‌های خطی را در فهرست‌های چاپی شناسانده‌اند:
            <span class="text-ink font-semibold tabular-nums">{{ Fa::num($stats['total_catalogers']) }}</span> فهرست‌نگار،
            <span class="text-ink font-semibold tabular-nums">{{ Fa::num($stats['total_volumes']) }}</span> مجلد فهرست و
            <span class="text-ink font-semibold tabular-nums">{{ Fa::num($stats['total_manuscripts']) }}</span> نسخه.
        </p>
        <form action="{{ route('catalogers.index') }}" method="GET" class="w-full max-w-2xl mt-3 flex flex-col sm:flex-row gap-2">
            <div class="relative flex-1">
                <label for="cataloger-q" class="sr-only">نام فهرست‌نگار</label>
                <input id="cataloger-q" type="text" name="q" value="{{ $search }}" placeholder="نام یا زندگی‌نامه"
                    class="w-full pr-10 pl-4 py-2.5 bg-surface rounded-sm border border-line-strong text-sm text-ink placeholder:text-muted focus:border-cinnabar focus:ring-4 focus:ring-cinnabar/15 outline-none">
                <svg class="absolute right-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-muted pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
            </div>
            <label for="cataloger-sort" class="sr-only">ترتیب</label>
            <select id="cataloger-sort" name="sort" class="sm:w-52 px-3 py-2.5 bg-surface rounded-sm border border-line-strong text-sm text-ink">
                <option value="birth_year" {{ $sort === 'birth_year' ? 'selected' : '' }}>سال ولادت</option>
                <option value="manuscripts" {{ $sort === 'manuscripts' ? 'selected' : '' }}>بیشترین نسخه</option>
                <option value="volumes" {{ $sort === 'volumes' ? 'selected' : '' }}>بیشترین مجلد</option>
                <option value="name" {{ $sort === 'name' ? 'selected' : '' }}>نام (الفبایی)</option>
            </select>
            <button type="submit" class="px-5 py-2.5 bg-cinnabar text-on-cinnabar font-semibold rounded-sm text-sm hover:opacity-90 transition cursor-pointer">نمایش</button>
        </form>
    </header>

    <div class="jadval" aria-hidden="true"></div>

    @if($catalogers->isEmpty())
        <p class="py-12 text-center text-muted">فهرست‌نگاری با این مشخصات پیدا نشد.</p>
    @else
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-x-6 gap-y-10">
            @foreach($catalogers as $cat)
                <a href="{{ route('catalogers.show', $cat) }}" class="group grid justify-items-center gap-1.5 text-center content-start">
                    <div class="w-full max-w-[150px] aspect-[4/5] rounded-t-full rounded-b-sm border border-gold p-1 bg-surface">
                        <div class="w-full h-full rounded-t-full rounded-b-[2px] overflow-hidden bg-paper grid place-items-center">
                            @if($cat->avatar_url)
                                <img src="{{ $cat->avatar_url }}" alt="" loading="lazy" class="w-full h-full object-cover sepia-[.85] contrast-[1.05] group-hover:sepia-0 transition duration-500 motion-reduce:transition-none">
                            @else
                                <span class="font-naskh text-4xl text-muted">{{ mb_substr($cat->name, 0, 1) }}</span>
                            @endif
                        </div>
                    </div>
                    <span class="text-sm font-bold text-ink leading-snug group-hover:text-cinnabar transition">{{ $cat->display_name }}</span>
                    @if($cat->life_years_text)<span class="text-[11px] text-muted leading-tight">{{ Fa::digits($cat->life_years_text) }}</span>@endif
                    <span class="text-[11px] text-muted tabular-nums">{{ Fa::num($cat->manuscripts_count) }} نسخه · {{ Fa::num($cat->volumes_count) }} مجلد</span>
                </a>
            @endforeach
        </div>
    @endif

    @if($catalogers->hasPages())
        <div class="pt-4 flex justify-center">{{ $catalogers->links() }}</div>
    @endif

</div>
@endsection

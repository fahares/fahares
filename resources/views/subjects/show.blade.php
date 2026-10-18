@extends('layouts.app')

@section('title', 'موضوع ' . $subject->name . ' | آثار و نسخه‌های خطی فهارس')
@section('meta_description', 'شناسنامه و فهرست کامل آثار و نسخه‌های خطی کهن در موضوع ' . $subject->name . ' مشتمل بر ' . \App\Support\Fa::num($subject->works_count) . ' اثر شناسایی‌شده.')

@section('content')
@php use App\Support\Fa; @endphp
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

    <nav class="flex items-center gap-2 text-xs text-muted" aria-label="مسیر">
        <a href="{{ route('home') }}" class="hover:text-cinnabar">فهارس</a>
        <span aria-hidden="true">/</span>
        <a href="{{ route('subjects.index') }}" class="hover:text-cinnabar">موضوعات</a>
        @if($subject->parent)
            <span aria-hidden="true">/</span>
            <a href="{{ route('subjects.show', $subject->parent) }}" class="hover:text-cinnabar">{{ $subject->parent->name }}</a>
        @endif
        <span aria-hidden="true">/</span>
        <span class="text-ink font-semibold">{{ $subject->name }}</span>
    </nav>

    <header class="text-center grid justify-items-center gap-2 pb-6">
        <p class="text-xs font-bold text-cinnabar">
            @if($subject->parent)
                زیرشاخه‌ی <a href="{{ route('subjects.show', $subject->parent) }}" class="underline decoration-cinnabar/40 hover:decoration-cinnabar">{{ $subject->parent->name }}</a>
            @else
                رده‌ی اصلی
            @endif
        </p>
        <h1 class="font-naskh text-[28px] sm:text-[40px] font-bold leading-[1.6] text-ink">{{ $subject->name }}</h1>
        <p class="text-sm text-muted">
            <span class="text-ink font-semibold tabular-nums">{{ Fa::num($subject->works_count) }}</span> اثر
            · <span class="text-ink font-semibold tabular-nums">{{ Fa::num($totalManuscripts) }}</span> نسخه
            @if($hasChildren) · {{ Fa::num($subject->children->count()) }} زیرشاخه @endif
        </p>
        @if($topLanguages->isNotEmpty())
            <div class="flex flex-wrap justify-center gap-1.5 mt-1">
                @foreach($topLanguages as $lang)
                    <x-ui.chip>{{ $lang->name }} <span class="text-muted tabular-nums">{{ Fa::num($lang->count) }}</span></x-ui.chip>
                @endforeach
            </div>
        @endif
    </header>

    <div class="jadval" aria-hidden="true"></div>

    @if($hasChildren)
        <x-ui.section title="زیرشاخه‌ها" :count="$subject->children->count()">
            <ul class="grid sm:grid-cols-2 lg:grid-cols-3 gap-x-10 text-sm">
                @foreach($subject->children as $child)
                    <li>
                        <a href="{{ route('subjects.show', $child) }}" class="flex items-baseline gap-2 py-1.5 group">
                            <span class="text-ink group-hover:text-cinnabar transition">{{ $child->name }}</span>
                            <span class="leader" aria-hidden="true"></span>
                            <span class="text-xs text-muted tabular-nums">{{ Fa::num($child->works_count) }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </x-ui.section>
    @endif

    <x-ui.section title="آثار" :count="$works->total()">
        <x-slot:actions>
            <x-ui.btn size="sm" :href="route('search', ['type' => 'works', 'subject_id' => $subject->id])">جستجوی پیشرفته در این موضوع</x-ui.btn>
        </x-slot:actions>

        <form action="{{ route('subjects.show', $subject) }}" method="GET" class="flex flex-col lg:flex-row items-stretch lg:items-center gap-2 mb-4">
            @if($hasChildren)
                <div class="inline-flex border border-line-strong rounded-sm overflow-hidden text-xs shrink-0">
                    <button type="submit" name="scope" value="cumulative" class="px-3 py-2 transition cursor-pointer {{ $isCumulative ? 'bg-ink text-paper' : 'text-muted hover:text-ink' }}">با زیرشاخه‌ها</button>
                    <button type="submit" name="scope" value="direct" class="px-3 py-2 transition cursor-pointer border-s border-line-strong {{ ! $isCumulative ? 'bg-ink text-paper' : 'text-muted hover:text-ink' }}">فقط همین موضوع</button>
                </div>
            @else
                <input type="hidden" name="scope" value="direct">
            @endif
            <div class="flex-1 relative">
                <label for="subject-q" class="sr-only">جستجو در آثار این موضوع</label>
                <input id="subject-q" type="text" name="q" value="{{ $search }}" placeholder="عنوان یا پدیدآور"
                    class="w-full pr-10 pl-4 py-2 bg-surface rounded-sm border border-line-strong text-sm text-ink placeholder:text-muted focus:border-cinnabar focus:ring-4 focus:ring-cinnabar/15 outline-none">
                <svg class="absolute right-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-muted pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <label for="subject-sort" class="sr-only">ترتیب</label>
                <select id="subject-sort" name="sort" onchange="this.form.submit()" class="px-3 py-2 bg-surface rounded-sm border border-line-strong text-sm text-ink">
                    <option value="copies" {{ $sort === 'copies' ? 'selected' : '' }}>بیشترین نسخه</option>
                    <option value="title" {{ $sort === 'title' ? 'selected' : '' }}>عنوان (الفبایی)</option>
                    <option value="date" {{ $sort === 'date' ? 'selected' : '' }}>کهن‌ترین تألیف</option>
                </select>
                <button type="submit" class="px-4 py-2 bg-cinnabar text-on-cinnabar font-semibold rounded-sm text-sm hover:opacity-90 transition cursor-pointer">پالایش</button>
                @if($search !== '' || $sort !== 'copies' || ($hasChildren && ! $isCumulative))
                    <a href="{{ route('subjects.show', $subject) }}" class="px-3 py-2 text-sm text-muted hover:text-cinnabar" title="برداشتن پالایه‌ها">✕</a>
                @endif
            </div>
        </form>

        @if($works->count() > 0)
            <ul class="divide-y divide-line border-y border-line">
                @foreach($works as $work)
                    <li class="py-3.5 space-y-1">
                        <div class="flex items-baseline gap-2">
                            <a href="{{ route('works.show', $work) }}" class="font-naskh text-lg font-bold text-ink hover:text-cinnabar transition leading-relaxed">{{ $work->primary_title }}</a>
                            <span class="leader hidden sm:block" aria-hidden="true"></span>
                            <span class="ms-auto sm:ms-0 text-xs text-muted tabular-nums whitespace-nowrap">{{ Fa::num($work->manuscripts_count) }} نسخه</span>
                        </div>
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[13px] text-ink/80">
                            @if($work->author)
                                <span>از <a href="{{ route('people.show', $work->author) }}" class="font-semibold text-ink hover:text-cinnabar">{{ $work->author->name }}</a> <x-ui.life :person="$work->author" /></span>
                            @elseif($work->author_name)
                                <span>از <span class="font-semibold text-ink">{{ $work->author_name }}</span></span>
                            @else
                                <span class="text-muted">پدیدآور ناشناخته</span>
                            @endif
                            @if($work->composition_year_hijri)<span>تألیف {{ Fa::digits($work->composition_year_hijri) }} ق</span>@endif
                            @if($work->work_form)<span>{{ $work->work_form }}</span>@endif
                            @if($work->has_autograph)<x-ui.chip tone="good" class="!text-[11px] !py-0">دستخط مؤلف</x-ui.chip>@endif
                        </div>
                        @php $otherTags = $work->subjects->where('id', '!=', $subject->id)->pluck('name')->merge($work->languages->pluck('name')); @endphp
                        @if($otherTags->isNotEmpty())
                            <div class="text-xs text-muted">{{ $otherTags->implode(' · ') }}</div>
                        @endif
                    </li>
                @endforeach
            </ul>
            @if($works->hasPages())
                <div class="pt-4">{{ $works->links() }}</div>
            @endif
        @else
            <div class="py-10 text-center space-y-2">
                <p class="text-sm text-muted">اثری با این مشخصات در این موضوع پیدا نشد.</p>
                @if($search !== '')
                    <a href="{{ route('subjects.show', $subject) }}" class="text-sm text-cinnabar hover:underline">برداشتن جستجو</a>
                @endif
            </div>
        @endif
    </x-ui.section>

</div>
@endsection

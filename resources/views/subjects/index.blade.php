@extends('layouts.app')

@section('title', 'موضوعات و شاخه‌های دانشی نسخ خطی | فهارس')
@section('meta_description', 'درختواره جامع موضوعات و رده‌بندی‌های دانشی متون و نسخه‌های کهن خطی ایران و جهان اسلام، شامل بیش از ' . \App\Support\Fa::approx(\App\Support\SiteStats::all()['works']) . ' اثر.')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6" 
     x-data="{
        search: '{{ addslashes($search) }}',
        matches(name, children) {
            if (!this.search.trim()) return true;
            const term = this.search.trim().toLowerCase();
            if (name.toLowerCase().includes(term)) return true;
            if (children && children.some(c => c.toLowerCase().includes(term))) return true;
            return false;
        }
     }">

    @php use App\Support\Fa; @endphp

    <!-- Header -->
    <header class="text-center grid justify-items-center gap-2 pb-2">
        <p class="text-xs font-bold text-cinnabar">طبقه‌بندی موضوعی</p>
        <h1 class="font-naskh text-[28px] sm:text-[38px] font-bold leading-[1.6] text-ink text-balance">موضوعات و شاخه‌های دانش</h1>
        <p class="text-sm text-muted max-w-2xl leading-relaxed">
            {{ Fa::num($totalRootSubjects) }} رده‌ی اصلی و {{ Fa::num($totalSubSubjects) }} زیرشاخه، دربرگیرنده‌ی {{ Fa::num($totalWorks) }} اثر. هر موضوع شناسنامه‌ای با زیرشاخه‌ها و آثار خود دارد.
        </p>
        <div class="relative w-full max-w-xl mt-3">
            <label for="subject-filter" class="sr-only">پالایش موضوعات</label>
            <input id="subject-filter" type="text" x-model="search"
                placeholder="پالایش در نام موضوع‌ها، مثلاً شعر، فقه، هیئت"
                class="w-full pr-11 pl-10 py-3 bg-surface rounded-sm border border-line-strong text-sm text-ink placeholder:text-muted focus:border-cinnabar focus:ring-4 focus:ring-cinnabar/15 outline-none transition">
            <svg class="absolute right-3.5 top-1/2 -translate-y-1/2 w-5 h-5 text-muted pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
            <button type="button" x-show="search.length > 0" x-cloak @click="search = ''" class="absolute left-3 top-1/2 -translate-y-1/2 p-1 text-muted hover:text-ink cursor-pointer" title="پاک کردن">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
    </header>

    <div class="jadval" aria-hidden="true"></div>

    <!-- Subject index -->
    <div class="columns-1 md:columns-2 lg:columns-3 gap-10 [column-fill:_balance]">
        @foreach($rootSubjects as $root)
            @php $childNamesJson = json_encode($root->children->pluck('name')->toArray(), JSON_UNESCAPED_UNICODE); @endphp
            <section x-show="matches('{{ addslashes($root->name) }}', {{ $childNamesJson }})" class="break-inside-avoid mb-8">
                <a href="{{ route('subjects.show', $root) }}" class="flex items-baseline gap-2 group">
                    <h2 class="rubric text-lg group-hover:underline">{{ $root->name }}</h2>
                    <span class="leader" aria-hidden="true"></span>
                    <span class="text-xs text-muted tabular-nums whitespace-nowrap">{{ Fa::num($root->works_count) }} اثر</span>
                </a>
                @if($root->children->isNotEmpty())
                    <ul class="mt-2 ps-5 space-y-0.5 text-sm">
                        @foreach($root->children as $child)
                            <li>
                                <a href="{{ route('subjects.show', $child) }}" class="flex items-baseline gap-2 py-0.5 group/c">
                                    <span class="text-ink group-hover/c:text-cinnabar transition">{{ $child->name }}</span>
                                    <span class="leader" aria-hidden="true"></span>
                                    <span class="text-xs text-muted tabular-nums">{{ Fa::num($child->works_count) }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endforeach
    </div>

</div>
@endsection

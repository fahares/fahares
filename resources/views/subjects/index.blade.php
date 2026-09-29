@extends('layouts.app')

@section('title', 'موضوعات و شاخه‌های دانشی نسخ خطی | فهارس')
@section('meta_description', 'درختواره جامع موضوعات و رده‌بندی‌های دانشی متون و نسخه‌های کهن خطی ایران و جهان اسلام، شامل بیش از ۷۶ هزار اثر در ۲۲ رده اصلی و ۱۰۷ زیرشاخه تخصصی.')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8" 
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

    <!-- Page Header & Statistics Banner -->
    <div class="relative overflow-hidden bg-white dark:bg-[#15192C] p-6 sm:p-10 rounded-3xl border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-6">
        <div class="absolute -right-16 -top-16 w-64 h-64 bg-amber-500/5 dark:bg-amber-400/5 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute -left-16 -bottom-16 w-64 h-64 bg-[#292C56]/5 dark:bg-[#B38A50]/5 rounded-full blur-3xl pointer-events-none"></div>

        <div class="flex flex-col md:flex-row md:items-center justify-between gap-6 relative z-10">
            <div class="space-y-2 max-w-2xl">
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-bold bg-[#B38A50]/10 text-[#B38A50] border border-[#B38A50]/20">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                    <span>تاکسونومی و طبقه‌بندی موضوعی</span>
                </div>
                <h1 class="text-2xl sm:text-3xl lg:text-4xl font-black text-[#292C56] dark:text-amber-100 tracking-tight">
                    درختواره موضوعات و شاخه‌های دانشی
                </h1>
                <p class="text-xs sm:text-sm text-stone-500 dark:text-stone-400 leading-relaxed">
                    مرور ساختاریافته گنجینه نسخ کهن خطی در ۲۲ رده کلان دانشی و بیش از ۱۰۰ زیرشاخه تخصصی؛ با کلیک بر هر شاخه می‌توانید شناسنامه، زیرمجموعه‌ها و کلیه آثار آن را مطالعه نمایید.
                </p>
            </div>

            <!-- Aggregate Stats Pills -->
            <div class="flex items-center gap-3 shrink-0 flex-wrap">
                <div class="bg-stone-50 dark:bg-stone-800/80 px-4 py-3 rounded-2xl border border-stone-200/80 dark:border-stone-700/80 text-center min-w-[100px]">
                    <span class="block text-xl sm:text-2xl font-black text-[#292C56] dark:text-amber-100">{{ number_format($totalRootSubjects) }}</span>
                    <span class="text-[11px] font-semibold text-stone-500 dark:text-stone-400">رده کلان</span>
                </div>
                <div class="bg-stone-50 dark:bg-stone-800/80 px-4 py-3 rounded-2xl border border-stone-200/80 dark:border-stone-700/80 text-center min-w-[100px]">
                    <span class="block text-xl sm:text-2xl font-black text-[#B38A50]">{{ number_format($totalSubSubjects) }}</span>
                    <span class="text-[11px] font-semibold text-stone-500 dark:text-stone-400">زیرشاخه تخصصی</span>
                </div>
                <div class="bg-stone-50 dark:bg-stone-800/80 px-4 py-3 rounded-2xl border border-stone-200/80 dark:border-stone-700/80 text-center min-w-[110px]">
                    <span class="block text-xl sm:text-2xl font-black text-[#292C56] dark:text-amber-100">{{ number_format($totalWorks) }}</span>
                    <span class="text-[11px] font-semibold text-stone-500 dark:text-stone-400">کل عناوین دارای موضوع</span>
                </div>
            </div>
        </div>

        <!-- Live Search / Instant Filter Input -->
        <div class="relative pt-2">
            <div class="relative flex items-center">
                <input 
                    type="text" 
                    x-model="search"
                    placeholder="جستجو و پالایش زنده در نام موضوعات اصلی یا زیرشاخه‌ها (مثلاً: شعر، تاریخ، فقه، فلسفه، هیئت...)"
                    class="w-full pr-11 pl-10 py-3.5 bg-stone-50 dark:bg-stone-800/90 rounded-2xl border border-stone-300 dark:border-stone-700 text-sm text-stone-800 dark:text-stone-100 placeholder-stone-400 focus:border-[#B38A50] focus:ring-2 focus:ring-[#B38A50]/20 transition shadow-xs">
                
                <div class="absolute right-3.5 text-stone-400 pointer-events-none">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                </div>

                <button 
                    type="button" 
                    x-show="search.length > 0" 
                    @click="search = ''" 
                    class="absolute left-3 p-1 rounded-full text-stone-400 hover:text-stone-600 dark:hover:text-stone-200 hover:bg-stone-200 dark:hover:bg-stone-700 transition"
                    title="پاک کردن">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Category Grid Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        @foreach($rootSubjects as $root)
            @php
                $childNamesJson = json_encode($root->children->pluck('name')->toArray(), JSON_UNESCAPED_UNICODE);
            @endphp
            <div 
                x-show="matches('{{ addslashes($root->name) }}', {{ $childNamesJson }})"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 transform scale-95"
                x-transition:enter-end="opacity-100 transform scale-100"
                class="bg-white dark:bg-[#15192C] rounded-3xl border border-stone-200/90 dark:border-stone-800 hover:border-[#B38A50]/70 dark:hover:border-[#B38A50]/70 shadow-sm hover:shadow-md transition-all duration-200 flex flex-col justify-between group overflow-hidden">
                
                <!-- Card Header -->
                <div class="p-5 sm:p-6 space-y-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <span class="w-10 h-10 rounded-2xl bg-amber-500/10 dark:bg-amber-400/10 text-[#B38A50] dark:text-amber-300 flex items-center justify-center font-bold text-lg group-hover:scale-105 transition-transform duration-200">
                                📚
                            </span>
                            <div>
                                <a href="{{ route('subjects.show', $root) }}" class="text-lg font-black text-[#292C56] dark:text-amber-100 group-hover:text-[#B38A50] transition">
                                    {{ $root->name }}
                                </a>
                                <div class="text-[11px] text-stone-400 mt-0.5">
                                    {{ $root->children->count() > 0 ? $root->children->count() . ' شاخه فرعی' : 'شاخه واحد' }}
                                </div>
                            </div>
                        </div>

                        <!-- Works Count Badge -->
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-black bg-amber-50 dark:bg-amber-950/40 text-[#B38A50] border border-amber-500/20 shadow-2xs shrink-0">
                            {{ number_format($root->works_count) }} اثر
                        </span>
                    </div>

                    <!-- Subcategories / Children Badges -->
                    @if($root->children->isNotEmpty())
                        <div class="space-y-2 pt-2 border-t border-stone-100 dark:border-stone-800/80">
                            <div class="text-[11px] font-bold text-stone-400 uppercase tracking-wider">زیرشاخه‌ها:</div>
                            <div class="flex flex-wrap gap-1.5 max-h-36 overflow-y-auto pr-1">
                                @foreach($root->children as $child)
                                    <a href="{{ route('subjects.show', $child) }}" 
                                       class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs bg-stone-50 dark:bg-stone-800/80 hover:bg-amber-500/10 dark:hover:bg-amber-400/10 text-stone-700 dark:text-stone-300 hover:text-[#B38A50] dark:hover:text-amber-300 border border-stone-200/70 dark:border-stone-700/60 hover:border-[#B38A50]/40 transition duration-150 group/tag">
                                        <span>{{ $child->name }}</span>
                                        <span class="text-[10px] text-stone-400 group-hover/tag:text-[#B38A50]">({{ number_format($child->works_count) }})</span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <div class="pt-2 border-t border-stone-100 dark:border-stone-800/80 text-xs text-stone-400 italic">
                            این موضوع به عنوان شاخه متمرکز فاقد زیرشاخه فرعی است.
                        </div>
                    @endif
                </div>

                <!-- Card Footer / Direct Action -->
                <div class="px-5 py-3.5 bg-stone-50/70 dark:bg-stone-800/40 border-t border-stone-100 dark:border-stone-800/80 flex items-center justify-between text-xs">
                    <a href="{{ route('subjects.show', $root) }}" class="font-bold text-[#B38A50] hover:text-[#9C753F] dark:hover:text-amber-300 flex items-center gap-1 transition">
                        <span>ورود به شناسنامه و آثار</span>
                        <span>←</span>
                    </a>

                    <a href="{{ route('search', ['type' => 'works', 'subject_id' => $root->id]) }}" 
                       class="text-stone-400 hover:text-stone-600 dark:hover:text-stone-200 transition text-[11px]" 
                       title="جستجوی پیشرفته در این موضوع">
                        کاوش در موتور جستجو
                    </a>
                </div>

            </div>
        @endforeach
    </div>

</div>
@endsection

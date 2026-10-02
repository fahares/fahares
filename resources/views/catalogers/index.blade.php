@extends('layouts.app')

@section('title', 'فهرست‌نگاران و میراث‌پژوهان نسخه‌های خطی | فهارس')
@section('meta_description', 'شناسنامه و کارنامه پژوهشی فهرست‌نگاران برجسته و استادان کتاب‌شناسی نسخه‌های خطی همراه با مجلدات فهارس و نسخه‌های توصیف‌شده')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">

    <!-- Header & Stats Banner -->
    <div class="bg-white dark:bg-[#15192C] p-6 sm:p-8 rounded-3xl border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-6">
        
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">
            <div class="space-y-2">
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-amber-50 dark:bg-amber-950/60 border border-amber-200 dark:border-amber-800/60 text-[#B38A50] text-xs font-bold">
                    <svg class="w-4 h-4 text-[#B38A50]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                    </svg>
                    <span>معرفی خادمان میراث مکتوب</span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-black text-[#292C56] dark:text-amber-100 tracking-tight">
                    فهرست‌نگاران و کتاب‌شناسان نسخ خطی
                </h1>
                <p class="text-xs sm:text-sm text-stone-500 dark:text-stone-400 max-w-3xl leading-relaxed">
                    فهرست استادان، دانشمندان و فهرست‌نگارانی که میراث دست‌نویس کهن را در فهارس چاپی و اسنادی توصیف کرده‌اند. با کلیک بر روی هر فهرست‌نگار، مجلدات تألیفی و تمامی نسخه‌های معرفی‌شده به قلم وی را مشاهده فرمایید.
                </p>
            </div>

            <!-- Stats badges -->
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 shrink-0">
                <div class="p-3.5 rounded-2xl bg-stone-50 dark:bg-[#0C0F1D] border border-stone-200 dark:border-stone-800 text-center">
                    <div class="text-xl font-black text-[#B38A50]">{{ number_format($stats['total_catalogers']) }}</div>
                    <div class="text-[11px] text-stone-500 dark:text-stone-400 font-medium">فهرست‌نگار</div>
                </div>
                <div class="p-3.5 rounded-2xl bg-stone-50 dark:bg-[#0C0F1D] border border-stone-200 dark:border-stone-800 text-center">
                    <div class="text-xl font-black text-[#292C56] dark:text-amber-200">{{ number_format($stats['total_volumes']) }}</div>
                    <div class="text-[11px] text-stone-500 dark:text-stone-400 font-medium">مجلد مأخذ چاپی</div>
                </div>
                <div class="col-span-2 sm:col-span-1 p-3.5 rounded-2xl bg-stone-50 dark:bg-[#0C0F1D] border border-stone-200 dark:border-stone-800 text-center">
                    <div class="text-xl font-black text-emerald-600 dark:text-emerald-400">{{ number_format($stats['total_manuscripts']) }}</div>
                    <div class="text-[11px] text-stone-500 dark:text-stone-400 font-medium">نسخه مستندشده</div>
                </div>
            </div>
        </div>

        <!-- Search & Filter Form -->
        <form action="{{ route('catalogers.index') }}" method="GET" class="pt-4 border-t border-stone-100 dark:border-stone-800/80 grid grid-cols-1 sm:grid-cols-12 gap-3">
            <div class="sm:col-span-8 relative">
                <input 
                    type="text" 
                    name="q" 
                    value="{{ $search }}" 
                    placeholder="جستجو در نام یا زندگینامه فهرست‌نگار..."
                    class="w-full pr-10 pl-4 py-2.5 bg-stone-50 dark:bg-stone-800/80 rounded-xl border border-stone-300 dark:border-stone-700 text-xs sm:text-sm text-stone-800 dark:text-stone-100 focus:border-[#B38A50] focus:ring-2 focus:ring-[#B38A50]/20 transition">
                <div class="absolute right-3.5 top-3 text-stone-400">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                </div>
            </div>

            <div class="sm:col-span-4 flex items-center gap-2">
                <select name="sort" class="w-full py-2.5 px-3 bg-stone-50 dark:bg-stone-800/80 rounded-xl border border-stone-300 dark:border-stone-700 text-xs sm:text-sm text-stone-700 dark:text-stone-200 focus:border-[#B38A50]">
                    <option value="birth_year" {{ $sort === 'birth_year' ? 'selected' : '' }}>سال ولادت (کهن‌تر به جدید)</option>
                    <option value="manuscripts" {{ $sort === 'manuscripts' ? 'selected' : '' }}>بیشترین نسخه توصیف‌شده</option>
                    <option value="volumes" {{ $sort === 'volumes' ? 'selected' : '' }}>بیشترین مجلدات فهرست</option>
                    <option value="name" {{ $sort === 'name' ? 'selected' : '' }}>ترتیب الفبایی نام</option>
                </select>

                <button type="submit" class="px-5 py-2.5 bg-[#B38A50] hover:bg-[#9C753F] text-white font-bold rounded-xl text-xs sm:text-sm transition shadow-sm shrink-0 cursor-pointer">
                    اعمال
                </button>
            </div>
        </form>

    </div>

    <!-- Catalogers Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">
        @forelse($catalogers as $cat)
            <a href="{{ route('catalogers.show', $cat) }}" 
               class="group relative bg-white dark:bg-[#15192C] p-5 rounded-3xl border border-[#EADFCF] dark:border-[#272F4C] hover:border-[#B38A50] dark:hover:border-[#B38A50] shadow-sm hover:shadow-lg hover:-translate-y-0.5 transition duration-200 flex flex-col justify-between">
                
                <div class="space-y-4">
                    <!-- Top row: Avatar & Dates -->
                    <div class="flex items-start justify-between gap-3">
                        <div class="relative shrink-0">
                            @if($cat->avatar_url)
                                <img src="{{ $cat->avatar_url }}" 
                                     alt="{{ $cat->name }}" 
                                     class="w-16 h-16 rounded-2xl object-cover border-2 border-amber-200 dark:border-amber-900/60 shadow-sm group-hover:scale-105 transition duration-200"
                                     loading="lazy">
                            @else
                                <div class="w-16 h-16 rounded-2xl bg-amber-50 dark:bg-amber-950/40 border-2 border-amber-200 dark:border-amber-900/60 flex items-center justify-center text-xl font-black text-[#B38A50] shadow-sm">
                                    {{ mb_substr($cat->name, 0, 1) }}
                                </div>
                            @endif
                        </div>

                        <div class="flex flex-col items-end gap-1">
                            @if($cat->life_years_text)
                                <span class="px-2 py-0.5 rounded-md bg-stone-100 dark:bg-stone-800 text-[10px] font-bold text-stone-600 dark:text-stone-300">
                                    {{ $cat->life_years_text }}
                                </span>
                            @endif
                        </div>
                    </div>

                    <!-- Names -->
                    <div class="space-y-1">
                        <div class="text-base font-bold text-[#292C56] dark:text-stone-100 group-hover:text-[#B38A50] transition line-clamp-1">
                            {{ $cat->display_name }}
                        </div>
                        @if($cat->bio)
                            <p class="text-xs text-stone-500 dark:text-stone-400 line-clamp-2 leading-relaxed">
                                {{ Str::limit(strip_tags($cat->bio), 100) }}
                            </p>
                        @endif
                    </div>
                </div>

                <!-- Footer Stats -->
                <div class="mt-5 pt-3 border-t border-stone-100 dark:border-stone-800/80 flex items-center justify-between text-xs">
                    <div class="flex items-center gap-1.5 text-stone-500 dark:text-stone-400">
                        <svg class="w-3.5 h-3.5 text-[#B38A50]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                        </svg>
                        <span>{{ $cat->volumes_count }} جلد</span>
                    </div>

                    <span class="font-bold text-[#B38A50] bg-amber-50 dark:bg-amber-950/40 px-2.5 py-0.5 rounded-full text-[11px] border border-amber-200 dark:border-amber-800/60">
                        {{ number_format($cat->manuscripts_count) }} نسخه
                    </span>
                </div>

            </a>
        @empty
            <div class="col-span-full p-12 bg-white dark:bg-[#15192C] rounded-3xl text-center text-stone-400 border border-stone-200 dark:border-stone-800">
                فهرست‌نگاری با این مشخصات یافت نشد.
            </div>
        @endforelse
    </div>

    <!-- Pagination -->
    @if($catalogers->hasPages())
        <div class="pt-4 flex justify-center">
            {{ $catalogers->links() }}
        </div>
    @endif

</div>
@endsection

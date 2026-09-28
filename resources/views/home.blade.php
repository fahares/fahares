@extends('layouts.app')

@section('title', 'فهارس | سامانه جامع کتاب‌شناسی و نسخه‌شناسی مکتوب')

@section('content')
<div class="space-y-16 py-6 sm:py-10">

    <!-- HERO SECTION -->
    <section class="max-w-5xl mx-auto px-4 sm:px-6 text-center space-y-8">
        
        <!-- Logo Emblem & Title -->
        <div class="inline-flex flex-col items-center justify-center space-y-4">
            <div class="relative group">
                <div class="absolute -inset-1 rounded-full bg-gradient-to-r from-[#B38A50] to-[#292C56] opacity-30 blur-lg group-hover:opacity-60 transition duration-500"></div>
                <img src="{{ asset('images/logo_emblem.png') }}" alt="نشان فهارس" class="relative h-28 sm:h-36 w-auto drop-shadow-md">
            </div>
            
            <div class="space-y-2">
                <h1 class="text-4xl sm:text-5xl font-black text-[#292C56] dark:text-amber-100 tracking-tight">
                    فهارس
                </h1>
                <p class="text-lg sm:text-xl font-medium text-[#B38A50] dark:text-amber-300/90">
                    پایگاه جامع کتاب‌شناسی و نسخه‌های کهن خطی
                </p>
                <p class="text-xs sm:text-sm text-stone-500 dark:text-stone-400 max-w-2xl mx-auto leading-relaxed">
                    دسترسی یکپارچه به گنجینه فهارس خطی • مشتمل بر ۳۴ مجلد فنخا، ۷۶ هزار اثر و بیش از ۴۳۸ هزار نسخه
                </p>
            </div>
        </div>

        <!-- LIVE SEARCH WIDGET (Alpine.js with Instant Autocomplete) -->
        <div x-data="{
                query: '',
                activeTab: 'works',
                results: { works: [], people: [], manuscripts: [] },
                loading: false,
                isOpen: false,
                fetchResults() {
                    if (this.query.trim().length < 2) {
                        this.results = { works: [], people: [], manuscripts: [] };
                        this.isOpen = false;
                        return;
                    }
                    this.loading = true;
                    fetch('{{ route('search.api') }}?q=' + encodeURIComponent(this.query) + '&type=all')
                        .then(res => res.json())
                        .then(data => {
                            this.results = data;
                            this.isOpen = (data.works.length > 0 || data.people.length > 0 || data.manuscripts.length > 0);
                            this.loading = false;
                        })
                        .catch(() => { this.loading = false; });
                }
            }" 
            class="relative max-w-3xl mx-auto text-right">

            <!-- Search Mode Tabs -->
            <div class="flex items-center justify-center gap-2 mb-3 text-xs sm:text-sm font-semibold">
                <button 
                    @click="activeTab = 'works'" 
                    :class="activeTab === 'works' ? 'bg-[#292C56] text-amber-100 shadow-md' : 'bg-white dark:bg-stone-800 text-stone-600 dark:text-stone-300 hover:bg-stone-100'"
                    class="px-4 py-1.5 rounded-full border border-stone-200 dark:border-stone-700 transition duration-150">
                    آثار و عناوین
                </button>
                <button 
                    @click="activeTab = 'manuscripts'" 
                    :class="activeTab === 'manuscripts' ? 'bg-[#292C56] text-amber-100 shadow-md' : 'bg-white dark:bg-stone-800 text-stone-600 dark:text-stone-300 hover:bg-stone-100'"
                    class="px-4 py-1.5 rounded-full border border-stone-200 dark:border-stone-700 transition duration-150">
                    نسخه‌های خطی
                </button>
                <button 
                    @click="activeTab = 'people'" 
                    :class="activeTab === 'people' ? 'bg-[#292C56] text-amber-100 shadow-md' : 'bg-white dark:bg-stone-800 text-stone-600 dark:text-stone-300 hover:bg-stone-100'"
                    class="px-4 py-1.5 rounded-full border border-stone-200 dark:border-stone-700 transition duration-150">
                    پدیدآوران و کاتبان
                </button>
            </div>

            <!-- Form -->
            <form action="{{ route('search') }}" method="GET" class="relative group">
                <input type="hidden" name="type" :value="activeTab">
                <input type="hidden" name="scope" value="titles_names">
                
                <div class="relative flex items-center">
                    <input 
                        type="text" 
                        name="q" 
                        x-model="query" 
                        @input.debounce.250ms="fetchResults()"
                        @focus="if(query.trim().length >= 2) isOpen = true"
                        @click.away="isOpen = false"
                        autocomplete="off"
                        placeholder="جستجو در عنوان اثر، نام مؤلف، کاتب، آغاز/انجام، یا شماره نسخه..."
                        class="w-full pr-14 pl-28 py-4 sm:py-5 bg-white dark:bg-[#15192C] text-stone-800 dark:text-stone-100 placeholder-stone-400 text-base sm:text-lg rounded-2xl border-2 border-[#EADFCF] dark:border-[#272F4C] focus:border-[#B38A50] dark:focus:border-[#B38A50] focus:ring-4 focus:ring-[#B38A50]/20 shadow-xl transition duration-200">
                    
                    <!-- Search Icon -->
                    <div class="absolute right-4 text-stone-400 group-focus-within:text-[#B38A50] transition">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    </div>

                    <!-- Submit Button -->
                    <button 
                        type="submit" 
                        class="absolute left-2.5 px-5 py-2.5 sm:py-3 bg-gradient-to-r from-[#B38A50] to-[#8F6B38] hover:from-[#9C753F] hover:to-[#7A5A2E] text-white font-semibold rounded-xl text-sm shadow-md hover:shadow-lg transition duration-200 flex items-center gap-1.5">
                        <span>کاوش</span>
                        <svg class="w-4 h-4 rtl:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                    </button>
                </div>
            </form>

            <!-- Realtime Autocomplete Dropdown -->
            <div 
                x-show="isOpen" 
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 translate-y-2"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-100"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 translate-y-2"
                class="absolute left-0 right-0 mt-2 bg-white dark:bg-[#15192C] rounded-2xl shadow-2xl border border-stone-200 dark:border-stone-700 max-h-96 overflow-y-auto z-50 p-2 divide-y divide-stone-100 dark:divide-stone-800"
                style="display: none;">
                
                <!-- Matching Works -->
                <template x-if="results.works && results.works.length > 0">
                    <div class="py-2">
                        <div class="px-3 py-1 text-[11px] font-bold text-[#B38A50] uppercase tracking-wider">آثار منطبق</div>
                        <template x-for="item in results.works" :key="'w-' + item.id">
                            <a :href="item.url" class="flex items-center justify-between px-3 py-2 rounded-xl hover:bg-stone-50 dark:hover:bg-stone-800/60 transition group">
                                <div>
                                    <div class="text-sm font-bold text-stone-800 dark:text-stone-100 group-hover:text-[#B38A50]" x-text="item.title"></div>
                                    <div class="text-xs text-stone-400" x-text="item.author"></div>
                                </div>
                                <span class="text-xs px-2 py-0.5 rounded-full bg-amber-50 dark:bg-amber-950/40 text-[#B38A50] font-mono" x-text="item.manuscripts_count + ' نسخه'"></span>
                            </a>
                        </template>
                    </div>
                </template>

                <!-- Matching People -->
                <template x-if="results.people && results.people.length > 0">
                    <div class="py-2">
                        <div class="px-3 py-1 text-[11px] font-bold text-[#292C56] dark:text-indigo-400 uppercase tracking-wider">پدیدآوران و کاتبان</div>
                        <template x-for="item in results.people" :key="'p-' + item.id">
                            <a :href="item.url" class="flex items-center justify-between px-3 py-2 rounded-xl hover:bg-stone-50 dark:hover:bg-stone-800/60 transition group">
                                <div class="text-sm font-semibold text-stone-800 dark:text-stone-100 group-hover:text-[#B38A50]" x-text="item.name"></div>
                                <span class="text-xs text-stone-400" x-text="item.death_hijri"></span>
                            </a>
                        </template>
                    </div>
                </template>

                <!-- Matching Manuscripts -->
                <template x-if="results.manuscripts && results.manuscripts.length > 0">
                    <div class="py-2">
                        <div class="px-3 py-1 text-[11px] font-bold text-emerald-600 dark:text-emerald-400 uppercase tracking-wider">نسخه‌های خطی</div>
                        <template x-for="item in results.manuscripts" :key="'m-' + item.id">
                            <a :href="item.url" class="flex items-center justify-between px-3 py-2 rounded-xl hover:bg-stone-50 dark:hover:bg-stone-800/60 transition group">
                                <div>
                                    <div class="text-sm font-semibold text-stone-800 dark:text-stone-100 group-hover:text-[#B38A50]" x-text="item.work_title"></div>
                                    <div class="text-xs text-stone-400" x-text="item.library + ' (بازیابی: ' + (item.accession_number || 'بی‌شماره') + ')'"></div>
                                </div>
                                <span class="text-[11px] text-stone-400">مشاهده نسخه ←</span>
                            </a>
                        </template>
                    </div>
                </template>

            </div>

            <!-- Quick Suggestions Pills -->
            <div class="mt-4 flex flex-wrap items-center justify-center gap-1.5 text-xs text-stone-500 dark:text-stone-400">
                <span class="font-medium text-stone-400 ml-1">پیشنهاد کاوش:</span>
                @foreach(['مثنوی معنوی', 'دیوان حافظ', 'شاهنامه', 'نهج‌البلاغه', 'گلستان', 'قانون ابن سینا', 'علامه حلی'] as $pill)
                    <a href="{{ route('search', ['q' => $pill, 'type' => 'works']) }}" class="px-2.5 py-1 rounded-lg bg-stone-100 dark:bg-stone-800 hover:bg-amber-100 dark:hover:bg-amber-950/40 hover:text-[#B38A50] transition">
                        {{ $pill }}
                    </a>
                @endforeach
            </div>

        </div>

    </section>

    <!-- LIVE STATS METRICS (34 Volumes, 71k Works, 323k Manuscripts) -->
    <section class="max-w-6xl mx-auto px-4 sm:px-6">
        <div class="bg-white/80 dark:bg-[#15192C]/80 backdrop-blur-md rounded-3xl p-6 sm:p-8 border border-[#EADFCF] dark:border-[#272F4C] shadow-lg">
            <div class="grid grid-cols-2 md:grid-cols-5 gap-6 text-center divide-y md:divide-y-0 md:divide-x md:divide-x-reverse divide-stone-200 dark:divide-stone-800">
                
                <div class="space-y-1 pt-3 md:pt-0">
                    <div class="text-3xl sm:text-4xl font-black text-[#292C56] dark:text-amber-100 font-mono">
                        {{ number_format($stats['volumes_count']) }}
                    </div>
                    <div class="text-xs sm:text-sm font-semibold text-stone-500 dark:text-stone-400">
                        مجلدات فهرستگان
                    </div>
                </div>

                <div class="space-y-1 pt-3 md:pt-0">
                    <div class="text-3xl sm:text-4xl font-black text-[#B38A50] font-mono">
                        {{ number_format($stats['works_count']) }}
                    </div>
                    <div class="text-xs sm:text-sm font-semibold text-stone-500 dark:text-stone-400">
                        عناوین آثار
                    </div>
                </div>

                <div class="space-y-1 pt-3 md:pt-0">
                    <div class="text-3xl sm:text-4xl font-black text-[#292C56] dark:text-amber-100 font-mono">
                        {{ number_format($stats['manuscripts_count']) }}
                    </div>
                    <div class="text-xs sm:text-sm font-semibold text-stone-500 dark:text-stone-400">
                        نسخه‌های خطی
                    </div>
                </div>

                <div class="space-y-1 pt-3 md:pt-0">
                    <div class="text-3xl sm:text-4xl font-black text-[#B38A50] font-mono">
                        {{ number_format($stats['people_count']) }}
                    </div>
                    <div class="text-xs sm:text-sm font-semibold text-stone-500 dark:text-stone-400">
                        پدیدآوران و کاتبان
                    </div>
                </div>

                <div class="col-span-2 md:col-span-1 space-y-1 pt-3 md:pt-0">
                    <div class="text-3xl sm:text-4xl font-black text-[#292C56] dark:text-amber-100 font-mono">
                        {{ number_format($stats['libraries_count']) }}
                    </div>
                    <div class="text-xs sm:text-sm font-semibold text-stone-500 dark:text-stone-400">
                        کتابخانه‌ها و مراکز
                    </div>
                </div>

            </div>
        </div>
    </section>

    <!-- TOP SUBJECTS TAXONOMY -->
    <section class="max-w-6xl mx-auto px-4 sm:px-6 space-y-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="w-2.5 h-6 bg-[#B38A50] rounded-sm"></span>
                <h2 class="text-xl sm:text-2xl font-bold text-stone-900 dark:text-stone-100">رده‌بندی‌های موضوعی شاخص</h2>
            </div>
            <a href="{{ route('search', ['type' => 'works']) }}" class="text-xs sm:text-sm font-semibold text-[#B38A50] hover:underline flex items-center gap-1">
                <span>مشاهده همه موضوعات</span>
                <span>←</span>
            </a>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            @foreach($topSubjects as $subject)
                <a href="{{ route('search', ['type' => 'works', 'subject_id' => $subject->id]) }}" 
                   class="group bg-white dark:bg-[#15192C] p-4 rounded-2xl border border-stone-200 dark:border-stone-800 hover:border-[#B38A50] dark:hover:border-[#B38A50] shadow-sm hover:shadow-md transition duration-200 flex flex-col justify-between h-28">
                    <div class="text-base font-bold text-stone-800 dark:text-stone-100 group-hover:text-[#B38A50] transition">
                        {{ $subject->title }}
                    </div>
                    <div class="flex items-center justify-between text-xs text-stone-400">
                        <span>تعداد عناوین</span>
                        <span class="font-mono font-bold text-[#B38A50] bg-amber-50 dark:bg-amber-950/40 px-2 py-0.5 rounded-full">
                            {{ number_format($subject->works_count) }}
                        </span>
                    </div>
                </a>
            @endforeach
        </div>
    </section>

    <!-- PROMINENT LIBRARIES & ARCHIVES -->
    <section class="max-w-6xl mx-auto px-4 sm:px-6 space-y-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="w-2.5 h-6 bg-[#292C56] dark:bg-indigo-400 rounded-sm"></span>
                <h2 class="text-xl sm:text-2xl font-bold text-stone-900 dark:text-stone-100">بزرگ‌ترین گنجینه‌های نسخ خطی</h2>
            </div>
            <a href="{{ route('libraries.index') }}" class="text-xs sm:text-sm font-semibold text-[#B38A50] hover:underline flex items-center gap-1">
                <span>نمایش همه ۱٬۰۴۸ کتابخانه</span>
                <span>←</span>
            </a>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
            @foreach($topLibraries as $lib)
                <a href="{{ route('libraries.show', $lib->id) }}" 
                   class="group bg-white dark:bg-[#15192C] p-4 rounded-2xl border border-stone-200 dark:border-stone-800 hover:border-[#292C56] dark:hover:border-indigo-400 shadow-sm hover:shadow-md transition duration-200 flex flex-col justify-between">
                    <div>
                        <div class="text-sm font-bold text-stone-800 dark:text-stone-100 group-hover:text-[#292C56] dark:group-hover:text-amber-200 transition line-clamp-1">
                            {{ $lib->name }}
                        </div>
                        <div class="text-xs text-stone-400 mt-1">
                            {{ $lib->city ?? 'ایران' }}
                        </div>
                    </div>
                    <div class="mt-4 pt-3 border-t border-stone-100 dark:border-stone-800 flex items-center justify-between text-xs text-stone-500">
                        <span>نسخه‌های موجود:</span>
                        <span class="font-mono font-bold text-stone-700 dark:text-stone-300">
                            {{ number_format($lib->manuscripts_count) }}
                        </span>
                    </div>
                </a>
            @endforeach
        </div>
    </section>

    <!-- ABOUT FANKHA & SCIENTIFIC CITATION -->
    <section class="max-w-6xl mx-auto px-4 sm:px-6">
        <div class="bg-gradient-to-br from-[#FAF7F2] to-[#F1ECE1] dark:from-[#15192C] dark:to-[#0F1224] rounded-3xl p-8 sm:p-10 border border-[#B38A50]/30 shadow-md flex flex-col md:flex-row items-center gap-8">
            <div class="w-full md:w-1/3 flex justify-center">
                <img src="{{ asset('images/fahares_logo_full.jpg') }}" alt="پوستر فهارس" class="h-64 sm:h-72 w-auto rounded-2xl shadow-xl border-4 border-white dark:border-stone-800 object-cover">
            </div>
            <div class="w-full md:w-2/3 space-y-4 text-justify">
                <div class="inline-block px-3 py-1 rounded-full bg-amber-100 dark:bg-amber-950/60 text-[#B38A50] text-xs font-bold">
                    دانشنامه میراث مکتوب
                </div>
                <h3 class="text-2xl font-bold text-stone-900 dark:text-stone-100">
                    مأخذ پایه: مجموعه ۳۴ جلدی «فنخا»
                </h3>
                <p class="text-sm leading-relaxed text-stone-600 dark:text-stone-300">
                    در گام نخست توسعه فهارس، <strong>فهرستگان نسخه‌های خطی ایران (فنخا)</strong> به عنوان جامع‌ترین مرجع به کوشش پژوهشگر برجسته <strong>استاد مصطفی درایتی</strong> در ۳۴ مجلد مبنای داده‌ها قرار گرفته است. به مرور زمان، سایر فهارس، فهرست‌های اختصاصی کتابخانه‌ها و مراجع نسخه‌شناسی کهن نیز در قالب این پایگاه یکپارچه عرضه خواهند شد.
                </p>
                <p class="text-sm leading-relaxed text-stone-600 dark:text-stone-300">
                    سامانه <strong>فهارس</strong> با پیاده‌سازی پایگاه داده رابطه‌ای مدرن و متصل به موتور Meilisearch، امکان کاوش بلادرنگ، فیلترگذاری چندبعدی و استناد علمی به مراجع نسخه‌شناسی را در بستری فاخر فراهم آورده است.
                </p>
                <div class="pt-2 flex flex-wrap items-center gap-3">
                    <a href="{{ route('search', ['type' => 'manuscripts']) }}" class="px-5 py-2.5 bg-[#292C56] text-amber-100 rounded-xl text-xs sm:text-sm font-semibold hover:bg-[#1A1D3B] transition shadow">
                        آغاز جستجو در نسخه‌ها
                    </a>
                    <a href="https://github.com/fahares/fahares" target="_blank" class="px-5 py-2.5 bg-white dark:bg-stone-800 border border-stone-300 dark:border-stone-700 text-stone-700 dark:text-stone-200 rounded-xl text-xs sm:text-sm font-semibold hover:bg-stone-50 transition">
                        مشاهده پروژه در گیت‌هاب
                    </a>
                </div>
            </div>
        </div>
    </section>

</div>
@endsection

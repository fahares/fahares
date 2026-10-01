<!DOCTYPE html>
<html lang="fa" dir="rtl" x-data="{ darkMode: document.documentElement.classList.contains('dark') }" :class="{ 'dark': darkMode }">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'فهارس | پایگاه جامع کتاب‌شناسی و نسخه‌شناسی مکتوب')</title>
    <meta name="description" content="@yield('meta_description', 'سامانه و موتور جستجوی جامع نسخه‌های خطی و کتاب‌شناسی مکتوب بر پایه مراجع و فهرستگان‌های معتبر')">
    
    <!-- Theme Initialization (Prevent FOUC and honor user/system preference) -->
    <script>
        if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon-32.png') }}">
    <link rel="icon" type="image/png" sizes="256x256" href="{{ asset('images/favicon-256.png') }}">
    
    <!-- Google Fonts: Vazirmatn -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="bg-parchment-pattern min-h-screen flex flex-col font-sans selection:bg-[#B38A50]/20 selection:text-[#B38A50]">

    <!-- Top Announcement / Brand Ribbon -->
    <div class="bg-gradient-to-r from-[#191B36] via-[#292C56] to-[#191B36] text-amber-100/90 text-xs py-1.5 px-4 text-center border-b border-[#B38A50]/30 shadow-sm flex flex-wrap items-center justify-center gap-2 sm:gap-3">
        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-bold bg-[#B38A50]/30 text-amber-200 border border-[#B38A50]/40">
            <span class="w-1.5 h-1.5 rounded-full bg-amber-400 animate-pulse"></span>
            نسخه آزمایشی
        </span>
        <span class="hidden sm:inline text-stone-500">•</span>
        <span>پایگاه فهارس نسخه‌های خطی • دربردارنده بیش از ۷۶ هزار اثر و ۴۳۸ هزار نسخه خطی</span>
    </div>

    <!-- Main Navigation Header (Glassmorphic) -->
    <header class="sticky top-0 z-40 bg-white/90 dark:bg-[#15192C]/90 backdrop-blur-md border-b border-[#EADFCF] dark:border-[#272F4C] transition-colors duration-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-20">
                
                <!-- Logo & Brand -->
                <div class="flex items-center gap-4">
                    <a href="{{ route('home') }}" class="flex items-center gap-3 group">
                        <img src="{{ asset('images/logo_emblem.png') }}" alt="فهارس" class="h-12 w-auto drop-shadow-sm group-hover:scale-105 transition-transform duration-200">
                        <div class="flex flex-col">
                            <div class="flex items-center gap-2">
                                <span class="text-2xl font-black text-[#292C56] dark:text-amber-100 tracking-tight leading-none group-hover:text-[#B38A50] transition-colors">فهارس</span>
                                <span class="inline-block px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-500/10 text-amber-800 dark:text-amber-300 border border-amber-500/20">آزمایشی</span>
                            </div>
                            <span class="text-[10px] text-stone-500 dark:text-stone-400 font-medium tracking-wide">فهرستگان نسخ خطی ایران</span>
                        </div>
                    </a>
                </div>

                <!-- Desktop Navigation Links -->
                <nav class="hidden md:flex items-center gap-1 text-sm font-semibold text-stone-700 dark:text-stone-300">
                    <a href="{{ route('home') }}" class="px-3 py-2 rounded-lg hover:text-[#B38A50] hover:bg-stone-100 dark:hover:bg-stone-800 transition {{ request()->routeIs('home') ? 'text-[#B38A50] bg-amber-50/60 dark:bg-amber-950/20' : '' }}">
                        صفحه نخست
                    </a>
                    <a href="{{ route('subjects.index') }}" class="px-3 py-2 rounded-lg hover:text-[#B38A50] hover:bg-stone-100 dark:hover:bg-stone-800 transition {{ request()->routeIs('subjects.*') ? 'text-[#B38A50] bg-amber-50/60 dark:bg-amber-950/20' : '' }}">
                        موضوعات
                    </a>
                    <a href="{{ route('libraries.index') }}" class="px-3 py-2 rounded-lg hover:text-[#B38A50] hover:bg-stone-100 dark:hover:bg-stone-800 transition {{ request()->routeIs('libraries.*') ? 'text-[#B38A50] bg-amber-50/60 dark:bg-amber-950/20' : '' }}">
                        کتابخانه‌ها و مراکز
                    </a>
                </nav>

                <!-- Actions: Search Trigger & Dark Mode Toggle -->
                <div class="flex items-center gap-3">
                    
                    <!-- Search Input Trigger Button (Opens Ctrl+K Spotlight Modal) -->
                    <button 
                        type="button" 
                        @click="window.dispatchEvent(new CustomEvent('open-spotlight-modal'))"
                        class="flex items-center gap-2 px-3 py-1.5 rounded-full border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800/80 text-xs text-stone-500 hover:border-[#B38A50] hover:text-[#B38A50] transition group shadow-sm cursor-pointer"
                        title="جستجوی سریع (Ctrl+K)">
                        <svg class="w-4 h-4 text-stone-400 group-hover:text-[#B38A50]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                        <span class="hidden sm:inline">کاوش سریع...</span>
                        <kbd class="hidden sm:inline-block px-1.5 py-0.5 text-[10px] bg-stone-200 dark:bg-stone-700 rounded text-stone-500 dark:text-stone-300 font-mono">Ctrl+K</kbd>
                    </button>

                    <!-- Dark Mode Toggle Button -->
                    <button 
                        type="button" 
                        @click="darkMode = !darkMode; localStorage.setItem('theme', darkMode ? 'dark' : 'light'); document.documentElement.classList.toggle('dark', darkMode)" 
                        class="p-2 rounded-full border border-stone-200 dark:border-stone-700 bg-stone-100 dark:bg-stone-800 text-stone-600 dark:text-amber-300 hover:text-[#B38A50] transition shadow-sm cursor-pointer"
                        title="تغییر حالت شب/روز">
                        <!-- Sun Icon (Dark Mode active) -->
                        <svg x-show="darkMode" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="4"/>
                            <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>
                        </svg>
                        <!-- Moon Icon (Light Mode active) -->
                        <svg x-show="!darkMode" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>
                        </svg>
                    </button>

                    <!-- Mobile Menu Button (Alpine) -->
                    <div x-data="{ open: false }" class="md:hidden relative">
                        <button @click="open = !open" class="p-2 rounded-lg border border-stone-200 dark:border-stone-700 text-stone-600 dark:text-stone-300">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                        </button>
                        <div x-show="open" @click.away="open = false" x-transition class="absolute left-0 mt-2 w-48 bg-white dark:bg-[#15192C] rounded-xl shadow-xl border border-stone-200 dark:border-stone-700 py-2 z-50 text-sm">
                            <a href="{{ route('home') }}" class="block px-4 py-2 hover:bg-stone-50 dark:hover:bg-stone-800 {{ request()->routeIs('home') ? 'text-[#B38A50] font-bold' : '' }}">صفحه نخست</a>
                            <a href="{{ route('subjects.index') }}" class="block px-4 py-2 hover:bg-stone-50 dark:hover:bg-stone-800 {{ request()->routeIs('subjects.*') ? 'text-[#B38A50] font-bold' : '' }}">موضوعات</a>
                            <a href="{{ route('libraries.index') }}" class="block px-4 py-2 hover:bg-stone-50 dark:hover:bg-stone-800 {{ request()->routeIs('libraries.*') ? 'text-[#B38A50] font-bold' : '' }}">کتابخانه‌ها و مراکز</a>
                        </div>
                    </div>

                </div>

            </div>
        </div>
    </header>

    <!-- Flash Messages (Notifications) -->
    @if(session('success'))
        <div class="max-w-4xl mx-auto mt-4 px-4">
            <div class="bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-300 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200 px-4 py-3 rounded-xl shadow-sm flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <svg class="w-5 h-5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <span>{{ session('success') }}</span>
                </div>
            </div>
        </div>
    @endif

    <!-- Main Content Slot -->
    <main class="flex-grow">
        @yield('content')
    </main>

    <!-- Footer -->
    <footer class="bg-[#191B36] text-stone-300 border-t-2 border-[#B38A50] mt-16 pt-12 pb-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <!-- Beta Notice & Scholarly Collaboration Callout Banner -->
            <div class="bg-[#13152c] border border-[#B38A50]/30 rounded-2xl p-5 sm:p-6 mb-10 flex flex-col md:flex-row items-center justify-between gap-5 shadow-inner">
                <div class="space-y-2 text-justify md:text-right">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-[#B38A50]/20 text-amber-300 border border-[#B38A50]/30">
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-400 animate-pulse"></span>
                            وضعیت سامانه: نسخه آزمایشی
                        </span>
                        <h4 class="text-sm font-bold text-amber-100">فراخوان همیاری علمی پژوهشگران و نسخه‌شناسان</h4>
                    </div>
                    <p class="text-xs text-stone-300/90 leading-relaxed max-w-4xl">
                        سامانه «فهارس» هم‌اکنون مراحل آزمایشی خود را سپری می‌کند. از آنجا که پردازش و ساختاردهی صدها هزار رکورد نسخه‌شناسی و کتاب‌شناسی همواره با خطاهای ناگزیر چاپی، پردازشی یا داده‌ای همراه است، از عموم استادان، نسخه‌پژوهان و محققان ارجمند صمیمانه تقاضا داریم با بازبینی داده‌ها و گزارش نارسایی‌های محتوایی یا فنی، ما را در ارتقای دقت و غنای این مرجع علمی یاری فرمایند.
                    </p>
                </div>
                <div class="shrink-0 flex flex-col sm:flex-row items-center gap-2.5 w-full md:w-auto">
                    <a href="mailto:info@fahares.net" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-[#B38A50] hover:bg-[#9e7841] text-[#191B36] font-bold text-xs transition duration-150 shadow whitespace-nowrap">
                        <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                        <span>مکاتبه: info@fahares.net</span>
                    </a>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-8 mb-12">
                
                <!-- Col 1: About Platform -->
                <div class="md:col-span-2 space-y-4">
                    <div class="flex items-center gap-3">
                        <img src="{{ asset('images/logo_emblem.png') }}" alt="فهارس" class="h-10 w-auto filter brightness-125">
                        <span class="text-2xl font-bold text-amber-100 tracking-wide">فهارس</span>
                    </div>
                    <p class="text-stone-400 text-sm leading-relaxed text-justify">
                        سامانه جامع کاوش در میراث مکتوب ایران و جهان اسلام، تدوین‌شده بر پایه فهرستگان‌های معتبر نسخ خطی (مشتمل بر ۳۴ مجلد فنخا به کوشش استاد مصطفی درایتی و سایر مراجع نسخه‌شناسی). این پلتفرم دسترسی دیجیتال و یکپارچه به صدها هزار نسخه خطی در مراکز اسنادی و کتابخانه‌های معتبر را فراهم می‌سازد.
                    </p>
                    <div class="flex flex-wrap items-center gap-3 pt-2 text-xs text-amber-200/80">
                        <a href="mailto:info@fahares.net" class="hover:text-amber-300 flex items-center gap-1.5 text-amber-300 font-sans font-medium">
                            <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                            </svg>
                            <span class="font-mono">info@fahares.net</span>
                        </a>
                        <span>•</span>
                        <a href="https://github.com/fahares/fahares" target="_blank" class="hover:text-amber-300 underline flex items-center gap-1">
                            <span>گیت‌هاب فهارس</span>
                        </a>
                        <span>•</span>
                        <a href="https://github.com/fahares/fahares-corpus" target="_blank" class="hover:text-amber-300 underline flex items-center gap-1">
                            <span>پیکره متنی (fahares-corpus)</span>
                        </a>
                    </div>
                </div>

                <!-- Col 2: Quick Links -->
                <div>
                    <h3 class="text-sm font-bold text-amber-200 tracking-wider mb-4 border-r-2 border-[#B38A50] pr-2">دسترسی سریع</h3>
                    <ul class="space-y-2 text-sm text-stone-400">
                        <li><a href="{{ route('search', ['type' => 'works']) }}" class="hover:text-amber-100 transition">فهرست آثار و عناوین</a></li>
                        <li><a href="{{ route('subjects.index') }}" class="hover:text-amber-100 transition">درختواره موضوعات و علوم</a></li>
                        <li><a href="{{ route('search', ['type' => 'manuscripts']) }}" class="hover:text-amber-100 transition">کاوش نسخه‌های کهن خطی</a></li>
                        <li><a href="{{ route('search', ['type' => 'people']) }}" class="hover:text-amber-100 transition">پدیدآوران، شارحان و کاتبان</a></li>
                        <li><a href="{{ route('libraries.index') }}" class="hover:text-amber-100 transition">مراکز نگهداری و کتابخانه‌ها</a></li>
                    </ul>
                </div>

                <!-- Col 3: Research & Features -->
                <div>
                    <h3 class="text-sm font-bold text-amber-200 tracking-wider mb-4 border-r-2 border-[#B38A50] pr-2">امکانات پژوهشی</h3>
                    <ul class="space-y-2 text-sm text-stone-400">
                        <li>جستجوی بلادرنگ با Meilisearch</li>
                        <li>فیلترهای کالبدشناسی (تذهیب، خط، سده)</li>
                        <li>سیستم مشارکت و ثبت تصحیحات پژوهشگران</li>
                        <li>داده‌های ساختاریافته و دسترسی باز</li>
                    </ul>
                </div>

            </div>

            <!-- Bottom Copyright -->
            <div class="border-t border-stone-700/60 pt-6 flex flex-col sm:flex-row items-center justify-between text-xs text-stone-400 gap-3">
                <p>© {{ date('Y') }} فهارس (fahares.net) • سامانه جامع کتاب‌شناسی و مراجع نسخه‌شناسی</p>
                <p class="text-stone-400">نسخه آزمایشی (Beta v1.0) • Laravel 13 & Meilisearch</p>
            </div>
        </div>
    </footer>

    <!-- Global Spotlight Quick Search Modal (Ctrl+K) -->
    <div 
        x-data="{
            openSearchModal: false,
            query: '',
            loading: false,
            selectedIndex: 0,
            results: { works: [], people: [], manuscripts: [] },
            init() {
                window.addEventListener('keydown', e => { 
                    if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K' || e.code === 'KeyK')) { 
                        e.preventDefault(); 
                        this.openModal();
                    } 
                });
                window.addEventListener('open-spotlight-modal', () => {
                    this.openModal();
                });
                this.$watch('openSearchModal', value => {
                    document.body.classList.toggle('overflow-hidden', value);
                });
            },
            openModal() {
                this.openSearchModal = true;
                this.selectedIndex = 0;
                this.$nextTick(() => {
                    this.$refs.spotlightInput?.focus();
                    this.$refs.spotlightInput?.select();
                });
            },
            closeModal() {
                this.openSearchModal = false;
            },
            clearQuery() {
                this.query = '';
                this.results = { works: [], people: [], manuscripts: [] };
                this.selectedIndex = 0;
                this.$nextTick(() => {
                    this.$refs.spotlightInput?.focus();
                });
            },
            fetchResults() {
                const q = this.query.trim();
                if (q.length < 2) {
                    this.results = { works: [], people: [], manuscripts: [] };
                    this.selectedIndex = 0;
                    this.loading = false;
                    return;
                }
                this.loading = true;
                fetch('{{ route('search.api', [], false) }}?q=' + encodeURIComponent(q) + '&type=all')
                    .then(res => res.json())
                    .then(data => {
                        this.results = data;
                        this.selectedIndex = 0;
                        this.loading = false;
                    })
                    .catch(() => {
                        this.loading = false;
                    });
            },
            hasResults() {
                return (this.results.works && this.results.works.length > 0) ||
                       (this.results.people && this.results.people.length > 0) ||
                       (this.results.manuscripts && this.results.manuscripts.length > 0);
            },
            totalCount() {
                return (this.results.works?.length || 0) +
                       (this.results.people?.length || 0) +
                       (this.results.manuscripts?.length || 0);
            },
            getAllItems() {
                const items = [];
                if (this.results.works) this.results.works.forEach(w => items.push(w));
                if (this.results.people) this.results.people.forEach(p => items.push(p));
                if (this.results.manuscripts) this.results.manuscripts.forEach(m => items.push(m));
                return items;
            },
            nextItem() {
                const total = this.totalCount();
                if (total === 0) return;
                this.selectedIndex = (this.selectedIndex + 1) % total;
                this.scrollToActive();
            },
            prevItem() {
                const total = this.totalCount();
                if (total === 0) return;
                this.selectedIndex = (this.selectedIndex - 1 + total) % total;
                this.scrollToActive();
            },
            scrollToActive() {
                this.$nextTick(() => {
                    const activeEl = this.$refs.resultsContainer?.querySelector('[data-active=\'true\']');
                    if (activeEl) {
                        activeEl.scrollIntoView({ block: 'nearest' });
                    }
                });
            },
            selectCurrentItem() {
                const items = this.getAllItems();
                if (this.hasResults() && this.selectedIndex >= 0 && this.selectedIndex < items.length) {
                    window.location.href = items[this.selectedIndex].url;
                } else {
                    this.goToFullSearch();
                }
            },
            goToFullSearch() {
                const q = this.query.trim();
                if (q.length > 0) {
                    window.location.href = '{{ route('search', [], false) }}?q=' + encodeURIComponent(q);
                }
            }
        }"
        @keydown.escape.window="closeModal()"
        class="relative z-50">

        <template x-teleport="body">
            <div 
                x-show="openSearchModal" 
                x-cloak
                class="fixed inset-0 z-50 overflow-y-auto p-4 sm:p-6 md:p-20"
                role="dialog" 
                aria-modal="true">

                <!-- Backdrop -->
                <div 
                    x-show="openSearchModal" 
                    x-transition:enter="ease-out duration-200"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    x-transition:leave="ease-in duration-150"
                    x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0"
                    @click="closeModal()"
                    class="fixed inset-0 bg-[#0F1224]/75 dark:bg-black/85 backdrop-blur-sm transition-opacity"></div>

                <!-- Modal Window Container -->
                <div 
                    x-show="openSearchModal"
                    x-transition:enter="ease-out duration-200"
                    x-transition:enter-start="opacity-0 scale-95 translate-y-2"
                    x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                    x-transition:leave="ease-in duration-150"
                    x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                    x-transition:leave-end="opacity-0 scale-95 translate-y-2"
                    class="mx-auto max-w-2xl transform overflow-hidden rounded-2xl bg-white dark:bg-[#15192C] shadow-2xl border border-stone-200 dark:border-[#272F4C] transition-all relative z-10">

                    <!-- Search Input Header -->
                    <div class="relative flex items-center border-b border-stone-200 dark:border-[#272F4C] px-4 py-3.5 bg-stone-50/70 dark:bg-[#191D33]">
                        <svg class="w-5 h-5 text-[#B38A50] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                        <input 
                            type="text" 
                            x-ref="spotlightInput"
                            x-model="query"
                            @input.debounce.200ms="fetchResults()"
                            @keydown.down.prevent="nextItem()"
                            @keydown.up.prevent="prevItem()"
                            @keydown.enter.prevent="selectCurrentItem()"
                            placeholder="کاوش سریع اثر، مؤلف، کاتب یا نسخه... (با ↑ و ↓ انتخاب کنید، Enter برای رفتن)"
                            class="w-full bg-transparent pr-3 pl-16 text-sm text-stone-900 dark:text-stone-100 placeholder-stone-400 focus:outline-none border-none focus:ring-0">
                        
                        <!-- Actions inside search bar -->
                        <div class="absolute left-3 flex items-center gap-1.5">
                            <button 
                                type="button" 
                                x-show="query.length > 0" 
                                @click="clearQuery()" 
                                class="p-1 rounded-md text-stone-400 hover:text-stone-600 dark:hover:text-stone-200 hover:bg-stone-200 dark:hover:bg-stone-700 transition cursor-pointer"
                                title="پاک کردن متن">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                            </button>
                            <button 
                                type="button" 
                                @click="closeModal()" 
                                class="px-1.5 py-0.5 text-[10px] font-mono bg-stone-200 dark:bg-[#272F4C] text-stone-600 dark:text-stone-300 rounded hover:bg-stone-300 dark:hover:bg-stone-600 transition cursor-pointer"
                                title="بستن پنجره">
                                Esc
                            </button>
                        </div>
                    </div>

                    <!-- Subtle Loading Progress Indicator -->
                    <div x-show="loading" class="h-0.5 w-full bg-amber-100 dark:bg-stone-800 overflow-hidden">
                        <div class="h-full bg-[#B38A50] animate-pulse w-full"></div>
                    </div>

                    <!-- Results & State Body -->
                    <div x-ref="resultsContainer" class="max-h-[60vh] overflow-y-auto divide-y divide-stone-100 dark:divide-[#272F4C] p-2">

                        <!-- State: Initial Empty Prompt -->
                        <div x-show="query.trim().length < 2" class="p-8 text-center text-xs text-stone-400 dark:text-stone-500">
                            <div class="inline-flex items-center justify-center w-10 h-10 rounded-full bg-amber-500/10 text-[#B38A50] mb-3">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                            </div>
                            <p class="font-medium text-stone-600 dark:text-stone-300 text-sm mb-1">جستجوی بلادرنگ در آثار، اشخاص و نسخه‌ها</p>
                            <p>دست‌کم ۲ حرف تایپ کنید؛ سپس با کلیدهای ↑ و ↓ میان گزینه‌ها حرکت کرده و با Enter وارد شوید.</p>
                        </div>

                        <!-- State: No Results -->
                        <div x-show="query.trim().length >= 2 && !loading && !hasResults()" class="p-8 text-center text-xs text-stone-500 dark:text-stone-400">
                            <p class="text-sm font-medium mb-1">موردی منطبق با «<span class="text-stone-800 dark:text-stone-200 font-semibold" x-text="query"></span>» در عناوین اصلی یافت نشد.</p>
                            <p class="mb-3 text-[11px] text-stone-400">می‌توانید همین عبارت را در کل فیلدها و کالبدشناسی نسخه‌ها جستجو کنید.</p>
                            <button 
                                type="button" 
                                @click="goToFullSearch()" 
                                class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl bg-[#B38A50] hover:bg-[#8F6B38] text-white text-xs font-semibold transition cursor-pointer shadow-sm">
                                <span>جستجوی پیشرفته در کل پایگاه (Enter)</span>
                                <svg class="w-3.5 h-3.5 rtl:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                            </button>
                        </div>

                        <!-- Group: Works (آثار و عناوین) -->
                        <template x-if="results.works && results.works.length > 0">
                            <div class="py-2">
                                <div class="flex items-center justify-between px-3 py-1.5 text-[11px] font-bold text-[#B38A50] uppercase tracking-wider">
                                    <span>آثار و عناوین</span>
                                    <span class="text-[10px] text-stone-400 font-normal" x-text="results.works.length + ' مورد'"></span>
                                </div>
                                <template x-for="(item, index) in results.works" :key="'w-' + item.id">
                                    <a :href="item.url" 
                                       @mouseenter="selectedIndex = index"
                                       :data-active="selectedIndex === index"
                                       :class="selectedIndex === index ? 'bg-amber-100/80 dark:bg-amber-950/60 ring-1 ring-[#B38A50]/50 shadow-xs' : 'hover:bg-stone-50 dark:hover:bg-[#1E233D]'"
                                       class="flex items-center justify-between px-3.5 py-2.5 rounded-xl transition group">
                                        <div class="min-w-0 pr-1">
                                            <div class="text-sm font-bold text-stone-800 dark:text-stone-100 group-hover:text-[#B38A50] truncate" x-text="item.title"></div>
                                            <div class="text-xs text-stone-500 dark:text-stone-400 truncate mt-0.5" x-text="item.author"></div>
                                        </div>
                                        <div class="shrink-0 flex items-center gap-2 mr-3">
                                            <span class="text-xs px-2.5 py-0.5 rounded-full bg-amber-50 dark:bg-amber-950/60 text-[#B38A50] dark:text-amber-300 font-semibold border border-amber-200/50 dark:border-amber-800/50" x-text="item.manuscripts_count + ' نسخه'"></span>
                                            <span :class="selectedIndex === index ? 'text-[#B38A50] font-bold translate-x-[-2px]' : 'text-stone-400 group-hover:text-[#B38A50]'" class="text-sm rtl:rotate-180 transition-transform">←</span>
                                        </div>
                                    </a>
                                </template>
                            </div>
                        </template>

                        <!-- Group: People (پدیدآوران و کاتبان) -->
                        <template x-if="results.people && results.people.length > 0">
                            <div class="py-2">
                                <div class="flex items-center justify-between px-3 py-1.5 text-[11px] font-bold text-indigo-600 dark:text-indigo-400 uppercase tracking-wider">
                                    <span>پدیدآوران و کاتبان</span>
                                    <span class="text-[10px] text-stone-400 font-normal" x-text="results.people.length + ' مورد'"></span>
                                </div>
                                <template x-for="(item, index) in results.people" :key="'p-' + item.id">
                                    <a :href="item.url" 
                                       @mouseenter="selectedIndex = (results.works?.length || 0) + index"
                                       :data-active="selectedIndex === ((results.works?.length || 0) + index)"
                                       :class="selectedIndex === ((results.works?.length || 0) + index) ? 'bg-indigo-100/80 dark:bg-indigo-950/60 ring-1 ring-indigo-500/50 shadow-xs' : 'hover:bg-stone-50 dark:hover:bg-[#1E233D]'"
                                       class="flex items-center justify-between px-3.5 py-2.5 rounded-xl transition group">
                                        <div class="min-w-0 pr-1">
                                            <div class="text-sm font-bold text-stone-800 dark:text-stone-100 group-hover:text-indigo-600 dark:group-hover:text-indigo-400 truncate" x-text="item.name"></div>
                                            <div class="text-xs text-stone-500 dark:text-stone-400 truncate mt-0.5" x-show="item.death_hijri" x-text="item.death_hijri"></div>
                                        </div>
                                        <div class="shrink-0 flex items-center gap-2 mr-3">
                                            <span class="text-xs px-2.5 py-0.5 rounded-full bg-indigo-50 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-300 font-semibold border border-indigo-200 dark:border-indigo-800/60" x-show="item.works_count" x-text="item.works_count + ' اثر'"></span>
                                            <span :class="selectedIndex === ((results.works?.length || 0) + index) ? 'text-indigo-600 dark:text-indigo-400 font-bold translate-x-[-2px]' : 'text-stone-400 group-hover:text-indigo-500'" class="text-sm rtl:rotate-180 transition-transform">←</span>
                                        </div>
                                    </a>
                                </template>
                            </div>
                        </template>

                        <!-- Group: Manuscripts (نسخه‌های خطی) -->
                        <template x-if="results.manuscripts && results.manuscripts.length > 0">
                            <div class="py-2">
                                <div class="flex items-center justify-between px-3 py-1.5 text-[11px] font-bold text-emerald-600 dark:text-emerald-400 uppercase tracking-wider">
                                    <span>نسخه‌های خطی</span>
                                    <span class="text-[10px] text-stone-400 font-normal" x-text="results.manuscripts.length + ' مورد'"></span>
                                </div>
                                <template x-for="(item, index) in results.manuscripts" :key="'m-' + item.id">
                                    <a :href="item.url" 
                                       @mouseenter="selectedIndex = (results.works?.length || 0) + (results.people?.length || 0) + index"
                                       :data-active="selectedIndex === ((results.works?.length || 0) + (results.people?.length || 0) + index)"
                                       :class="selectedIndex === ((results.works?.length || 0) + (results.people?.length || 0) + index) ? 'bg-emerald-100/80 dark:bg-emerald-950/60 ring-1 ring-emerald-500/50 shadow-xs' : 'hover:bg-stone-50 dark:hover:bg-[#1E233D]'"
                                       class="flex items-center justify-between px-3.5 py-2.5 rounded-xl transition group">
                                        <div class="min-w-0 pr-1">
                                            <div class="text-sm font-bold text-stone-800 dark:text-stone-100 group-hover:text-emerald-600 dark:group-hover:text-emerald-400 flex items-center gap-1.5 flex-wrap truncate">
                                                <span class="truncate" x-text="item.work_title"></span>
                                                <span x-show="item.author_name" class="text-xs font-normal text-stone-500 dark:text-stone-400" x-text="'(پدیدآور: ' + item.author_name + ')'"></span>
                                            </div>
                                            <div class="text-xs text-stone-500 dark:text-stone-400 truncate mt-0.5" x-text="item.library + (item.shelfmark || item.accession_number ? ' • بازیابی: ' + (item.shelfmark || item.accession_number) : '')"></div>
                                        </div>
                                        <div class="shrink-0 flex items-center gap-2 mr-3">
                                            <span class="text-xs text-stone-400 group-hover:text-emerald-600 dark:group-hover:text-emerald-400 font-medium">مشاهده نسخه</span>
                                            <span :class="selectedIndex === ((results.works?.length || 0) + (results.people?.length || 0) + index) ? 'text-emerald-600 dark:text-emerald-400 font-bold translate-x-[-2px]' : 'text-stone-400 group-hover:text-emerald-600'" class="text-sm rtl:rotate-180 transition-transform">←</span>
                                        </div>
                                    </a>
                                </template>
                            </div>
                        </template>

                    </div>

                    <!-- Footer Bar with navigation keys guide -->
                    <div class="flex items-center justify-between px-4 py-2.5 bg-stone-100/70 dark:bg-[#101426] border-t border-stone-200 dark:border-[#272F4C] text-[11px] text-stone-500 dark:text-stone-400">
                        <div class="flex items-center gap-3">
                            <span class="flex items-center gap-1">
                                <kbd class="px-1.5 py-0.5 bg-white dark:bg-stone-800 border border-stone-300 dark:border-stone-700 rounded text-[10px] font-mono">↑</kbd>
                                <kbd class="px-1.5 py-0.5 bg-white dark:bg-stone-800 border border-stone-300 dark:border-stone-700 rounded text-[10px] font-mono">↓</kbd>
                                <span>ناوبری</span>
                            </span>
                            <span class="flex items-center gap-1">
                                <kbd class="px-1.5 py-0.5 bg-white dark:bg-stone-800 border border-stone-300 dark:border-stone-700 rounded text-[10px] font-mono">↵ Enter</kbd>
                                <span>انتخاب</span>
                            </span>
                            <span class="flex items-center gap-1">
                                <kbd class="px-1.5 py-0.5 bg-white dark:bg-stone-800 border border-stone-300 dark:border-stone-700 rounded text-[10px] font-mono">Esc</kbd>
                                <span>بستن</span>
                            </span>
                        </div>
                        
                        <button 
                            type="button"
                            x-show="query.trim().length > 0" 
                            @click="goToFullSearch()" 
                            class="text-[#B38A50] hover:underline font-medium inline-flex items-center gap-1 cursor-pointer">
                            <span>کاوش جامع در کل پایگاه</span>
                            <span class="rtl:rotate-180">←</span>
                        </button>
                    </div>

                </div>
            </div>
        </template>
    </div>

    @stack('scripts')
</body>
</html>

<!DOCTYPE html>
<html lang="fa" dir="rtl" x-data="{ darkMode: localStorage.getItem('theme') === 'dark' }" :class="{ 'dark': darkMode }">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'فهارس | پایگاه جامع کتاب‌شناسی و نسخه‌شناسی مکتوب')</title>
    <meta name="description" content="@yield('meta_description', 'سامانه و موتور جستجوی جامع نسخه‌های خطی و کتاب‌شناسی مکتوب بر پایه مراجع و فهرستگان‌های معتبر')">
    
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
    <div class="bg-gradient-to-r from-[#191B36] via-[#292C56] to-[#191B36] text-amber-100/90 text-xs py-1.5 px-4 text-center border-b border-[#B38A50]/30 shadow-sm flex items-center justify-center gap-2">
        <span class="inline-block w-2 h-2 rounded-full bg-[#B38A50] animate-pulse"></span>
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
                            <span class="text-2xl font-black text-[#292C56] dark:text-amber-100 tracking-tight leading-none group-hover:text-[#B38A50] transition-colors">فهارس</span>
                            <span class="text-[10px] text-stone-500 dark:text-stone-400 font-medium tracking-wide">فهرستگان نسخ خطی ایران</span>
                        </div>
                    </a>
                </div>

                <!-- Desktop Navigation Links -->
                <nav class="hidden md:flex items-center gap-1 text-sm font-semibold text-stone-700 dark:text-stone-300">
                    <a href="{{ route('home') }}" class="px-3 py-2 rounded-lg hover:text-[#B38A50] hover:bg-stone-100 dark:hover:bg-stone-800 transition {{ request()->routeIs('home') ? 'text-[#B38A50] bg-amber-50/60 dark:bg-amber-950/20' : '' }}">
                        صفحه نخست
                    </a>
                    <a href="{{ route('search', ['type' => 'works']) }}" class="px-3 py-2 rounded-lg hover:text-[#B38A50] hover:bg-stone-100 dark:hover:bg-stone-800 transition {{ request()->routeIs('search') && request('type', 'works') === 'works' ? 'text-[#B38A50] bg-amber-50/60 dark:bg-amber-950/20' : '' }}">
                        کاوش آثار
                    </a>
                    <a href="{{ route('search', ['type' => 'manuscripts']) }}" class="px-3 py-2 rounded-lg hover:text-[#B38A50] hover:bg-stone-100 dark:hover:bg-stone-800 transition {{ request('type') === 'manuscripts' ? 'text-[#B38A50] bg-amber-50/60 dark:bg-amber-950/20' : '' }}">
                        نسخه‌های خطی
                    </a>
                    <a href="{{ route('search', ['type' => 'people']) }}" class="px-3 py-2 rounded-lg hover:text-[#B38A50] hover:bg-stone-100 dark:hover:bg-stone-800 transition {{ request('type') === 'people' ? 'text-[#B38A50] bg-amber-50/60 dark:bg-amber-950/20' : '' }}">
                        پدیدآوران و کاتبان
                    </a>
                    <a href="{{ route('libraries.index') }}" class="px-3 py-2 rounded-lg hover:text-[#B38A50] hover:bg-stone-100 dark:hover:bg-stone-800 transition {{ request()->routeIs('libraries.*') ? 'text-[#B38A50] bg-amber-50/60 dark:bg-amber-950/20' : '' }}">
                        کتابخانه‌ها و مراکز
                    </a>
                </nav>

                <!-- Actions: Search Trigger & Dark Mode Toggle -->
                <div class="flex items-center gap-3">
                    
                    <!-- Search Input Trigger -->
                    <a href="{{ route('search') }}" class="flex items-center gap-2 px-3 py-1.5 rounded-full border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800/80 text-xs text-stone-500 hover:border-[#B38A50] transition group shadow-sm">
                        <svg class="w-4 h-4 text-stone-400 group-hover:text-[#B38A50]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                        <span class="hidden sm:inline">کاوش سریع...</span>
                        <kbd class="hidden sm:inline-block px-1.5 py-0.5 text-[10px] font-mono bg-stone-200 dark:bg-stone-700 rounded text-stone-500 dark:text-stone-300">Ctrl+K</kbd>
                    </a>

                    <!-- Dark Mode Toggle Button -->
                    <button 
                        type="button" 
                        @click="darkMode = !darkMode; localStorage.setItem('theme', darkMode ? 'dark' : 'light')" 
                        class="p-2 rounded-full border border-stone-200 dark:border-stone-700 bg-stone-100 dark:bg-stone-800 text-stone-600 dark:text-amber-300 hover:text-[#B38A50] transition shadow-sm"
                        title="تغییر حالت شب/روز">
                        <!-- Sun Icon (Dark Mode active) -->
                        <svg x-show="darkMode" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 9H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                        <!-- Moon Icon (Light Mode active) -->
                        <svg x-show="!darkMode" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path></svg>
                    </button>

                    <!-- Mobile Menu Button (Alpine) -->
                    <div x-data="{ open: false }" class="md:hidden relative">
                        <button @click="open = !open" class="p-2 rounded-lg border border-stone-200 dark:border-stone-700 text-stone-600 dark:text-stone-300">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                        </button>
                        <div x-show="open" @click.away="open = false" x-transition class="absolute left-0 mt-2 w-48 bg-white dark:bg-[#15192C] rounded-xl shadow-xl border border-stone-200 dark:border-stone-700 py-2 z-50 text-sm">
                            <a href="{{ route('home') }}" class="block px-4 py-2 hover:bg-stone-50 dark:hover:bg-stone-800">صفحه نخست</a>
                            <a href="{{ route('search', ['type' => 'works']) }}" class="block px-4 py-2 hover:bg-stone-50 dark:hover:bg-stone-800">کاوش آثار</a>
                            <a href="{{ route('search', ['type' => 'manuscripts']) }}" class="block px-4 py-2 hover:bg-stone-50 dark:hover:bg-stone-800">نسخه‌های خطی</a>
                            <a href="{{ route('search', ['type' => 'people']) }}" class="block px-4 py-2 hover:bg-stone-50 dark:hover:bg-stone-800">پدیدآوران و کاتبان</a>
                            <a href="{{ route('libraries.index') }}" class="block px-4 py-2 hover:bg-stone-50 dark:hover:bg-stone-800">کتابخانه‌ها</a>
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
            <div class="grid grid-cols-1 md:grid-cols-4 gap-8 mb-12">
                
                <!-- Col 1: About Platform -->
                <div class="md:col-span-2 space-y-4">
                    <div class="flex items-center gap-3">
                        <img src="{{ asset('images/logo_emblem.png') }}" alt="فهارس" class="h-10 w-auto filter brightness-125">
                        <span class="text-2xl font-bold text-amber-100 tracking-wide">فهارس</span>
                    </div>
                    <p class="text-stone-400 text-sm leading-relaxed text-justify">
                        سامانه جامع کاوش در میراث مکتوب ایران و جهان اسلام، تدوین‌شده بر پایه فهرستگان‌های معتبر نسخ خطی (مشتمل بر ۳۴ مجلد فنخا به کوشش استاد مصطفی درایتی و سایر مراجع نسخه‌شناسی). این پلتفرم دسترسی دیجیتال و یکپارچه به صدها هزار نسخه خطی در بیش از ۱۰۰۰ مرکز اسنادی را فراهم می‌سازد.
                    </p>
                    <div class="flex items-center gap-3 pt-2 text-xs text-amber-200/80">
                        <span>پروژه متن‌باز و پژوهشی</span>
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
                <p class="font-mono text-stone-400">Version 1.0.0-Release • Laravel 13 & Meilisearch</p>
            </div>
        </div>
    </footer>

    @stack('scripts')
</body>
</html>

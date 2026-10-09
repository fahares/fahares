<!DOCTYPE html>
<html lang="fa" dir="rtl" x-data="{ darkMode: document.documentElement.classList.contains('dark'), sidebarOpen: false }" :class="{ 'dark': darkMode }">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'پنل مدیریت و نظارت علمی') | فهارس</title>
    
    <!-- Theme Initialization -->
    <script>
        if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    
    <!-- Google Fonts: Vazirmatn -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="bg-stone-100 dark:bg-[#0E1122] text-stone-800 dark:text-stone-200 font-sans min-h-screen flex selection:bg-[#B38A50]/20 selection:text-[#B38A50]">

    <!-- Mobile Sidebar Backdrop -->
    <div x-show="sidebarOpen" 
         @click="sidebarOpen = false" 
         x-cloak
         x-transition:enter="transition-opacity ease-linear duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition-opacity ease-linear duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-40 bg-stone-950/60 backdrop-blur-sm lg:hidden"></div>

    <!-- Sidebar Navigation -->
    <aside :class="sidebarOpen ? 'translate-x-0' : 'translate-x-full lg:translate-x-0'"
           class="fixed inset-y-0 right-0 z-50 w-72 bg-[#191B36] text-stone-300 flex flex-col border-l border-[#B38A50]/20 shadow-2xl transition-transform duration-300 ease-in-out">
        
        <!-- Sidebar Brand Header -->
        <div class="h-20 flex items-center justify-between px-6 border-b border-stone-800/80 bg-[#14162B]">
            <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-3">
                <img src="{{ asset('images/logo_emblem.png') }}" alt="فهارس" class="h-10 w-auto drop-shadow">
                <div>
                    <span class="text-xl font-black text-amber-100 tracking-tight leading-none block">فهارس</span>
                    <span class="text-[10px] text-[#B38A50] font-medium tracking-wide">مرکز فرماندهی و نظارت علمی</span>
                </div>
            </a>
            <button @click="sidebarOpen = false" class="lg:hidden text-stone-400 hover:text-white p-1">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <!-- Sidebar Navigation Items (Scrollable) -->
        <div class="flex-1 overflow-y-auto px-4 py-6 space-y-6 text-xs font-semibold custom-scrollbar">
            
            <!-- Group: پیشخوان و مانیتورینگ -->
            <div class="space-y-1">
                <span class="px-3 text-[10px] font-bold uppercase tracking-wider text-stone-400">نظارت عمومی</span>
                <a href="{{ route('admin.dashboard') }}" 
                   class="flex items-center justify-between px-3 py-2.5 rounded-xl transition {{ request()->routeIs('admin.dashboard') ? 'bg-[#B38A50] text-stone-950 font-bold shadow-md' : 'text-stone-300 hover:bg-white/5 hover:text-white' }}">
                    <div class="flex items-center gap-3">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>
                        </svg>
                        <span>داشبورد آماری</span>
                    </div>
                </a>
                <a href="{{ route('admin.audit-logs.index') }}" 
                   class="flex items-center gap-3 px-3 py-2.5 rounded-xl transition {{ request()->routeIs('admin.audit-logs.*') ? 'bg-[#B38A50] text-stone-950 font-bold shadow-md' : 'text-stone-300 hover:bg-white/5 hover:text-white' }}">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                    </svg>
                    <span>گزارش ممیزی و تغییرات</span>
                </a>
            </div>

            <!-- Group: داوری و اصلاحات -->
            <div class="space-y-1">
                <span class="px-3 text-[10px] font-bold uppercase tracking-wider text-stone-400">داوری و ویرایش علمی</span>
                <a href="{{ route('admin.suggestions.index') }}" 
                   class="flex items-center justify-between px-3 py-2.5 rounded-xl transition {{ request()->routeIs('admin.suggestions.*') ? 'bg-[#B38A50] text-stone-950 font-bold shadow-md' : 'text-stone-300 hover:bg-white/5 hover:text-white' }}">
                    <div class="flex items-center gap-3">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5M7.188 2.239l.777 2.897M5.136 7.965l-2.898-.777M13.95 4.05l-2.122 2.122m-5.657 5.656l-2.12 2.122"/>
                        </svg>
                        <span>پیشنهادات اصلاحی</span>
                    </div>
                    @php
                        $pendingCount = \App\Models\FieldSuggestion::where('status', 'pending')->count();
                    @endphp
                    @if($pendingCount > 0)
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-500 text-stone-950">
                            {{ number_format($pendingCount) }}
                        </span>
                    @endif
                </a>
                <a href="{{ route('admin.annotations.index') }}" 
                   class="flex items-center gap-3 px-3 py-2.5 rounded-xl transition {{ request()->routeIs('admin.annotations.*') ? 'bg-[#B38A50] text-stone-950 font-bold shadow-md' : 'text-stone-300 hover:bg-white/5 hover:text-white' }}">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/>
                    </svg>
                    <span>پانویس‌های انتقادی (دستگاه علمی)</span>
                </a>
            </div>

            <!-- Group: تاکسونومی و اعلام -->
            <div class="space-y-1">
                <span class="px-3 text-[10px] font-bold uppercase tracking-wider text-stone-400">تاکسونومی و پدیدآوران</span>
                <a href="{{ route('admin.subjects.index') }}" 
                   class="flex items-center gap-3 px-3 py-2.5 rounded-xl transition {{ request()->routeIs('admin.subjects.*') ? 'bg-[#B38A50] text-stone-950 font-bold shadow-md' : 'text-stone-300 hover:bg-white/5 hover:text-white' }}">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                    </svg>
                    <span>درخت و ادغام موضوعات</span>
                </a>
                <a href="{{ route('admin.people.index') }}" 
                   class="flex items-center gap-3 px-3 py-2.5 rounded-xl transition {{ request()->routeIs('admin.people.*') ? 'bg-[#B38A50] text-stone-950 font-bold shadow-md' : 'text-stone-300 hover:bg-white/5 hover:text-white' }}">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                    </svg>
                    <span>اشخاص، مؤلفان و ادغام اعلام</span>
                </a>
            </div>

            <!-- Group: منابع و کتابخانه‌ها -->
            <div class="space-y-1">
                <span class="px-3 text-[10px] font-bold uppercase tracking-wider text-stone-400">فهرستگان و مراکز</span>
                <a href="{{ route('admin.catalogers.index') }}" 
                   class="flex items-center gap-3 px-3 py-2.5 rounded-xl transition {{ request()->routeIs('admin.catalogers.*') ? 'bg-[#B38A50] text-stone-950 font-bold shadow-md' : 'text-stone-300 hover:bg-white/5 hover:text-white' }}">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                    </svg>
                    <span>فهرست‌نگاران فنخا</span>
                </a>
                <a href="{{ route('admin.libraries.index') }}" 
                   class="flex items-center gap-3 px-3 py-2.5 rounded-xl transition {{ request()->routeIs('admin.libraries.*') ? 'bg-[#B38A50] text-stone-950 font-bold shadow-md' : 'text-stone-300 hover:bg-white/5 hover:text-white' }}">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                    </svg>
                    <span>کتابخانه‌ها و آرشیوها</span>
                </a>
            </div>

            <!-- Group: زیرساخت و کاربران -->
            <div class="space-y-1">
                <span class="px-3 text-[10px] font-bold uppercase tracking-wider text-stone-400">سامانه و زیرساخت</span>
                <a href="{{ route('admin.system.index') }}" 
                   class="flex items-center gap-3 px-3 py-2.5 rounded-xl transition {{ request()->routeIs('admin.system.*') ? 'bg-[#B38A50] text-stone-950 font-bold shadow-md' : 'text-stone-300 hover:bg-white/5 hover:text-white' }}">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    </svg>
                    <span>عملیات کش و Meilisearch</span>
                </a>
                <a href="{{ route('admin.users.index') }}" 
                   class="flex items-center gap-3 px-3 py-2.5 rounded-xl transition {{ request()->routeIs('admin.users.*') ? 'bg-[#B38A50] text-stone-950 font-bold shadow-md' : 'text-stone-300 hover:bg-white/5 hover:text-white' }}">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                    </svg>
                    <span>کاربران و پژوهشگران</span>
                </a>
            </div>

        </div>

        <!-- Sidebar Footer (Admin Profile & Back to site) -->
        <div class="p-4 border-t border-stone-800/80 bg-[#14162B] space-y-3">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <div class="w-9 h-9 rounded-xl bg-[#B38A50]/20 border border-[#B38A50]/40 flex items-center justify-center font-bold text-amber-300 text-sm">
                        {{ mb_substr(auth()->user()->name, 0, 1) }}
                    </div>
                    <div class="overflow-hidden">
                        <p class="text-xs font-bold text-white truncate max-w-[130px]">{{ auth()->user()->name }}</p>
                        <span class="inline-block px-1.5 py-0.2 rounded text-[9px] font-bold bg-amber-500/20 text-amber-300">
                            {{ auth()->user()->role === 'admin' ? 'مدیر ارشد' : 'دبیر علمی' }}
                        </span>
                    </div>
                </div>
                <form action="{{ route('logout') }}" method="POST">
                    @csrf
                    <button type="submit" title="خروج" class="p-2 text-stone-400 hover:text-red-400 rounded-lg hover:bg-white/5 transition">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                        </svg>
                    </button>
                </form>
            </div>

            <a href="{{ route('home') }}" target="_blank" class="w-full flex items-center justify-center gap-2 py-2 px-3 rounded-xl border border-stone-700 bg-stone-800/60 hover:bg-stone-700 text-stone-300 hover:text-white text-xs font-semibold transition">
                <svg class="w-4 h-4 text-[#B38A50]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                </svg>
                <span>مشاهده وب‌سایت عمومی</span>
            </a>
        </div>

    </aside>

    <!-- Main Admin Shell Area -->
    <div class="flex-1 flex flex-col min-w-0 lg:mr-72">
        
        <!-- Admin Top Navigation Bar -->
        <header class="h-20 bg-white dark:bg-[#15192C] border-b border-stone-200 dark:border-stone-800 px-4 sm:px-8 flex items-center justify-between sticky top-0 z-30 shadow-xs">
            
            <!-- Left: Sidebar Trigger & Page Title -->
            <div class="flex items-center gap-4">
                <button @click="sidebarOpen = true" class="lg:hidden p-2 rounded-xl text-stone-600 dark:text-stone-300 hover:bg-stone-100 dark:hover:bg-stone-800">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
                <div>
                    <h1 class="text-lg sm:text-xl font-black text-stone-900 dark:text-stone-100">
                        @yield('page_title', 'مرکز مدیریت فهارس')
                    </h1>
                    <div class="text-[11px] text-stone-500 dark:text-stone-400 flex items-center gap-2">
                        @yield('breadcrumb')
                    </div>
                </div>
            </div>

            <!-- Right: Actions & Theme -->
            <div class="flex items-center gap-3">
                
                <!-- Dark Mode Switch -->
                <button type="button" 
                        @click="darkMode = !darkMode; localStorage.setItem('theme', darkMode ? 'dark' : 'light'); document.documentElement.classList.toggle('dark', darkMode)" 
                        class="p-2.5 rounded-xl border border-stone-200 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-600 dark:text-amber-300 hover:text-[#B38A50] transition shadow-xs cursor-pointer"
                        title="تغییر تم">
                    <svg x-show="darkMode" x-cloak class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>
                    </svg>
                    <svg x-show="!darkMode" x-cloak class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>
                    </svg>
                </button>

                <!-- Status Badge -->
                <div class="hidden sm:flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-800 dark:text-emerald-300 text-xs font-semibold">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span>سامانه برخط</span>
                </div>

            </div>

        </header>

        <!-- Flash Alert Messages -->
        <div class="px-4 sm:px-8 pt-6">
            @if(session('success'))
                <div class="p-4 rounded-2xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-300 dark:border-emerald-800 text-emerald-900 dark:text-emerald-200 text-xs flex items-center justify-between shadow-xs">
                    <div class="flex items-center gap-2">
                        <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span>{{ session('success') }}</span>
                    </div>
                </div>
            @endif

            @if(session('error'))
                <div class="p-4 rounded-2xl bg-red-50 dark:bg-red-950/40 border border-red-300 dark:border-red-800 text-red-900 dark:text-red-200 text-xs flex items-center justify-between shadow-xs">
                    <div class="flex items-center gap-2">
                        <svg class="w-5 h-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span>{{ session('error') }}</span>
                    </div>
                </div>
            @endif

            @if(session('warning'))
                <div class="p-4 rounded-2xl bg-amber-50 dark:bg-amber-950/40 border border-amber-300 dark:border-amber-800 text-amber-900 dark:text-amber-200 text-xs flex items-center justify-between shadow-xs">
                    <div class="flex items-center gap-2">
                        <svg class="w-5 h-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                        <span>{{ session('warning') }}</span>
                    </div>
                </div>
            @endif
        </div>

        <!-- Content Body Area -->
        <main class="flex-1 px-4 sm:px-8 py-6">
            @yield('content')
        </main>

        <!-- Admin Footer -->
        <footer class="h-14 border-t border-stone-200 dark:border-stone-800 px-4 sm:px-8 flex items-center justify-between text-xs text-stone-500 dark:text-stone-400 bg-white dark:bg-[#15192C]">
            <div>پایگاه فهارس نسخه خطی ایران • پنل نظارت علمی</div>
            <div class="font-mono text-[11px]">Laravel 13 • PHP 8.3 • Meilisearch</div>
        </footer>

    </div>

    @stack('scripts')
</body>
</html>

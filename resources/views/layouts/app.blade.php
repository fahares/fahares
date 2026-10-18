<!DOCTYPE html>
<html lang="fa" dir="rtl" x-data="{ darkMode: document.documentElement.classList.contains('dark') }" :class="{ 'dark': darkMode }">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'فهارس | پایگاه جامع کتاب‌شناسی و نسخه‌شناسی مکتوب')</title>
    <meta name="description" content="@yield('meta_description', 'سامانه و موتور جستجوی جامع نسخه‌های خطی و کتاب‌شناسی مکتوب بر پایه مراجع و فهرستگان‌های معتبر')">

    <!-- Open Graph / Facebook -->
    <meta property="og:site_name" content="فهارس | پایگاه جامع نسخه‌های خطی">
    <meta property="og:title" content="@yield('title', 'فهارس | پایگاه جامع کتاب‌شناسی و نسخه‌شناسی مکتوب')">
    <meta property="og:description" content="@yield('meta_description', 'سامانه و موتور جستجوی جامع نسخه‌های خطی و کتاب‌شناسی مکتوب بر پایه مراجع و فهرستگان‌های معتبر')">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:type" content="@yield('og_type', 'website')">
    <meta property="og:image" content="@yield('og_image', asset('images/brand/og-image.jpg'))">
    @hasSection('og_image')@else
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    @endif
    <meta property="og:locale" content="fa_IR">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="@yield('title', 'فهارس | پایگاه جامع کتاب‌شناسی و نسخه‌شناسی مکتوب')">
    <meta name="twitter:description" content="@yield('meta_description', 'سامانه و موتور جستجوی جامع نسخه‌های خطی و کتاب‌شناسی مکتوب')">
    <meta name="twitter:image" content="@yield('og_image', asset('images/brand/og-image.jpg'))">

    @stack('structured_data')
    
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
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/brand/shamseh-32.png') }}">
    <link rel="icon" type="image/png" sizes="256x256" href="{{ asset('images/brand/shamseh-256.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/brand/shamseh-180.png') }}">
    
    <!-- Google Fonts: Vazirmatn for text, Noto Naskh Arabic for headings and work titles -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Naskh+Arabic:wght@500;700&family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="bg-parchment-pattern min-h-screen flex flex-col font-sans selection:bg-cinnabar/20 selection:text-cinnabar">

    <!-- Main Navigation Header (Glassmorphic) -->
    <header class="sticky top-0 z-40 bg-surface/95 backdrop-blur-md border-b border-line transition-colors duration-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-20">
                
                <!-- Logo & Brand -->
                <div class="flex items-center gap-4">
                    <a href="{{ route('home') }}" class="flex items-center gap-3 group shrink-0">
                        <img src="{{ asset('images/brand/shamseh-180.png') }}" alt="" width="48" height="48" class="h-12 w-12 group-hover:rotate-[22.5deg] transition-transform duration-500 motion-reduce:transition-none">
                        <div class="flex flex-col">
                            <div class="flex items-center gap-2">
                                <span class="font-naskh text-[28px] font-bold text-ink leading-none group-hover:text-cinnabar transition-colors">فهارس</span>
                                <span class="inline-block px-2 py-px rounded-full text-[10px] font-bold text-muted border border-line-strong">آزمایشی</span>
                            </div>
                            <span class="text-[11px] text-muted font-medium mt-1">پایگاه جامع نسخه‌های خطی</span>
                        </div>
                    </a>
                </div>

                <!-- Desktop Navigation Links -->
                <nav class="hidden md:flex items-center gap-1 text-sm font-semibold text-ink">
                    <a href="{{ route('home') }}" class="px-3 py-2 rounded-lg whitespace-nowrap hover:text-cinnabar hover:bg-paper transition {{ request()->routeIs('home') ? 'text-cinnabar bg-amber-50/60 dark:bg-amber-950/20' : '' }}">
                        صفحه نخست
                    </a>
                    <a href="{{ route('subjects.index') }}" class="px-3 py-2 rounded-lg whitespace-nowrap hover:text-cinnabar hover:bg-paper transition {{ request()->routeIs('subjects.*') ? 'text-cinnabar bg-amber-50/60 dark:bg-amber-950/20' : '' }}">
                        موضوعات
                    </a>
                    <a href="{{ route('libraries.index') }}" class="px-3 py-2 rounded-lg whitespace-nowrap hover:text-cinnabar hover:bg-paper transition {{ request()->routeIs('libraries.*') ? 'text-cinnabar bg-amber-50/60 dark:bg-amber-950/20' : '' }}">
                        کتابخانه‌ها و مراکز
                    </a>
                    <a href="{{ route('catalogers.index') }}" class="px-3 py-2 rounded-lg whitespace-nowrap hover:text-cinnabar hover:bg-paper transition {{ request()->routeIs('catalogers.*') ? 'text-cinnabar bg-amber-50/60 dark:bg-amber-950/20' : '' }}">
                        فهرست‌نگاران
                    </a>
                </nav>

                <!-- Actions: Search Trigger & Dark Mode Toggle -->
                <div class="flex items-center gap-2 sm:gap-3">
                    
                    <!-- Search Input Trigger Button (Opens Ctrl+K Spotlight Modal) -->
                    <button 
                        type="button" 
                        @click="window.dispatchEvent(new CustomEvent('open-spotlight-modal'))"
                        class="flex items-center gap-2 px-3 py-1.5 rounded-full border border-line-strong bg-paper text-xs text-stone-500 hover:border-gold hover:text-cinnabar transition group shadow-sm cursor-pointer"
                        title="جستجوی سریع (Ctrl+K)">
                        <svg class="w-4 h-4 text-stone-400 group-hover:text-cinnabar" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                        <span class="hidden sm:inline">کاوش سریع...</span>
                        <kbd class="hidden sm:inline-block px-1.5 py-0.5 text-[10px] bg-stone-200 dark:bg-stone-700 rounded text-stone-500 dark:text-stone-300 font-mono">Ctrl+K</kbd>
                    </button>

                    <!-- Dark Mode Toggle Button -->
                    <button 
                        type="button" 
                        @click="darkMode = !darkMode; localStorage.setItem('theme', darkMode ? 'dark' : 'light'); document.documentElement.classList.toggle('dark', darkMode)" 
                        class="hidden sm:inline-flex p-2 rounded-full border border-line-strong bg-stone-100 dark:bg-stone-800 text-stone-600 dark:text-amber-300 hover:text-cinnabar transition shadow-sm cursor-pointer"
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

                    <!-- User Account / Auth Actions -->
                    @auth
                        @php $unreadNotifications = auth()->user()->unreadNotifications()->count(); @endphp
                        <div x-data="{ userMenuOpen: false }" class="relative">
                            <button @click="userMenuOpen = !userMenuOpen" aria-label="حساب کاربری: {{ auth()->user()->name }}"
                                    class="relative flex items-center gap-2 p-2 sm:px-3 sm:py-1.5 rounded-full border border-gold/40 bg-cinnabar/10 text-ink text-xs font-bold hover:border-gold transition cursor-pointer">
                                <span class="hidden sm:inline-block w-2 h-2 rounded-full bg-emerald-500"></span>
                                <svg class="sm:hidden w-5 h-5 text-cinnabar" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                                </svg>
                                <span class="hidden sm:inline max-w-[140px] truncate">{{ auth()->user()->name }}</span>
                                @if($unreadNotifications)
                                    <span class="absolute -top-1 -left-1 sm:static px-1.5 min-w-[1.25rem] text-center rounded-full bg-amber-500 text-stone-950 text-[10px] font-bold" title="اعلان خوانده‌نشده">{{ $unreadNotifications }}</span>
                                @endif
                                <svg class="hidden sm:block w-3.5 h-3.5 text-stone-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </button>

                            <div x-show="userMenuOpen" @click.away="userMenuOpen = false" x-cloak x-transition
                                 class="absolute left-0 mt-2 w-52 bg-surface rounded-2xl shadow-xl border border-line-strong py-2 z-50 text-xs divide-y divide-line">
                                <div class="px-4 py-2">
                                    <p class="font-bold text-ink truncate">{{ auth()->user()->name }}</p>
                                    <p class="text-[10px] text-stone-400 truncate dir-ltr text-right">{{ auth()->user()->email }}</p>
                                </div>
                                <div class="py-1">
                                    <a href="{{ route('my.suggestions') }}" class="flex items-center gap-2 px-4 py-2 text-ink hover:bg-paper font-medium transition">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                                        </svg>
                                        <span>پیشنهادهای من</span>
                                    </a>
                                    <a href="{{ route('notifications.index') }}" class="flex items-center gap-2 px-4 py-2 text-ink hover:bg-paper font-medium transition">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                                        </svg>
                                        <span>اعلان‌ها</span>
                                        @if($unreadNotifications)
                                            <span class="ms-auto px-1.5 rounded-full bg-amber-500 text-stone-950 text-[10px] font-bold">{{ $unreadNotifications }}</span>
                                        @endif
                                    </a>
                                    <a href="{{ route('notifications.settings') }}" class="flex items-center gap-2 px-4 py-2 text-ink hover:bg-paper font-medium transition">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/>
                                        </svg>
                                        <span>تنظیمات اعلان</span>
                                    </a>
                                    <button type="button" @click="userMenuOpen = false; $dispatch('open-feedback')" class="w-full text-right flex items-center gap-2 px-4 py-2 text-ink hover:bg-paper font-medium transition cursor-pointer">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/>
                                        </svg>
                                        <span>گزارش اشکال یا پیشنهاد</span>
                                    </button>
                                </div>
                                @if(auth()->user()->canAccessAdmin())
                                    <div class="py-1">
                                        <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-2 px-4 py-2 text-cinnabar hover:bg-amber-50 dark:hover:bg-amber-950/20 font-bold transition">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                                            </svg>
                                            <span>پنل مدیریت و داوری</span>
                                        </a>
                                    </div>
                                @endif
                                <div class="py-1">
                                    <form action="{{ route('logout') }}" method="POST">
                                        @csrf
                                        <button type="submit" class="w-full text-right flex items-center gap-2 px-4 py-2 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/20 font-medium transition cursor-pointer">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                                            </svg>
                                            <span>خروج از حساب</span>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @else
                        <a href="{{ route('register') }}" class="sm:hidden inline-flex items-center px-3.5 py-1.5 rounded-full bg-cinnabar hover:opacity-90 text-white text-xs font-bold whitespace-nowrap shadow-sm transition">عضویت</a>
                        <a href="{{ route('login') }}" class="hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 whitespace-nowrap rounded-full border border-line-strong bg-paper text-xs font-bold text-ink hover:border-gold hover:text-cinnabar transition shadow-sm">
                            <svg class="w-3.5 h-3.5 text-stone-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/>
                            </svg>
                            <span>ورود / عضویت</span>
                        </a>
                    @endauth

                    <!-- Mobile Menu Button (Alpine) -->
                    <div x-data="{ open: false }" class="md:hidden relative">
                        <button @click="open = !open" class="p-2 rounded-lg border border-line-strong text-ink/80">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                        </button>
                        <div x-show="open" @click.away="open = false" x-transition class="absolute left-0 mt-2 w-48 bg-surface rounded-xl shadow-xl border border-line-strong py-2 z-50 text-sm">
                            <a href="{{ route('home') }}" class="block px-4 py-2 hover:bg-paper {{ request()->routeIs('home') ? 'text-cinnabar font-bold' : '' }}">صفحه نخست</a>
                            <a href="{{ route('subjects.index') }}" class="block px-4 py-2 hover:bg-paper {{ request()->routeIs('subjects.*') ? 'text-cinnabar font-bold' : '' }}">موضوعات</a>
                            <a href="{{ route('libraries.index') }}" class="block px-4 py-2 hover:bg-paper {{ request()->routeIs('libraries.*') ? 'text-cinnabar font-bold' : '' }}">کتابخانه‌ها و مراکز</a>
                            <a href="{{ route('catalogers.index') }}" class="block px-4 py-2 hover:bg-paper {{ request()->routeIs('catalogers.*') ? 'text-cinnabar font-bold' : '' }}">فهرست‌نگاران</a>
                            <div class="my-1 border-t border-line-strong sm:hidden"></div>
                            <button type="button" @click="darkMode = !darkMode; localStorage.setItem('theme', darkMode ? 'dark' : 'light'); document.documentElement.classList.toggle('dark', darkMode)" class="sm:hidden w-full text-right px-4 py-2 hover:bg-paper">
                                <span x-show="darkMode" x-cloak>حالت روز</span>
                                <span x-show="!darkMode" x-cloak>حالت شب</span>
                            </button>
                            @guest
                                <div class="my-1 border-t border-line-strong"></div>
                                <a href="{{ route('login') }}" class="block px-4 py-2 hover:bg-paper {{ request()->routeIs('login') ? 'text-cinnabar font-bold' : '' }}">ورود</a>
                                <a href="{{ route('register') }}" class="block px-4 py-2 hover:bg-paper {{ request()->routeIs('register') ? 'text-cinnabar font-bold' : '' }}">عضویت</a>
                            @endguest
                        </div>
                    </div>

                </div>

            </div>
        </div>
    </header>

    @guest
        @if(request()->routeIs('home'))
            <div x-data="{ show: (() => { try { return localStorage.getItem('join-bar-dismissed') !== '1' } catch (e) { return true } })() }"
                 x-show="show" x-cloak
                 class="md:hidden bg-cinnabar/10 dark:bg-cinnabar/15 border-b border-gold/30">
                <div class="px-4 py-2.5 flex items-center gap-3 text-xs">
                    <p class="flex-1 leading-5 text-ink">برای ثبت پیشنهاد، یادداشت و اصلاح فهرست‌ها عضو شوید.</p>
                    <a href="{{ route('register') }}" class="shrink-0 px-3 py-1.5 rounded-full bg-cinnabar hover:opacity-90 text-white font-bold">عضویت</a>
                    <a href="{{ route('login') }}" class="shrink-0 font-bold text-cinnabar ">ورود</a>
                    <button type="button" aria-label="بستن"
                            @click="show = false; try { localStorage.setItem('join-bar-dismissed', '1') } catch (e) {}"
                            class="shrink-0 p-1 text-stone-400 hover:text-stone-600 dark:hover:text-stone-200">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>
        @endif
    @endguest

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
        @auth
            @unless(auth()->user()->hasVerifiedEmail() || request()->routeIs('verification.*'))
                {{-- Verification is optional; it lets the account claim its earlier guest suggestions --}}
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-4">
                    <div class="p-3 rounded-2xl bg-amber-500/10 border border-amber-500/20 text-xs text-amber-900 dark:text-amber-200 flex flex-wrap items-center justify-between gap-2">
                        <span>ایمیل حساب شما هنوز تأیید نشده است. با تأیید آن، پیشنهادهایی که پیش‌تر بدون ورود فرستاده‌اید به حسابتان وصل می‌شوند.</span>
                        <a href="{{ route('verification.notice') }}" class="font-bold text-cinnabar hover:underline shrink-0">تأیید ایمیل</a>
                    </div>
                </div>
            @endunless
        @endauth
        @yield('content')
    </main>

    <!-- Footer -->
    <footer class="bg-[#221C17] text-stone-300 border-t-2 border-gold mt-16 pt-12 pb-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <!-- Beta Notice & Scholarly Collaboration Callout Banner -->
            <div class="bg-black/20 border border-gold/30 rounded-2xl p-5 sm:p-6 mb-10 flex flex-col md:flex-row items-center justify-between gap-5 shadow-inner">
                <div class="space-y-2 text-justify md:text-right">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-cinnabar/20 text-amber-300 border border-gold/30">
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
                    <a href="mailto:info@fahares.net" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-cinnabar hover:opacity-90 text-on-cinnabar font-bold text-xs transition duration-150 shadow whitespace-nowrap">
                        <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                        <span>مکاتبه: info@fahares.net</span>
                    </a>
                    @guest
                        <a href="{{ route('register') }}" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl border border-gold/60 hover:bg-cinnabar/15 text-amber-200 font-bold text-xs transition duration-150 whitespace-nowrap">
                            <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                            </svg>
                            <span>ثبت‌نام پژوهشگران</span>
                        </a>
                    @endguest
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-8 mb-12">
                
                <!-- Col 1: About Platform -->
                <div class="md:col-span-2 space-y-4">
                    <div class="flex items-center gap-3">
                        <img src="{{ asset('images/brand/shamseh-64.png') }}" alt="" width="40" height="40" class="h-10 w-10">
                        <span class="font-naskh text-3xl font-bold text-amber-100">فهارس</span>
                    </div>
                    <p class="text-stone-400 text-sm leading-relaxed text-justify">
                        سامانه‌ی جامع کاوش در میراث مکتوب ایران و جهان اسلام، بر پایه‌ی فهرست‌های معتبر نسخه‌های خطی. داده‌های آثار، نسخه‌ها، پدیدآوران، کاتبان و کتابخانه‌ها از این فهرست‌ها گرد آمده و هر داده به مأخذ خود ارجاع دارد. منابع تازه به‌تدریج افزوده می‌شوند.
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
                    <h3 class="text-sm font-bold text-amber-200 tracking-wider mb-4 border-r-2 border-gold pr-2">دسترسی سریع</h3>
                    <ul class="space-y-2 text-sm text-stone-400">
                        <li><a href="{{ route('search', ['type' => 'works']) }}" class="hover:text-amber-100 transition">فهرست آثار و عناوین</a></li>
                        <li><a href="{{ route('subjects.index') }}" class="hover:text-amber-100 transition">درختواره موضوعات و علوم</a></li>
                        <li><a href="{{ route('search', ['type' => 'manuscripts']) }}" class="hover:text-amber-100 transition">کاوش نسخه‌های کهن خطی</a></li>
                        <li><a href="{{ route('search', ['type' => 'people']) }}" class="hover:text-amber-100 transition">پدیدآوران، شارحان و کاتبان</a></li>
                        <li><a href="{{ route('libraries.index') }}" class="hover:text-amber-100 transition">مراکز نگهداری و کتابخانه‌ها</a></li>
                        <li x-data><button type="button" @click="$dispatch('open-feedback')" class="hover:text-amber-100 transition cursor-pointer text-right">گزارش اشکال یا پیشنهاد</button></li>
                    </ul>
                </div>

                <!-- Col 3: Research & Features -->
                <div>
                    <h3 class="text-sm font-bold text-amber-200 tracking-wider mb-4 border-r-2 border-gold pr-2">امکانات پژوهشی</h3>
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

    <!-- Site feedback: report a problem or suggest, from any page -->
    <x-feedback />

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
                    class="fixed inset-0 bg-black/60 dark:bg-black/85 backdrop-blur-sm transition-opacity"></div>

                <!-- Modal Window Container -->
                <div 
                    x-show="openSearchModal"
                    x-transition:enter="ease-out duration-200"
                    x-transition:enter-start="opacity-0 scale-95 translate-y-2"
                    x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                    x-transition:leave="ease-in duration-150"
                    x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                    x-transition:leave-end="opacity-0 scale-95 translate-y-2"
                    class="mx-auto max-w-2xl transform overflow-hidden rounded-2xl bg-surface shadow-2xl border border-stone-200 dark:border-line transition-all relative z-10">

                    <!-- Search Input Header -->
                    <div class="relative flex items-center border-b border-stone-200 dark:border-line px-4 py-3.5 bg-stone-50/70 dark:bg-paper">
                        <svg class="w-5 h-5 text-cinnabar shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
                            class="w-full bg-transparent pr-3 pl-16 text-sm text-ink placeholder-stone-400 focus:outline-none border-none focus:ring-0">
                        
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
                                class="px-1.5 py-0.5 text-[10px] font-mono bg-stone-200 dark:bg-line text-ink/80 rounded hover:bg-stone-300 dark:hover:bg-stone-600 transition cursor-pointer"
                                title="بستن پنجره">
                                Esc
                            </button>
                        </div>
                    </div>

                    <!-- Subtle Loading Progress Indicator -->
                    <div x-show="loading" class="h-0.5 w-full bg-amber-100 dark:bg-stone-800 overflow-hidden">
                        <div class="h-full bg-cinnabar animate-pulse w-full"></div>
                    </div>

                    <!-- Results & State Body -->
                    <div x-ref="resultsContainer" class="max-h-[60vh] overflow-y-auto divide-y divide-stone-100 dark:divide-line p-2">

                        <!-- State: Initial Empty Prompt -->
                        <div x-show="query.trim().length < 2" class="p-8 text-center text-xs text-muted">
                            <div class="inline-flex items-center justify-center w-10 h-10 rounded-full bg-amber-500/10 text-cinnabar mb-3">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                            </div>
                            <p class="font-medium text-ink/80 text-sm mb-1">جستجوی بلادرنگ در آثار، اشخاص و نسخه‌ها</p>
                            <p>دست‌کم ۲ حرف تایپ کنید؛ سپس با کلیدهای ↑ و ↓ میان گزینه‌ها حرکت کرده و با Enter وارد شوید.</p>
                        </div>

                        <!-- State: No Results -->
                        <div x-show="query.trim().length >= 2 && !loading && !hasResults()" class="p-8 text-center text-xs text-muted">
                            <p class="text-sm font-medium mb-1">موردی منطبق با «<span class="text-ink font-semibold" x-text="query"></span>» در عناوین اصلی یافت نشد.</p>
                            <p class="mb-3 text-[11px] text-stone-400">می‌توانید همین عبارت را در کل فیلدها و کالبدشناسی نسخه‌ها جستجو کنید.</p>
                            <button 
                                type="button" 
                                @click="goToFullSearch()" 
                                class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl bg-cinnabar hover:opacity-90 text-on-cinnabar text-xs font-semibold transition cursor-pointer shadow-sm">
                                <span>جستجوی پیشرفته در کل پایگاه (Enter)</span>
                                <svg class="w-3.5 h-3.5 rtl:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                            </button>
                        </div>

                        <!-- Group: Works (آثار و عناوین) -->
                        <template x-if="results.works && results.works.length > 0">
                            <div class="py-2">
                                <div class="flex items-center justify-between px-3 py-1.5 text-[11px] font-bold text-cinnabar uppercase tracking-wider">
                                    <span>آثار و عناوین</span>
                                    <span class="text-[10px] text-stone-400 font-normal" x-text="results.works.length + ' مورد'"></span>
                                </div>
                                <template x-for="(item, index) in results.works" :key="'w-' + item.id">
                                    <a :href="item.url" 
                                       @mouseenter="selectedIndex = index"
                                       :data-active="selectedIndex === index"
                                       :class="selectedIndex === index ? 'bg-amber-100/80 dark:bg-amber-950/60 ring-1 ring-cinnabar/50 shadow-xs' : 'hover:bg-stone-50 dark:hover:bg-paper'"
                                       class="flex items-center justify-between px-3.5 py-2.5 rounded-xl transition group">
                                        <div class="min-w-0 pr-1">
                                            <div class="text-sm font-bold text-ink group-hover:text-cinnabar truncate" x-text="item.title"></div>
                                            <div class="text-xs text-muted truncate mt-0.5" x-text="item.author"></div>
                                        </div>
                                        <div class="shrink-0 flex items-center gap-2 mr-3">
                                            <span class="text-xs px-2.5 py-0.5 rounded-full bg-amber-50 dark:bg-amber-950/60 text-cinnabar dark:text-amber-300 font-semibold border border-amber-200/50 dark:border-amber-800/50" x-text="item.manuscripts_count + ' نسخه'"></span>
                                            <span :class="selectedIndex === index ? 'text-cinnabar font-bold translate-x-[-2px]' : 'text-stone-400 group-hover:text-cinnabar'" class="text-sm rtl:rotate-180 transition-transform">←</span>
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
                                       :class="selectedIndex === ((results.works?.length || 0) + index) ? 'bg-indigo-100/80 dark:bg-indigo-950/60 ring-1 ring-indigo-500/50 shadow-xs' : 'hover:bg-stone-50 dark:hover:bg-paper'"
                                       class="flex items-center justify-between px-3.5 py-2.5 rounded-xl transition group">
                                        <div class="min-w-0 pr-1">
                                            <div class="text-sm font-bold text-ink group-hover:text-indigo-600 dark:group-hover:text-indigo-400 truncate" x-text="item.name"></div>
                                            <div class="text-xs text-muted truncate mt-0.5" x-show="item.death_hijri" x-text="item.death_hijri"></div>
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
                                       :class="selectedIndex === ((results.works?.length || 0) + (results.people?.length || 0) + index) ? 'bg-emerald-100/80 dark:bg-emerald-950/60 ring-1 ring-emerald-500/50 shadow-xs' : 'hover:bg-stone-50 dark:hover:bg-paper'"
                                       class="flex items-center justify-between px-3.5 py-2.5 rounded-xl transition group">
                                        <div class="min-w-0 pr-1">
                                            <div class="text-sm font-bold text-ink group-hover:text-emerald-600 dark:group-hover:text-emerald-400 flex items-center gap-1.5 flex-wrap truncate">
                                                <span class="truncate" x-text="item.work_title"></span>
                                                <span x-show="item.author_name" class="text-xs font-normal text-muted" x-text="'(پدیدآور: ' + item.author_name + ')'"></span>
                                            </div>
                                            <div class="text-xs text-muted truncate mt-0.5" x-text="item.library + (item.shelfmark || item.accession_number ? ' • بازیابی: ' + (item.shelfmark || item.accession_number) : '')"></div>
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
                    <div class="flex items-center justify-between px-4 py-2.5 bg-stone-100/70 dark:bg-paper border-t border-stone-200 dark:border-line text-[11px] text-muted">
                        <div class="flex items-center gap-3">
                            <span class="flex items-center gap-1">
                                <kbd class="px-1.5 py-0.5 bg-white dark:bg-stone-800 border border-line-strong rounded text-[10px] font-mono">↑</kbd>
                                <kbd class="px-1.5 py-0.5 bg-white dark:bg-stone-800 border border-line-strong rounded text-[10px] font-mono">↓</kbd>
                                <span>ناوبری</span>
                            </span>
                            <span class="flex items-center gap-1">
                                <kbd class="px-1.5 py-0.5 bg-white dark:bg-stone-800 border border-line-strong rounded text-[10px] font-mono">↵ Enter</kbd>
                                <span>انتخاب</span>
                            </span>
                            <span class="flex items-center gap-1">
                                <kbd class="px-1.5 py-0.5 bg-white dark:bg-stone-800 border border-line-strong rounded text-[10px] font-mono">Esc</kbd>
                                <span>بستن</span>
                            </span>
                        </div>
                        
                        <button 
                            type="button"
                            x-show="query.trim().length > 0" 
                            @click="goToFullSearch()" 
                            class="text-cinnabar hover:underline font-medium inline-flex items-center gap-1 cursor-pointer">
                            <span>کاوش جامع در کل پایگاه</span>
                            <span class="rtl:rotate-180">←</span>
                        </button>
                    </div>

                </div>
            </div>
        </template>
    </div>

    <!-- Global Catalog Facsimile Viewer Modal -->
    <x-catalog-viewer />

    @stack('scripts')
</body>
</html>

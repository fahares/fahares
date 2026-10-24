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
    <link rel="icon" type="image/x-icon" href="{{ \App\Support\VersionedAsset::url('favicon.ico') }}">
    
    <!-- Google Fonts: Vazirmatn -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="bg-paper text-ink font-sans min-h-screen flex selection:bg-cinnabar/20 selection:text-cinnabar">
@php
    $navGroups = \App\Support\AdminNav::groups(auth()->user());
    $navGroup = \App\Support\AdminNav::currentGroup($navGroups);
@endphp

    {{-- Phone: the menu slides in over a scrim --}}
    <div x-show="sidebarOpen" @click="sidebarOpen = false" x-cloak x-transition.opacity
         class="fixed inset-0 z-40 bg-black/40 lg:hidden"></div>

    {{-- Menu on the page's own paper (backlog item 65, option «الف»): four groups with rubric headings --}}
    <aside :class="sidebarOpen ? 'translate-x-0' : 'translate-x-full lg:translate-x-0'"
           class="fixed inset-y-0 right-0 z-50 w-64 bg-surface border-l border-line flex flex-col transition-transform duration-200 ease-out motion-reduce:transition-none">
        <div class="h-16 flex items-center justify-between px-5 border-b border-line">
            <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-2.5">
                <img src="{{ \App\Support\VersionedAsset::url('images/brand/shamseh-64.png') }}" alt="" width="36" height="36" class="h-9 w-9">
                <span>
                    <span class="font-naskh text-2xl font-bold text-ink leading-none block">فهارس</span>
                    <span class="text-[11px] text-gold">پنل مدیریت و داوری</span>
                </span>
            </a>
            <button type="button" @click="sidebarOpen = false" class="lg:hidden p-1.5 text-muted hover:text-cinnabar cursor-pointer" aria-label="بستن منو">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-4 text-[13px]" aria-label="منوی پنل">
            @foreach($navGroups as $group)
                <div>
                    <h2 class="rubric text-sm px-2 pb-1">{{ $group['title'] }}</h2>
                    @foreach($group['items'] as $item)
                        <a href="{{ $item['url'] }}" @if($item['active']) aria-current="page" @endif
                           class="flex items-center justify-between gap-2 px-3 py-1.5 rounded-sm transition {{ $item['active'] ? 'bg-paper text-cinnabar font-bold shadow-[inset_-3px_0_0_var(--cinnabar)]' : 'text-ink hover:bg-paper' }}">
                            <span>{{ $item['label'] }}</span>
                            @if($item['count'])
                                <span class="px-1.5 min-w-5 text-center rounded-full text-[11px] font-bold tabular-nums {{ $item['soft'] ? 'border border-line text-muted' : 'bg-cinnabar text-on-cinnabar' }}">{{ \App\Support\Fa::num($item['count']) }}</span>
                            @endif
                        </a>
                    @endforeach
                </div>
            @endforeach
        </nav>

        <div class="border-t border-line px-5 py-3 flex items-center gap-2.5 text-xs">
            <x-ui.avatar :user="auth()->user()" />
            <div class="min-w-0 flex-1">
                <p class="font-bold text-ink truncate">{{ auth()->user()->name }}</p>
                <p class="text-muted">{{ \App\Models\Role::where('key', auth()->user()->role)->value('label') ?? auth()->user()->role }}</p>
            </div>
            <form action="{{ route('logout') }}" method="POST">
                @csrf
                <button type="submit" class="text-muted hover:text-cinnabar transition cursor-pointer">خروج</button>
            </form>
        </div>
    </aside>

    <div class="flex-1 flex flex-col min-w-0 lg:mr-64">

        <header class="h-14 bg-surface border-b border-line px-3 sm:px-6 flex items-center justify-between gap-3 sticky top-0 z-30">
            <div class="flex items-center gap-3 min-w-0">
                <button type="button" @click="sidebarOpen = true" class="lg:hidden size-9 grid place-items-center rounded-sm border border-line-strong bg-paper text-ink cursor-pointer" aria-label="منو">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <span class="lg:hidden font-naskh text-xl font-bold">فهارس</span>
            </div>
            <div class="flex items-center gap-2 text-xs">
                <span class="hidden sm:inline text-muted">{{ auth()->user()->name }}</span>
                <button type="button"
                        @click="darkMode = !darkMode; localStorage.setItem('theme', darkMode ? 'dark' : 'light'); document.documentElement.classList.toggle('dark', darkMode)"
                        class="size-8 grid place-items-center rounded-sm border border-line-strong bg-paper text-ink hover:text-cinnabar transition cursor-pointer" title="روشن یا تیره" aria-label="روشن یا تیره">
                    <svg x-show="darkMode" x-cloak class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>
                    <svg x-show="!darkMode" x-cloak class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
                </button>
                <x-ui.btn :href="route('home')" size="sm" target="_blank">مشاهده‌ی سایت</x-ui.btn>
            </div>
        </header>

        {{-- Page heading: the menu group above the title, then the page's own actions --}}
        <div class="px-3 sm:px-6 pt-5 flex flex-wrap items-end justify-between gap-3">
            <div class="min-w-0">
                <div class="text-xs text-muted flex flex-wrap items-center gap-1.5">
                    @hasSection('breadcrumb')
                        @yield('breadcrumb')
                    @else
                        {{ $navGroup }}
                    @endif
                </div>
                <h1 class="font-naskh text-2xl font-bold text-ink leading-snug text-balance">@yield('page_title', 'پنل مدیریت')</h1>
            </div>
            @hasSection('page_actions')
                <div class="flex flex-wrap items-center gap-2">@yield('page_actions')</div>
            @endif
        </div>

        {{-- Flash messages --}}
        @if(session()->hasAny(['success', 'error', 'warning', 'info']))
        <div class="px-3 sm:px-6 pt-4 space-y-2">
            @foreach(['success' => 'border-emerald-600/40 text-emerald-800 dark:text-emerald-300', 'error' => 'border-cinnabar/50 text-cinnabar', 'warning' => 'border-amber-600/50 text-amber-800 dark:text-amber-300', 'info' => 'border-line-strong text-ink'] as $key => $tone)
                @if(session($key))
                    <div role="status" class="px-4 py-2.5 bg-surface border border-s-4 {{ $tone }} text-[13px]">{{ session($key) }}</div>
                @endif
            @endforeach
        </div>
        @endif

        <main class="flex-1 px-3 sm:px-6 py-5">
            @yield('content')
        </main>
    </div>

    @stack('scripts')
</body>
</html>

@extends('layouts.admin')

@section('title', 'پیشخوان و آمار کلی')
@section('page_title', 'مرکز فرماندهی پایگاه فهارس')
@section('breadcrumb')
    <span>پیشخوان</span>
    <span>/</span>
    <span class="text-stone-400">داشبورد آماری و نظارتی</span>
@endsection

@section('content')
<div class="space-y-8">

    <!-- KPI Metric Cards Grid -->
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4">
        
        <!-- Manuscripts Card -->
        <div class="p-5 rounded-2xl bg-surface border border-line shadow-xs relative overflow-hidden group">
            <div class="absolute top-0 right-0 w-1.5 h-full bg-cinnabar"></div>
            <div class="flex items-center justify-between">
                <span class="text-xs text-muted font-bold">نسخه‌های خطی</span>
                <span class="p-2 rounded-xl bg-cinnabar/10 text-cinnabar">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                </span>
            </div>
            <div class="mt-3">
                <div class="text-2xl font-black text-ink font-mono tracking-tight">
                    {{ number_format($counts['manuscripts']) }}
                </div>
                <span class="text-[10px] text-stone-400">مدخل دست‌نویس</span>
            </div>
        </div>

        <!-- Works Card -->
        <div class="p-5 rounded-2xl bg-surface border border-line shadow-xs relative overflow-hidden group">
            <div class="absolute top-0 right-0 w-1.5 h-full bg-ink"></div>
            <div class="flex items-center justify-between">
                <span class="text-xs text-muted font-bold">عناوین آثار</span>
                <span class="p-2 rounded-xl bg-blue-500/10 text-blue-500">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </span>
            </div>
            <div class="mt-3">
                <div class="text-2xl font-black text-ink font-mono tracking-tight">
                    {{ number_format($counts['works']) }}
                </div>
                <span class="text-[10px] text-stone-400">کتاب و رساله مستقل</span>
            </div>
        </div>

        <!-- People Card -->
        <div class="p-5 rounded-2xl bg-surface border border-line shadow-xs relative overflow-hidden group">
            <div class="absolute top-0 right-0 w-1.5 h-full bg-indigo-500"></div>
            <div class="flex items-center justify-between">
                <span class="text-xs text-muted font-bold">پدیدآوران و اعلام</span>
                <span class="p-2 rounded-xl bg-indigo-500/10 text-indigo-500">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                </span>
            </div>
            <div class="mt-3">
                <div class="text-2xl font-black text-ink font-mono tracking-tight">
                    {{ number_format($counts['people']) }}
                </div>
                <span class="text-[10px] text-stone-400">مؤلف، کاتب، واقف</span>
            </div>
        </div>

        <!-- Catalogers Card -->
        <div class="p-5 rounded-2xl bg-surface border border-line shadow-xs relative overflow-hidden group">
            <div class="absolute top-0 right-0 w-1.5 h-full bg-emerald-500"></div>
            <div class="flex items-center justify-between">
                <span class="text-xs text-muted font-bold">فهرست‌نگاران</span>
                <span class="p-2 rounded-xl bg-emerald-500/10 text-emerald-500">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                </span>
            </div>
            <div class="mt-3">
                <div class="text-2xl font-black text-ink font-mono tracking-tight">
                    {{ number_format($counts['catalogers']) }}
                </div>
                <span class="text-[10px] text-stone-400">استاد و محقق رسمی</span>
            </div>
        </div>

        <!-- Libraries Card -->
        <div class="p-5 rounded-2xl bg-surface border border-line shadow-xs relative overflow-hidden group">
            <div class="absolute top-0 right-0 w-1.5 h-full bg-amber-500"></div>
            <div class="flex items-center justify-between">
                <span class="text-xs text-muted font-bold">کتابخانه‌ها</span>
                <span class="p-2 rounded-xl bg-amber-500/10 text-amber-500">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                </span>
            </div>
            <div class="mt-3">
                <div class="text-2xl font-black text-ink font-mono tracking-tight">
                    {{ number_format($counts['libraries']) }}
                </div>
                <span class="text-[10px] text-stone-400">مرکز و آرشیو نسخ</span>
            </div>
        </div>

        <!-- Subjects Card -->
        <div class="p-5 rounded-2xl bg-surface border border-line shadow-xs relative overflow-hidden group">
            <div class="absolute top-0 right-0 w-1.5 h-full bg-rose-500"></div>
            <div class="flex items-center justify-between">
                <span class="text-xs text-muted font-bold">موضوعات دانش</span>
                <span class="p-2 rounded-xl bg-rose-500/10 text-rose-500">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                </span>
            </div>
            <div class="mt-3">
                <div class="text-2xl font-black text-ink font-mono tracking-tight">
                    {{ number_format($counts['subjects']) }}
                </div>
                <span class="text-[10px] text-stone-400">شاخه درختی تاکسونومی</span>
            </div>
        </div>

    </div>

    <!-- Quick Action Banners -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- Suggestions Review Banner -->
        <div class="p-6 rounded-2xl bg-gradient-to-br from-amber-500/15 via-amber-500/5 to-transparent border border-amber-500/30 flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-3">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-500/20 text-amber-900 dark:text-amber-200">
                        صف داوری علمی
                    </span>
                    <span class="text-2xl font-black font-mono text-amber-700 dark:text-amber-400">
                        {{ number_format($counts['pending_suggestions']) }}
                    </span>
                </div>
                <h3 class="text-base font-bold text-ink">پیشنهادات اصلاحی در انتظار بررسی</h3>
                <p class="text-xs text-ink/80 mt-1 leading-relaxed">
                    گزارش‌ها و استنادات ارسالی توسط پژوهشگران برای بررسی کاتبان، تاریخ‌ها، آغاز و انجام نسخه‌های خطی.
                </p>
            </div>
            <div class="mt-5">
                <a href="{{ route('admin.suggestions.index') }}" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-cinnabar hover:opacity-90 text-on-cinnabar text-xs font-bold shadow-md transition">
                    <span>ورود به میز کار داوری</span>
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                </a>
            </div>
        </div>

        <!-- Meilisearch Health Status -->
        <div class="p-6 rounded-2xl bg-surface border border-line shadow-xs flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-3">
                    <span class="text-xs font-bold text-muted">موتور جستجوی Meilisearch</span>
                    @if($meiliStatus['healthy'])
                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[11px] font-bold bg-emerald-500/15 text-emerald-700 dark:text-emerald-300">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                            فعال و آماده
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[11px] font-bold bg-red-500/15 text-red-700 dark:text-red-300">
                            <span class="w-1.5 h-1.5 rounded-full bg-red-500"></span>
                            غیرفعال / خطا
                        </span>
                    @endif
                </div>
                <h3 class="text-base font-bold text-ink">وضعیت نمایه و کاوش زنده</h3>
                <p class="text-xs text-muted mt-1 leading-relaxed">
                    @if($meiliStatus['healthy'])
                        سرویس میلی‌سرچ (نسخه {{ $meiliStatus['version'] ?? 'v1.6' }}) به صورت کانتینری متصل است و جستجوی لحظه‌ای فعال می‌باشد.
                    @else
                        امکان ارتباط با سرویس میلی‌سرچ وجود ندارد: {{ Str::limit($meiliStatus['error'] ?? 'دسترسی مقدور نیست', 60) }}
                    @endif
                </p>
            </div>
            <div class="mt-5">
                <a href="{{ route('admin.system.index') }}" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-line-strong hover:bg-stone-50 dark:hover:bg-stone-800 text-ink text-xs font-bold transition">
                    <span>مدیریت نمایه‌ها و کش</span>
                </a>
            </div>
        </div>

        <!-- Readings summary: approved suggestions kept as readings (see SuggestionReadings) -->
        <div class="p-6 rounded-2xl bg-surface border border-line shadow-xs flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-3">
                    <span class="text-xs font-bold text-muted">دستگاه علمی و انتقادی</span>
                    <span class="text-2xl font-black font-mono text-ink">
                        {{ number_format($counts['readings']) }}
                    </span>
                </div>
                <h3 class="text-base font-bold text-ink">اقوال پژوهشگران</h3>
                <p class="text-xs text-muted mt-1 leading-relaxed">
                    پیشنهادهای تأییدشده‌ای که به‌عنوان «قول مختار» یا «قول دیگر» با مستندشان در صفحه‌ی رکورد نمایش داده می‌شوند.
                </p>
            </div>
            <div class="mt-5">
                <a href="{{ route('admin.suggestions.index', ['status' => 'approved']) }}" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-line-strong hover:bg-stone-50 dark:hover:bg-stone-800 text-ink text-xs font-bold transition">
                    <span>مشاهده پیشنهادهای تأییدشده</span>
                </a>
            </div>
        </div>

    </div>

    <!-- Tables Grid: Recent Pending Suggestions & Recent Audit Logs -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        
        <!-- Pending Suggestions List -->
        <div class="bg-surface rounded-2xl border border-line shadow-xs overflow-hidden">
            <div class="p-5 border-b border-line flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <span class="p-2 rounded-xl bg-amber-500/10 text-amber-600">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5M7.188 2.239l.777 2.897M5.136 7.965l-2.898-.777M13.95 4.05l-2.122 2.122m-5.657 5.656l-2.12 2.122"/></svg>
                    </span>
                    <h3 class="text-sm font-bold text-ink">آخرین پیشنهادات اصلاحی در صف داوری</h3>
                </div>
                <a href="{{ route('admin.suggestions.index') }}" class="text-xs font-bold text-cinnabar hover:underline">
                    مشاهده همه
                </a>
            </div>

            <div class="divide-y divide-line">
                @forelse($pendingSuggestions as $sug)
                    <div class="p-4 hover:bg-paper transition flex items-center justify-between gap-4">
                        <div class="space-y-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-stone-100 dark:bg-stone-800 text-ink/80">
                                    {{ \Illuminate\Support\Str::limit($sug->place_label, 40) }}
                                </span>
                                <span class="text-xs font-bold text-ink">
                                    فیلد: <span class="text-cinnabar">{{ $sug->field_label }}</span>
                                </span>
                            </div>
                            <p class="text-xs text-ink/80 truncate max-w-md">
                                مقدار پیشنهادی: <strong class="text-ink">{{ $sug->suggested_value }}</strong>
                            </p>
                            <div class="text-[10px] text-stone-400 flex items-center gap-2">
                                <span>ثبت‌کننده: {{ $sug->guest_name ?: 'پژوهشگر مهمان' }}</span>
                                <span>•</span>
                                <span>{{ $sug->created_at->diffForHumans() }}</span>
                            </div>
                        </div>

                        <div class="shrink-0">
                            <a href="{{ route('admin.suggestions.show', $sug) }}" 
                               class="px-3 py-1.5 rounded-xl bg-stone-100 dark:bg-stone-800 hover:bg-cinnabar hover:text-white text-ink text-xs font-bold transition">
                                بررسی
                            </a>
                        </div>
                    </div>
                @empty
                    <div class="p-8 text-center text-xs text-stone-400">
                        صف داوری خالی است؛ تمامی پیشنهادات ارسالی بررسی و نهایی شده‌اند.
                    </div>
                @endforelse
            </div>
        </div>

        <!-- Recent Audit Activity Stream -->
        <div class="bg-surface rounded-2xl border border-line shadow-xs overflow-hidden">
            <div class="p-5 border-b border-line flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <span class="p-2 rounded-xl bg-blue-500/10 text-blue-600">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </span>
                    <h3 class="text-sm font-bold text-ink">فید زنده تغییرات و ممیزی سیستم</h3>
                </div>
                <a href="{{ route('admin.audit-logs.index') }}" class="text-xs font-bold text-cinnabar hover:underline">
                    گزارش کامل
                </a>
            </div>

            <div class="divide-y divide-line">
                @forelse($recentLogs as $log)
                    <div class="p-4 hover:bg-paper transition flex items-center justify-between gap-4">
                        <div class="space-y-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold 
                                    {{ $log->action === 'create' ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300' : '' }}
                                    {{ $log->action === 'update' ? 'bg-blue-500/15 text-blue-700 dark:text-blue-300' : '' }}
                                    {{ $log->action === 'delete' ? 'bg-red-500/15 text-red-700 dark:text-red-300' : '' }}
                                    {{ $log->action === 'verify' ? 'bg-amber-500/15 text-amber-700 dark:text-amber-300' : '' }}">
                                    {{ $log->action }}
                                </span>
                                <span class="text-xs font-semibold text-ink">
                                    {{ class_basename($log->auditable_type) }} #{{ $log->auditable_id }}
                                </span>
                            </div>
                            <div class="text-[11px] text-muted">
                                توسط: <strong class="text-ink">{{ $log->user?->name ?: 'سیستم' }}</strong>
                            </div>
                        </div>

                        <div class="shrink-0 text-left">
                            <span class="text-[10px] text-stone-400 font-mono">{{ $log->created_at->diffForHumans() }}</span>
                        </div>
                    </div>
                @empty
                    <div class="p-8 text-center text-xs text-stone-400">
                        هنوز رخداد تغییراتی در سیستم ثبت نشده است.
                    </div>
                @endforelse
            </div>
        </div>

    </div>

</div>
@endsection

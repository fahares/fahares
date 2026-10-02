@extends('layouts.app')

@section('title', 'نسخه خطی ' . ($manuscript->work?->primary_title ?? '') . ' | ' . ($manuscript->libraryRecord?->name ?? $manuscript->library) . ' (' . ($manuscript->shelfmark ?? 'بی‌شماره') . ')')
@section('meta_description', 'شناسنامه کالبدشناسی نسخه خطی ' . ($manuscript->work?->primary_title ?? '') . ' در ' . ($manuscript->libraryRecord?->name ?? $manuscript->library) . ' با شماره بازیابی ' . ($manuscript->shelfmark ?? ''))
@section('og_type', 'article')

@section('content')
<div x-data="{ 
    suggestionModalOpen: false, 
    selectedField: 'کاتب',
    citationModalOpen: false,
    qrModalOpen: false,
    copiedKey: null,
    copyText(text, key) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(() => {
                this.copiedKey = key;
                setTimeout(() => { if (this.copiedKey === key) this.copiedKey = null; }, 2500);
            });
        } else {
            const ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            this.copiedKey = key;
            setTimeout(() => { if (this.copiedKey === key) this.copiedKey = null; }, 2500);
        }
    },
    openQrModal() {
        this.qrModalOpen = true;
        this.$nextTick(() => {
            const canvas = document.getElementById('manuscript-qr-canvas');
            if (canvas && window.QRCode) {
                window.QRCode.toCanvas(canvas, '{{ url('/m/' . $manuscript->id) }}', {
                    width: 220,
                    margin: 1,
                    color: {
                        dark: '#15192C',
                        light: '#FFFFFF'
                    }
                }, function (error) {
                    if (error) console.error(error);
                });
            }
        });
    },
    downloadQr() {
        const canvas = document.getElementById('manuscript-qr-canvas');
        if (canvas) {
            const link = document.createElement('a');
            link.download = 'qrcode-manuscript-{{ $manuscript->id }}.png';
            link.href = canvas.toDataURL('image/png');
            link.click();
        }
    }
}" class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">

    <!-- Breadcrumb -->
    <nav class="flex items-center gap-2 text-xs text-stone-500">
        <a href="{{ route('home') }}" class="hover:text-[#B38A50]">فهارس</a>
        <span>/</span>
        <a href="{{ route('search', ['type' => 'manuscripts']) }}" class="hover:text-[#B38A50]">نسخه‌های خطی</a>
        <span>/</span>
        @if($manuscript->work)
            <a href="{{ route('works.show', $manuscript->work) }}" class="hover:text-[#B38A50] truncate max-w-xs">{{ $manuscript->work->primary_title }}</a>
            <span>/</span>
        @endif
        <span class="text-stone-800 dark:text-stone-200 font-semibold">{{ $manuscript->shelfmark ?? 'شناسنامه نسخه' }}</span>
    </nav>

    <!-- MANUSCRIPT HEADER IDENTIFICATION -->
    <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 sm:p-8 border border-[#EADFCF] dark:border-[#272F4C] shadow-md space-y-6 relative overflow-hidden">
        
        <div class="flex flex-col md:flex-row md:items-start justify-between gap-6">
            <div class="space-y-3 flex-1">
                
                <div class="flex flex-wrap items-center gap-2">
                    <span class="px-3 py-1 rounded-full bg-amber-50 dark:bg-amber-950/40 text-[#B38A50] text-xs font-bold">
                        شناسنامه کالبدشناسی نسخه خطی
                    </span>

                    @if($manuscript->is_autograph)
                        <span class="px-3 py-1 rounded-full bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-200 text-xs font-bold flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M6.267 3.455a3.066 3.066 0 001.745-.723 3.066 3.066 0 013.976 0 3.066 3.066 0 001.745.723 3.066 3.066 0 012.812 2.812c.051.643.304 1.254.723 1.745a3.066 3.066 0 010 3.976 3.066 3.066 0 00-.723 1.745 3.066 3.066 0 01-2.812 2.812 3.066 3.066 0 00-1.745.723 3.066 3.066 0 01-3.976 0 3.066 3.066 0 00-1.745-.723 3.066 3.066 0 01-2.812-2.812 3.066 3.066 0 00-.723-1.745 3.066 3.066 0 010-3.976 3.066 3.066 0 00.723-1.745 3.066 3.066 0 012.812-2.812zm7.44 5.252a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                            <span>اصل نسخه (دستخط مؤلف)</span>
                        </span>
                    @endif
                </div>

                @if($manuscript->work)
                    <div class="space-y-1">
                        <span class="text-xs text-stone-400 font-medium">عنوان اثر:</span>
                        <h1 class="text-2xl sm:text-3xl font-black text-[#292C56] dark:text-amber-100 tracking-tight">
                            <a href="{{ route('works.show', $manuscript->work) }}" class="hover:text-[#B38A50] transition">
                                {{ $manuscript->work->primary_title }}
                            </a>
                        </h1>
                        @if($manuscript->work->author)
                            <div class="text-xs text-stone-600 dark:text-stone-300">
                                پدیدآور اثر: <a href="{{ route('people.show', $manuscript->work->author) }}" class="font-bold text-[#B38A50] hover:underline">{{ $manuscript->work->author->name }}</a>
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            <!-- Header Actions: Permalink, QR Code, Academic Citation, Feedback -->
            <div class="flex flex-wrap items-center gap-2.5 pt-1">
                
                <!-- Permalink Capsule & QR Button -->
                <div class="inline-flex items-center gap-1.5 p-1 pr-2.5 sm:pr-3 rounded-2xl bg-[#FEF9F3] dark:bg-[#1A1E35] border border-[#EADFCF] dark:border-[#272F4C] shadow-2xs text-xs">
                    <span class="text-stone-400 dark:text-stone-400 font-medium flex items-center gap-1 select-none">
                        <svg class="w-3.5 h-3.5 text-[#B38A50]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                        </svg>
                        <span class="hidden sm:inline">پیوند کوتاه:</span>
                    </span>
                    <a href="{{ url('/m/' . $manuscript->id) }}" class="font-mono text-[11px] font-bold text-[#292C56] dark:text-amber-200 px-2 py-0.5 rounded-lg bg-white/80 dark:bg-[#15192C]/80 border border-stone-200/60 dark:border-stone-700/60 hover:text-[#B38A50] transition" dir="ltr" title="پیوند پایدار نسخه">
                        /m/{{ $manuscript->id }}
                    </a>
                    
                    <!-- Copy button with tooltip -->
                    <button 
                        type="button" 
                        @click="copyText('{{ url('/m/' . $manuscript->id) }}', 'permalink')"
                        class="relative p-1.5 rounded-xl bg-white dark:bg-[#15192C] hover:bg-[#B38A50] hover:text-white dark:hover:bg-[#B38A50] text-stone-600 dark:text-stone-300 border border-stone-200 dark:border-stone-700 hover:border-[#B38A50] transition shadow-2xs cursor-pointer"
                        title="رونوشت پیوند کوتاه پایدار">
                        
                        <svg x-show="copiedKey !== 'permalink'" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                        </svg>
                        <svg x-show="copiedKey === 'permalink'" x-cloak class="w-3.5 h-3.5 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                        </svg>

                        <!-- Tooltip feedback -->
                        <span 
                            x-show="copiedKey === 'permalink'" 
                            x-cloak 
                            x-transition 
                            class="absolute -bottom-8 left-1/2 -translate-x-1/2 whitespace-nowrap px-2 py-0.5 rounded-md bg-stone-900 text-white text-[10px] font-bold shadow-lg z-30 pointer-events-none">
                            کپی شد! ✓
                        </span>
                    </button>

                    <!-- QR Code Button -->
                    <button 
                        type="button" 
                        @click="openQrModal()"
                        class="p-1.5 rounded-xl bg-white dark:bg-[#15192C] hover:bg-[#B38A50] hover:text-white dark:hover:bg-[#B38A50] text-[#B38A50] border border-stone-200 dark:border-stone-700 hover:border-[#B38A50] transition shadow-2xs cursor-pointer"
                        title="نمایش QR Code نسخه جهت اسکن با گوشی">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z" />
                        </svg>
                    </button>
                </div>

                <!-- Academic Citation Button -->
                <button 
                    type="button" 
                    @click="citationModalOpen = true"
                    class="px-3.5 py-2 rounded-xl bg-stone-50 dark:bg-[#1A1E35] border border-[#B38A50]/60 hover:bg-[#B38A50] hover:text-white dark:hover:bg-[#B38A50] dark:hover:text-stone-900 text-[#B38A50] font-bold text-xs transition duration-200 flex items-center gap-1.5 shadow-2xs cursor-pointer">
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z" />
                    </svg>
                    <span>دریافت استناد علمی</span>
                </button>

                <!-- Suggestion / Feedback Action Button -->
                <button 
                    @click="suggestionModalOpen = true" 
                    type="button" 
                    class="px-3.5 py-2 rounded-xl border border-stone-300 dark:border-stone-700 text-stone-600 dark:text-stone-300 hover:border-[#B38A50] hover:text-[#B38A50] hover:bg-white dark:hover:bg-[#15192C] font-semibold text-xs transition duration-200 flex items-center gap-1.5 shadow-2xs cursor-pointer">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                    </svg>
                    <span>پیشنهاد تصحیح</span>
                </button>
            </div>
        </div>

        <!-- Shelfmark & Location Bar -->
        <div class="p-5 rounded-2xl bg-[#FEF9F3] dark:bg-[#1A1E35] border border-[#EADFCF] dark:border-[#272F4C] grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs">
            <div class="space-y-1">
                <span class="text-stone-400 block font-medium">کتابخانه و مخزن نگهداری:</span>
                @if($manuscript->libraryRecord || $manuscript->library_id)
                    <a href="{{ route('libraries.show', $manuscript->libraryRecord ?? $manuscript->library_id) }}" class="text-sm font-bold text-[#292C56] dark:text-amber-200 hover:text-[#B38A50] transition">
                        {{ $manuscript->libraryRecord?->name ?? $manuscript->library }}
                    </a>
                @else
                    <span class="text-sm font-bold text-stone-800 dark:text-stone-200">{{ $manuscript->library ?? 'نامشخص' }}</span>
                @endif
            </div>

            <div class="space-y-1">
                <span class="text-stone-400 block font-medium">شهر و کشور:</span>
                <span class="text-sm font-bold text-stone-800 dark:text-stone-200">{{ $manuscript->city ?? 'ایران' }}</span>
            </div>

            <div class="space-y-1">
                <span class="text-stone-400 block font-medium">شماره نسخه:</span>
                <span class="text-base font-black text-[#B38A50] tracking-wide">
                    {{ $manuscript->shelfmark ?? 'بی‌شماره' }}
                </span>
            </div>
        </div>

    </div>

    <!-- CODICOLOGICAL FLAGS (10 Indicators) -->
    <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-3">
        <h3 class="text-xs font-bold text-stone-400 uppercase tracking-wider">نشان‌های نسخه‌شناسی و کالبدشناسی</h3>
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-2 text-xs">
            
            <div class="p-3 rounded-xl border flex items-center justify-between {{ $manuscript->is_autograph ? 'bg-emerald-50 dark:bg-emerald-950/40 border-emerald-300 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200 font-bold' : 'bg-stone-50 dark:bg-stone-800/40 border-stone-200 dark:border-stone-800 text-stone-400' }}">
                <span>اصل نسخه (دستخط مؤلف)</span>
                <span>{{ $manuscript->is_autograph ? '✓' : '—' }}</span>
            </div>

            <div class="p-3 rounded-xl border flex items-center justify-between {{ $manuscript->has_author_marginalia ? 'bg-emerald-50 dark:bg-emerald-950/40 border-emerald-300 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200 font-bold' : 'bg-stone-50 dark:bg-stone-800/40 border-stone-200 dark:border-stone-800 text-stone-400' }}">
                <span>حواشی به خط مؤلف</span>
                <span>{{ $manuscript->has_author_marginalia ? '✓' : '—' }}</span>
            </div>

            <div class="p-3 rounded-xl border flex items-center justify-between {{ $manuscript->is_illuminated ? 'bg-amber-50 dark:bg-amber-950/40 border-amber-300 dark:border-amber-800 text-[#B38A50] font-bold' : 'bg-stone-50 dark:bg-stone-800/40 border-stone-200 dark:border-stone-800 text-stone-400' }}">
                <span>تذهیب و سرلوح</span>
                <span>{{ $manuscript->is_illuminated ? '✓' : '—' }}</span>
            </div>

            <div class="p-3 rounded-xl border flex items-center justify-between {{ $manuscript->is_illustrated ? 'bg-purple-50 dark:bg-purple-950/40 border-purple-300 dark:border-purple-800 text-purple-800 dark:text-purple-200 font-bold' : 'bg-stone-50 dark:bg-stone-800/40 border-stone-200 dark:border-stone-800 text-stone-400' }}">
                <span>نگاره و نقاشی</span>
                <span>{{ $manuscript->is_illustrated ? '✓' : '—' }}</span>
            </div>

            <div class="p-3 rounded-xl border flex items-center justify-between {{ $manuscript->is_corrected ? 'bg-blue-50 dark:bg-blue-950/40 border-blue-300 dark:border-blue-800 text-blue-800 dark:text-blue-200 font-bold' : 'bg-stone-50 dark:bg-stone-800/40 border-stone-200 dark:border-stone-800 text-stone-400' }}">
                <span>تصحیح‌شده (مصحح)</span>
                <span>{{ $manuscript->is_corrected ? '✓' : '—' }}</span>
            </div>

            <div class="p-3 rounded-xl border flex items-center justify-between {{ $manuscript->has_marginal_notes ? 'bg-stone-100 dark:bg-stone-800 border-stone-300 dark:border-stone-700 text-stone-800 dark:text-stone-200 font-bold' : 'bg-stone-50 dark:bg-stone-800/40 border-stone-200 dark:border-stone-800 text-stone-400' }}">
                <span>دارای حواشی (محشی)</span>
                <span>{{ $manuscript->has_marginal_notes ? '✓' : '—' }}</span>
            </div>

            <div class="p-3 rounded-xl border flex items-center justify-between {{ $manuscript->is_ruled ? 'bg-stone-100 dark:bg-stone-800 border-stone-300 dark:border-stone-700 text-stone-800 dark:text-stone-200 font-bold' : 'bg-stone-50 dark:bg-stone-800/40 border-stone-200 dark:border-stone-800 text-stone-400' }}">
                <span>سطور مجدول (جدول‌بندی)</span>
                <span>{{ $manuscript->is_ruled ? '✓' : '—' }}</span>
            </div>

            <div class="p-3 rounded-xl border flex items-center justify-between {{ $manuscript->has_catchwords ? 'bg-stone-100 dark:bg-stone-800 border-stone-300 dark:border-stone-700 text-stone-800 dark:text-stone-200 font-bold' : 'bg-stone-50 dark:bg-stone-800/40 border-stone-200 dark:border-stone-800 text-stone-400' }}">
                <span>دارای رکابه (پای‌صفحه)</span>
                <span>{{ $manuscript->has_catchwords ? '✓' : '—' }}</span>
            </div>

            <div class="p-3 rounded-xl border flex items-center justify-between {{ $manuscript->is_collated ? 'bg-emerald-50 dark:bg-emerald-950/40 border-emerald-300 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200 font-bold' : 'bg-stone-50 dark:bg-stone-800/40 border-stone-200 dark:border-stone-800 text-stone-400' }}">
                <span>مقابله و بلاغ</span>
                <span>{{ $manuscript->is_collated ? '✓' : '—' }}</span>
            </div>

            <div class="p-3 rounded-xl border flex items-center justify-between {{ $manuscript->is_facsimile ? 'bg-amber-50 dark:bg-amber-950/40 border-amber-300 dark:border-amber-800 text-amber-800 dark:text-amber-200 font-bold' : 'bg-stone-50 dark:bg-stone-800/40 border-stone-200 dark:border-stone-800 text-stone-400' }}">
                <span>چاپ عکسی / فاکسیمیله</span>
                <span>{{ $manuscript->is_facsimile ? '✓' : '—' }}</span>
            </div>

        </div>
    </div>

    <!-- PHYSICAL & HISTORICAL DETAILS -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        
        <!-- Scribe & Date Card -->
        <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-4">
            <h3 class="text-sm font-bold text-[#292C56] dark:text-amber-200 border-r-2 border-[#B38A50] pr-2">
                مشخصات کاتب و تاریخ کتابت
            </h3>
            
            <dl class="space-y-3 text-xs divide-y divide-stone-100 dark:divide-stone-800">
                <div class="flex justify-between pt-2">
                    <dt class="text-stone-400">نام کاتب:</dt>
                    <dd class="font-bold text-stone-800 dark:text-stone-200">
                        @if($manuscript->is_autograph)
                            @if($manuscript->work?->author)
                                <a href="{{ route('people.show', $manuscript->work->author) }}" class="text-emerald-700 dark:text-emerald-400 hover:underline font-bold inline-flex items-center gap-1">
                                    <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                    </svg>
                                    <span>دستخط مؤلف ({{ $manuscript->work->author->name }})</span>
                                </a>
                            @elseif($manuscript->scribe)
                                <a href="{{ route('people.show', $manuscript->scribe) }}" class="text-emerald-700 dark:text-emerald-400 hover:underline font-bold inline-flex items-center gap-1">
                                    <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                    </svg>
                                    <span>دستخط مؤلف ({{ $manuscript->scribe->name }})</span>
                                </a>
                            @else
                                <span class="text-emerald-700 dark:text-emerald-400 font-bold">مؤلف (نسخه اصل)</span>
                            @endif
                        @elseif($manuscript->scribe_id)
                            <a href="{{ route('people.show', $manuscript->scribe_id) }}" class="text-[#B38A50] hover:underline">{{ $manuscript->scribe_name ?: 'مشاهده کاتب' }}</a>
                        @elseif($manuscript->scribe_name)
                            {{ $manuscript->scribe_name }}
                        @elseif($manuscript->is_bika)
                            <span class="text-stone-400 italic">بی‌کاتب</span>
                        @else
                            <span class="text-stone-400">نامشخص</span>
                        @endif
                    </dd>
                </div>

                <div class="flex justify-between pt-2">
                    <dt class="text-stone-400">تاریخ کتابت:</dt>
                    <dd class="font-bold text-stone-800 dark:text-stone-200">
                        @if($manuscript->copy_date_raw)
                            <span>{{ $manuscript->copy_date_raw }}</span>
                            @if($manuscript->copy_date_hijri_year)
                                <span class="text-stone-400 mr-1">({{ $manuscript->copy_date_hijri_year }} هـ.ق)</span>
                            @endif
                        @elseif($manuscript->is_bita)
                            <span class="text-stone-400 italic">بی‌تاریخ</span>
                        @else
                            <span class="text-stone-400">نامشخص</span>
                        @endif
                    </dd>
                </div>

                <div class="flex justify-between pt-2">
                    <dt class="text-stone-400">محل کتابت:</dt>
                    <dd class="font-bold text-stone-800 dark:text-stone-200">
                        {{ $manuscript->copy_place ?? 'نامشخص' }}
                    </dd>
                </div>

                @if($manuscript->commissioned_by)
                    <div class="flex justify-between pt-2">
                        <dt class="text-stone-400">سفارش کتابت (فرماینده):</dt>
                        <dd class="font-bold text-[#B38A50] dark:text-amber-300">
                            {{ $manuscript->commissioned_by }}
                        </dd>
                    </div>
                @endif

                <div class="flex justify-between pt-2">
                    <dt class="text-stone-400">ردیف ثبت در مأخذ:</dt>
                    <dd class="font-bold text-stone-700 dark:text-stone-300">
                        ردیف {{ $manuscript->sequence_number }} (در {{ $manuscript->catalog?->short_name ?? 'فنخا' }})
                    </dd>
                </div>
            </dl>
        </div>

        <!-- Codicology & Material Card -->
        <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-4">
            <h3 class="text-sm font-bold text-[#292C56] dark:text-amber-200 border-r-2 border-[#B38A50] pr-2">
                ویژگی‌های مادی و ظاهری نسخه
            </h3>
            
            <dl class="space-y-3 text-xs divide-y divide-stone-100 dark:divide-stone-800">
                <div class="flex justify-between pt-2">
                    <dt class="text-stone-400">نوع و اقلام خط:</dt>
                    <dd class="font-bold text-stone-800 dark:text-stone-200">
                        {{ $manuscript->script_names ?? 'نامشخص' }}
                        @if($manuscript->script_style)
                            <span class="text-stone-400 mr-1">({{ $manuscript->script_style }})</span>
                        @endif
                    </dd>
                </div>

                <div class="flex justify-between pt-2">
                    <dt class="text-stone-400">تعداد برگ و سطر:</dt>
                    <dd class="font-bold text-stone-800 dark:text-stone-200">
                        {{ $manuscript->folios ? $manuscript->folios . ' برگ' : '-' }} • {{ $manuscript->lines ? $manuscript->lines . ' سطر' : '-' }}
                    </dd>
                </div>

                <div class="flex justify-between pt-2">
                    <dt class="text-stone-400">ابعاد و اندازه (سانتی‌متر):</dt>
                    <dd class="font-bold text-stone-800 dark:text-stone-200">
                        {{ $manuscript->dimensions ?? '-' }}
                    </dd>
                </div>

                <div class="flex justify-between pt-2">
                    <dt class="text-stone-400">کاغذ و جلد:</dt>
                    <dd class="font-bold text-stone-800 dark:text-stone-200">
                        {{ $manuscript->paper ?? 'کاغذ نامشخص' }} • {{ $manuscript->binding ?? 'جلد نامشخص' }}
                    </dd>
                </div>
            </dl>
        </div>

    </div>

    <!-- INCIPIT & EXPLICIT (آغاز و انجام نسخه) -->
    @if(count($manuscript->incipits_list) > 0 || count($manuscript->explicits_list) > 0)
        <div class="space-y-4">
            
            @if(count($manuscript->incipits_list) > 0)
                <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 sm:p-7 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-4">
                    <div class="flex items-center justify-between pb-2 border-b border-stone-100 dark:border-stone-800">
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-4 bg-[#B38A50] rounded-sm"></span>
                            <h3 class="text-xs font-bold text-[#B38A50] uppercase tracking-wider">
                                آغاز نسخه
                            </h3>
                        </div>
                        @if(count($manuscript->incipits_list) > 1)
                            <span class="text-[11px] text-stone-500 font-medium bg-amber-50 dark:bg-amber-950/40 px-2.5 py-0.5 rounded-full border border-amber-200 dark:border-amber-900/40">
                                {{ count($manuscript->incipits_list) }} آغاز ثبت‌شده
                            </span>
                        @endif
                    </div>

                    <div class="space-y-3">
                        @foreach($manuscript->incipits_list as $idx => $inc)
                            <div class="p-4 rounded-2xl bg-amber-50/40 dark:bg-amber-950/20 border-r-4 border-[#B38A50] space-y-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    @if(!empty($inc['is_work_match']))
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-md text-[11px] font-bold bg-[#B38A50]/20 text-[#B38A50] dark:bg-amber-900/40 dark:text-amber-300 border border-amber-300/60 dark:border-amber-700/60">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                                            </svg>
                                            <span>{{ $inc['label'] }}</span>
                                        </span>
                                    @elseif(!empty($inc['label']))
                                        <span class="inline-block px-2.5 py-0.5 rounded-md text-[11px] font-bold bg-[#B38A50]/15 text-[#B38A50]">
                                            {{ $inc['label'] }}
                                        </span>
                                    @elseif(count($manuscript->incipits_list) > 1)
                                        <span class="inline-block px-2.5 py-0.5 rounded-md text-[11px] font-bold bg-[#B38A50]/15 text-[#B38A50]">
                                            آغاز {{ $idx + 1 }}
                                        </span>
                                    @endif
                                </div>
                                @if(!empty($inc['is_placeholder']))
                                    <div class="text-stone-600 dark:text-stone-400 text-xs italic leading-relaxed">
                                        {{ $inc['text'] }}
                                    </div>
                                @else
                                    <div class="manuscript-quote text-stone-800 dark:text-stone-100 text-sm leading-relaxed font-medium">
                                        « {{ trim($inc['text'], "«» \t\n\r\0\x0B") }} »
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if(count($manuscript->explicits_list) > 0)
                <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 sm:p-7 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-4">
                    <div class="flex items-center justify-between pb-2 border-b border-stone-100 dark:border-stone-800">
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-4 bg-[#292C56] dark:bg-indigo-400 rounded-sm"></span>
                            <h3 class="text-xs font-bold text-[#292C56] dark:text-indigo-400 uppercase tracking-wider">
                                انجام نسخه
                            </h3>
                        </div>
                        @if(count($manuscript->explicits_list) > 1)
                            <span class="text-[11px] text-stone-500 font-medium bg-indigo-50 dark:bg-indigo-950/40 px-2.5 py-0.5 rounded-full border border-indigo-200 dark:border-indigo-900/40">
                                {{ count($manuscript->explicits_list) }} انجام ثبت‌شده
                            </span>
                        @endif
                    </div>

                    <div class="space-y-3">
                        @foreach($manuscript->explicits_list as $idx => $exp)
                            <div class="p-4 rounded-2xl bg-indigo-50/40 dark:bg-indigo-950/20 border-r-4 border-[#292C56] dark:border-indigo-400 space-y-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    @if(!empty($exp['is_work_match']))
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-md text-[11px] font-bold bg-[#292C56]/15 dark:bg-indigo-400/20 text-[#292C56] dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                                            </svg>
                                            <span>{{ $exp['label'] }}</span>
                                        </span>
                                    @elseif(!empty($exp['label']))
                                        <span class="inline-block px-2.5 py-0.5 rounded-md text-[11px] font-bold bg-[#292C56]/15 dark:bg-indigo-400/20 text-[#292C56] dark:text-indigo-300">
                                            {{ $exp['label'] }}
                                        </span>
                                    @elseif(count($manuscript->explicits_list) > 1)
                                        <span class="inline-block px-2.5 py-0.5 rounded-md text-[11px] font-bold bg-[#292C56]/15 dark:bg-indigo-400/20 text-[#292C56] dark:text-indigo-300">
                                            انجام {{ $idx + 1 }}
                                        </span>
                                    @endif
                                </div>
                                @if(!empty($exp['is_placeholder']))
                                    <div class="text-stone-600 dark:text-stone-400 text-xs italic leading-relaxed">
                                        {{ $exp['text'] }}
                                    </div>
                                @else
                                    <div class="manuscript-quote text-stone-800 dark:text-stone-100 text-sm leading-relaxed font-medium">
                                        « {{ trim($exp['text'], "«» \t\n\r\0\x0B") }} »
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

        </div>
    @endif

    <!-- OWNERSHIP, SEALS, WAQF & MANUSCRIPT NOTES -->
    @if(count($manuscript->ownership_and_seals_list) > 0 || $manuscript->residual_notes_text || count($manuscript->editorial_notes_list) > 0)
        <div class="space-y-4">
            
            @if(count($manuscript->ownership_and_seals_list) > 0)
                <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 sm:p-7 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-4">
                    <div class="flex items-center gap-2 pb-2 border-b border-stone-100 dark:border-stone-800">
                        <span class="w-2 h-4 bg-amber-600 rounded-sm"></span>
                        <h3 class="text-xs font-bold text-amber-800 dark:text-amber-300 uppercase tracking-wider">
                            مهرها، یادداشت‌های تملک و وقفیات نسخه
                        </h3>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        @foreach($manuscript->ownership_and_seals_list as $seal)
                            @php
                                $isSeal = str_contains($seal, 'مهر');
                                $isWaqf = str_contains($seal, 'وقف') || str_contains($seal, 'واقف');
                            @endphp
                            <div class="p-3.5 rounded-2xl border flex items-start gap-3 {{ $isSeal ? 'bg-amber-50/60 dark:bg-amber-950/20 border-amber-200 dark:border-amber-900/60' : ($isWaqf ? 'bg-emerald-50/60 dark:bg-emerald-950/20 border-emerald-200 dark:border-emerald-900/60' : 'bg-stone-50/80 dark:bg-stone-800/40 border-stone-200 dark:border-stone-800') }}">
                                <div class="shrink-0 mt-0.5">
                                    @if($isSeal)
                                        <div class="w-7 h-7 rounded-xl bg-amber-100 dark:bg-amber-900/60 text-amber-800 dark:text-amber-200 flex items-center justify-center" title="نشان مهر نسخه">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                            </svg>
                                        </div>
                                    @elseif($isWaqf)
                                        <div class="w-7 h-7 rounded-xl bg-emerald-100 dark:bg-emerald-900/60 text-emerald-800 dark:text-emerald-200 flex items-center justify-center" title="یادداشت وقف">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                            </svg>
                                        </div>
                                    @else
                                        <div class="w-7 h-7 rounded-xl bg-stone-200 dark:bg-stone-700 text-stone-700 dark:text-stone-300 flex items-center justify-center" title="تملک و سرگذشت نسخه">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                                            </svg>
                                        </div>
                                    @endif
                                </div>
                                <div class="text-xs text-stone-800 dark:text-stone-200 font-medium leading-relaxed">
                                    {{ $seal }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if($manuscript->residual_notes_text || count($manuscript->editorial_notes_list) > 0)
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    
                    @if($manuscript->residual_notes_text)
                        <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-2">
                            <span class="text-xs font-bold text-stone-500 uppercase tracking-wider block">فوائد و رسائل مندرج در نسخه:</span>
                            <div class="text-stone-700 dark:text-stone-300 text-xs leading-relaxed bg-stone-50 dark:bg-stone-800/40 p-3.5 rounded-2xl border border-stone-200 dark:border-stone-800">
                                {{ $manuscript->residual_notes_text }}
                            </div>
                        </div>
                    @endif

                    @if(count($manuscript->editorial_notes_list) > 0)
                        <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-2">
                            <span class="text-xs font-bold text-stone-500 uppercase tracking-wider block">یادداشت‌های انتقادی و تصحیحی فهرست‌نگار:</span>
                            <div class="space-y-1.5">
                                @foreach($manuscript->editorial_notes_list as $editNote)
                                    <div class="text-stone-700 dark:text-stone-300 text-xs leading-relaxed bg-stone-50 dark:bg-stone-800/40 p-3 rounded-xl border border-stone-200 dark:border-stone-800 flex items-start gap-2">
                                        <span class="text-[#B38A50] mt-0.5">•</span>
                                        <span>{{ $editNote }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                </div>
            @endif

        </div>
    @endif

    <!-- CITATION IN PRINTED SOURCE CATALOG & RAW CATALOG TEXT -->
    <div class="p-6 sm:p-7 rounded-3xl bg-[#FEF9F3] dark:bg-[#1A1E35] border border-[#EADFCF] dark:border-[#272F4C] space-y-5 shadow-xs">
        
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 text-xs">
            <div class="space-y-1.5 text-right">
                <div class="flex items-center gap-2">
                    <span class="px-2.5 py-0.5 rounded-md bg-amber-100 dark:bg-amber-950/60 text-[#B38A50] text-[11px] font-bold">
                        {{ $manuscript->catalog?->short_name ?? 'فنخا' }}
                    </span>
                    <span class="font-bold text-sm text-stone-800 dark:text-stone-200">مشخصات مأخذ و استناد فهرست‌نگاری:</span>
                </div>
                <p class="text-stone-600 dark:text-stone-300 text-xs leading-relaxed max-w-2xl">
                    {{ $manuscript->catalog?->citation_format ?? ($manuscript->catalog?->name ?? 'فهرستگان نسخه‌های خطی ایران (فنخا)') }}
                </p>
            </div>
            
            <div class="flex items-center gap-2.5 self-start sm:self-center shrink-0">
                <div class="text-xs font-bold text-[#B38A50] whitespace-nowrap bg-white dark:bg-[#15192C] px-4 py-2 rounded-2xl border border-[#EADFCF] dark:border-[#272F4C] shadow-2xs">
                    جلد {{ $manuscript->volume_number }} • صفحه {{ $manuscript->page_start }} • ردیف {{ $manuscript->sequence_number }}
                </div>
                @if(!$manuscript->clean_raw_text && $manuscript->volume_number && $manuscript->page_start)
                    <button 
                        type="button"
                        @click="$dispatch('open-catalog-viewer', { catalog: '{{ $manuscript->catalog?->code ?? 'fankha' }}', catalogName: '{{ $manuscript->catalog?->short_name ?? 'فنخا' }}', volume: {{ $manuscript->volume_number }}, page: {{ $manuscript->page_start }} })"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-amber-50 dark:bg-amber-950/40 border border-amber-300 dark:border-amber-800/60 text-xs font-bold text-[#B38A50] dark:text-amber-300 hover:bg-amber-100 dark:hover:bg-amber-900/40 active:scale-95 transition shadow-2xs whitespace-nowrap cursor-pointer">
                        <svg class="w-3.5 h-3.5 text-[#B38A50] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                        <span>مشاهده برگه در مأخذ چاپی</span>
                    </button>
                @endif
            </div>
        </div>

        @if($manuscript->cataloger || $manuscript->catalogVolume)
            <div class="p-3.5 sm:p-4 rounded-2xl bg-white/95 dark:bg-[#0C0F1D]/90 border border-amber-200/90 dark:border-[#272F4C] shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-4">
                @if($manuscript->cataloger)
                    <div class="flex items-center gap-5 sm:gap-6">
                        <a href="{{ route('catalogers.show', $manuscript->cataloger) }}" class="relative shrink-0 block group me-1 sm:me-2">
                            @if($manuscript->cataloger->avatar_url)
                                <img src="{{ $manuscript->cataloger->avatar_url }}" 
                                     alt="{{ $manuscript->cataloger->name }}" 
                                     class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl object-cover border-2 border-amber-300 dark:border-amber-700/80 shadow-xs group-hover:scale-105 group-hover:border-[#B38A50] transition duration-200">
                            @else
                                <div class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-amber-100 dark:bg-amber-950/60 border-2 border-amber-300 dark:border-amber-700/80 flex items-center justify-center text-xl font-black text-[#B38A50] shadow-xs">
                                    {{ mb_substr($manuscript->cataloger->name, 0, 1) }}
                                </div>
                            @endif
                        </a>

                        <div class="space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-[11px] font-bold text-[#B38A50]">
                                    فهرست‌نگار مأخذ چاپی:
                                </span>
                                @if($manuscript->cataloger->life_years_text)
                                    <span class="text-[10px] text-stone-400 dark:text-stone-500 font-normal">
                                        • {{ $manuscript->cataloger->life_years_text }}
                                    </span>
                                @endif
                            </div>
                            <a href="{{ route('catalogers.show', $manuscript->cataloger) }}" class="inline-flex items-center gap-1.5 text-sm sm:text-base font-black text-[#292C56] dark:text-stone-100 hover:text-[#B38A50] transition group">
                                <span>{{ $manuscript->cataloger->display_name }}</span>
                                <svg class="w-3.5 h-3.5 text-stone-400 group-hover:text-[#B38A50] transition-transform -scale-x-100" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                </svg>
                            </a>
                        </div>
                    </div>
                @endif

                @if($manuscript->catalogVolume)
                    <div class="self-start md:self-center bg-[#FBF7F0] dark:bg-[#15192C] px-3.5 py-2 sm:px-4 sm:py-2.5 rounded-xl border border-amber-200/70 dark:border-[#272F4C] text-right">
                        <span class="block text-[10px] text-stone-400 dark:text-stone-500 font-medium">مأخذ تفصیلی و جلد فهرست:</span>
                        <div class="flex items-center gap-2 mt-0.5">
                            <svg class="w-4 h-4 text-amber-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                            </svg>
                            <span class="text-xs sm:text-sm font-bold text-stone-800 dark:text-stone-200">
                                {{ $manuscript->catalogVolume->title }}
                            </span>
                        </div>
                    </div>
                @endif
            </div>
        @endif

        @if($manuscript->clean_raw_text)
            <div x-data="{ copied: false }" class="pt-4 border-t border-[#EADFCF] dark:border-[#272F4C] space-y-3">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <span class="w-1.5 h-3.5 bg-[#B38A50] rounded-xs"></span>
                        <span class="font-bold text-stone-800 dark:text-stone-200 text-xs sm:text-sm">
                            متن {{ $manuscript->catalog?->short_name ?? 'فنخا' }} (مأخذ نسخه خطی):
                        </span>
                    </div>
                    <div class="flex items-center gap-2 self-start sm:self-center">
                        @if($manuscript->volume_number && $manuscript->page_start)
                            <button 
                                type="button" 
                                @click="$dispatch('open-catalog-viewer', { catalog: '{{ $manuscript->catalog?->code ?? 'fankha' }}', catalogName: '{{ $manuscript->catalog?->short_name ?? 'فنخا' }}', volume: {{ $manuscript->volume_number }}, page: {{ $manuscript->page_start }} })"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-amber-50 dark:bg-amber-950/40 border border-amber-300 dark:border-amber-800/60 text-xs font-bold text-[#B38A50] dark:text-amber-300 hover:bg-amber-100 dark:hover:bg-amber-900/40 active:scale-95 transition shadow-2xs whitespace-nowrap cursor-pointer">
                                <svg class="w-3.5 h-3.5 text-[#B38A50] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                </svg>
                                <span>مشاهده برگه در مأخذ چاپی</span>
                            </button>
                        @endif

                        <button 
                            type="button" 
                            @click="navigator.clipboard.writeText($refs.rawTextContent.innerText.trim()); copied = true; setTimeout(() => copied = false, 2500)"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white dark:bg-[#15192C] border border-[#EADFCF] dark:border-[#272F4C] text-xs font-semibold text-stone-600 dark:text-stone-300 hover:text-[#B38A50] hover:border-[#B38A50] active:scale-95 transition shadow-2xs whitespace-nowrap cursor-pointer">
                            <svg x-show="!copied" class="w-3.5 h-3.5 text-stone-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                            </svg>
                            <svg x-show="copied" class="w-3.5 h-3.5 text-emerald-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="display: none;">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                            </svg>
                            <span x-show="!copied">رونوشت متن مأخذ</span>
                            <span x-show="copied" class="text-emerald-600 font-bold" style="display: none;">کپی شد! ✓</span>
                        </button>
                    </div>
                </div>
                
                <div 
                    x-ref="rawTextContent" 
                    class="p-4 sm:p-5 rounded-2xl bg-[#FEF9F3] dark:bg-[#0C0F1D] border border-[#EADFCF] dark:border-[#272F4C] text-xs sm:text-[13px] text-stone-800 dark:text-stone-100 leading-loose sm:leading-loose whitespace-pre-line text-right selection:bg-amber-100 dark:selection:bg-amber-950 font-normal">{{ $manuscript->clean_raw_text }}</div>
            </div>
        @endif

    </div>

    <!-- SUGGESTION MODAL DIALOG (Alpine.js) -->
    <div 
        x-show="suggestionModalOpen" 
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
        style="display: none;">
        
        <div 
            @click.away="suggestionModalOpen = false"
            class="bg-white dark:bg-[#15192C] w-full max-w-lg rounded-3xl p-6 sm:p-8 border border-stone-200 dark:border-stone-700 shadow-2xl space-y-6 text-right max-h-[90vh] overflow-y-auto">
            
            <div class="flex items-center justify-between pb-3 border-b border-stone-100 dark:border-stone-800">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-5 bg-[#B38A50] rounded-sm"></span>
                    <h3 class="font-bold text-lg text-stone-900 dark:text-stone-100">ثبت پیشنهاد تصحیح نسخه</h3>
                </div>
                <button @click="suggestionModalOpen = false" class="text-stone-400 hover:text-stone-600 text-lg">✕</button>
            </div>

            <p class="text-xs text-stone-500 leading-relaxed">
                پژوهشگر گرامی؛ در صورتی که در اطلاعات این نسخه (کاتب، تاریخ، آغاز/انجام، شماره نسخه یا مشخصات مادی) خطایی مشاهده نموده‌اید، لطفاً اصلاحیه خود را همراه با مستند علمی ثبت بفرمایید (همچنین می‌توانید گزارش خود را مستقیماً به ایمیل <a href="mailto:info@fahares.net" class="text-[#B38A50] hover:underline font-mono font-medium">info@fahares.net</a> ارسال فرمایید).
            </p>

            <form action="{{ route('suggestions.store') }}" method="POST" class="space-y-4 text-xs">
                @csrf
                <input type="hidden" name="suggestable_type" value="App\Models\Manuscript">
                <input type="hidden" name="suggestable_id" value="{{ $manuscript->id }}">

                <!-- Field Selector -->
                <div class="space-y-1">
                    <label class="block font-semibold text-stone-700 dark:text-stone-300">فیلد نیازمند تصحیح:</label>
                    <select name="field_name" x-model="selectedField" class="w-full p-2.5 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-800 dark:text-stone-100 font-medium">
                        <option value="scribe_name">نام کاتب</option>
                        <option value="copy_date_raw">تاریخ کتابت</option>
                        <option value="shelfmark">شماره نسخه</option>
                        <option value="incipit_text">عبارت آغاز (Incipit)</option>
                        <option value="explicit_text">عبارت انجام (Explicit)</option>
                        <option value="ownership_and_seals">مهرها، یادداشت‌های تملک و وقفیات</option>
                        <option value="script_names">نوع خط</option>
                        <option value="folios">تعداد برگ</option>
                        <option value="dimensions">ابعاد نسخه</option>
                        <option value="paper">نوع کاغذ</option>
                        <option value="binding">نوع جلد</option>
                        <option value="other">سایر موارد نسخه‌شناسی</option>
                    </select>
                </div>

                <!-- Suggested Value -->
                <div class="space-y-1">
                    <label class="block font-semibold text-stone-700 dark:text-stone-300">مقدار یا عبارت صحیح پیشنهادی:</label>
                    <textarea name="suggested_value" rows="2" required placeholder="عبارت صحیح را اینجا وارد نمایید..." class="w-full p-2.5 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-800 dark:text-stone-100 focus:border-[#B38A50]"></textarea>
                </div>

                <!-- Rationale & Citation -->
                <div class="space-y-1">
                    <label class="block font-semibold text-stone-700 dark:text-stone-300">مستند ادعا و منبع ارجاع (الزامی):</label>
                    <textarea name="rationale_citation" rows="3" required placeholder="مثال: ترقیمه صفحه آخر نسخه، یا فهرست کتابخانه ملی جلد ۴ صفحه ۱۲..." class="w-full p-2.5 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-800 dark:text-stone-100 focus:border-[#B38A50]"></textarea>
                </div>

                <!-- Researcher Info -->
                <div class="grid grid-cols-2 gap-3 pt-2">
                    <div class="space-y-1">
                        <label class="block font-semibold text-stone-700 dark:text-stone-300">نام شما (اختیاری):</label>
                        <input type="text" name="guest_name" placeholder="دکتر..." class="w-full p-2.5 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-800 dark:text-stone-100">
                    </div>
                    <div class="space-y-1">
                        <label class="block font-semibold text-stone-700 dark:text-stone-300">ایمیل تماس (اختیاری):</label>
                        <input type="email" name="guest_email" placeholder="email@example.com" class="w-full p-2.5 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-800 dark:text-stone-100">
                    </div>
                </div>

                <!-- Actions -->
                <div class="pt-4 flex items-center justify-end gap-3 border-t border-stone-100 dark:border-stone-800">
                    <button type="button" @click="suggestionModalOpen = false" class="px-4 py-2 rounded-xl text-stone-500 hover:bg-stone-100 dark:hover:bg-stone-800 font-semibold">
                        انصراف
                    </button>
                    <button type="submit" class="px-5 py-2.5 bg-[#B38A50] hover:bg-[#9C753F] text-white rounded-xl font-bold shadow-md transition">
                        ارسال پیشنهاد به هیئت علمی
                    </button>
                </div>

            </form>

    <!-- QR CODE MODAL DIALOG (Alpine.js) -->
    <div 
        x-show="qrModalOpen" 
        x-cloak
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
        style="display: none;">
        
        <div 
            @click.away="qrModalOpen = false"
            class="bg-white dark:bg-[#15192C] w-full max-w-sm rounded-3xl p-6 sm:p-7 border border-[#EADFCF] dark:border-[#272F4C] shadow-2xl space-y-5 text-center relative overflow-hidden">
            
            <div class="flex items-center justify-between pb-3 border-b border-stone-100 dark:border-stone-800">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-5 bg-[#B38A50] rounded-sm"></span>
                    <h3 class="font-bold text-base text-stone-900 dark:text-stone-100">رمزینه هوشمند (QR Code) نسخه</h3>
                </div>
                <button @click="qrModalOpen = false" class="text-stone-400 hover:text-stone-600 dark:hover:text-stone-200 text-lg leading-none cursor-pointer">✕</button>
            </div>

            <!-- QR Canvas Container -->
            <div class="flex flex-col items-center justify-center p-4 rounded-2xl bg-white border border-[#EADFCF] shadow-inner">
                <canvas id="manuscript-qr-canvas" class="max-w-full h-auto"></canvas>
            </div>

            <!-- Details -->
            <div class="space-y-1.5 text-right bg-stone-50 dark:bg-stone-800/50 p-3 rounded-2xl border border-stone-200/70 dark:border-stone-700/70 text-xs">
                <div class="flex justify-between items-center">
                    <span class="text-stone-400">شماره نسخه:</span>
                    <span class="font-bold text-[#B38A50]">{{ $manuscript->shelfmark ?? 'بی‌شماره' }}</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-stone-400">کتابخانه:</span>
                    <span class="font-bold text-stone-800 dark:text-stone-200 truncate max-w-[200px]">{{ $manuscript->libraryRecord?->name ?? $manuscript->library }}</span>
                </div>
                <div class="flex justify-between items-center pt-1.5 border-t border-stone-200/60 dark:border-stone-700/60">
                    <span class="text-stone-400">پیوند پایدار:</span>
                    <span class="font-mono text-[11px] font-bold text-[#292C56] dark:text-amber-200" dir="ltr">{{ url('/m/' . $manuscript->id) }}</span>
                </div>
            </div>

            <p class="text-[11px] text-stone-500 dark:text-stone-400 leading-relaxed text-justify">
                این بارکد را با دوربین تلفن همراه خود اسکن کنید تا در مراجعات حضوری به مخازن، اطلاعات و شناسنامه این نسخه خطی مستقیماً روی گوشی همراه شما نمایش یابد.
            </p>

            <!-- Modal Actions -->
            <div class="pt-1 flex items-center justify-center gap-2">
                <button 
                    type="button" 
                    @click="downloadQr()"
                    class="px-4 py-2 rounded-xl bg-[#B38A50] hover:bg-[#9C753F] text-white text-xs font-bold transition flex items-center gap-1.5 shadow-sm cursor-pointer">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                    </svg>
                    <span>ذخیره تصویر QR</span>
                </button>
                <button 
                    type="button" 
                    @click="copyText('{{ url('/m/' . $manuscript->id) }}', 'qr-modal');"
                    class="px-4 py-2 rounded-xl border border-stone-300 dark:border-stone-700 hover:border-[#B38A50] text-stone-700 dark:text-stone-300 text-xs font-semibold transition cursor-pointer">
                    <span x-show="copiedKey !== 'qr-modal'">کپی آدرس</span>
                    <span x-show="copiedKey === 'qr-modal'" class="text-emerald-600 font-bold" style="display: none;">کپی شد! ✓</span>
                </button>
            </div>

        </div>
    </div>

    <!-- ACADEMIC CITATION MODAL DIALOG (Alpine.js) -->
    <div 
        x-show="citationModalOpen" 
        x-cloak
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
        style="display: none;">
        
        <div 
            @click.away="citationModalOpen = false"
            class="bg-white dark:bg-[#15192C] w-full max-w-2xl rounded-3xl p-6 sm:p-8 border border-[#EADFCF] dark:border-[#272F4C] shadow-2xl space-y-6 text-right max-h-[90vh] overflow-y-auto">
            
            <div class="flex items-center justify-between pb-3 border-b border-stone-100 dark:border-stone-800">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-5 bg-[#B38A50] rounded-sm"></span>
                    <h3 class="font-bold text-lg text-stone-900 dark:text-stone-100">دریافت استناد علمی و ارجاع استاندارد</h3>
                </div>
                <button @click="citationModalOpen = false" class="text-stone-400 hover:text-stone-600 dark:hover:text-stone-200 text-lg leading-none cursor-pointer">✕</button>
            </div>

            <p class="text-xs text-stone-500 dark:text-stone-400 leading-relaxed">
                پژوهشگر گرامی؛ جهت استناد به این نسخه خطی در مقالات علمی، پایان‌نامه‌ها و طرح‌های کتاب‌شناختی، می‌توانید از قالب‌های آماده زیر استفاده فرمایید:
            </p>

            @php
                $fankhaCitation = "درایتی، مصطفی. فهرستگان نسخه‌های خطی ایران (فنخا). ج " . ($manuscript->volume_number ?? '-') . "، ص " . ($manuscript->page_start ?? '-') . "، ردیف " . ($manuscript->sequence_number ?? '-') . ".";
                
                $workTitle = $manuscript->work?->primary_title ?? 'نسخه خطی';
                $authorName = $manuscript->work?->author?->name ?? 'ناشناخته';
                $libName = $manuscript->libraryRecord?->name ?? $manuscript->library ?? 'نامشخص';
                $shelfmark = $manuscript->shelfmark ?? 'بی‌شماره';
                $shortUrl = url('/m/' . $manuscript->id);

                $persianArticleCitation = "{$workTitle} (مؤلف: {$authorName})، نسخه خطی {$libName}، شماره بازیابی: {$shelfmark}. پایگاه فهارس: {$shortUrl}";

                $authorEn = $manuscript->work?->author?->name ?? 'Unknown';
                $cityEn = $manuscript->city ?? 'Iran';
                $chicagoCitation = "{$authorEn}. \"{$workTitle}.\" Manuscript {$shelfmark}, {$libName}, {$cityEn}. Fahares: {$shortUrl}";
            @endphp

            <div class="space-y-4">
                
                <!-- Format 1: FanKha Source -->
                <div class="p-4 rounded-2xl bg-[#FEF9F3] dark:bg-[#1A1E35] border border-[#EADFCF] dark:border-[#272F4C] space-y-2">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-0.5 rounded-md bg-amber-100 dark:bg-amber-950/60 text-[#B38A50] text-[11px] font-bold">
                                قالب ۱
                            </span>
                            <span class="text-xs font-bold text-stone-800 dark:text-stone-200">قالب مأخذ فنخا (فهرستگان نسخه‌های خطی ایران)</span>
                        </div>
                        <button 
                            type="button" 
                            @click="copyText(@js($fankhaCitation), 'fankha')"
                            class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl bg-white dark:bg-[#15192C] border border-[#EADFCF] dark:border-[#272F4C] text-[11px] font-semibold text-stone-700 dark:text-stone-300 hover:text-[#B38A50] hover:border-[#B38A50] transition shadow-2xs cursor-pointer">
                            <span x-show="copiedKey !== 'fankha'">کپی ارجاع</span>
                            <span x-show="copiedKey === 'fankha'" class="text-emerald-600 font-bold" style="display: none;">کپی شد! ✓</span>
                        </button>
                    </div>
                    <div class="p-3 rounded-xl bg-white dark:bg-[#15192C] border border-stone-200/80 dark:border-stone-800 text-xs text-stone-800 dark:text-stone-200 leading-relaxed font-normal select-all">
                        {{ $fankhaCitation }}
                    </div>
                </div>

                <!-- Format 2: Persian Articles / Codicological Reference -->
                <div class="p-4 rounded-2xl bg-stone-50 dark:bg-[#1A1E35] border border-stone-200 dark:border-[#272F4C] space-y-2">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-0.5 rounded-md bg-stone-200 dark:bg-stone-800 text-stone-700 dark:text-stone-300 text-[11px] font-bold">
                                قالب ۲
                            </span>
                            <span class="text-xs font-bold text-stone-800 dark:text-stone-200">قالب ارجاع نسخه‌شناسی در مقالات و رسائل</span>
                        </div>
                        <button 
                            type="button" 
                            @click="copyText(@js($persianArticleCitation), 'article')"
                            class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl bg-white dark:bg-[#15192C] border border-stone-200 dark:border-stone-700 text-[11px] font-semibold text-stone-700 dark:text-stone-300 hover:text-[#B38A50] hover:border-[#B38A50] transition shadow-2xs cursor-pointer">
                            <span x-show="copiedKey !== 'article'">کپی ارجاع</span>
                            <span x-show="copiedKey === 'article'" class="text-emerald-600 font-bold" style="display: none;">کپی شد! ✓</span>
                        </button>
                    </div>
                    <div class="p-3 rounded-xl bg-white dark:bg-[#15192C] border border-stone-200/80 dark:border-stone-800 text-xs text-stone-800 dark:text-stone-200 leading-relaxed font-normal select-all">
                        {{ $persianArticleCitation }}
                    </div>
                </div>

                <!-- Format 3: Chicago Style -->
                <div class="p-4 rounded-2xl bg-indigo-50/40 dark:bg-[#1A1E35] border border-indigo-100 dark:border-[#272F4C] space-y-2">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-0.5 rounded-md bg-indigo-100 dark:bg-indigo-950/60 text-[#292C56] dark:text-indigo-300 text-[11px] font-bold">
                                قالب ۳
                            </span>
                            <span class="text-xs font-bold text-stone-800 dark:text-stone-200">قالب استاندارد شیکاگو (Chicago Style)</span>
                        </div>
                        <button 
                            type="button" 
                            @click="copyText(@js($chicagoCitation), 'chicago')"
                            class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl bg-white dark:bg-[#15192C] border border-stone-200 dark:border-stone-700 text-[11px] font-semibold text-stone-700 dark:text-stone-300 hover:text-[#B38A50] hover:border-[#B38A50] transition shadow-2xs cursor-pointer">
                            <span x-show="copiedKey !== 'chicago'">کپی ارجاع</span>
                            <span x-show="copiedKey === 'chicago'" class="text-emerald-600 font-bold" style="display: none;">کپی شد! ✓</span>
                        </button>
                    </div>
                    <div class="p-3 rounded-xl bg-white dark:bg-[#15192C] border border-stone-200/80 dark:border-stone-800 text-xs text-stone-800 dark:text-stone-200 leading-relaxed font-mono select-all text-left" dir="ltr">
                        {{ $chicagoCitation }}
                    </div>
                </div>

            </div>

            <div class="pt-2 flex justify-end">
                <button type="button" @click="citationModalOpen = false" class="px-5 py-2 rounded-xl bg-stone-100 dark:bg-stone-800 hover:bg-stone-200 dark:hover:bg-stone-700 text-stone-700 dark:text-stone-300 text-xs font-bold transition cursor-pointer">
                    بستن
                </button>
            </div>

        </div>
    </div>

</div>
@endsection

@push('structured_data')
<script type="application/ld+json">
{
  "@@context": "https://schema.org",
  "@type": "Manuscript",
  "name": "نسخه خطی {{ addcslashes($manuscript->work?->primary_title ?? 'بدون عنوان', '"\\') }}",
  "identifier": "{{ addcslashes($manuscript->shelfmark ?? '', '"\\') }}",
  "holdingArchive": {
    "@type": "Library",
    "name": "{{ addcslashes($manuscript->libraryRecord?->name ?? $manuscript->library ?? '', '"\\') }}"
  },
  @if($manuscript->scribe_name)"creator": { "@type": "Person", "name": "{{ addcslashes($manuscript->scribe_name, '"\\') }}" },@endif
  "url": "{{ route('manuscripts.show', $manuscript) }}"
}
</script>
@endpush


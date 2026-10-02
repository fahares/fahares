@extends('layouts.app')

@section('title', $work->primary_title . ' | شناسنامه اثر در فهارس')
@section('meta_description', 'مشخصات کتاب‌شناختی و نسخه‌های خطی ' . $work->primary_title . ' در فهارس نسخه‌های خطی')
@section('og_type', 'book')

@section('content')
<div x-data="{ 
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
            const canvas = document.getElementById('work-qr-canvas');
            if (canvas && window.QRCode) {
                window.QRCode.toCanvas(canvas, '{{ url('/w/' . $work->id) }}', {
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
        const canvas = document.getElementById('work-qr-canvas');
        if (canvas) {
            const link = document.createElement('a');
            link.download = 'qrcode-work-{{ $work->id }}.png';
            link.href = canvas.toDataURL('image/png');
            link.click();
        }
    }
}" class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">

    <!-- Breadcrumb -->
    <nav class="flex items-center gap-2 text-xs text-stone-500">
        <a href="{{ route('home') }}" class="hover:text-[#B38A50]">فهارس</a>
        <span>/</span>
        <a href="{{ route('search', ['type' => 'works']) }}" class="hover:text-[#B38A50]">آثار</a>
        <span>/</span>
        <span class="text-stone-800 dark:text-stone-200 font-semibold truncate">{{ $work->primary_title }}</span>
    </nav>

    <!-- WORK HEADER CARD -->
    <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 sm:p-8 border border-[#EADFCF] dark:border-[#272F4C] shadow-md space-y-6 relative overflow-hidden">
        
        <!-- Subtle decorative glow -->
        <div class="absolute -top-12 -left-12 w-48 h-48 bg-[#B38A50]/10 rounded-full blur-2xl pointer-events-none"></div>

        <div class="flex flex-col md:flex-row md:items-start justify-between gap-6">
            <div class="space-y-3 flex-1">
                <div class="inline-block px-3 py-1 rounded-full bg-amber-50 dark:bg-amber-950/40 text-[#B38A50] text-xs font-bold">
                    شناسنامه کتاب‌شناختی اثر
                </div>

                <h1 class="text-2xl sm:text-3xl font-black text-[#292C56] dark:text-amber-100 tracking-tight">
                    {{ $work->primary_title }}
                </h1>

                @if($work->clean_title && $work->clean_title !== $work->primary_title)
                    <div class="text-sm text-stone-500 font-medium">
                        عنوان پیراسته: <span class="text-stone-700 dark:text-stone-300">{{ $work->clean_title }}</span>
                    </div>
                @endif

                @if($work->transliteration)
                    <div class="text-xs text-stone-400">
                        {{ $work->transliteration }}
                    </div>
                @endif
            </div>

            <!-- Manuscript Count Badge -->
            <div class="flex flex-col items-start md:items-end gap-2">
                <div class="px-5 py-3 rounded-2xl bg-gradient-to-br from-amber-50 to-[#FEF9F3] dark:from-stone-800 dark:to-[#15192C] border border-[#B38A50]/40 text-center shadow-sm">
                    <span class="block text-2xl sm:text-3xl font-black text-[#B38A50]">
                        {{ number_format($work->manuscripts_count) }}
                    </span>
                    <span class="text-xs font-semibold text-stone-600 dark:text-stone-400">نسخه ثبت‌شده</span>
                    @if(!empty($work->copy_century_text))
                        <span class="block text-[11px] text-stone-500 dark:text-stone-400 font-medium mt-1">
                            {{ $work->copy_century_text }}
                        </span>
                    @endif
                </div>

                @if($work->has_autograph)
                    <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-emerald-50 dark:bg-emerald-950/60 border border-emerald-300 dark:border-emerald-800 text-emerald-800 dark:text-emerald-300 text-xs font-bold shadow-xs">
                        <svg class="w-3.5 h-3.5 text-emerald-600 dark:text-emerald-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                        </svg>
                        <span>دارای نسخه اصل (دستخط مؤلف)</span>
                    </div>
                @endif

                <a href="#manuscripts" class="text-xs text-[#B38A50] hover:underline font-semibold flex items-center gap-1">
                    <span>مشاهده فهرست نسخه‌ها</span>
                    <span>↓</span>
                </a>
            </div>
        </div>

        <!-- Work Quick Actions: Permalink & Academic Citation -->
        <div class="flex flex-wrap items-center justify-between gap-3 pt-4 border-t border-stone-100 dark:border-stone-800">
            <div class="flex flex-wrap items-center gap-2.5">
                
                <!-- Permalink Capsule & QR Button -->
                <div class="inline-flex items-center gap-1.5 p-1 pr-2.5 sm:pr-3 rounded-2xl bg-[#FEF9F3] dark:bg-[#1A1E35] border border-[#EADFCF] dark:border-[#272F4C] shadow-2xs text-xs">
                    <span class="text-stone-400 dark:text-stone-400 font-medium flex items-center gap-1 select-none">
                        <svg class="w-3.5 h-3.5 text-[#B38A50]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                        </svg>
                        <span class="hidden sm:inline">پیوند کوتاه:</span>
                    </span>
                    <a href="{{ url('/w/' . $work->id) }}" class="font-mono text-[11px] font-bold text-[#292C56] dark:text-amber-200 px-2 py-0.5 rounded-lg bg-white/80 dark:bg-[#15192C]/80 border border-stone-200/60 dark:border-stone-700/60 hover:text-[#B38A50] transition" dir="ltr" title="پیوند پایدار اثر">
                        /w/{{ $work->id }}
                    </a>
                    
                    <!-- Copy button with tooltip -->
                    <button 
                        type="button" 
                        @click="copyText('{{ url('/w/' . $work->id) }}', 'permalink')"
                        class="relative p-1.5 rounded-xl bg-white dark:bg-[#15192C] hover:bg-[#B38A50] hover:text-white dark:hover:bg-[#B38A50] text-stone-600 dark:text-stone-300 border border-stone-200 dark:border-stone-700 hover:border-[#B38A50] transition shadow-2xs cursor-pointer"
                        title="رونوشت پیوند کوتاه اثر">
                        
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
                        title="نمایش QR Code اثر جهت اشتراک‌گذاری یا اسکن">
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
            </div>

            <div class="text-xs text-stone-400 font-medium">
                شناسه یکتا در پایگاه فهارس: <span class="font-mono text-stone-600 dark:text-stone-300 font-bold">#{{ $work->id }}</span>
            </div>
        </div>

        <!-- Metadata Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 pt-6 border-t border-stone-100 dark:border-stone-800 text-xs">
            
            <!-- Author -->
            <div class="space-y-1">
                <span class="text-stone-400 block font-medium">پدیدآور / مؤلف:</span>
                @if($work->author)
                    <a href="{{ route('people.show', $work->author) }}" class="text-sm font-bold text-[#292C56] dark:text-amber-200 hover:text-[#B38A50] transition">
                        {{ $work->author->name }}
                    </a>
                @elseif($work->author_name)
                    <span class="text-sm font-bold text-stone-700 dark:text-stone-300">{{ $work->author_name }}</span>
                @else
                    <span class="text-sm text-stone-400 italic">ناشناخته</span>
                @endif
            </div>

            <!-- Composition Year -->
            <div class="space-y-1">
                <span class="text-stone-400 block font-medium">تاریخ و سده تألیف:</span>
                @if($work->composition_year_hijri)
                    <span class="text-sm font-bold text-stone-700 dark:text-stone-300">{{ $work->composition_year_hijri }} هـ.ق</span>
                @elseif($work->composition_date_raw)
                    <span class="text-sm font-bold text-stone-700 dark:text-stone-300">{{ $work->composition_date_raw }}</span>
                @else
                    <span class="text-sm text-stone-400 italic">نامشخص</span>
                @endif
            </div>

            <!-- Source in Catalog -->
            <div class="space-y-1">
                <span class="text-stone-400 block font-medium">مأخذ فهرست‌نویسی:</span>
                <div class="flex items-center gap-2">
                    <span class="text-sm font-bold text-stone-700 dark:text-stone-300">
                        {{ $work->catalog?->short_name ?? 'فنخا' }}، ج {{ $work->volume_number }}، ص {{ $work->page_start }}
                        @if($work->page_end && $work->page_end > $work->page_start)
                            تا {{ $work->page_end }}
                        @endif
                    </span>
                    @if($work->volume_number && $work->page_start)
                        <button 
                            type="button"
                            @click="$dispatch('open-catalog-viewer', { catalog: '{{ $work->catalog?->code ?? 'fankha' }}', catalogName: '{{ $work->catalog?->short_name ?? 'فنخا' }}', volume: {{ $work->volume_number }}, page: {{ $work->page_start }} })"
                            class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-lg bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800/60 text-[11px] font-bold text-[#B38A50] hover:bg-amber-100 dark:hover:bg-amber-900/50 transition cursor-pointer"
                            title="مشاهده برگه در مأخذ چاپی {{ $work->catalog?->short_name ?? 'فنخا' }}">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                            </svg>
                            <span>مشاهده برگه</span>
                        </button>
                    @endif
                </div>
            </div>

            <!-- Language & Form -->
            <div class="space-y-1">
                <span class="text-stone-400 block font-medium">زبان و قالب:</span>
                <span class="text-sm font-bold text-stone-700 dark:text-stone-300">
                    {{ $work->language_summary ?? 'عربی / فارسی' }}
                    @if($work->work_form)
                        <span class="text-xs text-stone-400 font-normal">({{ $work->work_form }})</span>
                    @endif
                </span>
            </div>

        </div>

        <!-- Subjects -->
        @if($work->subjects && $work->subjects->count() > 0)
            <div class="pt-4 border-t border-stone-100 dark:border-stone-800 flex flex-wrap items-center gap-2 text-xs">
                <span class="text-stone-400 font-medium">رده‌های موضوعی:</span>
                @foreach($work->subjects as $subj)
                    <a href="{{ route('subjects.show', $subj) }}" 
                       class="px-3 py-1 rounded-full bg-stone-100 dark:bg-stone-800 hover:bg-amber-100 dark:hover:bg-amber-950/40 text-stone-700 dark:text-stone-300 hover:text-[#B38A50] transition">
                        {{ $subj->title }}
                    </a>
                @endforeach
            </div>
        @endif

        <!-- Alternative Titles & Referrals -->
        @if(!empty($work->alternative_titles))
            <div class="pt-4 border-t border-stone-100 dark:border-stone-800 text-xs space-y-1">
                <span class="text-stone-400 font-medium">عناوین دیگر و هم‌تراز:</span>
                <div class="flex flex-wrap gap-2 pt-1">
                    @foreach($work->alternative_titles as $alt)
                        @if(is_array($alt) && !empty($alt['title']))
                            <span class="px-2.5 py-1 rounded-lg bg-stone-50 dark:bg-stone-800/80 border border-stone-200 dark:border-stone-700 text-stone-700 dark:text-stone-300">
                                {{ $alt['title'] }}
                                @if(!empty($alt['transliteration']))
                                    <span class="text-[10px] text-stone-400">({{ $alt['transliteration'] }})</span>
                                @endif
                            </span>
                        @endif
                    @endforeach
                </div>
            </div>
        @endif

        <!-- Dedication, Place, Related Work -->
        @if(!empty($work->dedication) || !empty($work->composition_place) || !empty($work->related_work))
            <div class="pt-4 border-t border-stone-100 dark:border-stone-800 grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs">
                @if(!empty($work->dedication))
                    <div class="space-y-1">
                        <span class="text-stone-400 block font-medium">اهدا / به درخواست:</span>
                        <span class="font-bold text-stone-700 dark:text-stone-300">{{ $work->dedication }}</span>
                    </div>
                @endif
                @if(!empty($work->composition_place))
                    <div class="space-y-1">
                        <span class="text-stone-400 block font-medium">محل تألیف:</span>
                        <span class="font-bold text-stone-700 dark:text-stone-300">{{ $work->composition_place }}</span>
                    </div>
                @endif
                @if(!empty($work->related_work))
                    <div class="space-y-1">
                        <span class="text-stone-400 block font-medium">اثر مرتبط / وابسته به:</span>
                        <span class="font-bold text-stone-700 dark:text-stone-300">{{ $work->related_work }}</span>
                    </div>
                @endif
            </div>
        @endif

    </div>

    <!-- WORK DESCRIPTION & INCIPIT/EXPLICIT -->
    @if(!empty($work->description) || !empty($work->incipit_text) || !empty($work->explicit_text))
        <div class="space-y-4">
            @if(!empty($work->description))
                <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 sm:p-7 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-3">
                    <div class="flex items-center gap-2 pb-2 border-b border-stone-100 dark:border-stone-800">
                        <span class="w-2 h-4 bg-[#B38A50] rounded-sm"></span>
                        <h3 class="text-xs font-bold text-[#B38A50] uppercase tracking-wider">
                            معرفی و مشخصات کتاب‌شناختی اثر (در مأخذ فنخا)
                        </h3>
                    </div>
                    <div class="text-stone-700 dark:text-stone-200 text-xs sm:text-sm leading-loose text-justify font-normal">
                        {{ $work->description }}
                    </div>
                </div>
            @endif

            @if(!empty($work->incipit_text) || !empty($work->explicit_text))
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @if(!empty($work->incipit_text))
                        <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-2">
                            <div class="flex items-center gap-2 pb-1 border-b border-stone-100 dark:border-stone-800">
                                <span class="w-2 h-3.5 bg-[#B38A50] rounded-sm"></span>
                                <span class="text-xs font-bold text-[#B38A50]">آغاز اثر</span>
                            </div>
                            <div class="p-3.5 rounded-2xl bg-amber-50/40 dark:bg-amber-950/20 border-r-4 border-[#B38A50] text-xs leading-relaxed text-stone-800 dark:text-stone-100 font-medium">
                                « {{ trim($work->incipit_text, "«» \t\n\r\0\x0B") }} »
                            </div>
                        </div>
                    @endif

                    @if(!empty($work->explicit_text))
                        <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-2">
                            <div class="flex items-center gap-2 pb-1 border-b border-stone-100 dark:border-stone-800">
                                <span class="w-2 h-3.5 bg-[#292C56] dark:bg-indigo-400 rounded-sm"></span>
                                <span class="text-xs font-bold text-[#292C56] dark:text-indigo-400">انجام اثر</span>
                            </div>
                            <div class="p-3.5 rounded-2xl bg-indigo-50/40 dark:bg-indigo-950/20 border-r-4 border-[#292C56] dark:border-indigo-400 text-xs leading-relaxed text-stone-800 dark:text-stone-100 font-medium">
                                « {{ trim($work->explicit_text, "«» \t\n\r\0\x0B") }} »
                            </div>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    @endif

    <!-- PRINT INFO, COMMENTARIES, BIBLIOGRAPHY & RAW TEXT -->
    <div class="space-y-4">
        @if(!empty($work->print_info))
            <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-2">
                <div class="flex items-center gap-2 pb-1 border-b border-stone-100 dark:border-stone-800">
                    <span class="w-2 h-3.5 bg-amber-600 rounded-sm"></span>
                    <span class="text-xs font-bold text-amber-800 dark:text-amber-300">سوابق و چاپ‌های اثر</span>
                </div>
                <div class="text-xs leading-loose text-stone-700 dark:text-stone-300 font-medium">
                    {{ $work->print_info }}
                </div>
            </div>
        @endif

        @if(!empty($work->commentaries_and_glosses) && count($work->commentaries_and_glosses) > 0)
            <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-3">
                <div class="flex items-center gap-2 pb-1 border-b border-stone-100 dark:border-stone-800">
                    <span class="w-2 h-3.5 bg-[#292C56] dark:bg-indigo-400 rounded-sm"></span>
                    <span class="text-xs font-bold text-[#292C56] dark:text-indigo-400">شروح، حواشی و منظومه‌ها (در فنخا)</span>
                </div>
                <div class="flex flex-wrap gap-2 pt-1 text-xs">
                    @foreach($work->commentaries_and_glosses as $comm)
                        <span class="px-3 py-1.5 rounded-xl bg-stone-50 dark:bg-stone-800/80 border border-stone-200 dark:border-stone-700 text-stone-700 dark:text-stone-300 font-medium">
                            {{ $comm }}
                        </span>
                    @endforeach
                </div>
            </div>
        @endif

        @if(!empty($work->bibliography) && count($work->bibliography) > 0)
            <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-3">
                <div class="flex items-center gap-2 pb-1 border-b border-stone-100 dark:border-stone-800">
                    <span class="w-2 h-3.5 bg-[#B38A50] rounded-sm"></span>
                    <span class="text-xs font-bold text-[#B38A50]">مآخذ و منابع کتاب‌شناسی اثر</span>
                </div>
                <div class="flex flex-wrap gap-2 pt-1 text-xs">
                    @foreach($work->bibliography as $bib)
                        <span class="px-3 py-1.5 rounded-xl bg-amber-50/50 dark:bg-amber-950/20 border border-amber-200/60 dark:border-amber-900/40 text-stone-800 dark:text-stone-200 font-medium">
                            {{ $bib }}
                        </span>
                    @endforeach
                </div>
            </div>
        @endif

        @if($work->clean_raw_text)
            <div x-data="{ copied: false }" class="bg-white dark:bg-[#15192C] rounded-3xl p-5 sm:p-7 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-4">
                
                <!-- Header Toolbar: Title on right, Actions on left -->
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3.5 border-b border-stone-100 dark:border-stone-800/80">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-4 bg-[#B38A50] rounded-sm"></span>
                        <h3 class="text-xs sm:text-sm font-bold text-stone-900 dark:text-stone-100 tracking-wide">
                            متن {{ $work->catalog?->short_name ?? 'فنخا' }} ({{ $work->catalog?->name ?? 'فهرستگان نسخه‌های خطی ایران' }})
                        </h3>
                    </div>

                    <div class="flex items-center gap-2 self-start sm:self-center">
                        @if($work->volume_number && $work->page_start)
                            <button 
                                type="button" 
                                @click="$dispatch('open-catalog-viewer', { catalog: '{{ $work->catalog?->code ?? 'fankha' }}', catalogName: '{{ $work->catalog?->short_name ?? 'فنخا' }}', volume: {{ $work->volume_number }}, page: {{ $work->page_start }} })"
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
                            @click="navigator.clipboard.writeText($refs.workRawText.innerText.trim()); copied = true; setTimeout(() => copied = false, 2500)"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-stone-50 dark:bg-stone-800/80 border border-stone-200 dark:border-stone-700 text-xs font-semibold text-stone-600 dark:text-stone-300 hover:text-[#B38A50] hover:border-[#B38A50] active:scale-95 transition shadow-2xs whitespace-nowrap cursor-pointer">
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
                
                <!-- Raw text body -->
                <div 
                    x-ref="workRawText" 
                    class="p-4 sm:p-6 rounded-2xl bg-[#FEF9F3] dark:bg-[#0C0F1D] border border-[#EADFCF] dark:border-[#272F4C] text-xs sm:text-[13px] text-stone-800 dark:text-stone-100 leading-loose sm:leading-loose whitespace-pre-line text-right selection:bg-amber-100 dark:selection:bg-amber-950 font-normal">
                    {{ $work->clean_raw_text }}
                </div>
            </div>
        @endif
    </div>

    <!-- MANUSCRIPTS TABLE SECTION -->
    <section id="manuscripts" class="space-y-4">
        
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <span class="w-2.5 h-6 bg-[#B38A50] rounded-sm"></span>
                <h2 class="text-xl font-bold text-stone-900 dark:text-stone-100">
                    نسخه‌های خطی این اثر
                </h2>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-amber-50 dark:bg-amber-950/40 text-[#B38A50]">
                    {{ number_format($work->manuscripts_count) }} نسخه
                </span>
            </div>

            @if(isset($sort) && $sort !== 'sequence')
                <div class="flex items-center gap-2 text-xs">
                    <span class="text-stone-400">مرتب‌شده بر اساس:</span>
                    <span class="font-bold text-[#B38A50]">
                        @switch($sort)
                            @case('library') کتابخانه @break
                            @case('shelfmark') شماره نسخه @break
                            @case('scribe') کاتب @break
                            @case('date') تاریخ کتابت @break
                            @case('script') نوع خط @break
                            @case('folios') تعداد برگ @break
                            @default {{ $sort }}
                        @endswitch
                        ({{ $direction === 'asc' ? 'صعودی' : 'نزولی' }})
                    </span>
                    <a href="{{ route('works.show', $work) }}#manuscripts" 
                       class="text-stone-400 hover:text-red-500 transition mr-2 underline">
                        بازنشانی ترتیب
                    </a>
                </div>
            @endif
        </div>

        <div class="bg-white dark:bg-[#15192C] rounded-3xl border border-[#EADFCF] dark:border-[#272F4C] shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-right text-xs">
                    <thead class="bg-stone-50 dark:bg-stone-800/60 text-stone-600 dark:text-stone-300 font-bold border-b border-stone-200 dark:border-stone-700 select-none">
                        @php
                            $renderSortHeader = function($col, $title, $align = 'right') use ($work, $sort, $direction) {
                                $isActive = ($sort === $col);
                                $nextDir = ($isActive && $direction === 'asc') ? 'desc' : 'asc';
                                $url = route('works.show', array_merge(request()->except(['page']), ['id' => $work->getRouteKey(), 'sort' => $col, 'direction' => $nextDir, 'page' => 1])) . '#manuscripts';
                                
                                $justify = $align === 'center' ? 'justify-center' : 'justify-start';
                                
                                $html = '<a href="' . e($url) . '" class="inline-flex items-center gap-1.5 hover:text-[#B38A50] transition group ' . ($isActive ? 'text-[#B38A50] font-black' : 'text-stone-600 dark:text-stone-300') . '" title="مرتب‌سازی بر اساس ' . e($title) . '">';
                                $html .= '<span>' . e($title) . '</span>';
                                
                                if ($isActive) {
                                    if ($direction === 'asc') {
                                        $html .= '<svg class="w-3.5 h-3.5 text-[#B38A50] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 15l7-7 7 7"/></svg>';
                                    } else {
                                        $html .= '<svg class="w-3.5 h-3.5 text-[#B38A50] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/></svg>';
                                    }
                                } else {
                                    $html .= '<svg class="w-3 h-3 text-stone-300 dark:text-stone-600 opacity-60 group-hover:opacity-100 group-hover:text-[#B38A50] shrink-0 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"/></svg>';
                                }
                                
                                $html .= '</a>';
                                return $html;
                            };
                        @endphp
                        <tr>
                            <th class="py-3.5 px-4 w-16 text-center">{!! $renderSortHeader('sequence', 'ردیف', 'center') !!}</th>
                            <th class="py-3.5 px-4">{!! $renderSortHeader('library', 'کتابخانه و مرکز نگهداری') !!}</th>
                            <th class="py-3.5 px-4">{!! $renderSortHeader('shelfmark', 'شماره نسخه') !!}</th>
                            <th class="py-3.5 px-4">{!! $renderSortHeader('scribe', 'کاتب') !!}</th>
                            <th class="py-3.5 px-4">{!! $renderSortHeader('date', 'تاریخ کتابت') !!}</th>
                            <th class="py-3.5 px-4">{!! $renderSortHeader('script', 'نوع خط') !!}</th>
                            <th class="py-3.5 px-4">{!! $renderSortHeader('folios', 'برگ') !!}</th>
                            <th class="py-3.5 px-4 text-center">مشاهده</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 dark:divide-stone-800/60 text-stone-700 dark:text-stone-300">
                        @forelse($manuscripts as $index => $ms)
                            <tr class="hover:bg-amber-50/40 dark:hover:bg-stone-800/40 transition">
                                <td class="py-3 px-4 text-center text-stone-400">
                                    {{ $manuscripts->firstItem() + $index }}
                                </td>
                                
                                <td class="py-3 px-4">
                                    <div class="font-bold text-stone-900 dark:text-stone-100">
                                        {{ $ms->library?->name ?? $ms->library ?? 'نامشخص' }}
                                    </div>
                                    @if($ms->city)
                                        <span class="text-[11px] text-stone-400">{{ $ms->city }}</span>
                                    @endif
                                </td>

                                <td class="py-3 px-4 font-bold text-[#B38A50]">
                                    {{ $ms->shelfmark ?? 'بی‌شماره' }}
                                </td>

                                <td class="py-3 px-4">
                                    @if($ms->scribe_name)
                                        <span class="font-medium">{{ $ms->scribe_name }}</span>
                                        @if($ms->is_autograph)
                                            <span class="text-xs font-semibold text-emerald-700 dark:text-emerald-400 mr-1">(مؤلف)</span>
                                        @endif
                                    @elseif($ms->is_autograph)
                                        @if($work->author)
                                            <a href="{{ route('people.show', $work->author) }}" class="font-medium text-emerald-700 dark:text-emerald-400 hover:underline">
                                                {{ $work->author->name }} <span class="text-xs font-semibold opacity-90 mr-0.5">(مؤلف)</span>
                                            </a>
                                        @elseif($work->author_name)
                                            <span class="font-medium text-emerald-700 dark:text-emerald-400">
                                                {{ $work->author_name }} <span class="text-xs font-semibold opacity-90 mr-0.5">(مؤلف)</span>
                                            </span>
                                        @else
                                            <span class="font-medium text-emerald-700 dark:text-emerald-400">مؤلف</span>
                                        @endif
                                    @elseif($ms->is_bika)
                                        <span class="text-stone-400 italic">بی‌کاتب</span>
                                    @else
                                        <span class="text-stone-400">نامشخص</span>
                                    @endif
                                </td>

                                <td class="py-3 px-4">
                                    @if($ms->copy_date_raw)
                                        <span>{{ $ms->copy_date_raw }}</span>
                                    @elseif($ms->is_bita)
                                        <span class="text-stone-400 italic">بی‌تاریخ</span>
                                    @else
                                        <span class="text-stone-400">-</span>
                                    @endif
                                </td>

                                <td class="py-3 px-4">
                                    {{ $ms->script_names ?? '-' }}
                                </td>

                                <td class="py-3 px-4">
                                    {{ $ms->folios ? $ms->folios : '-' }}
                                </td>

                                <td class="py-3 px-4 text-center">
                                    <a href="{{ route('manuscripts.show', $ms) }}" 
                                       class="px-3 py-1 bg-stone-100 dark:bg-stone-800 hover:bg-[#B38A50] hover:text-white rounded-lg text-xs font-semibold transition inline-block">
                                        شناسنامه ←
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="py-8 text-center text-stone-400">
                                    هیچ نسخه‌ای برای این اثر ثبت نشده است.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Table Pagination -->
            @if($manuscripts->hasPages())
                <div class="p-4 border-t border-stone-100 dark:border-stone-800 bg-stone-50/50 dark:bg-stone-800/30">
                    {{ $manuscripts->fragment('manuscripts')->links() }}
                </div>
            @endif
        </div>

    </section>

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
                    <h3 class="font-bold text-base text-stone-900 dark:text-stone-100">رمزینه هوشمند (QR Code) اثر</h3>
                </div>
                <button @click="qrModalOpen = false" class="text-stone-400 hover:text-stone-600 dark:hover:text-stone-200 text-lg leading-none cursor-pointer">✕</button>
            </div>

            <!-- QR Canvas Container -->
            <div class="flex flex-col items-center justify-center p-4 rounded-2xl bg-white border border-[#EADFCF] shadow-inner">
                <canvas id="work-qr-canvas" class="max-w-full h-auto"></canvas>
            </div>

            <!-- Details -->
            <div class="space-y-1.5 text-right bg-stone-50 dark:bg-stone-800/50 p-3 rounded-2xl border border-stone-200/70 dark:border-stone-700/70 text-xs">
                <div class="flex justify-between items-center">
                    <span class="text-stone-400">عنوان اثر:</span>
                    <span class="font-bold text-[#B38A50] truncate max-w-[200px]">{{ $work->primary_title }}</span>
                </div>
                @if($work->author || $work->author_name)
                    <div class="flex justify-between items-center">
                        <span class="text-stone-400">مؤلف:</span>
                        <span class="font-bold text-stone-800 dark:text-stone-200 truncate max-w-[200px]">{{ $work->author?->name ?? $work->author_name }}</span>
                    </div>
                @endif
                <div class="flex justify-between items-center pt-1.5 border-t border-stone-200/60 dark:border-stone-700/60">
                    <span class="text-stone-400">پیوند پایدار:</span>
                    <span class="font-mono text-[11px] font-bold text-[#292C56] dark:text-amber-200" dir="ltr">{{ url('/w/' . $work->id) }}</span>
                </div>
            </div>

            <p class="text-[11px] text-stone-500 dark:text-stone-400 leading-relaxed text-justify">
                این بارکد را اسکن کنید تا صفحه شناسنامه کتاب‌شناختی این اثر مستقیماً روی تلفن همراه باز شود.
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
                    @click="copyText('{{ url('/w/' . $work->id) }}', 'qr-modal');"
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
                    <h3 class="font-bold text-lg text-stone-900 dark:text-stone-100">دریافت استناد علمی اثر</h3>
                </div>
                <button @click="citationModalOpen = false" class="text-stone-400 hover:text-stone-600 dark:hover:text-stone-200 text-lg leading-none cursor-pointer">✕</button>
            </div>

            <p class="text-xs text-stone-500 dark:text-stone-400 leading-relaxed">
                جهت استناد و ارجاع علمی به این اثر در مقالات پژوهشی، تصحیحات انتقادی و پایان‌نامه‌ها، می‌توانید از قالب‌های آماده زیر استفاده فرمایید:
            </p>

            @php
                $workFankhaCitation = "درایتی، مصطفی. فهرستگان نسخه‌های خطی ایران (فنخا). ج " . ($work->volume_number ?? '-') . "، ص " . ($work->page_start ?? '-') . ($work->page_end && $work->page_end > $work->page_start ? " تا {$work->page_end}" : "") . "، مدخل «" . $work->primary_title . "».";
                
                $authorPrefix = $work->author ? $work->author->name . '. ' : ($work->author_name ? $work->author_name . '. ' : '');
                $shortUrlWork = url('/w/' . $work->id);
                $workGeneralCitation = "{$authorPrefix}«{$work->primary_title}». پایگاه فهارس: {$shortUrlWork}";

                $authorEn = $work->author?->name ?? ($work->author_name ?? 'Unknown');
                $workChicagoCitation = "{$authorEn}. \"{$work->primary_title}.\" Fahares Database: {$shortUrlWork}";
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
                            @click="copyText(@js($workFankhaCitation), 'fankha')"
                            class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl bg-white dark:bg-[#15192C] border border-[#EADFCF] dark:border-[#272F4C] text-[11px] font-semibold text-stone-700 dark:text-stone-300 hover:text-[#B38A50] hover:border-[#B38A50] transition shadow-2xs cursor-pointer">
                            <span x-show="copiedKey !== 'fankha'">کپی ارجاع</span>
                            <span x-show="copiedKey === 'fankha'" class="text-emerald-600 font-bold" style="display: none;">کپی شد! ✓</span>
                        </button>
                    </div>
                    <div class="p-3 rounded-xl bg-white dark:bg-[#15192C] border border-stone-200/80 dark:border-stone-800 text-xs text-stone-800 dark:text-stone-200 leading-relaxed font-normal select-all">
                        {{ $workFankhaCitation }}
                    </div>
                </div>

                <!-- Format 2: General Citation in Persian Articles -->
                <div class="p-4 rounded-2xl bg-stone-50 dark:bg-[#1A1E35] border border-stone-200 dark:border-[#272F4C] space-y-2">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-0.5 rounded-md bg-stone-200 dark:bg-stone-800 text-stone-700 dark:text-stone-300 text-[11px] font-bold">
                                قالب ۲
                            </span>
                            <span class="text-xs font-bold text-stone-800 dark:text-stone-200">قالب ارجاع عمومی در مقالات و کتب پژوهشی</span>
                        </div>
                        <button 
                            type="button" 
                            @click="copyText(@js($workGeneralCitation), 'general')"
                            class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl bg-white dark:bg-[#15192C] border border-stone-200 dark:border-stone-700 text-[11px] font-semibold text-stone-700 dark:text-stone-300 hover:text-[#B38A50] hover:border-[#B38A50] transition shadow-2xs cursor-pointer">
                            <span x-show="copiedKey !== 'general'">کپی ارجاع</span>
                            <span x-show="copiedKey === 'general'" class="text-emerald-600 font-bold" style="display: none;">کپی شد! ✓</span>
                        </button>
                    </div>
                    <div class="p-3 rounded-xl bg-white dark:bg-[#15192C] border border-stone-200/80 dark:border-stone-800 text-xs text-stone-800 dark:text-stone-200 leading-relaxed font-normal select-all">
                        {{ $workGeneralCitation }}
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
                            @click="copyText(@js($workChicagoCitation), 'chicago')"
                            class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl bg-white dark:bg-[#15192C] border border-stone-200 dark:border-stone-700 text-[11px] font-semibold text-stone-700 dark:text-stone-300 hover:text-[#B38A50] hover:border-[#B38A50] transition shadow-2xs cursor-pointer">
                            <span x-show="copiedKey !== 'chicago'">کپی ارجاع</span>
                            <span x-show="copiedKey === 'chicago'" class="text-emerald-600 font-bold" style="display: none;">کپی شد! ✓</span>
                        </button>
                    </div>
                    <div class="p-3 rounded-xl bg-white dark:bg-[#15192C] border border-stone-200/80 dark:border-stone-800 text-xs text-stone-800 dark:text-stone-200 leading-relaxed font-mono select-all text-left" dir="ltr">
                        {{ $workChicagoCitation }}
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
  "@type": "Book",
  "name": "{{ addcslashes($work->primary_title, '"\\') }}",
  @if($work->author)"author": { "@type": "Person", "name": "{{ addcslashes($work->author->name, '"\\') }}" },@endif
  "inLanguage": "fa",
  "url": "{{ route('works.show', $work) }}",
  "description": "{{ addcslashes(str_replace(["\r", "\n"], ' ', Str::limit(strip_tags($work->description ?? $work->clean_raw_text ?? ''), 250)), '"\\') }}"
}
</script>
@endpush


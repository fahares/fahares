@extends('layouts.app')

@section('title', 'نسخه خطی ' . ($manuscript->work?->primary_title ?? '') . ' | ' . ($manuscript->library?->name ?? $manuscript->library) . ' (' . ($manuscript->shelfmark ?? 'بی‌شماره') . ')')
@section('meta_description', 'شناسنامه کالبدشناسی نسخه خطی ' . ($manuscript->work?->primary_title ?? '') . ' در ' . ($manuscript->library?->name ?? $manuscript->library) . ' با شماره بازیابی ' . ($manuscript->shelfmark ?? ''))

@section('content')
<div x-data="{ suggestionModalOpen: false, selectedField: 'کاتب' }" class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">

    <!-- Breadcrumb -->
    <nav class="flex items-center gap-2 text-xs text-stone-500">
        <a href="{{ route('home') }}" class="hover:text-[#B38A50]">فهارس</a>
        <span>/</span>
        <a href="{{ route('search', ['type' => 'manuscripts']) }}" class="hover:text-[#B38A50]">نسخه‌های خطی</a>
        <span>/</span>
        @if($manuscript->work)
            <a href="{{ route('works.show', $manuscript->work_id) }}" class="hover:text-[#B38A50] truncate max-w-xs">{{ $manuscript->work->primary_title }}</a>
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
                            <a href="{{ route('works.show', $manuscript->work_id) }}" class="hover:text-[#B38A50] transition">
                                {{ $manuscript->work->primary_title }}
                            </a>
                        </h1>
                        @if($manuscript->work->author)
                            <div class="text-xs text-stone-600 dark:text-stone-300">
                                پدیدآور اثر: <a href="{{ route('people.show', $manuscript->work->author_id) }}" class="font-bold text-[#B38A50] hover:underline">{{ $manuscript->work->author->name }}</a>
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            <!-- Suggestion / Feedback Action Button -->
            <div>
                <button 
                    @click="suggestionModalOpen = true" 
                    type="button" 
                    class="px-4 py-2.5 rounded-xl border border-[#B38A50] text-[#B38A50] hover:bg-[#B38A50] hover:text-white dark:hover:text-stone-900 font-semibold text-xs transition duration-200 flex items-center gap-2 shadow-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                    <span>پیشنهاد تصحیح / تکمیل این نسخه</span>
                </button>
            </div>
        </div>

        <!-- Shelfmark & Location Bar -->
        <div class="p-5 rounded-2xl bg-[#FEF9F3] dark:bg-[#1A1E35] border border-[#EADFCF] dark:border-[#272F4C] grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs">
            <div class="space-y-1">
                <span class="text-stone-400 block font-medium">کتابخانه و مخزن نگهداری:</span>
                @if($manuscript->library_id)
                    <a href="{{ route('libraries.show', $manuscript->library_id) }}" class="text-sm font-bold text-[#292C56] dark:text-amber-200 hover:text-[#B38A50] transition">
                        {{ $manuscript->library?->name ?? $manuscript->library }}
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
                <span class="text-stone-400 block font-medium">شماره بازیابی / قفسه:</span>
                <span class="text-base font-black text-[#B38A50] tracking-wide">
                    {{ $manuscript->shelfmark ?? 'بی‌شماره' }}
                </span>
            </div>
        </div>

    </div>

    <!-- CODICOLOGICAL FLAGS (9 Indicators) -->
    <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-3">
        <h3 class="text-xs font-bold text-stone-400 uppercase tracking-wider">نشان‌های نسخه‌شناسی و کالبدشناسی</h3>
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-2 text-xs">
            
            <div class="p-3 rounded-xl border flex items-center justify-between {{ $manuscript->is_autograph ? 'bg-emerald-50 dark:bg-emerald-950/40 border-emerald-300 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200 font-bold' : 'bg-stone-50 dark:bg-stone-800/40 border-stone-200 dark:border-stone-800 text-stone-400' }}">
                <span>اصل نسخه (دستخط مؤلف)</span>
                <span>{{ $manuscript->is_autograph ? '✓' : '—' }}</span>
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
                        @if($manuscript->scribe_id)
                            <a href="{{ route('people.show', $manuscript->scribe_id) }}" class="text-[#B38A50] hover:underline">{{ $manuscript->scribe_name }}</a>
                        @elseif($manuscript->scribe_name)
                            {{ $manuscript->scribe_name }}
                        @elseif($manuscript->is_autograph)
                            @if($manuscript->work?->author_id)
                                <a href="{{ route('people.show', $manuscript->work->author_id) }}" class="text-emerald-700 dark:text-emerald-400 hover:underline">مؤلف</a>
                            @else
                                <span class="text-emerald-700 dark:text-emerald-400">مؤلف</span>
                            @endif
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
    @if($manuscript->incipit || $manuscript->explicit || $manuscript->notes)
        <div class="space-y-4">
            
            @if($manuscript->incipit)
                <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-2">
                    <span class="text-xs font-bold text-[#B38A50] uppercase tracking-wider block">آغاز نسخه (Incipit):</span>
                    <div class="manuscript-quote text-stone-800 dark:text-stone-100 text-sm leading-relaxed">
                        « {{ $manuscript->incipit }} »
                    </div>
                </div>
            @endif

            @if($manuscript->explicit)
                <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-2">
                    <span class="text-xs font-bold text-[#292C56] dark:text-indigo-400 uppercase tracking-wider block">انجام نسخه (Explicit):</span>
                    <div class="manuscript-quote text-stone-800 dark:text-stone-100 text-sm leading-relaxed">
                        « {{ $manuscript->explicit }} »
                    </div>
                </div>
            @endif

            @if($manuscript->notes)
                <div class="bg-white dark:bg-[#15192C] rounded-3xl p-6 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-2">
                    <span class="text-xs font-bold text-stone-400 uppercase tracking-wider block">یادداشت‌های نسخه‌شناسی:</span>
                    <div class="text-stone-700 dark:text-stone-300 text-xs leading-relaxed">
                        {{ $manuscript->notes }}
                    </div>
                </div>
            @endif

        </div>
    @endif

    <!-- CITATION IN PRINTED SOURCE CATALOG -->
    <div class="p-6 rounded-3xl bg-[#FEF9F3] dark:bg-[#1A1E35] border border-[#EADFCF] dark:border-[#272F4C] flex flex-col sm:flex-row items-center justify-between text-xs gap-4 shadow-xs">
        <div class="space-y-1 text-right">
            <div class="flex items-center gap-2">
                <span class="px-2 py-0.5 rounded-md bg-amber-100 dark:bg-amber-950/60 text-[#B38A50] text-[11px] font-bold">
                    {{ $manuscript->catalog?->short_name ?? 'فنخا' }}
                </span>
                <span class="font-bold text-stone-800 dark:text-stone-200">مشخصات مأخذ و استناد فهرست‌نگاری:</span>
            </div>
            <p class="text-stone-600 dark:text-stone-300 text-xs leading-relaxed pt-1">
                {{ $manuscript->catalog?->citation_format ?? ($manuscript->catalog?->name ?? 'فهرستگان نسخه‌های خطی ایران (فنخا)') }}
            </p>
        </div>
        <div class="text-sm font-bold text-[#B38A50] whitespace-nowrap bg-white dark:bg-[#15192C] px-5 py-2.5 rounded-2xl border border-[#EADFCF] dark:border-[#272F4C] shadow-xs">
            جلد {{ $manuscript->volume_number }} • صفحه {{ $manuscript->page_start }}
        </div>
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
                پژوهشگر گرامی؛ در صورتی که در اطلاعات این نسخه (کاتب، تاریخ، آغاز/انجام، شماره قفسه یا مشخصات مادی) خطایی مشاهده نموده‌اید، لطفاً اصلاحیه خود را همراه با مستند علمی ثبت بفرمایید.
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
                        <option value="shelfmark">شماره بازیابی / قفسه</option>
                        <option value="incipit">عبارت آغاز (Incipit)</option>
                        <option value="explicit">عبارت انجام (Explicit)</option>
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

        </div>
    </div>

</div>
@endsection

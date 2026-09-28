@extends('layouts.app')

@section('title', 'کاوش و جستجوی پیشرفته | فهارس')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">

    <!-- Search Header & Mode Bar -->
    <div class="bg-white dark:bg-[#15192C] p-6 rounded-3xl border border-[#EADFCF] dark:border-[#272F4C] shadow-md space-y-4">
        
        <form action="{{ route('search') }}" method="GET" class="space-y-4">
            <!-- Search bar -->
            <div class="relative flex items-center">
                <input 
                    type="text" 
                    name="q" 
                    value="{{ $query }}" 
                    placeholder="عبارت مورد نظر برای کاوش..."
                    class="w-full pr-12 pl-28 py-3.5 bg-stone-50 dark:bg-stone-800/80 text-stone-800 dark:text-stone-100 placeholder-stone-400 rounded-2xl border border-stone-300 dark:border-stone-700 focus:border-[#B38A50] focus:ring-4 focus:ring-[#B38A50]/20 text-base shadow-inner transition">
                
                <div class="absolute right-4 text-stone-400">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                </div>

                <button 
                    type="submit" 
                    class="absolute left-2 px-5 py-2 bg-[#292C56] hover:bg-[#191B36] text-amber-100 font-semibold rounded-xl text-sm transition shadow">
                    جستجو
                </button>
            </div>

            <!-- Type Tabs -->
            <div class="flex flex-wrap items-center justify-between gap-3 pt-2 border-t border-stone-100 dark:border-stone-800 text-sm">
                <div class="flex items-center gap-2">
                    <span class="text-xs text-stone-400 font-medium">دامنه جستجو:</span>
                    <button type="submit" name="type" value="works" 
                        class="px-3 py-1 rounded-lg font-medium transition {{ $type === 'works' ? 'bg-[#B38A50] text-white shadow-sm' : 'bg-stone-100 dark:bg-stone-800 text-stone-600 dark:text-stone-300 hover:bg-stone-200' }}">
                        آثار و عناوین
                    </button>
                    <button type="submit" name="type" value="manuscripts" 
                        class="px-3 py-1 rounded-lg font-medium transition {{ $type === 'manuscripts' ? 'bg-[#B38A50] text-white shadow-sm' : 'bg-stone-100 dark:bg-stone-800 text-stone-600 dark:text-stone-300 hover:bg-stone-200' }}">
                        نسخه‌های خطی
                    </button>
                    <button type="submit" name="type" value="people" 
                        class="px-3 py-1 rounded-lg font-medium transition {{ $type === 'people' ? 'bg-[#B38A50] text-white shadow-sm' : 'bg-stone-100 dark:bg-stone-800 text-stone-600 dark:text-stone-300 hover:bg-stone-200' }}">
                        پدیدآوران و کاتبان
                    </button>
                </div>

                @if($results)
                    <div class="text-xs font-mono text-stone-500">
                        نمایش {{ number_format($results->total()) }} نتیجه
                    </div>
                @endif
            </div>

            <!-- Active Filters Reset -->
            @if($subjectId || $libraryId || $scriptId || $century || $flag)
                <div class="flex items-center gap-2 pt-2 text-xs">
                    <span class="text-stone-400">فیلترهای فعال:</span>
                    <a href="{{ route('search', ['q' => $query, 'type' => $type]) }}" class="px-2 py-0.5 rounded bg-red-50 dark:bg-red-950/40 text-red-600 dark:text-red-300 hover:underline">
                        پاکسازی همه فیلترها ✕
                    </a>
                </div>
            @endif
        </form>

    </div>

    <!-- Main Grid: Sidebar Filters + Results List -->
    <div class="grid grid-cols-1 lg:grid-cols-4 gap-8">
        
        <!-- SIDEBAR FILTERS -->
        <aside class="space-y-6">
            
            <form action="{{ route('search') }}" method="GET" class="bg-white dark:bg-[#15192C] p-5 rounded-3xl border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-6 text-sm">
                <input type="hidden" name="q" value="{{ $query }}">
                <input type="hidden" name="type" value="{{ $type }}">

                <div class="flex items-center justify-between pb-3 border-b border-stone-100 dark:border-stone-800">
                    <span class="font-bold text-stone-800 dark:text-stone-100">فیلترهای پیشرفته</span>
                    <button type="submit" class="text-xs text-[#B38A50] font-semibold hover:underline">اعمال فیلتر</button>
                </div>

                @if($type === 'works')
                    <!-- Subject Filter -->
                    <div class="space-y-2">
                        <label class="block text-xs font-bold text-stone-600 dark:text-stone-300">موضوع اثر</label>
                        <select name="subject_id" class="w-full text-xs p-2.5 rounded-xl border border-stone-200 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-800 dark:text-stone-100">
                            <option value="">همه موضوعات</option>
                            @foreach($subjects as $sub)
                                <option value="{{ $sub->id }}" {{ $subjectId == $sub->id ? 'selected' : '' }}>
                                    {{ $sub->title }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif

                @if($type === 'manuscripts')
                    <!-- Library Filter -->
                    <div class="space-y-2">
                        <label class="block text-xs font-bold text-stone-600 dark:text-stone-300">کتابخانه / مرکز اسناد</label>
                        <select name="library_id" class="w-full text-xs p-2.5 rounded-xl border border-stone-200 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-800 dark:text-stone-100">
                            <option value="">همه کتابخانه‌ها</option>
                            @foreach($libraries as $lib)
                                <option value="{{ $lib->id }}" {{ $libraryId == $lib->id ? 'selected' : '' }}>
                                    {{ $lib->name }} ({{ $lib->city }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Script Type Filter -->
                    <div class="space-y-2">
                        <label class="block text-xs font-bold text-stone-600 dark:text-stone-300">نوع خط</label>
                        <select name="script_id" class="w-full text-xs p-2.5 rounded-xl border border-stone-200 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-800 dark:text-stone-100">
                            <option value="">همه انواع خطوط</option>
                            @foreach($scripts as $sc)
                                <option value="{{ $sc->id }}" {{ $scriptId == $sc->id ? 'selected' : '' }}>
                                    {{ $sc->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Century Filter -->
                    <div class="space-y-2">
                        <label class="block text-xs font-bold text-stone-600 dark:text-stone-300">قرن کتابت (هجری قمری)</label>
                        <select name="century" class="w-full text-xs p-2.5 rounded-xl border border-stone-200 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-800 dark:text-stone-100">
                            <option value="">همه قرون</option>
                            @for($c = 4; $c <= 14; $c++)
                                <option value="{{ $c }}" {{ $century == $c ? 'selected' : '' }}>
                                    سده {{ $c }} هجری
                                </option>
                            @endfor
                        </select>
                    </div>

                    <!-- Codicological Flags -->
                    <div class="space-y-2">
                        <label class="block text-xs font-bold text-stone-600 dark:text-stone-300">ویژگی‌های کالبدشناسی</label>
                        <div class="space-y-1.5 text-xs text-stone-600 dark:text-stone-300">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="flag" value="" {{ empty($flag) ? 'checked' : '' }} class="text-[#B38A50]">
                                <span>همه</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="flag" value="is_autograph" {{ $flag === 'is_autograph' ? 'checked' : '' }} class="text-[#B38A50]">
                                <span>اصل نسخه (دستخط مؤلف)</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="flag" value="is_illuminated" {{ $flag === 'is_illuminated' ? 'checked' : '' }} class="text-[#B38A50]">
                                <span>دارای تذهیب و سرلوح</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="flag" value="is_illustrated" {{ $flag === 'is_illustrated' ? 'checked' : '' }} class="text-[#B38A50]">
                                <span>دارای نگاره و تصویر</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="flag" value="is_corrected" {{ $flag === 'is_corrected' ? 'checked' : '' }} class="text-[#B38A50]">
                                <span>تصحیح‌شده</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="flag" value="has_marginal_notes" {{ $flag === 'has_marginal_notes' ? 'checked' : '' }} class="text-[#B38A50]">
                                <span>دارای حواشی</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="flag" value="is_collated" {{ $flag === 'is_collated' ? 'checked' : '' }} class="text-[#B38A50]">
                                <span>مقابله‌شده</span>
                            </label>
                        </div>
                    </div>
                @endif

                <button type="submit" class="w-full py-2.5 bg-[#B38A50] hover:bg-[#9C753F] text-white font-semibold rounded-xl text-xs transition shadow">
                    اعمال فیلترها
                </button>
            </form>

        </aside>

        <!-- RESULTS CONTENT -->
        <div class="lg:col-span-3 space-y-4">
            
            @if($results && $results->count() > 0)
                
                @if($type === 'works')
                    <!-- Works Cards -->
                    <div class="space-y-3">
                        @foreach($results as $work)
                            <div class="bg-white dark:bg-[#15192C] p-5 rounded-2xl border border-stone-200 dark:border-stone-800 hover:border-[#B38A50] dark:hover:border-[#B38A50] shadow-sm hover:shadow-md transition duration-200 space-y-3">
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <a href="{{ route('works.show', $work->id) }}" class="text-lg font-bold text-[#292C56] dark:text-amber-100 hover:text-[#B38A50] transition">
                                            {{ $work->primary_title }}
                                        </a>
                                        @if($work->clean_title && $work->clean_title !== $work->primary_title)
                                            <span class="text-xs text-stone-400 mr-2">({{ $work->clean_title }})</span>
                                        @endif
                                        
                                        <div class="text-xs text-stone-600 dark:text-stone-300 mt-1 flex flex-wrap items-center gap-x-4 gap-y-1">
                                            @if($work->author)
                                                <span>پدیدآور: <a href="{{ route('people.show', $work->author_id) }}" class="font-semibold text-stone-800 dark:text-stone-200 hover:underline">{{ $work->author->name }}</a></span>
                                            @elseif($work->author_name)
                                                <span>پدیدآور: <strong class="text-stone-700 dark:text-stone-300">{{ $work->author_name }}</strong></span>
                                            @else
                                                <span class="text-stone-400">پدیدآور: ناشناخته</span>
                                            @endif

                                            @if($work->composition_year_hijri)
                                                <span>تألیف: <strong class="font-mono">{{ $work->composition_year_hijri }} هـ.ق</strong></span>
                                            @endif

                                            <span>منبع در فنخا: <strong class="font-mono">جلد {{ $work->volume_number }}، ص {{ $work->page_start }}</strong></span>
                                        </div>
                                    </div>

                                    <div class="flex flex-col items-end gap-1">
                                        <span class="px-3 py-1 rounded-full bg-amber-50 dark:bg-amber-950/40 text-[#B38A50] text-xs font-mono font-bold">
                                            {{ number_format($work->manuscripts_count) }} نسخه
                                        </span>
                                    </div>
                                </div>

                                <!-- Subjects & Languages -->
                                <div class="flex flex-wrap items-center gap-1.5 pt-2 border-t border-stone-100 dark:border-stone-800/80 text-[11px]">
                                    @foreach($work->subjects as $subj)
                                        <span class="px-2 py-0.5 rounded bg-stone-100 dark:bg-stone-800 text-stone-600 dark:text-stone-400">{{ $subj->title }}</span>
                                    @endforeach
                                    @foreach($work->languages as $lang)
                                        <span class="px-2 py-0.5 rounded bg-stone-100 dark:bg-stone-800 text-stone-500 font-mono">{{ $lang->name }}</span>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>

                @elseif($type === 'manuscripts')
                    <!-- Manuscripts Cards -->
                    <div class="space-y-3">
                        @foreach($results as $ms)
                            <div class="bg-white dark:bg-[#15192C] p-5 rounded-2xl border border-stone-200 dark:border-stone-800 hover:border-[#B38A50] dark:hover:border-[#B38A50] shadow-sm hover:shadow-md transition duration-200 space-y-3">
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <a href="{{ route('manuscripts.show', $ms->id) }}" class="text-base font-bold text-[#292C56] dark:text-amber-100 hover:text-[#B38A50] transition">
                                                {{ $ms->work?->primary_title ?? 'نسخه بدون عنوان' }}
                                            </a>
                                            @if($ms->is_autograph)
                                                <span class="px-2 py-0.5 rounded-full bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300 text-[10px] font-bold">اصل نسخه (دستخط مؤلف)</span>
                                            @endif
                                        </div>

                                        <div class="text-xs text-stone-600 dark:text-stone-300 mt-1 flex flex-wrap items-center gap-x-4 gap-y-1">
                                            <span>کتابخانه: <strong>{{ $ms->library?->name ?? $ms->library ?? 'نامشخص' }}</strong> ({{ $ms->city }})</span>
                                            <span>شماره بازیابی: <strong class="font-mono text-[#B38A50]">{{ $ms->shelfmark ?? 'بی‌شماره' }}</strong></span>
                                            @if($ms->scribe_name)
                                                <span>کاتب: <strong>{{ $ms->scribe_name }}</strong></span>
                                            @elseif($ms->is_bika)
                                                <span class="text-stone-400">بی‌کاتب</span>
                                            @endif
                                            @if($ms->copy_date_raw)
                                                <span>تاریخ: <strong class="font-mono">{{ $ms->copy_date_raw }}</strong></span>
                                            @elseif($ms->is_bita)
                                                <span class="text-stone-400">بی‌تاریخ</span>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="text-right text-xs font-mono text-stone-400">
                                        فنخا ج{{ $ms->volume_number }}، ص{{ $ms->page_start }}
                                    </div>
                                </div>

                                <!-- Codicological Badges -->
                                <div class="flex flex-wrap items-center gap-1.5 pt-2 border-t border-stone-100 dark:border-stone-800/80 text-[11px]">
                                    @if($ms->script_names)
                                        <span class="px-2 py-0.5 rounded bg-amber-50 dark:bg-amber-950/40 text-[#B38A50] font-medium">خط: {{ $ms->script_names }}</span>
                                    @endif
                                    @if($ms->folios)
                                        <span class="px-2 py-0.5 rounded bg-stone-100 dark:bg-stone-800 text-stone-600 dark:text-stone-400">{{ $ms->folios }} برگ</span>
                                    @endif
                                    @if($ms->is_illuminated)
                                        <span class="px-2 py-0.5 rounded bg-indigo-50 dark:bg-indigo-950/40 text-indigo-700 dark:text-indigo-300">مذهب</span>
                                    @endif
                                    @if($ms->is_illustrated)
                                        <span class="px-2 py-0.5 rounded bg-purple-50 dark:bg-purple-950/40 text-purple-700 dark:text-purple-300">دارای تصویر</span>
                                    @endif
                                    @if($ms->is_corrected)
                                        <span class="px-2 py-0.5 rounded bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300">مصحح</span>
                                    @endif
                                    @if($ms->has_marginal_notes)
                                        <span class="px-2 py-0.5 rounded bg-stone-100 dark:bg-stone-800 text-stone-600 dark:text-stone-400">محشی</span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                @elseif($type === 'people')
                    <!-- People Cards -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        @foreach($results as $person)
                            <a href="{{ route('people.show', $person->id) }}" class="bg-white dark:bg-[#15192C] p-4 rounded-2xl border border-stone-200 dark:border-stone-800 hover:border-[#B38A50] dark:hover:border-[#B38A50] shadow-sm hover:shadow-md transition flex flex-col justify-between">
                                <div>
                                    <div class="text-base font-bold text-[#292C56] dark:text-amber-100 hover:text-[#B38A50] transition">
                                        {{ $person->name }}
                                    </div>
                                    @if($person->transliteration)
                                        <div class="text-[11px] font-mono text-stone-400">{{ $person->transliteration }}</div>
                                    @endif
                                    <div class="text-xs text-stone-500 mt-2">
                                        @if($person->death_year_hijri)
                                            وفات: <strong class="font-mono">{{ $person->death_year_hijri }} هـ.ق</strong>
                                        @elseif($person->century_hijri)
                                            سده: <strong class="font-mono">{{ $person->century_hijri }} هـ.ق</strong>
                                        @endif
                                    </div>
                                </div>
                                <div class="mt-4 pt-3 border-t border-stone-100 dark:border-stone-800 flex items-center justify-between text-xs">
                                    <span class="text-stone-400">تألیفات ثبت‌شده:</span>
                                    <span class="font-mono font-bold text-[#B38A50]">{{ $person->works_count }} اثر</span>
                                </div>
                            </a>
                        @endforeach
                    </div>

                @endif

                <!-- Pagination Links -->
                <div class="pt-6">
                    {{ $results->links() }}
                </div>

            @else
                <!-- No Results State -->
                <div class="bg-white dark:bg-[#15192C] p-12 rounded-3xl border border-stone-200 dark:border-stone-800 text-center space-y-4">
                    <div class="w-16 h-16 mx-auto rounded-full bg-stone-100 dark:bg-stone-800 flex items-center justify-center text-stone-400">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    </div>
                    <div class="text-lg font-bold text-stone-800 dark:text-stone-200">نتیجه‌ای با مشخصات درخواستی یافت نشد</div>
                    <p class="text-xs text-stone-400 max-w-md mx-auto">
                        لطفاً املاء کلمات را بازبینی کنید یا فیلترهای محدودکننده را تغییر دهید.
                    </p>
                    <a href="{{ route('search', ['type' => $type]) }}" class="inline-block px-4 py-2 bg-stone-100 dark:bg-stone-800 hover:bg-stone-200 text-xs font-semibold rounded-xl text-stone-700 dark:text-stone-300">
                        مشاهده تمام موارد این بخش
                    </a>
                </div>
            @endif

        </div>

    </div>

</div>
@endsection

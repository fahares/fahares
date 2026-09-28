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

            <!-- Search Controls: Scope Radios & Type Tabs -->
            <div class="flex flex-wrap items-center justify-between gap-4 pt-3 border-t border-stone-100 dark:border-stone-800 text-xs">
                
                <!-- Scope Radio Buttons -->
                <div class="flex flex-wrap items-center gap-4 text-stone-600 dark:text-stone-300">
                    <span class="font-bold text-stone-400">جست‌وجوی عبارت:</span>
                    <label class="inline-flex items-center gap-1.5 cursor-pointer hover:text-[#B38A50] transition">
                        <input type="radio" name="scope" value="titles" {{ $scope === 'titles' ? 'checked' : '' }} onchange="this.form.submit()" class="text-[#B38A50] focus:ring-[#B38A50]">
                        <span>فقط در عناوین</span>
                    </label>
                    <label class="inline-flex items-center gap-1.5 cursor-pointer hover:text-[#B38A50] transition">
                        <input type="radio" name="scope" value="titles_names" {{ $scope === 'titles_names' ? 'checked' : '' }} onchange="this.form.submit()" class="text-[#B38A50] focus:ring-[#B38A50]">
                        <span class="font-bold">فقط در عناوین و اعلام</span>
                    </label>
                    <label class="inline-flex items-center gap-1.5 cursor-pointer hover:text-[#B38A50] transition">
                        <input type="radio" name="scope" value="all" {{ $scope === 'all' ? 'checked' : '' }} onchange="this.form.submit()" class="text-[#B38A50] focus:ring-[#B38A50]">
                        <span>در همۀ اطلاعات</span>
                    </label>
                </div>

                <!-- Type Tabs -->
                <div class="flex items-center gap-2">
                    <span class="text-xs text-stone-400 font-medium">دامنه:</span>
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
                    <div class="text-xs text-stone-500 font-medium">
                        نمایش {{ number_format($results->total()) }} نتیجه
                    </div>
                @endif
            </div>

            <!-- Active Filters Reset -->
            @if($subjectId || $libraryId || $scriptId || $century || $flag)
                <div class="flex flex-wrap items-center gap-2 pt-2 text-xs">
                    <span class="text-stone-400">فیلترهای فعال:</span>

                    @if($selectedLibrary)
                        <a href="{{ route('search', array_merge(request()->except('library_id'), ['page' => 1])) }}" 
                           class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-amber-50 dark:bg-amber-950/60 text-[#B38A50] border border-[#B38A50]/30 hover:bg-amber-100 dark:hover:bg-amber-900/50 transition">
                            <span>کتابخانه: <strong>{{ $selectedLibrary->name }}</strong></span>
                            <span class="text-red-500 font-bold hover:scale-125 transition">✕</span>
                        </a>
                    @endif

                    @if($selectedScript)
                        <a href="{{ route('search', array_merge(request()->except('script_id'), ['page' => 1])) }}" 
                           class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-amber-50 dark:bg-amber-950/60 text-[#B38A50] border border-[#B38A50]/30 hover:bg-amber-100 dark:hover:bg-amber-900/50 transition">
                            <span>خط: <strong>{{ $selectedScript->name }}</strong></span>
                            <span class="text-red-500 font-bold hover:scale-125 transition">✕</span>
                        </a>
                    @endif

                    @if($century)
                        <a href="{{ route('search', array_merge(request()->except('century'), ['page' => 1])) }}" 
                           class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-amber-50 dark:bg-amber-950/60 text-[#B38A50] border border-[#B38A50]/30 hover:bg-amber-100 dark:hover:bg-amber-900/50 transition">
                            <span>سده: <strong>{{ $century }} هجری</strong></span>
                            <span class="text-red-500 font-bold hover:scale-125 transition">✕</span>
                        </a>
                    @endif

                    @if($selectedSubject)
                        <a href="{{ route('search', array_merge(request()->except('subject_id'), ['page' => 1])) }}" 
                           class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-amber-50 dark:bg-amber-950/60 text-[#B38A50] border border-[#B38A50]/30 hover:bg-amber-100 dark:hover:bg-amber-900/50 transition">
                            <span>موضوع: <strong>{{ $selectedSubject->title }}</strong></span>
                            <span class="text-red-500 font-bold hover:scale-125 transition">✕</span>
                        </a>
                    @endif

                    @if($flag)
                        @php
                            $flagLabels = [
                                'is_autograph' => 'اصل نسخه (دستخط مؤلف)',
                                'is_illuminated' => 'دارای تذهیب و سرلوح',
                                'is_illustrated' => 'دارای نگاره و تصویر',
                                'is_corrected' => 'تصحیح‌شده',
                                'has_marginal_notes' => 'دارای حواشی',
                                'is_collated' => 'مقابله‌شده',
                            ];
                        @endphp
                        <a href="{{ route('search', array_merge(request()->except('flag'), ['page' => 1])) }}" 
                           class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-amber-50 dark:bg-amber-950/60 text-[#B38A50] border border-[#B38A50]/30 hover:bg-amber-100 dark:hover:bg-amber-900/50 transition">
                            <span>ویژگی: <strong>{{ $flagLabels[$flag] ?? $flag }}</strong></span>
                            <span class="text-red-500 font-bold hover:scale-125 transition">✕</span>
                        </a>
                    @endif

                    <a href="{{ route('search', ['q' => $query, 'type' => $type, 'scope' => $scope]) }}" class="px-2.5 py-1 rounded-lg bg-red-50 dark:bg-red-950/40 text-red-600 dark:text-red-300 hover:underline">
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
                <input type="hidden" name="scope" value="{{ $scope }}">

                <div class="flex items-center justify-between pb-3 border-b border-stone-100 dark:border-stone-800">
                    <span class="font-bold text-stone-800 dark:text-stone-100">فیلترهای پیشرفته</span>
                    <button type="submit" class="text-xs text-[#B38A50] font-semibold hover:underline">اعمال فیلتر</button>
                </div>

                @if($type === 'works')
                    <!-- Subject Filter -->
                    <div class="space-y-2">
                        <div class="flex items-center justify-between">
                            <label class="block text-xs font-bold text-stone-600 dark:text-stone-300">موضوع اثر</label>
                            @if($subjects->isNotEmpty())
                                <span class="text-[10px] text-stone-400 font-medium">{{ $subjects->count() }} موضوع</span>
                            @endif
                        </div>
                        <select name="subject_id" class="w-full text-xs p-2.5 rounded-xl border border-stone-200 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-800 dark:text-stone-100">
                            <option value="">همه موضوعات</option>
                            @foreach($subjects as $sub)
                                <option value="{{ $sub->id }}" {{ $subjectId == $sub->id ? 'selected' : '' }}>
                                    {{ $sub->title }} @if(isset($sub->matching_count)) ({{ number_format($sub->matching_count) }}) @endif
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif

                @if($type === 'manuscripts')
                    <!-- Library Filter -->
                    <div class="space-y-2">
                        <div class="flex items-center justify-between">
                            <label class="block text-xs font-bold text-stone-600 dark:text-stone-300">کتابخانه / مرکز اسناد</label>
                            @if($libraries->isNotEmpty())
                                <span class="text-[10px] text-[#B38A50] font-semibold">{{ $libraries->count() }} مرکز</span>
                            @endif
                        </div>
                        <select name="library_id" class="w-full text-xs p-2.5 rounded-xl border border-stone-200 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-800 dark:text-stone-100">
                            <option value="">
                                همه کتابخانه‌ها {{ $totalFacetManuscripts > 0 ? '(' . number_format($totalFacetManuscripts) . ' نسخه)' : '' }}
                            </option>
                            @foreach($libraries as $lib)
                                <option value="{{ $lib->id }}" {{ $libraryId == $lib->id ? 'selected' : '' }}>
                                    {{ $lib->name }} ({{ $lib->city }}) @if(isset($lib->matching_count)) — {{ number_format($lib->matching_count) }} نسخه @endif
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Script Type Filter -->
                    <div class="space-y-2">
                        <div class="flex items-center justify-between">
                            <label class="block text-xs font-bold text-stone-600 dark:text-stone-300">نوع خط</label>
                            @if($scripts->isNotEmpty())
                                <span class="text-[10px] text-stone-400 font-medium">{{ $scripts->count() }} خط</span>
                            @endif
                        </div>
                        <select name="script_id" class="w-full text-xs p-2.5 rounded-xl border border-stone-200 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-800 dark:text-stone-100">
                            <option value="">همه انواع خطوط</option>
                            @foreach($scripts as $sc)
                                <option value="{{ $sc->id }}" {{ $scriptId == $sc->id ? 'selected' : '' }}>
                                    {{ $sc->name }} @if(isset($sc->matching_count)) ({{ number_format($sc->matching_count) }} نسخه) @endif
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Century Filter -->
                    <div class="space-y-2">
                        <label class="block text-xs font-bold text-stone-600 dark:text-stone-300">قرن کتابت (هجری قمری)</label>
                        <select name="century" class="w-full text-xs p-2.5 rounded-xl border border-stone-200 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-800 dark:text-stone-100">
                            <option value="">همه قرون</option>
                            @if(!empty($centuries))
                                @foreach($centuries as $c => $cCount)
                                    <option value="{{ $c }}" {{ $century == $c ? 'selected' : '' }}>
                                        سده {{ $c }} هجری @if($cCount) ({{ number_format($cCount) }} نسخه) @endif
                                    </option>
                                @endforeach
                            @else
                                @for($c = 4; $c <= 14; $c++)
                                    <option value="{{ $c }}" {{ $century == $c ? 'selected' : '' }}>
                                        سده {{ $c }} هجری
                                    </option>
                                @endfor
                            @endif
                        </select>
                    </div>

                    <!-- Codicological Flags -->
                    <div class="space-y-2">
                        <label class="block text-xs font-bold text-stone-600 dark:text-stone-300">ویژگی‌های کالبدشناسی</label>
                        <div class="space-y-2 text-xs text-stone-600 dark:text-stone-300">
                            <label class="flex items-center justify-between cursor-pointer">
                                <div class="flex items-center gap-2">
                                    <input type="radio" name="flag" value="" {{ empty($flag) ? 'checked' : '' }} class="text-[#B38A50]">
                                    <span>همه</span>
                                </div>
                            </label>
                            <label class="flex items-center justify-between cursor-pointer">
                                <div class="flex items-center gap-2">
                                    <input type="radio" name="flag" value="is_autograph" {{ $flag === 'is_autograph' ? 'checked' : '' }} class="text-[#B38A50]">
                                    <span>اصل نسخه (دستخط مؤلف)</span>
                                </div>
                                @if(!empty($flagCounts['is_autograph']))
                                    <span class="text-[10px] text-[#B38A50] font-bold">({{ $flagCounts['is_autograph'] }})</span>
                                @endif
                            </label>
                            <label class="flex items-center justify-between cursor-pointer">
                                <div class="flex items-center gap-2">
                                    <input type="radio" name="flag" value="is_illuminated" {{ $flag === 'is_illuminated' ? 'checked' : '' }} class="text-[#B38A50]">
                                    <span>دارای تذهیب و سرلوح</span>
                                </div>
                                @if(!empty($flagCounts['is_illuminated']))
                                    <span class="text-[10px] text-[#B38A50] font-bold">({{ $flagCounts['is_illuminated'] }})</span>
                                @endif
                            </label>
                            <label class="flex items-center justify-between cursor-pointer">
                                <div class="flex items-center gap-2">
                                    <input type="radio" name="flag" value="is_illustrated" {{ $flag === 'is_illustrated' ? 'checked' : '' }} class="text-[#B38A50]">
                                    <span>دارای نگاره و تصویر</span>
                                </div>
                                @if(!empty($flagCounts['is_illustrated']))
                                    <span class="text-[10px] text-[#B38A50] font-bold">({{ $flagCounts['is_illustrated'] }})</span>
                                @endif
                            </label>
                            <label class="flex items-center justify-between cursor-pointer">
                                <div class="flex items-center gap-2">
                                    <input type="radio" name="flag" value="is_corrected" {{ $flag === 'is_corrected' ? 'checked' : '' }} class="text-[#B38A50]">
                                    <span>تصحیح‌شده</span>
                                </div>
                                @if(!empty($flagCounts['is_corrected']))
                                    <span class="text-[10px] text-[#B38A50] font-bold">({{ $flagCounts['is_corrected'] }})</span>
                                @endif
                            </label>
                            <label class="flex items-center justify-between cursor-pointer">
                                <div class="flex items-center gap-2">
                                    <input type="radio" name="flag" value="has_marginal_notes" {{ $flag === 'has_marginal_notes' ? 'checked' : '' }} class="text-[#B38A50]">
                                    <span>دارای حواشی</span>
                                </div>
                                @if(!empty($flagCounts['has_marginal_notes']))
                                    <span class="text-[10px] text-[#B38A50] font-bold">({{ $flagCounts['has_marginal_notes'] }})</span>
                                @endif
                            </label>
                            <label class="flex items-center justify-between cursor-pointer">
                                <div class="flex items-center gap-2">
                                    <input type="radio" name="flag" value="is_collated" {{ $flag === 'is_collated' ? 'checked' : '' }} class="text-[#B38A50]">
                                    <span>مقابله‌شده</span>
                                </div>
                                @if(!empty($flagCounts['is_collated']))
                                    <span class="text-[10px] text-[#B38A50] font-bold">({{ $flagCounts['is_collated'] }})</span>
                                @endif
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
                                        <a href="{{ route('works.show', $work) }}" class="text-lg font-bold text-[#292C56] dark:text-amber-100 hover:text-[#B38A50] transition">
                                            {{ $work->primary_title }}
                                        </a>
                                        @if($work->clean_title && $work->clean_title !== $work->primary_title)
                                            <span class="text-xs text-stone-400 mr-2">({{ $work->clean_title }})</span>
                                        @endif
                                        
                                        <div class="text-xs text-stone-600 dark:text-stone-300 mt-1 flex flex-wrap items-center gap-x-4 gap-y-1">
                                            @if($work->author)
                                                <span>پدیدآور: <a href="{{ route('people.show', $work->author) }}" class="font-semibold text-stone-800 dark:text-stone-200 hover:underline">{{ $work->author->name }}</a></span>
                                            @elseif($work->author_name)
                                                <span>پدیدآور: <strong class="text-stone-700 dark:text-stone-300">{{ $work->author_name }}</strong></span>
                                            @else
                                                <span class="text-stone-400">پدیدآور: ناشناخته</span>
                                            @endif

                                            @if($work->composition_year_hijri)
                                                <span>تألیف: <strong>{{ $work->composition_year_hijri }} هـ.ق</strong></span>
                                            @elseif($work->author?->death_year_hijri)
                                                <span>وفات مؤلف: <strong>{{ $work->author->death_year_hijri }} هـ.ق</strong></span>
                                            @elseif($work->author?->death_century_hijri)
                                                <span>وفات مؤلف: <strong>قرن {{ $work->author->death_century_hijri }} هـ.ق</strong></span>
                                            @elseif($work->author?->death_date_raw)
                                                <span>وفات مؤلف: <strong>{{ $work->author->death_date_raw }}</strong></span>
                                            @endif

                                            @if($work->work_form)
                                                <span>قالب: <strong>{{ $work->work_form }}</strong></span>
                                            @endif

                                            @if($work->composition_place)
                                                <span>مکان تألیف: <strong>{{ $work->composition_place }}</strong></span>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="flex flex-col items-end gap-1.5 shrink-0 text-left">
                                        <span class="px-3 py-1 rounded-full bg-amber-50 dark:bg-amber-950/40 text-[#B38A50] text-xs font-bold shadow-xs">
                                            {{ number_format($work->manuscripts_count) }} نسخه
                                        </span>
                                        @if($work->has_autograph)
                                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-emerald-50 dark:bg-emerald-950/50 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800/80 text-[11px] font-bold shadow-xs" title="دارای نسخه به دستخط مؤلف">
                                                <svg class="w-3.5 h-3.5 text-emerald-600 dark:text-emerald-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                                </svg>
                                                <span>دستخط مؤلف</span>
                                            </span>
                                        @endif
                                        @if(!empty($work->copy_century_text))
                                            <span class="text-[11px] text-stone-500 dark:text-stone-400 font-medium whitespace-nowrap">
                                                {{ $work->copy_century_text }}
                                            </span>
                                        @endif
                                    </div>
                                </div>

                                <!-- Subjects & Languages -->
                                <div class="flex flex-wrap items-center gap-1.5 pt-2 border-t border-stone-100 dark:border-stone-800/80 text-[11px]">
                                    @foreach($work->subjects as $subj)
                                        <span class="px-2 py-0.5 rounded bg-stone-100 dark:bg-stone-800 text-stone-600 dark:text-stone-400">{{ $subj->title }}</span>
                                    @endforeach
                                    @foreach($work->languages as $lang)
                                        <span class="px-2 py-0.5 rounded bg-stone-100 dark:bg-stone-800 text-stone-500">{{ $lang->name }}</span>
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
                                            <a href="{{ route('manuscripts.show', $ms) }}" class="text-base font-bold text-[#292C56] dark:text-amber-100 hover:text-[#B38A50] transition">
                                                {{ $ms->work?->primary_title ?? 'نسخه بدون عنوان' }}
                                            </a>
                                            @if($ms->is_autograph)
                                                <span class="px-2 py-0.5 rounded-full bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300 text-[10px] font-bold">اصل نسخه (دستخط مؤلف)</span>
                                            @endif
                                        </div>

                                        <div class="text-xs text-stone-600 dark:text-stone-300 mt-1 flex flex-wrap items-center gap-x-4 gap-y-1">
                                            <span>کتابخانه: <strong>{{ $ms->libraryRecord?->name ?? $ms->library ?? 'نامشخص' }}</strong> ({{ $ms->city }})</span>
                                            <span>شماره بازیابی: <strong class="text-[#B38A50]">{{ $ms->shelfmark ?? 'بی‌شماره' }}</strong></span>
                                            @if($ms->scribe_name)
                                                <span>کاتب: <strong>{{ $ms->scribe_name }}</strong></span>
                                            @elseif($ms->is_autograph)
                                                <span>کاتب: <strong class="text-emerald-700 dark:text-emerald-300">مؤلف</strong></span>
                                            @elseif($ms->is_bika)
                                                <span class="text-stone-400">بی‌کاتب</span>
                                            @endif
                                            @if($ms->copy_date_raw)
                                                <span>تاریخ: <strong>{{ $ms->copy_date_raw }}</strong></span>
                                            @elseif($ms->is_bita)
                                                <span class="text-stone-400">بی‌تاریخ</span>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="flex flex-col items-end gap-1.5 shrink-0 text-left">
                                        @if($ms->is_autograph)
                                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-emerald-50 dark:bg-emerald-950/50 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800/80 text-[11px] font-bold shadow-xs">
                                                <svg class="w-3.5 h-3.5 text-emerald-600 dark:text-emerald-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                                </svg>
                                                <span>دستخط مؤلف</span>
                                            </span>
                                        @endif
                                        @if($ms->century_text)
                                            <span class="px-2.5 py-0.5 rounded-full bg-amber-50 dark:bg-amber-950/40 text-[#B38A50] text-[11px] font-medium shadow-xs">
                                                {{ $ms->century_text }}
                                            </span>
                                        @endif
                                        @if($ms->catalog_citation)
                                            <span class="text-[11px] text-stone-500 dark:text-stone-400" title="شماره و ارجاع در فهرست اصلی کتابخانه">
                                                {{ $ms->catalog_citation }}
                                            </span>
                                        @endif
                                    </div>
                                </div>

                                @if(!empty($ms->incipit))
                                    <div class="text-[11px] text-stone-600 dark:text-stone-300 line-clamp-1 bg-amber-50/50 dark:bg-amber-950/20 px-3 py-1.5 rounded-xl border-r-2 border-[#B38A50]">
                                        @if($ms->incipit_matches_work)
                                            <span class="text-[#B38A50] font-bold">آغاز (برابر با اثر):</span> « {{ Str::limit(trim($ms->incipit, "«» \t\n\r\0\x0B"), 110) }} »
                                        @else
                                            <span class="text-[#B38A50] font-bold">آغاز نسخه:</span> « {{ Str::limit(trim($ms->incipit, "«» \t\n\r\0\x0B"), 110) }} »
                                        @endif
                                    </div>
                                @endif

                                <!-- Codicological Badges -->
                                <div class="flex flex-wrap items-center gap-1.5 pt-2 border-t border-stone-100 dark:border-stone-800/80 text-[11px]">
                                    @if($ms->script_names)
                                        <span class="px-2 py-0.5 rounded bg-amber-50 dark:bg-amber-950/40 text-[#B38A50] font-medium">خط: {{ $ms->script_names }}</span>
                                    @endif
                                    @if($ms->folios)
                                        <span class="px-2 py-0.5 rounded bg-stone-100 dark:bg-stone-800 text-stone-600 dark:text-stone-400">{{ $ms->folios }} برگ</span>
                                    @endif
                                    @if($ms->is_autograph)
                                        <span class="px-2 py-0.5 rounded bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 font-semibold">نسخه اصل مؤلف</span>
                                    @endif
                                    @if($ms->has_author_marginalia)
                                        <span class="px-2 py-0.5 rounded bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 font-semibold">حواشی مؤلف</span>
                                    @endif
                                    @if(!empty($ms->ownership_and_seals_list) && count($ms->ownership_and_seals_list) > 0)
                                        <span class="px-2 py-0.5 rounded bg-amber-50 dark:bg-amber-950/40 text-amber-800 dark:text-amber-200 font-medium">
                                            دارای مهر / تملک ({{ count($ms->ownership_and_seals_list) }})
                                        </span>
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
                            <a href="{{ route('people.show', $person) }}" class="bg-white dark:bg-[#15192C] p-4 rounded-2xl border border-stone-200 dark:border-stone-800 hover:border-[#B38A50] dark:hover:border-[#B38A50] shadow-sm hover:shadow-md transition flex flex-col justify-between">
                                <div>
                                    <div class="text-base font-bold text-[#292C56] dark:text-amber-100 hover:text-[#B38A50] transition">
                                        {{ $person->name }}
                                    </div>
                                    @if($person->transliteration)
                                        <div class="text-[11px] text-stone-400">{{ $person->transliteration }}</div>
                                    @endif
                                    <div class="text-xs text-stone-500 mt-2">
                                        @if($person->death_year_hijri)
                                            وفات: <strong>{{ $person->death_year_hijri }} هـ.ق</strong>
                                        @elseif($person->century_hijri)
                                            سده: <strong>{{ $person->century_hijri }} هـ.ق</strong>
                                        @endif
                                    </div>
                                </div>
                                <div class="mt-4 pt-3 border-t border-stone-100 dark:border-stone-800 flex items-center justify-between text-xs">
                                    <span class="text-stone-400">تألیفات ثبت‌شده:</span>
                                    <span class="font-bold text-[#B38A50]">{{ $person->works_count }} اثر</span>
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

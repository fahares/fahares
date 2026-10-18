@extends('layouts.app')

@section('title', 'کاوش و جستجوی پیشرفته | فهارس')

@section('content')
@php use App\Support\Fa; @endphp
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">

    <!-- Search Header & Mode Bar -->
    <div class="bg-surface p-6 rounded-sm border border-line space-y-4">
        
        <form action="{{ route('search') }}" method="GET" class="space-y-4">
            <input type="hidden" name="type" id="search-type-input" value="{{ $type }}">

            <!-- Search bar -->
            <div class="relative flex items-center">
                <input 
                    type="text" 
                    name="q" 
                    value="{{ $query }}" 
                    placeholder="عبارت مورد نظر برای کاوش..."
                    class="w-full pr-12 pl-28 py-3.5 bg-paper text-ink placeholder:text-muted rounded-sm border border-line-strong focus:border-cinnabar focus:ring-4 focus:ring-cinnabar/15 text-base transition">
                
                <div class="absolute right-4 text-muted">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                </div>

                <button 
                    type="submit" 
                    class="absolute left-2 px-5 py-2 bg-cinnabar hover:opacity-90 text-on-cinnabar font-semibold rounded-sm text-sm transition shadow">
                    جستجو
                </button>
            </div>

            <!-- Search Controls: Scope Radios & Type Tabs -->
            <div class="flex flex-wrap items-center justify-between gap-4 pt-3 border-t border-line text-xs">
                
                <!-- Scope Radio Buttons -->
                <div class="flex flex-wrap items-center gap-4 text-ink/80">
                    <span class="font-bold text-muted">جست‌وجوی عبارت:</span>
                    <label class="inline-flex items-center gap-1.5 cursor-pointer hover:text-cinnabar transition">
                        <input type="radio" name="scope" value="titles" {{ $scope === 'titles' ? 'checked' : '' }} onchange="this.form.submit()" class="text-cinnabar focus:ring-cinnabar">
                        <span>فقط در عناوین</span>
                    </label>
                    <label class="inline-flex items-center gap-1.5 cursor-pointer hover:text-cinnabar transition">
                        <input type="radio" name="scope" value="titles_names" {{ $scope === 'titles_names' ? 'checked' : '' }} onchange="this.form.submit()" class="text-cinnabar focus:ring-cinnabar">
                        <span class="font-bold">فقط در عناوین و اعلام</span>
                    </label>
                    <label class="inline-flex items-center gap-1.5 cursor-pointer hover:text-cinnabar transition">
                        <input type="radio" name="scope" value="all" {{ $scope === 'all' ? 'checked' : '' }} onchange="this.form.submit()" class="text-cinnabar focus:ring-cinnabar">
                        <span>در همۀ اطلاعات</span>
                    </label>
                </div>

                <!-- Type Tabs -->
                <div class="flex items-center gap-2">
                    <span class="text-xs text-muted font-medium">دامنه:</span>
                    <button type="submit" onclick="document.getElementById('search-type-input').value='works'" 
                        class="px-3 py-1 rounded-sm font-medium transition {{ $type === 'works' ? 'bg-ink text-paper' : 'text-muted hover:text-ink' }}">
                        آثار و عناوین@isset($tabCounts['works']) <span class="text-[10px] opacity-75">({{ Fa::num($tabCounts['works']) }})</span>@endisset
                    </button>
                    <button type="submit" onclick="document.getElementById('search-type-input').value='manuscripts'" 
                        class="px-3 py-1 rounded-sm font-medium transition {{ $type === 'manuscripts' ? 'bg-ink text-paper' : 'text-muted hover:text-ink' }}">
                        نسخه‌های خطی@isset($tabCounts['manuscripts']) <span class="text-[10px] opacity-75">({{ Fa::num($tabCounts['manuscripts']) }})</span>@endisset
                    </button>
                    <button type="submit" onclick="document.getElementById('search-type-input').value='people'" 
                        class="px-3 py-1 rounded-sm font-medium transition {{ $type === 'people' ? 'bg-ink text-paper' : 'text-muted hover:text-ink' }}">
                        پدیدآوران و کاتبان@isset($tabCounts['people']) <span class="text-[10px] opacity-75">({{ Fa::num($tabCounts['people']) }})</span>@endisset
                    </button>
                </div>

                @if($results)
                    <div class="text-xs text-muted font-medium">
                        {{ Fa::num($results->total()) }} نتیجه
                    </div>
                @endif
            </div>

            <!-- Active Filters Reset -->
            @if($subjectId || $libraryId || $scriptId || $century || !empty($flags) || $languageId || $workForm || $manuscriptsRange)
                <div class="flex flex-wrap items-center gap-2 pt-2 text-xs">
                    <span class="text-muted">فیلترهای فعال:</span>

                    @if($selectedLibrary)
                        <a href="{{ route('search', array_merge(request()->except('library_id'), ['page' => 1])) }}" 
                           class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-sm bg-cinnabar/5 text-cinnabar border border-cinnabar/30 hover:bg-cinnabar/10 transition">
                            <span>کتابخانه: <strong title="{{ $selectedLibrary->display_name }}">{{ $selectedLibrary->short_name }}</strong></span>
                            <span class="text-red-500 font-bold hover:scale-125 transition">✕</span>
                        </a>
                    @endif

                    @if($selectedScript)
                        <a href="{{ route('search', array_merge(request()->except('script_id'), ['page' => 1])) }}" 
                           class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-sm bg-cinnabar/5 text-cinnabar border border-cinnabar/30 hover:bg-cinnabar/10 transition">
                            <span>خط: <strong>{{ $selectedScript->name }}</strong></span>
                            <span class="text-red-500 font-bold hover:scale-125 transition">✕</span>
                        </a>
                    @endif

                    @if($century)
                        <a href="{{ route('search', array_merge(request()->except('century'), ['page' => 1])) }}" 
                           class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-sm bg-cinnabar/5 text-cinnabar border border-cinnabar/30 hover:bg-cinnabar/10 transition">
                            <span>سده: <strong>{{ $century }} هجری</strong></span>
                            <span class="text-red-500 font-bold hover:scale-125 transition">✕</span>
                        </a>
                    @endif

                    @if($selectedSubject)
                        <a href="{{ route('search', array_merge(request()->except('subject_id'), ['page' => 1])) }}" 
                           class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-sm bg-cinnabar/5 text-cinnabar border border-cinnabar/30 hover:bg-cinnabar/10 transition">
                            <span>موضوع: <strong>{{ $selectedSubject->title }}</strong>@if($selectedSubject->children->isNotEmpty()) <span class="text-[10px] opacity-75 font-normal">(شامل {{ $selectedSubject->children->count() }} زیرموضوع)</span>@elseif($selectedSubject->parent) <span class="text-[10px] opacity-75 font-normal">(زیرمجموعه {{ $selectedSubject->parent->name }})</span>@endif</span>
                            <span class="text-red-500 font-bold hover:scale-125 transition">✕</span>
                        </a>
                    @endif

                    @if($selectedLanguage)
                        <a href="{{ route('search', array_merge(request()->except('language_id'), ['page' => 1])) }}" 
                           class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-sm bg-cinnabar/5 text-cinnabar border border-cinnabar/30 hover:bg-cinnabar/10 transition">
                            <span>زبان: <strong>{{ $selectedLanguage->name }}</strong></span>
                            <span class="text-red-500 font-bold hover:scale-125 transition">✕</span>
                        </a>
                    @endif

                    @if($workForm && isset($validWorkForms[$workForm]))
                        <a href="{{ route('search', array_merge(request()->except('work_form'), ['page' => 1])) }}" 
                           class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-sm bg-cinnabar/5 text-cinnabar border border-cinnabar/30 hover:bg-cinnabar/10 transition">
                            <span>قالب: <strong>{{ $validWorkForms[$workForm] }}</strong></span>
                            <span class="text-red-500 font-bold hover:scale-125 transition">✕</span>
                        </a>
                    @endif

                    @if($manuscriptsRange && isset($validManuscriptRanges[$manuscriptsRange]))
                        <a href="{{ route('search', array_merge(request()->except('manuscripts_range'), ['page' => 1])) }}" 
                           class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-sm bg-cinnabar/5 text-cinnabar border border-cinnabar/30 hover:bg-cinnabar/10 transition">
                            <span>فراوانی نسخ: <strong>{{ $validManuscriptRanges[$manuscriptsRange] }}</strong></span>
                            <span class="text-red-500 font-bold hover:scale-125 transition">✕</span>
                        </a>
                    @endif

                    @if(!empty($flags))
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
                        @foreach($flags as $curFlag)
                            @php
                                $remainingFlags = array_values(array_diff($flags, [$curFlag]));
                                $newQuery = request()->except(['flags', 'flag', 'page']);
                                if (!empty($remainingFlags)) {
                                    $newQuery['flags'] = $remainingFlags;
                                }
                                $newQuery['page'] = 1;
                            @endphp
                            <a href="{{ route('search', $newQuery) }}" 
                               class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-sm bg-cinnabar/5 text-cinnabar border border-cinnabar/30 hover:bg-cinnabar/10 transition">
                                <span>ویژگی: <strong>{{ $flagLabels[$curFlag] ?? $curFlag }}</strong></span>
                                <span class="text-red-500 font-bold hover:scale-125 transition">✕</span>
                            </a>
                        @endforeach
                    @endif

                    <a href="{{ route('search', ['q' => $query, 'type' => $type, 'scope' => $scope]) }}" class="px-2.5 py-1 rounded-sm bg-red-50 dark:bg-red-950/40 text-red-600 dark:text-red-300 hover:underline">
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
            
            <form action="{{ route('search') }}" method="GET" class="bg-surface p-5 rounded-sm border border-line shadow-sm space-y-6 text-sm">
                <input type="hidden" name="q" value="{{ $query }}">
                <input type="hidden" name="type" value="{{ $type }}">
                <input type="hidden" name="scope" value="{{ $scope }}">

                <div class="flex items-center justify-between pb-3 border-b border-line">
                    <span class="rubric text-base">پالایش نتایج</span>
                    <button type="submit" class="text-xs text-cinnabar font-semibold hover:underline">اعمال فیلتر</button>
                </div>

                @if($type === 'works')
                    <!-- Subject Filter (Searchable Combobox) -->
                    @php
                        $subjectsJson = $subjects->map(function($s) {
                            return [
                                'id' => (int) $s->id,
                                'name' => (string) ($s->title ?: $s->name),
                                'count' => (int) ($s->matching_count ?? $s->works_count ?? 0),
                                'parent_id' => $s->parent_id,
                                'parent_name' => $s->parent ? $s->parent->name : null,
                            ];
                        })->values();
                    @endphp

                    <script>
                    function subjectCombobox(config) {
                        return {
                            open: false,
                            search: '',
                            selectedId: config.selectedId || '',
                            highlightedIndex: -1,
                            items: config.items || [],
                            get selectedItem() {
                                return this.items.find(i => String(i.id) === String(this.selectedId)) || null;
                            },
                            get filteredItems() {
                                const q = this.normalize(this.search);
                                if (!q) return this.items;
                                return this.items.filter(i => this.normalize(i.name).includes(q) || (i.parent_name && this.normalize(i.parent_name).includes(q)));
                            },
                            normalize(text) {
                                if (!text) return '';
                                return text
                                    .toString()
                                    .toLowerCase()
                                    .replace(/[\u064B-\u065F\u0670]/g, '')
                                    .replace(/[يى]/g, 'ی')
                                    .replace(/[ك]/g, 'ک')
                                    .replace(/[\u200c\s]+/g, ' ')
                                    .trim();
                            },
                            openDropdown() {
                                this.open = true;
                                this.search = '';
                                if (this.selectedId) {
                                    const idx = this.items.findIndex(i => String(i.id) === String(this.selectedId));
                                    this.highlightedIndex = idx !== -1 ? idx : -1;
                                } else {
                                    this.highlightedIndex = -1;
                                }
                                this.$nextTick(() => {
                                    if (this.$refs.subjectSearchInput) this.$refs.subjectSearchInput.focus();
                                    this.scrollToHighlighted();
                                });
                            },
                            onSearchChange() {
                                this.highlightedIndex = this.filteredItems.length > 0 ? 0 : -1;
                                this.scrollToHighlighted();
                            },
                            onArrowDown() {
                                if (!this.open) {
                                    this.openDropdown();
                                    return;
                                }
                                const maxIdx = this.filteredItems.length - 1;
                                if (this.highlightedIndex < maxIdx) {
                                    this.highlightedIndex++;
                                    this.scrollToHighlighted();
                                }
                            },
                            onArrowUp() {
                                if (!this.open) {
                                    this.openDropdown();
                                    return;
                                }
                                const minIdx = this.search.trim() ? 0 : -1;
                                if (this.highlightedIndex > minIdx) {
                                    this.highlightedIndex--;
                                    this.scrollToHighlighted();
                                }
                            },
                            onEnter() {
                                if (!this.open) {
                                    this.openDropdown();
                                    return;
                                }
                                if (this.highlightedIndex >= 0 && this.highlightedIndex < this.filteredItems.length) {
                                    this.select(this.filteredItems[this.highlightedIndex].id);
                                } else if (this.highlightedIndex === -1 && !this.search.trim()) {
                                    this.select('');
                                } else if (this.filteredItems.length > 0) {
                                    this.select(this.filteredItems[0].id);
                                }
                            },
                            scrollToHighlighted() {
                                this.$nextTick(() => {
                                    if (!this.$refs.optionsList) return;
                                    const target = this.$refs.optionsList.querySelector('[data-highlighted="true"]');
                                    if (target) {
                                        target.scrollIntoView({ block: 'nearest' });
                                    }
                                });
                            },
                            select(id) {
                                this.selectedId = id ? String(id) : '';
                                this.open = false;
                                this.search = '';
                                this.highlightedIndex = -1;
                                this.$nextTick(() => {
                                    if (this.$refs.triggerButton) this.$refs.triggerButton.focus();
                                });
                            },
                            clear() {
                                this.selectedId = '';
                                this.search = '';
                                this.highlightedIndex = -1;
                            }
                        };
                    }
                    </script>

                    <div x-data="subjectCombobox({ selectedId: '{{ $subjectId ?? '' }}', items: {{ \Illuminate\Support\Js::from($subjectsJson) }} })"
                         class="relative space-y-2"
                         @click.outside="open = false"
                         @keydown.escape.window="open = false">
                        
                        <div class="flex items-center justify-between">
                            <label class="block text-xs font-bold text-ink/80">موضوع اثر</label>
                            @if($subjects->isNotEmpty())
                                <span class="text-[10px] text-cinnabar font-semibold">{{ $subjects->count() }} موضوع</span>
                            @endif
                        </div>

                        <input type="hidden" name="subject_id" :value="selectedId">

                        <div class="relative">
                            <!-- Trigger Button -->
                            <button type="button" 
                                    x-ref="triggerButton"
                                    @click="open ? (open = false) : openDropdown()"
                                    @keydown.arrow-down.prevent="openDropdown()"
                                    @keydown.enter.prevent="openDropdown()"
                                    class="w-full text-xs p-2.5 rounded-sm border border-line-strong bg-paper text-ink flex items-center justify-between gap-2 hover:border-[#B38A50] focus:outline-none focus:ring-1 focus:ring-cinnabar transition text-right">
                                <span class="truncate" x-text="selectedItem ? selectedItem.name + (selectedItem.count ? ' (' + Number(selectedItem.count).toLocaleString('fa-IR') + ')' : '') : 'همه موضوعات'">
                                    @if($selectedSubject)
                                        {{ $selectedSubject->title }} @if($selectedSubject->matching_count) ({{ number_format($selectedSubject->matching_count) }}) @elseif($selectedSubject->works_count) ({{ number_format($selectedSubject->works_count) }}) @endif
                                    @else
                                        همه موضوعات
                                    @endif
                                </span>
                                <div class="flex items-center gap-1.5 shrink-0 text-muted">
                                    <template x-if="selectedId">
                                        <span @click.stop="clear()" class="hover:text-red-500 cursor-pointer p-0.5 rounded-md hover:bg-stone-200 dark:hover:bg-stone-700 transition" title="پاک کردن انتخاب">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </span>
                                    </template>
                                    <svg class="w-4 h-4 transition-transform duration-200" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                    </svg>
                                </div>
                            </button>

                            <!-- Dropdown Box -->
                            <div x-show="open" 
                                 x-transition:enter="transition ease-out duration-100"
                                 x-transition:enter-start="transform opacity-0 scale-95"
                                 x-transition:enter-end="transform opacity-100 scale-100"
                                 x-transition:leave="transition ease-in duration-75"
                                 x-transition:leave-start="transform opacity-100 scale-100"
                                 x-transition:leave-end="transform opacity-0 scale-95"
                                 class="absolute z-30 mt-1.5 w-full bg-surface border border-line rounded-sm shadow-2xl p-2.5 space-y-2 backdrop-blur-sm"
                                 style="display: none;">
                                 
                                <!-- Search Input inside Dropdown -->
                                <div class="relative">
                                    <input x-ref="subjectSearchInput"
                                           type="text" 
                                           x-model="search" 
                                           @input="onSearchChange()"
                                           @keydown.arrow-down.prevent="onArrowDown()"
                                           @keydown.arrow-up.prevent="onArrowUp()"
                                           @keydown.enter.prevent="onEnter()"
                                           @keydown.escape.prevent="open = false"
                                           placeholder="جستجوی موضوع (مثلاً فقه، فلسفه...)" 
                                           class="w-full text-xs p-2 pr-8 pl-7 rounded-sm border border-line-strong bg-paper/90 text-ink focus:outline-none focus:border-[#B38A50] focus:ring-1 focus:ring-cinnabar transition">
                                    <svg class="w-3.5 h-3.5 text-muted absolute right-2.5 top-2.5 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                    </svg>
                                    <template x-if="search">
                                        <button type="button" @click="search = ''; onSearchChange();" class="absolute left-2.5 top-2.5 text-muted hover:text-stone-600 dark:hover:text-stone-200">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </template>
                                </div>

                                <!-- Options List -->
                                <div x-ref="optionsList" class="max-h-60 overflow-y-auto space-y-0.5 custom-scrollbar text-xs py-1">
                                    <!-- Option: All Subjects -->
                                    <button type="button" 
                                            tabindex="-1"
                                            x-show="!search.trim()"
                                            :data-highlighted="highlightedIndex === -1"
                                            @click="select('')"
                                            @mouseenter="highlightedIndex = -1"
                                            class="w-full text-right px-2.5 py-1.5 rounded-sm flex items-center justify-between transition"
                                            :class="{
                                                'bg-[#B38A50]/20 text-cinnabar font-bold ring-1 ring-[#B38A50]/30': !selectedId,
                                                'bg-stone-200/80 dark:bg-stone-700/70 text-stone-900 dark:text-stone-100': highlightedIndex === -1 && selectedId,
                                                'hover:bg-stone-100 dark:hover:bg-stone-800/80': highlightedIndex !== -1 && selectedId
                                            }">
                                        <span>همه موضوعات</span>
                                        <span class="text-[10px] text-muted font-normal">({{ $subjects->count() }})</span>
                                    </button>

                                    <!-- Filtered Options -->
                                    <template x-for="(item, index) in filteredItems" :key="item.id">
                                        <button type="button" 
                                                tabindex="-1"
                                                :data-highlighted="highlightedIndex === index"
                                                @click="select(item.id)"
                                                @mouseenter="highlightedIndex = index"
                                                class="w-full text-right px-2.5 py-1.5 rounded-sm flex items-center justify-between gap-2 transition"
                                                :class="{
                                                    'bg-[#B38A50]/20 text-cinnabar font-bold ring-1 ring-[#B38A50]/30': selectedId == item.id,
                                                    'bg-stone-200/80 dark:bg-stone-700/70 text-stone-900 dark:text-stone-100': highlightedIndex === index && selectedId != item.id,
                                                    'hover:bg-stone-100 dark:hover:bg-stone-800/80': highlightedIndex !== index && selectedId != item.id
                                                }">
                                            <div class="flex items-center gap-1.5 min-w-0">
                                                <span class="truncate" x-text="item.name"></span>
                                                <template x-if="item.parent_name">
                                                    <span class="text-[9px] px-1.5 py-0.5 rounded bg-stone-200/70 dark:bg-stone-700/70 text-muted dark:text-muted shrink-0 font-normal" x-text="'زیرمجموعه ' + item.parent_name"></span>
                                                </template>
                                            </div>
                                            <span class="text-[10px] font-mono shrink-0" 
                                                  :class="selectedId == item.id ? 'text-cinnabar' : (highlightedIndex === index ? 'text-ink/80' : 'text-muted dark:text-muted')"
                                                  x-show="item.count" 
                                                  x-text="'(' + Number(item.count).toLocaleString('fa-IR') + ')'"></span>
                                        </button>
                                    </template>

                                    <!-- Empty search notice -->
                                    <div x-show="filteredItems.length === 0" class="text-center py-4 text-xs text-muted">
                                        موضوعی با این عبارت یافت نشد
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Language Filter -->
                    <div class="space-y-2">
                        <div class="flex items-center justify-between">
                            <label class="block text-xs font-bold text-ink/80">زبان اثر</label>
                            @if($languages->isNotEmpty())
                                <span class="text-[10px] text-cinnabar font-semibold">{{ $languages->count() }} زبان</span>
                            @endif
                        </div>
                        <select name="language_id" class="w-full text-xs p-2.5 rounded-sm border border-line-strong bg-paper text-ink">
                            <option value="">همه زبان‌ها</option>
                            @foreach($languages as $lang)
                                <option value="{{ $lang->id }}" {{ $languageId == $lang->id ? 'selected' : '' }}>
                                    {{ $lang->name }} @if(isset($lang->matching_count)) ({{ number_format($lang->matching_count) }} اثر) @elseif($lang->works_count) ({{ number_format($lang->works_count) }} اثر) @endif
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Century Filter -->
                    <div class="space-y-2">
                        <label class="block text-xs font-bold text-ink/80">قرن تألیف (هجری قمری)</label>
                        <select name="century" class="w-full text-xs p-2.5 rounded-sm border border-line-strong bg-paper text-ink">
                            <option value="">همه قرون</option>
                            @if(!empty($centuries))
                                @foreach($centuries as $c => $cCount)
                                    <option value="{{ $c }}" {{ $century == $c ? 'selected' : '' }}>
                                        سده {{ $c }} هجری @if($cCount) ({{ number_format($cCount) }} اثر) @endif
                                    </option>
                                @endforeach
                            @else
                                @for($c = 3; $c <= 14; $c++)
                                    <option value="{{ $c }}" {{ $century == $c ? 'selected' : '' }}>
                                        سده {{ $c }} هجری
                                    </option>
                                @endfor
                            @endif
                        </select>
                    </div>

                    <!-- Work Form Filter -->
                    <div class="space-y-2">
                        <label class="block text-xs font-bold text-ink/80">قالب / ساختار اثر</label>
                        <select name="work_form" class="w-full text-xs p-2.5 rounded-sm border border-line-strong bg-paper text-ink">
                            <option value="">همه قالب‌ها</option>
                            @foreach($validWorkForms as $fKey => $fLabel)
                                <option value="{{ $fKey }}" {{ $workForm == $fKey ? 'selected' : '' }}>
                                    {{ $fLabel }} @if(!empty($workFormCounts[$fKey])) ({{ number_format($workFormCounts[$fKey]) }}) @endif
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Manuscript Availability Filter -->
                    <div class="space-y-2">
                        <label class="block text-xs font-bold text-ink/80">فراوانی نسخ خطی</label>
                        <select name="manuscripts_range" class="w-full text-xs p-2.5 rounded-sm border border-line-strong bg-paper text-ink">
                            <option value="">همه آثار</option>
                            @foreach($validManuscriptRanges as $rKey => $rLabel)
                                <option value="{{ $rKey }}" {{ $manuscriptsRange == $rKey ? 'selected' : '' }}>
                                    {{ $rLabel }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif

                @if($type === 'manuscripts')
                    <!-- Library Filter -->
                    <div class="space-y-2">
                        <div class="flex items-center justify-between">
                            <label class="block text-xs font-bold text-ink/80">کتابخانه / مرکز اسناد</label>
                            @if($libraries->isNotEmpty())
                                <span class="text-[10px] text-cinnabar font-semibold">{{ $libraries->count() }} مرکز</span>
                            @endif
                        </div>
                        <select name="library_id" class="w-full text-xs p-2.5 rounded-sm border border-line-strong bg-paper text-ink">
                            <option value="">
                                همه کتابخانه‌ها {{ $totalFacetManuscripts > 0 ? '(' . number_format($totalFacetManuscripts) . ' نسخه)' : '' }}
                            </option>
                            @foreach($libraries as $lib)
                                <option value="{{ $lib->id }}" title="{{ $lib->display_name }}" {{ $libraryId == $lib->id ? 'selected' : '' }}>
                                    {{ $lib->short_name }} ({{ $lib->city }}) @if(isset($lib->matching_count)) — {{ number_format($lib->matching_count) }} نسخه @endif
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Script Type Filter -->
                    <div class="space-y-2">
                        <div class="flex items-center justify-between">
                            <label class="block text-xs font-bold text-ink/80">نوع خط</label>
                            @if($scripts->isNotEmpty())
                                <span class="text-[10px] text-muted font-medium">{{ $scripts->count() }} خط</span>
                            @endif
                        </div>
                        <select name="script_id" class="w-full text-xs p-2.5 rounded-sm border border-line-strong bg-paper text-ink">
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
                        <label class="block text-xs font-bold text-ink/80">قرن کتابت (هجری قمری)</label>
                        <select name="century" class="w-full text-xs p-2.5 rounded-sm border border-line-strong bg-paper text-ink">
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
                        <label class="block text-xs font-bold text-ink/80">ویژگی‌های کالبدشناسی</label>
                        <div class="space-y-2 text-xs text-ink/80">
                            @php
                                $flagOptions = [
                                    'is_autograph' => 'اصل نسخه (دستخط مؤلف)',
                                    'is_illuminated' => 'دارای تذهیب و سرلوح',
                                    'is_illustrated' => 'دارای نگاره و تصویر',
                                    'is_corrected' => 'تصحیح‌شده',
                                    'has_marginal_notes' => 'دارای حواشی',
                                    'is_collated' => 'مقابله‌شده',
                                ];
                            @endphp
                            @foreach($flagOptions as $fKey => $fLabel)
                                <label class="flex items-center justify-between cursor-pointer group hover:text-stone-900 dark:hover:text-white transition">
                                    <div class="flex items-center gap-2">
                                        <input type="checkbox" name="flags[]" value="{{ $fKey }}" {{ in_array($fKey, $flags ?? []) ? 'checked' : '' }} class="w-4 h-4 rounded text-cinnabar focus:ring-cinnabar border-stone-300 dark:border-stone-600 dark:bg-stone-700">
                                        <span>{{ $fLabel }}</span>
                                    </div>
                                    @if(!empty($flagCounts[$fKey]))
                                        <span class="text-[10px] text-cinnabar font-bold">({{ number_format($flagCounts[$fKey]) }})</span>
                                    @endif
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif

                <button type="submit" class="w-full py-2.5 bg-cinnabar hover:opacity-90 text-on-cinnabar font-semibold rounded-sm text-xs transition shadow">
                    اعمال فیلترها
                </button>
            </form>

        </aside>

        <!-- RESULTS CONTENT -->
        <div class="lg:col-span-3 space-y-4 min-w-0">

            @if($results && $results->count() > 0)

                <!-- Results bar with share button -->
                <div class="flex flex-wrap items-center justify-between gap-3 text-sm"
                     x-data="{
                         copied: false,
                         copyLink() {
                             const url = window.location.href;
                             if (navigator.clipboard && navigator.clipboard.writeText) {
                                 navigator.clipboard.writeText(url).then(() => { this.copied = true; setTimeout(() => this.copied = false, 2500); });
                             } else {
                                 const ta = document.createElement('textarea');
                                 ta.value = url; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
                                 this.copied = true; setTimeout(() => this.copied = false, 2500);
                             }
                         }
                     }">
                    <h2 class="rubric text-lg">
                        <span><span class="tabular-nums">{{ Fa::num($results->total()) }}</span> {{ ['works' => 'اثر', 'manuscripts' => 'نسخه', 'people' => 'شخص'][$type] ?? 'نتیجه' }}</span>
                        @if($query)<span class="font-sans text-sm font-normal text-muted">برای «<span class="text-ink font-semibold">{{ $query }}</span>»</span>@endif
                    </h2>
                    <x-ui.btn size="sm" @click="copyLink()">
                        <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/></svg>
                        <span x-show="!copied">پیوند این جستجو</span>
                        <span x-show="copied" x-cloak class="text-emerald-600">رونوشت شد</span>
                    </x-ui.btn>
                </div>

                @if($type === 'works')
                    <ul class="divide-y divide-line border-y border-line">
                        @foreach($results as $work)
                            <li class="py-4 space-y-1.5">
                                <div class="flex items-baseline gap-2">
                                    <a href="{{ route('works.show', $work) }}" class="font-naskh text-lg sm:text-xl font-bold text-ink hover:text-cinnabar transition leading-relaxed">@highlight($work->primary_title, $query)</a>
                                    <span class="leader hidden sm:block" aria-hidden="true"></span>
                                    <a href="{{ route('works.show', $work) }}#manuscripts" class="ms-auto sm:ms-0 text-xs text-muted tabular-nums whitespace-nowrap hover:text-cinnabar">{{ Fa::num($work->manuscripts_count) }} نسخه</a>
                                </div>
                                @if(($work->clean_title && $work->clean_title !== $work->primary_title) || ($work->match_reason ?? null))
                                    <div class="flex flex-wrap items-center gap-2 text-xs text-muted">
                                        @if($work->clean_title && $work->clean_title !== $work->primary_title)<span>@highlight($work->clean_title, $query)</span>@endif
                                        @if($work->match_reason ?? null)<x-ui.chip class="!text-[11px] !py-0">{{ $work->match_reason }}</x-ui.chip>@endif
                                    </div>
                                @endif
                                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[13px] text-ink/80">
                                    @if($work->author)
                                        <span>از <a href="{{ route('people.show', $work->author) }}" class="font-semibold text-ink hover:text-cinnabar">@highlight($work->author->name, $query)</a> <x-ui.life :person="$work->author" /></span>
                                    @elseif($work->author_name)
                                        <span>از <span class="font-semibold text-ink">@highlight($work->author_name, $query)</span></span>
                                    @else
                                        <span class="text-muted">پدیدآور ناشناخته</span>
                                    @endif
                                    @if($work->composition_year_hijri)<span>تألیف {{ Fa::digits($work->composition_year_hijri) }} ق</span>@endif
                                    @if($work->work_form)<span>{{ $validWorkForms[$work->work_form] ?? $work->work_form }}</span>@endif
                                    @if($work->composition_place)<span>{{ $work->composition_place }}</span>@endif
                                    @if(! empty($work->copy_century_text))<span class="text-muted">نسخه‌ها {{ Fa::digits($work->copy_century_text) }}</span>@endif
                                    @if($work->has_autograph)<x-ui.chip tone="good" class="!text-[11px] !py-0">دستخط مؤلف</x-ui.chip>@endif
                                </div>
                                @if($work->subjects->isNotEmpty() || $work->languages->isNotEmpty())
                                    <div class="text-xs text-muted">
                                        {{ $work->subjects->pluck('title')->merge($work->languages->pluck('name'))->implode(' · ') }}
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                @elseif($type === 'manuscripts')
                    <ul class="divide-y divide-line border-y border-line">
                        @foreach($results as $ms)
                            <li class="py-4 space-y-1.5">
                                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                    <a href="{{ route('manuscripts.show', $ms) }}" class="font-naskh text-lg font-bold text-ink hover:text-cinnabar transition leading-relaxed">@highlight($ms->work?->primary_title ?? 'نسخه‌ی بی‌عنوان', $query)</a>
                                    @if($ms->is_autograph)<x-ui.chip tone="good" class="!text-[11px] !py-0">دستخط مؤلف</x-ui.chip>@endif
                                    @if($ms->century_text)<span class="ms-auto text-xs text-muted whitespace-nowrap">{{ Fa::digits($ms->century_text) }}</span>@endif
                                </div>
                                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[13px] text-ink/80">
                                    @if($ms->work?->author)
                                        <span>از <a href="{{ route('people.show', $ms->work->author) }}" class="font-semibold text-ink hover:text-cinnabar">@highlight($ms->work->author->name, $query)</a></span>
                                    @elseif($ms->work?->author_name)
                                        <span>از <span class="font-semibold text-ink">@highlight($ms->work->author_name, $query)</span></span>
                                    @endif
                                    <span><span class="font-semibold text-ink" title="{{ $ms->libraryRecord?->display_name }}">{{ $ms->libraryRecord?->short_name ?? $ms->library ?? 'نامشخص' }}</span>@if($ms->city)، {{ $ms->city }}@endif</span>
                                    <span>شماره‌ی <span dir="ltr" class="font-semibold text-cinnabar">@highlight($ms->shelfmark ?? 'بی‌شماره', $query)</span></span>
                                    @if($ms->scribe_name)
                                        <span>کاتب: @highlight($ms->scribe_name, $query)</span>
                                    @elseif($ms->is_autograph)
                                        <span>کاتب: <span class="text-emerald-700 dark:text-emerald-300">مؤلف</span></span>
                                    @elseif($ms->is_bika)
                                        <span class="text-muted">بی‌کاتب</span>
                                    @endif
                                    @if($ms->copy_date_raw)
                                        <span>کتابت {{ Fa::digits($ms->copy_date_raw) }}</span>
                                    @elseif($ms->is_bita)
                                        <span class="text-muted">بی‌تاریخ</span>
                                    @endif
                                    @if($ms->commissioned_by)<span>به سفارش {{ $ms->commissioned_by }}</span>@endif
                                    @if($ms->catalog_citation)<span class="text-muted" title="شماره و ارجاع در فهرست اصلی کتابخانه">{{ $ms->catalog_citation }}</span>@endif
                                </div>
                                @if(! empty($ms->incipit))
                                    <p class="text-[13px] text-ink/80 line-clamp-1 border-s-2 border-gold ps-3 font-naskh">
                                        <span class="font-sans text-xs font-bold text-cinnabar">{{ $ms->incipit_matches_work ? 'آغاز (برابر با اثر):' : 'آغاز:' }}</span>
                                        @highlight(Str::limit(trim($ms->incipit, "«» \t\n\r\0\x0B"), 110), $query)
                                    </p>
                                @endif
                                @if(! empty($ms->explicit))
                                    <p class="text-[13px] text-ink/80 line-clamp-1 border-s-2 border-line-strong ps-3 font-naskh">
                                        <span class="font-sans text-xs font-bold text-muted">{{ $ms->explicit_matches_work ? 'انجام (برابر با اثر):' : 'انجام:' }}</span>
                                        @highlight(Str::limit(trim($ms->explicit, "«» \t\n\r\0\x0B"), 110), $query)
                                    </p>
                                @endif
                                @php
                                    $msTags = array_filter([
                                        $ms->script_names ? 'خط ' . $ms->script_names : null,
                                        $ms->folios ? Fa::digits($ms->folios) . ' برگ' : null,
                                        $ms->has_author_marginalia ? 'حواشی مؤلف' : null,
                                        (! empty($ms->ownership_and_seals_list) && count($ms->ownership_and_seals_list)) ? 'مهر و تملک (' . Fa::digits(count($ms->ownership_and_seals_list)) . ')' : null,
                                        $ms->is_illuminated ? 'مذهّب' : null,
                                        $ms->is_illustrated ? 'مصوّر' : null,
                                        $ms->is_corrected ? 'مصحَّح' : null,
                                        $ms->has_marginal_notes ? 'محشّی' : null,
                                    ]);
                                @endphp
                                @if($msTags)
                                    <div class="text-xs text-muted">{{ implode(' · ', $msTags) }}</div>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                @elseif($type === 'people')
                    <ul class="divide-y divide-line border-y border-line">
                        @foreach($results as $person)
                            <li>
                                <a href="{{ route('people.show', $person) }}" class="flex items-baseline gap-2 py-3 group">
                                    <span class="min-w-0">
                                        <span class="font-naskh text-lg font-bold text-ink group-hover:text-cinnabar transition">@highlight($person->name, $query)</span>
                                        <x-ui.life :person="$person" />
                                        @if($person->transliteration)<span class="block text-[11px] text-muted" dir="ltr">{{ $person->transliteration }}</span>@endif
                                    </span>
                                    <span class="leader" aria-hidden="true"></span>
                                    <span class="text-xs text-muted tabular-nums whitespace-nowrap">{{ Fa::num($person->works_count) }} اثر</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <div class="pt-6">
                    {{ $results->links() }}
                </div>

            @else
                <!-- No Results State -->
                <div class="py-12 text-center space-y-5">
                    <p class="font-naskh text-2xl font-bold text-ink">نتیجه‌ای پیدا نشد</p>

                    @php
                        $tabLabels = ['works' => 'اثر', 'manuscripts' => 'نسخه', 'people' => 'شخص'];
                        $elsewhere = collect($tabCounts ?? [])->except($type)->filter();
                    @endphp
                    @if($elsewhere->isNotEmpty())
                        <div class="flex flex-wrap justify-center gap-2 text-sm">
                            @foreach($elsewhere as $tab => $count)
                                <x-ui.btn :href="route('search', ['q' => $query, 'type' => $tab, 'scope' => $scope])">{{ Fa::num($count) }} {{ $tabLabels[$tab] }} با این عبارت پیدا شد ←</x-ui.btn>
                            @endforeach
                        </div>
                    @endif

                    @if(! empty($suggestion))
                        <p class="text-sm text-ink/80">
                            آیا منظور شما
                            <a href="{{ route('search', array_merge(request()->except(['page', 'q']), ['q' => $suggestion])) }}" class="font-bold text-cinnabar underline decoration-cinnabar/40 hover:decoration-cinnabar text-base">«{{ $suggestion }}»</a>
                            بود؟
                        </p>
                    @endif

                    <p class="text-sm text-muted max-w-md mx-auto leading-relaxed">املای واژه‌ها را بازبینی کنید، یا دامنه‌ی جستجو و پالایه‌ها را تغییر دهید.</p>
                    <x-ui.btn :href="route('search', ['type' => $type])">همه‌ی موارد این بخش</x-ui.btn>
                </div>
            @endif

        </div>

    </div>

</div>
@endsection

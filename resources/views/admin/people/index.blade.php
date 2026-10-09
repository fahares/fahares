@extends('layouts.admin')

@section('title', 'مدیریت اشخاص و اعلام')
@section('page_title', 'مدیریت پدیدآوران، مؤلفان و کاتبان (Authorities)')
@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-[#B38A50]">پیشخوان</a>
    <span>/</span>
    <span class="text-stone-400">اشخاص و اعلام</span>
@endsection

@section('content')
<div class="space-y-6">

    <!-- Action & Filter Bar -->
    <div class="flex flex-wrap items-center justify-between gap-4">
        
        <!-- Search & Filters Form -->
        <form action="{{ route('admin.people.index') }}" method="GET" class="flex flex-wrap items-center gap-2 max-w-2xl w-full">
            <div class="relative flex-1 min-w-[200px]">
                <input type="text" name="q" value="{{ $search }}" placeholder="جستجوی نام شخص، کنیه، لقب..." 
                       class="w-full pl-8 pr-4 py-2 rounded-xl border border-stone-300 dark:border-stone-700 bg-white dark:bg-[#15192C] text-xs focus:border-[#B38A50] outline-none">
                @if($search)
                    <a href="{{ route('admin.people.index', ['role' => $role, 'century' => $century]) }}" class="absolute left-2.5 top-1/2 -translate-y-1/2 text-stone-400 hover:text-stone-600 text-xs">✕</a>
                @endif
            </div>

            <!-- Role Filter -->
            <select name="role" class="py-2 px-3 rounded-xl border border-stone-300 dark:border-stone-700 bg-white dark:bg-[#15192C] text-xs font-semibold focus:border-[#B38A50] outline-none">
                <option value="">نقش: همه</option>
                <option value="author" {{ $role === 'author' ? 'selected' : '' }}>فقط مؤلفان</option>
                <option value="scribe" {{ $role === 'scribe' ? 'selected' : '' }}>فقط کاتبان</option>
            </select>

            <!-- Century Filter -->
            <select name="century" class="py-2 px-3 rounded-xl border border-stone-300 dark:border-stone-700 bg-white dark:bg-[#15192C] text-xs font-semibold focus:border-[#B38A50] outline-none">
                <option value="">قرن: همه</option>
                @for($c = 1; $c <= 15; $c++)
                    <option value="{{ $c }}" {{ $century == $c ? 'selected' : '' }}>قرن {{ $c }} هـ.ق</option>
                @endfor
            </select>

            <button type="submit" class="px-4 py-2 rounded-xl bg-[#292C56] text-amber-200 text-xs font-bold shrink-0">فیلتر</button>
        </form>

        <!-- Merge Tool Button -->
        <a href="{{ route('admin.people.merge') }}" 
           class="px-4 py-2 rounded-xl bg-amber-500/15 border border-amber-500/30 text-amber-900 dark:text-amber-200 hover:bg-amber-500/25 font-bold text-xs transition flex items-center gap-1.5 shadow-2xs">
            <svg class="w-4 h-4 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
            <span>ابزار ادغام اعلام تکراری</span>
        </a>

    </div>

    <!-- People Table -->
    <div class="bg-white dark:bg-[#15192C] rounded-2xl border border-stone-200 dark:border-stone-800 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-stone-50 dark:bg-stone-800/60 text-stone-500 border-b border-stone-100 dark:border-stone-800">
                    <tr>
                        <th class="p-4 font-bold">شناسه</th>
                        <th class="p-4 font-bold">نام و شهرت</th>
                        <th class="p-4 font-bold">سال‌های حیات / قرن</th>
                        <th class="p-4 font-bold">نقش‌ها</th>
                        <th class="p-4 font-bold">آثار تألیفی</th>
                        <th class="p-4 font-bold">نسخ کتابت‌شده</th>
                        <th class="p-4 font-bold text-center">عملیات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100 dark:divide-stone-800">
                    @forelse($people as $p)
                        <tr class="hover:bg-stone-50/50 dark:hover:bg-stone-800/40 transition">
                            <td class="p-4 font-mono font-bold text-stone-400">#{{ $p->id }}</td>
                            <td class="p-4">
                                <a href="{{ route('people.show', $p) }}" target="_blank" class="font-bold text-stone-900 dark:text-stone-100 hover:text-[#B38A50] inline-flex items-center gap-1">
                                    <span>{{ $p->name }}</span>
                                    <svg class="w-3 h-3 text-stone-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                </a>
                                @if($p->transliteration)
                                    <div class="text-[10px] text-stone-400 font-mono">{{ $p->transliteration }}</div>
                                @endif
                            </td>
                            <td class="p-4 text-stone-600 dark:text-stone-300">
                                @if($p->death_year_hijri)
                                    <span>وفات {{ $p->death_year_hijri }} هـ.ق</span>
                                @elseif($p->century_hijri)
                                    <span>قرن {{ $p->century_hijri }} هـ.ق</span>
                                @else
                                    <span class="text-stone-400">—</span>
                                @endif
                            </td>
                            <td class="p-4">
                                <div class="flex items-center gap-1">
                                    @if($p->is_author)
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-blue-500/10 text-blue-700 dark:text-blue-300">مؤلف</span>
                                    @endif
                                    @if($p->is_scribe)
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-500/10 text-amber-700 dark:text-amber-300">کاتب</span>
                                    @endif
                                </div>
                            </td>
                            <td class="p-4">
                                <span class="font-mono font-bold text-stone-800 dark:text-stone-200">
                                    {{ number_format($p->works_count) }}
                                </span>
                            </td>
                            <td class="p-4">
                                <span class="font-mono font-bold text-stone-800 dark:text-stone-200">
                                    {{ number_format($p->manuscripts_count) }}
                                </span>
                            </td>
                            <td class="p-4 text-center">
                                <div class="flex items-center justify-center gap-1.5">
                                    <a href="{{ route('admin.people.edit', $p) }}" 
                                       class="px-2.5 py-1.5 rounded-lg bg-stone-100 dark:bg-stone-800 hover:bg-[#B38A50] hover:text-white text-stone-700 dark:text-stone-200 font-bold transition">
                                        ویرایش
                                    </a>
                                    <a href="{{ route('admin.people.merge', ['source_id' => $p->id]) }}" 
                                       title="ادغام این شخص تکراری در شخص دیگر"
                                       class="px-2 py-1.5 rounded-lg bg-amber-500/10 hover:bg-amber-600 hover:text-white text-amber-700 dark:text-amber-300 font-bold transition flex items-center gap-1">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                                        <span>ادغام</span>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="p-12 text-center text-xs text-stone-400">
                                شخصی با این مشخصات یافت نشد.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($people->hasPages())
            <div class="p-4 border-t border-stone-100 dark:border-stone-800">
                {{ $people->links() }}
            </div>
        @endif
    </div>

</div>
@endsection

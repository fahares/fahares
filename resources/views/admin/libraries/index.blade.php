@extends('layouts.admin')

@section('title', 'مدیریت کتابخانه‌ها و آرشیوها')
@section('page_title', 'مدیریت کتابخانه‌ها و آرشیوهای نسخ خطی')
@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-[#B38A50]">پیشخوان</a>
    <span>/</span>
    <span class="text-stone-400">کتابخانه‌ها</span>
@endsection

@section('content')
<div class="space-y-6">

    <!-- Filters & Search Bar -->
    <div class="flex flex-wrap items-center justify-between gap-4">
        
        <form action="{{ route('admin.libraries.index') }}" method="GET" class="flex flex-wrap items-center gap-2 max-w-xl w-full">
            <div class="relative flex-1 min-w-[200px]">
                <input type="text" name="q" value="{{ $search }}" placeholder="جستجوی نام کتابخانه یا مرکز..." 
                       class="w-full pl-8 pr-4 py-2 rounded-xl border border-stone-300 dark:border-stone-700 bg-white dark:bg-[#15192C] text-xs focus:border-[#B38A50] outline-none">
                @if($search)
                    <a href="{{ route('admin.libraries.index', ['city' => $city]) }}" class="absolute left-2.5 top-1/2 -translate-y-1/2 text-stone-400 hover:text-stone-600 text-xs">✕</a>
                @endif
            </div>

            <!-- City Filter -->
            <select name="city" class="py-2 px-3 rounded-xl border border-stone-300 dark:border-stone-700 bg-white dark:bg-[#15192C] text-xs font-semibold focus:border-[#B38A50] outline-none">
                <option value="">شهر: همه شهرها</option>
                @foreach($cities as $c)
                    <option value="{{ $c }}" {{ $city === $c ? 'selected' : '' }}>{{ $c }}</option>
                @endforeach
            </select>

            <button type="submit" class="px-4 py-2 rounded-xl bg-[#292C56] text-amber-200 text-xs font-bold shrink-0">فیلتر</button>
        </form>

        <div class="text-xs text-stone-400">
            تعداد مراکز: <strong class="text-stone-700 dark:text-stone-200">{{ number_format($libraries->total()) }}</strong>
        </div>

    </div>

    <!-- Libraries Table -->
    <div class="bg-white dark:bg-[#15192C] rounded-2xl border border-stone-200 dark:border-stone-800 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-stone-50 dark:bg-stone-800/60 text-stone-500 border-b border-stone-100 dark:border-stone-800">
                    <tr>
                        <th class="p-4 font-bold">شناسه</th>
                        <th class="p-4 font-bold">نام کتابخانه</th>
                        <th class="p-4 font-bold">عنوان کامل و رسمی</th>
                        <th class="p-4 font-bold">شهر و کشور</th>
                        <th class="p-4 font-bold">تعداد نسخه‌های ثبت‌شده</th>
                        <th class="p-4 font-bold text-center">عملیات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100 dark:divide-stone-800">
                    @forelse($libraries as $lib)
                        <tr class="hover:bg-stone-50/50 dark:hover:bg-stone-800/40 transition">
                            <td class="p-4 font-mono font-bold text-stone-400">#{{ $lib->id }}</td>
                            <td class="p-4">
                                <a href="{{ route('libraries.show', $lib) }}" target="_blank" class="font-bold text-stone-900 dark:text-stone-100 hover:text-[#B38A50] inline-flex items-center gap-1">
                                    <span>{{ $lib->name }}</span>
                                    <svg class="w-3 h-3 text-stone-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                </a>
                            </td>
                            <td class="p-4 text-stone-600 dark:text-stone-300 max-w-xs truncate">
                                {{ $lib->full_name ?: '—' }}
                            </td>
                            <td class="p-4 text-stone-600 dark:text-stone-300">
                                <span>{{ $lib->city }}</span>
                                <span class="text-stone-400 text-[10px]">({{ $lib->country }})</span>
                            </td>
                            <td class="p-4">
                                <span class="px-2.5 py-0.5 rounded-full font-mono font-bold text-[11px] bg-stone-100 dark:bg-stone-800 text-stone-800 dark:text-stone-200">
                                    {{ number_format($lib->manuscripts_count) }}
                                </span>
                            </td>
                            <td class="p-4 text-center">
                                <a href="{{ route('admin.libraries.edit', $lib) }}" 
                                   class="px-3 py-1.5 rounded-xl bg-stone-100 dark:bg-stone-800 hover:bg-[#B38A50] hover:text-white text-stone-700 dark:text-stone-200 font-bold transition">
                                    ویرایش
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="p-12 text-center text-xs text-stone-400">
                                کتابخانه‌ای با این مشخصات یافت نشد.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($libraries->hasPages())
            <div class="p-4 border-t border-stone-100 dark:border-stone-800">
                {{ $libraries->links() }}
            </div>
        @endif
    </div>

</div>
@endsection

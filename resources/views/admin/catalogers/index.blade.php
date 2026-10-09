@extends('layouts.admin')

@section('title', 'مدیریت فهرست‌نگاران فنخا')
@section('page_title', 'مدیریت شناسنامه ۷۱ فهرست‌نگار اصیل فنخا')
@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-[#B38A50]">پیشخوان</a>
    <span>/</span>
    <span class="text-stone-400">فهرست‌نگاران</span>
@endsection

@section('content')
<div class="space-y-6">

    <div class="flex items-center justify-between border-b border-stone-200 dark:border-stone-800 pb-4">
        <p class="text-xs text-stone-500">
            فهرست ۷۱ استاد و پژوهشگر ارشد کتاب‌شناسی که مجلدات فنخا با اتکا به کارنامه آنان تدوین شده است.
        </p>
        <span class="text-xs font-bold text-stone-700 dark:text-stone-300">
            مجموع: <span class="font-mono text-[#B38A50]">{{ $catalogers->count() }} نفر</span>
        </span>
    </div>

    <!-- Catalogers Grid / Table -->
    <div class="bg-white dark:bg-[#15192C] rounded-2xl border border-stone-200 dark:border-stone-800 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-stone-50 dark:bg-stone-800/60 text-stone-500 border-b border-stone-100 dark:border-stone-800">
                    <tr>
                        <th class="p-4 font-bold">شناسه و تصویر</th>
                        <th class="p-4 font-bold">نام استاد</th>
                        <th class="p-4 font-bold">سال‌های حیات</th>
                        <th class="p-4 font-bold">مجلدات فهرست</th>
                        <th class="p-4 font-bold">تعداد نسخ زیرپوشش</th>
                        <th class="p-4 font-bold text-center">عملیات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100 dark:divide-stone-800">
                    @foreach($catalogers as $cat)
                        <tr class="hover:bg-stone-50/50 dark:hover:bg-stone-800/40 transition">
                            <td class="p-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-xl overflow-hidden bg-stone-100 dark:bg-stone-800 border border-stone-200 dark:border-stone-700 shrink-0">
                                        @if($cat->avatar_url)
                                            <img src="{{ $cat->avatar_url }}" alt="{{ $cat->name }}" class="w-full h-full object-cover">
                                        @else
                                            <div class="w-full h-full flex items-center justify-center font-bold text-stone-400 text-xs">
                                                {{ mb_substr($cat->name, 0, 1) }}
                                            </div>
                                        @endif
                                    </div>
                                    <span class="font-mono text-stone-400">#{{ $cat->id }}</span>
                                </div>
                            </td>
                            <td class="p-4">
                                <a href="{{ route('catalogers.show', $cat) }}" target="_blank" class="font-bold text-stone-900 dark:text-stone-100 hover:text-[#B38A50] inline-flex items-center gap-1">
                                    <span>{{ $cat->name }}</span>
                                    <svg class="w-3 h-3 text-stone-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                </a>
                            </td>
                            <td class="p-4 text-stone-600 dark:text-stone-300">
                                {{ $cat->life_years_text ?: '—' }}
                            </td>
                            <td class="p-4 font-mono font-bold text-stone-700 dark:text-stone-300">
                                {{ number_format($cat->volumes_count) }}
                            </td>
                            <td class="p-4">
                                <span class="px-2.5 py-0.5 rounded-full font-mono font-bold text-[11px] bg-stone-100 dark:bg-stone-800 text-stone-800 dark:text-stone-200">
                                    {{ number_format($cat->manuscripts_count) }}
                                </span>
                            </td>
                            <td class="p-4 text-center">
                                <a href="{{ route('admin.catalogers.edit', $cat) }}" 
                                   class="px-3 py-1.5 rounded-xl bg-stone-100 dark:bg-stone-800 hover:bg-[#B38A50] hover:text-white text-stone-700 dark:text-stone-200 font-bold transition">
                                    ویرایش شناسنامه
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection

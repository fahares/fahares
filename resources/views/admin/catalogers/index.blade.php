@extends('layouts.admin')

@section('title', 'فهرست‌نگاران')
@section('page_title', 'فهرست‌نگاران')
@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-cinnabar">پیشخوان</a>
    <span>/</span>
    <span class="text-stone-400">فهرست‌نگاران</span>
@endsection

@section('content')
<div class="space-y-6">

    <div class="flex items-center justify-between border-b border-line pb-4">
        <p class="text-xs text-stone-500">
            استادان و پژوهشگرانی که نسخه‌ها را در فهرست‌های چاپی شناسانده‌اند. عکس و شناسنامه‌ی هر کدام از اینجا ویرایش می‌شود.
        </p>
        <span class="text-xs font-bold text-ink">
            مجموع: <span class="font-mono text-cinnabar">{{ $catalogers->count() }} نفر</span>
        </span>
    </div>

    <!-- Catalogers Grid / Table -->
    <div class="bg-surface rounded-2xl border border-line shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-paper text-stone-500 border-b border-line">
                    <tr>
                        <th class="p-4 font-bold">شناسه و تصویر</th>
                        <th class="p-4 font-bold">نام استاد</th>
                        <th class="p-4 font-bold">سال‌های حیات</th>
                        <th class="p-4 font-bold">مجلدات فهرست</th>
                        <th class="p-4 font-bold">تعداد نسخ زیرپوشش</th>
                        <th class="p-4 font-bold text-center">عملیات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach($catalogers as $cat)
                        <tr class="hover:bg-paper transition">
                            <td class="p-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-xl overflow-hidden bg-stone-100 dark:bg-stone-800 border border-line-strong shrink-0">
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
                                <a href="{{ route('catalogers.show', $cat) }}" target="_blank" class="font-bold text-ink hover:text-cinnabar inline-flex items-center gap-1">
                                    <span>{{ $cat->name }}</span>
                                    <svg class="w-3 h-3 text-stone-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                </a>
                            </td>
                            <td class="p-4 text-ink/80">
                                {{ $cat->life_years_text ?: '—' }}
                            </td>
                            <td class="p-4 font-mono font-bold text-ink">
                                {{ number_format($cat->volumes_count) }}
                            </td>
                            <td class="p-4">
                                <span class="px-2.5 py-0.5 rounded-full font-mono font-bold text-[11px] bg-stone-100 dark:bg-stone-800 text-ink">
                                    {{ number_format($cat->manuscripts_count) }}
                                </span>
                            </td>
                            <td class="p-4 text-center">
                                <a href="{{ route('admin.catalogers.edit', $cat) }}" 
                                   class="px-3 py-1.5 rounded-xl bg-stone-100 dark:bg-stone-800 hover:bg-cinnabar hover:text-white text-ink font-bold transition">
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

@extends('layouts.admin')

@section('title', 'میز کار داوری پیشنهادات اصلاحی')
@section('page_title', 'میز کار داوری و اصلاحات علمی')
@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-[#B38A50]">پیشخوان</a>
    <span>/</span>
    <span class="text-stone-400">پیشنهادات اصلاحی پژوهشگران</span>
@endsection

@section('content')
<div class="space-y-6">

    <!-- Top Status Filter Tabs -->
    <div class="flex flex-wrap items-center justify-between gap-4 border-b border-stone-200 dark:border-stone-800 pb-4">
        
        <div class="flex items-center gap-2">
            <a href="{{ route('admin.suggestions.index', ['status' => 'pending', 'type' => $type]) }}" 
               class="px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-2 {{ $status === 'pending' ? 'bg-amber-500 text-stone-950 shadow-md' : 'bg-white dark:bg-[#15192C] text-stone-600 dark:text-stone-300 hover:bg-stone-50 dark:hover:bg-stone-800' }}">
                <span>در انتظار بررسی</span>
                <span class="px-2 py-0.5 rounded-full text-[10px] {{ $status === 'pending' ? 'bg-stone-950 text-amber-300' : 'bg-stone-200 dark:bg-stone-700' }}">
                    {{ number_format($stats['pending']) }}
                </span>
            </a>

            <a href="{{ route('admin.suggestions.index', ['status' => 'approved', 'type' => $type]) }}" 
               class="px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-2 {{ $status === 'approved' ? 'bg-emerald-600 text-white shadow-md' : 'bg-white dark:bg-[#15192C] text-stone-600 dark:text-stone-300 hover:bg-stone-50 dark:hover:bg-stone-800' }}">
                <span>تایید و اعمال‌شده</span>
                <span class="px-2 py-0.5 rounded-full text-[10px] {{ $status === 'approved' ? 'bg-white/20 text-white' : 'bg-stone-200 dark:bg-stone-700' }}">
                    {{ number_format($stats['approved']) }}
                </span>
            </a>

            <a href="{{ route('admin.suggestions.index', ['status' => 'rejected', 'type' => $type]) }}" 
               class="px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-2 {{ $status === 'rejected' ? 'bg-red-600 text-white shadow-md' : 'bg-white dark:bg-[#15192C] text-stone-600 dark:text-stone-300 hover:bg-stone-50 dark:hover:bg-stone-800' }}">
                <span>رد شده</span>
                <span class="px-2 py-0.5 rounded-full text-[10px] {{ $status === 'rejected' ? 'bg-white/20 text-white' : 'bg-stone-200 dark:bg-stone-700' }}">
                    {{ number_format($stats['rejected']) }}
                </span>
            </a>

            <a href="{{ route('admin.suggestions.index', ['status' => 'all', 'type' => $type]) }}" 
               class="px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-2 {{ $status === 'all' ? 'bg-[#292C56] text-amber-200 shadow-md' : 'bg-white dark:bg-[#15192C] text-stone-600 dark:text-stone-300 hover:bg-stone-50 dark:hover:bg-stone-800' }}">
                <span>همه</span>
                <span class="px-2 py-0.5 rounded-full text-[10px] bg-stone-200 dark:bg-stone-700">
                    {{ number_format($stats['total']) }}
                </span>
            </a>
        </div>

        <!-- Entity Type Filter Dropdown -->
        <div class="flex items-center gap-2 text-xs">
            <span class="text-stone-400">موجودیت:</span>
            <div class="flex items-center gap-1">
                <a href="{{ route('admin.suggestions.index', ['status' => $status]) }}" class="px-2.5 py-1 rounded-lg {{ empty($type) ? 'bg-[#B38A50]/20 text-[#B38A50] font-bold' : 'text-stone-500 hover:text-stone-800' }}">همه</a>
                <a href="{{ route('admin.suggestions.index', ['status' => $status, 'type' => 'Manuscript']) }}" class="px-2.5 py-1 rounded-lg {{ $type === 'Manuscript' ? 'bg-[#B38A50]/20 text-[#B38A50] font-bold' : 'text-stone-500 hover:text-stone-800' }}">نسخه‌ها</a>
                <a href="{{ route('admin.suggestions.index', ['status' => $status, 'type' => 'Work']) }}" class="px-2.5 py-1 rounded-lg {{ $type === 'Work' ? 'bg-[#B38A50]/20 text-[#B38A50] font-bold' : 'text-stone-500 hover:text-stone-800' }}">آثار</a>
                <a href="{{ route('admin.suggestions.index', ['status' => $status, 'type' => 'Person']) }}" class="px-2.5 py-1 rounded-lg {{ $type === 'Person' ? 'bg-[#B38A50]/20 text-[#B38A50] font-bold' : 'text-stone-500 hover:text-stone-800' }}">اشخاص</a>
            </div>
        </div>

    </div>

    <!-- Suggestions Table -->
    <div class="bg-white dark:bg-[#15192C] rounded-2xl border border-stone-200 dark:border-stone-800 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-stone-50 dark:bg-stone-800/60 text-stone-500 border-b border-stone-100 dark:border-stone-800">
                    <tr>
                        <th class="p-4 font-bold">شناسه و تاریخ</th>
                        <th class="p-4 font-bold">موجودیت هدف</th>
                        <th class="p-4 font-bold">فیلد اصلاحی</th>
                        <th class="p-4 font-bold">مقدار پیشنهادی</th>
                        <th class="p-4 font-bold">پژوهشگر ارائه‌دهنده</th>
                        <th class="p-4 font-bold">وضعیت</th>
                        <th class="p-4 font-bold text-center">عملیات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100 dark:divide-stone-800">
                    @forelse($suggestions as $sug)
                        <tr class="hover:bg-stone-50/50 dark:hover:bg-stone-800/40 transition">
                            <td class="p-4">
                                <span class="font-mono font-bold text-stone-900 dark:text-stone-100">#{{ $sug->id }}</span>
                                <div class="text-[10px] text-stone-400 mt-0.5">{{ $sug->created_at->format('Y/m/d H:i') }}</div>
                            </td>
                            <td class="p-4">
                                @php
                                    $base = class_basename($sug->suggestable_type);
                                    $link = match($base) {
                                        'Manuscript' => route('manuscripts.show', $sug->suggestable_id),
                                        'Work' => route('works.show', $sug->suggestable_id),
                                        'Person' => route('people.show', $sug->suggestable_id),
                                        default => '#'
                                    };
                                @endphp
                                <a href="{{ $link }}" target="_blank" class="text-[#B38A50] hover:underline font-bold inline-flex items-center gap-1">
                                    <span>{{ $base }}</span>
                                    <span class="font-mono">#{{ $sug->suggestable_id }}</span>
                                    <svg class="w-3 h-3 text-stone-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                </a>
                            </td>
                            <td class="p-4">
                                <span class="px-2 py-0.5 rounded font-mono text-[11px] bg-stone-100 dark:bg-stone-800 font-semibold text-stone-700 dark:text-stone-300">
                                    {{ $sug->field_name }}
                                </span>
                            </td>
                            <td class="p-4 max-w-xs truncate">
                                <span class="font-medium text-stone-900 dark:text-stone-100" title="{{ $sug->suggested_value }}">
                                    {{ Str::limit($sug->suggested_value, 45) }}
                                </span>
                            </td>
                            <td class="p-4">
                                <div class="font-medium text-stone-800 dark:text-stone-200">{{ $sug->guest_name ?: 'پژوهشگر مهمان' }}</div>
                                @if($sug->guest_email)
                                    <div class="text-[10px] text-stone-400 dir-ltr text-right">{{ $sug->guest_email }}</div>
                                @endif
                            </td>
                            <td class="p-4">
                                @if($sug->status === 'pending')
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/15 text-amber-800 dark:text-amber-300">
                                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                                        در انتظار داوری
                                    </span>
                                @elseif($sug->status === 'approved')
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/15 text-emerald-800 dark:text-emerald-300">
                                        تایید و اعمال شد
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-red-500/15 text-red-800 dark:text-red-300">
                                        رد شده
                                    </span>
                                @endif
                            </td>
                            <td class="p-4 text-center">
                                <a href="{{ route('admin.suggestions.show', $sug) }}" 
                                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-stone-100 dark:bg-stone-800 hover:bg-[#B38A50] hover:text-white text-stone-700 dark:text-stone-200 font-bold transition shadow-2xs">
                                    <span>بررسی</span>
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="p-12 text-center text-xs text-stone-400">
                                هیچ پیشنهادی با این فیلترها یافت نشد.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($suggestions->hasPages())
            <div class="p-4 border-t border-stone-100 dark:border-stone-800">
                {{ $suggestions->links() }}
            </div>
        @endif
    </div>

</div>
@endsection

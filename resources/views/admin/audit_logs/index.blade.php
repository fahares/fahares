@extends('layouts.admin')

@section('title', 'گزارش ممیزی و تغییرات سیستم')
@section('page_title', 'گزارش ممیزی و تاریخچه تغییرات پایگاه (Audit Logs)')
@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-[#B38A50]">پیشخوان</a>
    <span>/</span>
    <span class="text-stone-400">گزارش ممیزی</span>
@endsection

@section('content')
<div class="space-y-6">

    <!-- Action Filter Toolbar -->
    <div class="flex flex-wrap items-center justify-between gap-4 border-b border-stone-200 dark:border-stone-800 pb-4">
        
        <div class="flex flex-wrap items-center gap-2 text-xs font-bold">
            <a href="{{ route('admin.audit-logs.index') }}" 
               class="px-3.5 py-1.5 rounded-xl transition {{ empty($action) ? 'bg-[#292C56] text-amber-200' : 'bg-white dark:bg-[#15192C] text-stone-600 dark:text-stone-300' }}">
                همه عملیات‌ها
            </a>
            <a href="{{ route('admin.audit-logs.index', ['action' => 'verify']) }}" 
               class="px-3.5 py-1.5 rounded-xl transition {{ $action === 'verify' ? 'bg-amber-500 text-stone-950 font-black' : 'bg-white dark:bg-[#15192C] text-stone-600 dark:text-stone-300' }}">
                داوری و تایید اصلاحیه (Verify)
            </a>
            <a href="{{ route('admin.audit-logs.index', ['action' => 'merge']) }}" 
               class="px-3.5 py-1.5 rounded-xl transition {{ $action === 'merge' ? 'bg-indigo-600 text-white font-black' : 'bg-white dark:bg-[#15192C] text-stone-600 dark:text-stone-300' }}">
                ادغام اعلام و موضوعات (Merge)
            </a>
            <a href="{{ route('admin.audit-logs.index', ['action' => 'update']) }}" 
               class="px-3.5 py-1.5 rounded-xl transition {{ $action === 'update' ? 'bg-blue-600 text-white font-black' : 'bg-white dark:bg-[#15192C] text-stone-600 dark:text-stone-300' }}">
                ویرایش‌ها (Update)
            </a>
            <a href="{{ route('admin.audit-logs.index', ['action' => 'create']) }}" 
               class="px-3.5 py-1.5 rounded-xl transition {{ $action === 'create' ? 'bg-emerald-600 text-white font-black' : 'bg-white dark:bg-[#15192C] text-stone-600 dark:text-stone-300' }}">
                ایجاد جدید (Create)
            </a>
        </div>

        <div class="text-xs text-stone-400">
            تعداد رخدادها: <strong class="text-stone-700 dark:text-stone-200">{{ number_format($logs->total()) }}</strong>
        </div>

    </div>

    <!-- Audit Logs Table -->
    <div class="bg-white dark:bg-[#15192C] rounded-2xl border border-stone-200 dark:border-stone-800 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-stone-50 dark:bg-stone-800/60 text-stone-500 border-b border-stone-100 dark:border-stone-800">
                    <tr>
                        <th class="p-4 font-bold">شناسه و زمان</th>
                        <th class="p-4 font-bold">اقدام‌کننده</th>
                        <th class="p-4 font-bold">نوع اقدام</th>
                        <th class="p-4 font-bold">موجودیت هدف</th>
                        <th class="p-4 font-bold">نشانی IP</th>
                        <th class="p-4 font-bold">جزئیات تغییرات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100 dark:divide-stone-800">
                    @forelse($logs as $log)
                        <tr x-data="{ expanded: false }" class="hover:bg-stone-50/50 dark:hover:bg-stone-800/40 transition">
                            <td class="p-4">
                                <span class="font-mono font-bold text-stone-900 dark:text-stone-100">#{{ $log->id }}</span>
                                <div class="text-[10px] text-stone-400 font-mono mt-0.5">{{ $log->created_at->format('Y/m/d H:i:s') }}</div>
                            </td>
                            <td class="p-4">
                                <span class="font-bold text-stone-800 dark:text-stone-200">
                                    {{ $log->user?->name ?: 'سیستم خودکار' }}
                                </span>
                                @if($log->user)
                                    <div class="text-[10px] text-stone-400 font-mono">{{ $log->user->email }}</div>
                                @endif
                            </td>
                            <td class="p-4">
                                <span class="px-2.5 py-1 rounded-full text-[10px] font-bold 
                                    {{ $log->action === 'create' ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300' : '' }}
                                    {{ $log->action === 'update' ? 'bg-blue-500/15 text-blue-700 dark:text-blue-300' : '' }}
                                    {{ $log->action === 'delete' ? 'bg-red-500/15 text-red-700 dark:text-red-300' : '' }}
                                    {{ $log->action === 'verify' ? 'bg-amber-500/15 text-amber-800 dark:text-amber-300' : '' }}
                                    {{ $log->action === 'merge' ? 'bg-indigo-500/15 text-indigo-700 dark:text-indigo-300' : '' }}
                                    {{ str_starts_with($log->action, 'rollback') ? 'bg-purple-500/15 text-purple-700 dark:text-purple-300' : '' }}">
                                    {{ $log->action }}
                                </span>
                            </td>
                            <td class="p-4">
                                <span class="font-mono text-stone-700 dark:text-stone-300 font-bold block">
                                    {{ class_basename($log->auditable_type) }} #{{ $log->auditable_id }}
                                </span>
                            </td>
                            <td class="p-4 font-mono text-stone-400 text-[11px] dir-ltr text-right">
                                {{ $log->ip_address ?: '127.0.0.1' }}
                            </td>
                            <td class="p-4">
                                <div class="flex items-center gap-2">
                                    <button type="button" @click="expanded = !expanded" 
                                            class="px-2.5 py-1 rounded-lg border border-stone-200 dark:border-stone-700 hover:bg-stone-100 dark:hover:bg-stone-800 text-[11px] font-bold transition flex items-center gap-1 cursor-pointer">
                                        <span x-text="expanded ? 'بستن' : 'مشاهده Diff'">مشاهده Diff</span>
                                        <svg :class="expanded ? 'rotate-180' : ''" class="w-3 h-3 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                    </button>

                                    @if(in_array($log->action, ['merge', 'verify', 'update']) && !empty($log->old_values))
                                        <form action="{{ route('admin.audit-logs.rollback', $log) }}" method="POST" class="inline" 
                                              onsubmit="return confirm('هشدار: آیا از بازگردانی (Rollback) این تغییر و احیای وضعیت قبلی اطمینان کامل دارید؟')">
                                            @csrf
                                            <button type="submit" 
                                                    title="بازگردانی به وضعیت قبل"
                                                    class="px-2.5 py-1 rounded-lg bg-rose-500/10 hover:bg-rose-600 text-rose-700 hover:text-white dark:text-rose-300 border border-rose-500/20 text-[11px] font-bold transition flex items-center gap-1 cursor-pointer">
                                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/></svg>
                                                <span>بازگردانی (Undo)</span>
                                            </button>
                                        </form>
                                    @endif
                                </div>

                                <div x-show="expanded" x-cloak class="mt-3 p-3 rounded-xl bg-stone-900 text-stone-100 font-mono text-[11px] dir-ltr text-left space-y-2 max-w-lg overflow-x-auto shadow-inner">
                                    @if($log->old_values)
                                        <div>
                                            <span class="text-red-400 font-bold">--- OLD VALUES:</span>
                                            <pre class="text-[10px] text-red-300 whitespace-pre-wrap">{{ json_encode($log->old_values, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre>
                                        </div>
                                    @endif
                                    @if($log->new_values)
                                        <div>
                                            <span class="text-emerald-400 font-bold">+++ NEW VALUES:</span>
                                            <pre class="text-[10px] text-emerald-300 whitespace-pre-wrap">{{ json_encode($log->new_values, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre>
                                        </div>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="p-12 text-center text-xs text-stone-400">
                                لاگ ممیزی ثبت نشده است.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($logs->hasPages())
            <div class="p-4 border-t border-stone-100 dark:border-stone-800">
                {{ $logs->links() }}
            </div>
        @endif
    </div>

</div>
@endsection

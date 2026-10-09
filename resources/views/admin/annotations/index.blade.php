@extends('layouts.admin')

@section('title', 'دستگاه علمی و پانویس‌های انتقادی')
@section('page_title', 'دستگاه علمی و پانویس‌های انتقادی (Scholarly Apparatus)')
@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-[#B38A50]">پیشخوان</a>
    <span>/</span>
    <span class="text-stone-400">پانویس‌های علمی</span>
@endsection

@section('content')
<div class="space-y-6">

    <!-- Categories Filter & Header -->
    <div class="flex flex-wrap items-center justify-between gap-4 border-b border-stone-200 dark:border-stone-800 pb-4">
        
        <div class="flex items-center gap-2 text-xs font-bold">
            <a href="{{ route('admin.annotations.index') }}" 
               class="px-3.5 py-1.5 rounded-xl transition {{ empty($category) ? 'bg-[#292C56] text-amber-200' : 'bg-white dark:bg-[#15192C] text-stone-600 dark:text-stone-300' }}">
                همه پانویس‌ها
            </a>
            <a href="{{ route('admin.annotations.index', ['category' => 'scholarly_correction']) }}" 
               class="px-3.5 py-1.5 rounded-xl transition {{ $category === 'scholarly_correction' ? 'bg-[#292C56] text-amber-200' : 'bg-white dark:bg-[#15192C] text-stone-600 dark:text-stone-300' }}">
                تصحیحات پژوهشی
            </a>
            <a href="{{ route('admin.annotations.index', ['category' => 'parser_fix']) }}" 
               class="px-3.5 py-1.5 rounded-xl transition {{ $category === 'parser_fix' ? 'bg-[#292C56] text-amber-200' : 'bg-white dark:bg-[#15192C] text-stone-600 dark:text-stone-300' }}">
                اصلاحات پارسر
            </a>
            <a href="{{ route('admin.annotations.index', ['category' => 'ocr_fix']) }}" 
               class="px-3.5 py-1.5 rounded-xl transition {{ $category === 'ocr_fix' ? 'bg-[#292C56] text-amber-200' : 'bg-white dark:bg-[#15192C] text-stone-600 dark:text-stone-300' }}">
                اصلاحات چاپی / OCR
            </a>
        </div>

        <div class="text-xs text-stone-400">
            مجموع پانویس‌های ثبت‌شده: <strong class="text-stone-700 dark:text-stone-200">{{ number_format($annotations->total()) }}</strong>
        </div>

    </div>

    <!-- Table -->
    <div class="bg-white dark:bg-[#15192C] rounded-2xl border border-stone-200 dark:border-stone-800 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-stone-50 dark:bg-stone-800/60 text-stone-500 border-b border-stone-100 dark:border-stone-800">
                    <tr>
                        <th class="p-4 font-bold">شناسه و تاریخ</th>
                        <th class="p-4 font-bold">موجودیت و فیلد</th>
                        <th class="p-4 font-bold">متن اصیل فنخا</th>
                        <th class="p-4 font-bold">مقدار تصحیح‌شده</th>
                        <th class="p-4 font-bold">مستند و منبع ادعا</th>
                        <th class="p-4 font-bold">نمایش عمومی</th>
                        <th class="p-4 font-bold text-center">عملیات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100 dark:divide-stone-800">
                    @forelse($annotations as $anno)
                        <tr class="hover:bg-stone-50/50 dark:hover:bg-stone-800/40 transition">
                            <td class="p-4">
                                <span class="font-mono font-bold text-stone-900 dark:text-stone-100">#{{ $anno->id }}</span>
                                <div class="text-[10px] text-stone-400">{{ $anno->created_at->format('Y/m/d') }}</div>
                            </td>
                            <td class="p-4">
                                @php
                                    $base = class_basename($anno->annotatable_type);
                                    $link = match($base) {
                                        'Manuscript' => route('manuscripts.show', $anno->annotatable_id),
                                        'Work' => route('works.show', $anno->annotatable_id),
                                        'Person' => route('people.show', $anno->annotatable_id),
                                        default => '#'
                                    };
                                @endphp
                                <a href="{{ $link }}" target="_blank" class="text-[#B38A50] hover:underline font-bold block">
                                    {{ $base }} #{{ $anno->annotatable_id }}
                                </a>
                                <span class="font-mono text-[10px] text-stone-400">{{ $anno->field_name }}</span>
                            </td>
                            <td class="p-4 max-w-xs truncate text-stone-500">
                                {{ $anno->original_fankha_value ?: '—' }}
                            </td>
                            <td class="p-4 max-w-xs truncate font-bold text-emerald-700 dark:text-emerald-300">
                                {{ $anno->corrected_value }}
                            </td>
                            <td class="p-4 max-w-xs truncate text-stone-600 dark:text-stone-400" title="{{ $anno->citation_source }}">
                                {{ Str::limit($anno->citation_source, 40) }}
                            </td>
                            <td class="p-4">
                                <form action="{{ route('admin.annotations.toggle-public', $anno) }}" method="POST">
                                    @csrf
                                    <button type="submit" class="px-2.5 py-1 rounded-full text-[10px] font-bold cursor-pointer transition {{ $anno->is_public ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300' : 'bg-stone-200 dark:bg-stone-700 text-stone-600 dark:text-stone-400' }}">
                                        {{ $anno->is_public ? 'نمایش در سایت' : 'مخفی' }}
                                    </button>
                                </form>
                            </td>
                            <td class="p-4 text-center">
                                <form action="{{ route('admin.annotations.destroy', $anno) }}" method="POST" onsubmit="return confirm('آیا از حذف این پانویس علمی اطمینان دارید؟')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="p-1.5 text-stone-400 hover:text-red-600 transition" title="حذف پانویس">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="p-12 text-center text-xs text-stone-400">
                                پانویس انتقادی ثبت نشده است.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($annotations->hasPages())
            <div class="p-4 border-t border-stone-100 dark:border-stone-800">
                {{ $annotations->links() }}
            </div>
        @endif
    </div>

</div>
@endsection

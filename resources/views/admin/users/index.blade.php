@extends('layouts.admin')

@section('title', 'مدیریت کاربران و پژوهشگران')
@section('page_title', 'مدیریت کاربران و سطوح دسترسی (Users & Roles)')
@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-[#B38A50]">پیشخوان</a>
    <span>/</span>
    <span class="text-stone-400">کاربران</span>
@endsection

@section('content')
<div class="space-y-6">

    <!-- Role Filter Tabs -->
    <div class="flex flex-wrap items-center justify-between gap-4 border-b border-stone-200 dark:border-stone-800 pb-4">
        
        <div class="flex flex-wrap items-center gap-2 text-xs font-bold">
            <a href="{{ route('admin.users.index') }}" 
               class="px-3.5 py-1.5 rounded-xl transition {{ empty($role) ? 'bg-[#292C56] text-amber-200' : 'bg-white dark:bg-[#15192C] text-stone-600 dark:text-stone-300' }}">
                همه کاربران
            </a>
            <a href="{{ route('admin.users.index', ['role' => 'admin']) }}" 
               class="px-3.5 py-1.5 rounded-xl transition {{ $role === 'admin' ? 'bg-amber-500 text-stone-950 font-black' : 'bg-white dark:bg-[#15192C] text-stone-600 dark:text-stone-300' }}">
                مدیران ارشد (Admin)
            </a>
            <a href="{{ route('admin.users.index', ['role' => 'editor']) }}" 
               class="px-3.5 py-1.5 rounded-xl transition {{ $role === 'editor' ? 'bg-indigo-600 text-white font-black' : 'bg-white dark:bg-[#15192C] text-stone-600 dark:text-stone-300' }}">
                دبیران علمی (Editor)
            </a>
            <a href="{{ route('admin.users.index', ['role' => 'researcher']) }}" 
               class="px-3.5 py-1.5 rounded-xl transition {{ $role === 'researcher' ? 'bg-blue-600 text-white font-black' : 'bg-white dark:bg-[#15192C] text-stone-600 dark:text-stone-300' }}">
                پژوهشگران (Researcher)
            </a>
            <a href="{{ route('admin.users.index', ['role' => 'user']) }}" 
               class="px-3.5 py-1.5 rounded-xl transition {{ $role === 'user' ? 'bg-stone-600 text-white font-black' : 'bg-white dark:bg-[#15192C] text-stone-600 dark:text-stone-300' }}">
                کاربران عادی (User)
            </a>
        </div>

        <div class="text-xs text-stone-400">
            مجموع حساب‌ها: <strong class="text-stone-700 dark:text-stone-200">{{ number_format($users->total()) }}</strong>
        </div>

    </div>

    <!-- Users Table -->
    <div class="bg-white dark:bg-[#15192C] rounded-2xl border border-stone-200 dark:border-stone-800 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-stone-50 dark:bg-stone-800/60 text-stone-500 border-b border-stone-100 dark:border-stone-800">
                    <tr>
                        <th class="p-4 font-bold">شناسه</th>
                        <th class="p-4 font-bold">نام و نام خانوادگی</th>
                        <th class="p-4 font-bold">نشانی رایانامه</th>
                        <th class="p-4 font-bold">نقش و سطح دسترسی</th>
                        <th class="p-4 font-bold">وابستگی پژوهشی / دانشگاهی</th>
                        <th class="p-4 font-bold">تاریخ ثبت‌نام</th>
                        <th class="p-4 font-bold text-center">عملیات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100 dark:divide-stone-800">
                    @forelse($users as $u)
                        <tr class="hover:bg-stone-50/50 dark:hover:bg-stone-800/40 transition">
                            <td class="p-4 font-mono font-bold text-stone-400">#{{ $u->id }}</td>
                            <td class="p-4 font-bold text-stone-900 dark:text-stone-100">
                                <span>{{ $u->name }}</span>
                                @if($u->is_verified_scholar)
                                    <span class="inline-block mr-1 text-emerald-500" title="پژوهشگر تاییدشده">✓</span>
                                @endif
                            </td>
                            <td class="p-4 font-mono text-stone-600 dark:text-stone-300 dir-ltr text-right">
                                {{ $u->email }}
                            </td>
                            <td class="p-4">
                                @if($u->role === 'admin')
                                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/15 text-amber-800 dark:text-amber-300">مدیر ارشد</span>
                                @elseif($u->role === 'editor')
                                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-indigo-500/15 text-indigo-800 dark:text-indigo-300">دبیر علمی</span>
                                @elseif($u->role === 'researcher')
                                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-blue-500/15 text-blue-800 dark:text-blue-300">پژوهشگر</span>
                                @else
                                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-stone-100 dark:bg-stone-800 text-stone-600 dark:text-stone-400">کاربر</span>
                                @endif
                            </td>
                            <td class="p-4 text-stone-500">
                                {{ $u->affiliation ?: '—' }}
                            </td>
                            <td class="p-4 font-mono text-stone-400 text-[11px]">
                                {{ $u->created_at->format('Y/m/d') }}
                            </td>
                            <td class="p-4 text-center">
                                <div class="flex items-center justify-center gap-1">
                                    <a href="{{ route('admin.users.edit', $u) }}" 
                                       class="px-2.5 py-1 rounded-lg bg-stone-100 dark:bg-stone-800 hover:bg-[#B38A50] hover:text-white text-stone-700 dark:text-stone-200 font-bold transition">
                                        ویرایش
                                    </a>
                                    @if($u->id !== auth()->id())
                                        <form action="{{ route('admin.users.destroy', $u) }}" method="POST" onsubmit="return confirm('آیا از حذف این کاربر اطمینان دارید؟')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="p-1 text-stone-400 hover:text-red-500 transition" title="حذف کاربر">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="p-12 text-center text-xs text-stone-400">
                                کاربری در این رده یافت نشد.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($users->hasPages())
            <div class="p-4 border-t border-stone-100 dark:border-stone-800">
                {{ $users->links() }}
            </div>
        @endif
    </div>

</div>
@endsection

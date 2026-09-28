@extends('layouts.app')

@section('title', 'کتابخانه‌ها و گنجینه‌های نسخ خطی ایران و جهان | فهارس')
@section('meta_description', 'فهرست نزدیک به ۵۰۰ کتابخانه، موزه و مرکز اسنادی ایران و جهان همراه با تعداد نسخه‌های خطی شناسایی‌شده در فهارس')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">

    <!-- Header & Search -->
    <div class="bg-white dark:bg-[#15192C] p-6 sm:p-8 rounded-3xl border border-[#EADFCF] dark:border-[#272F4C] shadow-md space-y-6">
        
        <div class="space-y-2">
            <h1 class="text-2xl sm:text-3xl font-black text-[#292C56] dark:text-amber-100 tracking-tight">
                کتابخانه‌ها و مراکز اسناد نسخ خطی
            </h1>
            <p class="text-xs sm:text-sm text-stone-500 dark:text-stone-400">
                فهرست نزدیک به ۵۰۰ کتابخانه، موزه و مجموعه اسنادی ایران و جهان ثبت‌شده در فهارس و مجموعه‌های خطی
            </p>
        </div>

        <form action="{{ route('libraries.index') }}" method="GET" class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div class="sm:col-span-2 relative">
                <input 
                    type="text" 
                    name="q" 
                    value="{{ $search }}" 
                    placeholder="جستجو در نام کتابخانه یا مرکز..."
                    class="w-full pr-10 pl-4 py-3 bg-stone-50 dark:bg-stone-800 rounded-xl border border-stone-300 dark:border-stone-700 text-sm focus:border-[#B38A50] focus:ring-2 focus:ring-[#B38A50]/20">
                <div class="absolute right-3.5 top-3.5 text-stone-400">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <select name="city" class="w-full p-3 bg-stone-50 dark:bg-stone-800 rounded-xl border border-stone-300 dark:border-stone-700 text-sm">
                    <option value="">همه شهرها</option>
                    @foreach($cities as $c)
                        <option value="{{ $c }}" {{ $city === $c ? 'selected' : '' }}>{{ $c }}</option>
                    @endforeach
                </select>

                <button type="submit" class="px-5 py-3 bg-[#B38A50] hover:bg-[#9C753F] text-white font-semibold rounded-xl text-sm transition shadow">
                    فیلتر
                </button>
            </div>
        </form>

    </div>

    <!-- Libraries Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
        @forelse($libraries as $lib)
            <a href="{{ route('libraries.show', $lib) }}" 
               class="bg-white dark:bg-[#15192C] p-5 rounded-2xl border border-stone-200 dark:border-stone-800 hover:border-[#B38A50] dark:hover:border-[#B38A50] shadow-sm hover:shadow-md transition duration-200 flex flex-col justify-between group">
                <div class="space-y-1">
                    <div class="text-base font-bold text-[#292C56] dark:text-stone-100 group-hover:text-[#B38A50] transition">
                        {{ $lib->name }}
                    </div>
                    <div class="text-xs text-stone-400">
                        {{ $lib->city ?? 'ایران' }} @if($lib->country && $lib->country !== 'ایران') • {{ $lib->country }} @endif
                    </div>
                </div>

                <div class="mt-4 pt-3 border-t border-stone-100 dark:border-stone-800 flex items-center justify-between text-xs text-stone-500">
                    <span>تعداد نسخه‌ها:</span>
                    <span class="font-bold text-[#B38A50] bg-amber-50 dark:bg-amber-950/40 px-2 py-0.5 rounded-full">
                        {{ number_format($lib->manuscripts_count) }} نسخه
                    </span>
                </div>
            </a>
        @empty
            <div class="col-span-3 p-12 bg-white dark:bg-[#15192C] rounded-3xl text-center text-stone-400">
                کتابخانه‌ای با این مشخصات یافت نشد.
            </div>
        @endforelse
    </div>

    <!-- Pagination -->
    @if($libraries->hasPages())
        <div class="pt-4">
            {{ $libraries->links() }}
        </div>
    @endif

</div>
@endsection

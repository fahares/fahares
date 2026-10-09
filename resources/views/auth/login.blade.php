@extends('layouts.app')

@section('title', 'ورود به سامانه | فهارس')

@section('content')
<div class="min-h-[70vh] flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-md w-full space-y-8 bg-white dark:bg-stone-900 p-8 rounded-3xl border border-[#B38A50]/30 shadow-xl shadow-stone-900/5 relative overflow-hidden">
        
        <!-- Subtle Top Gold Accent Bar -->
        <div class="absolute top-0 inset-x-0 h-1.5 bg-gradient-to-r from-[#292C56] via-[#B38A50] to-[#292C56]"></div>

        <!-- Header -->
        <div class="text-center space-y-2">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-[#B38A50]/10 border border-[#B38A50]/20 text-[#B38A50] mb-2">
                <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                </svg>
            </div>
            <h2 class="text-2xl font-black text-stone-900 dark:text-stone-100">ورود به حساب کاربری</h2>
            <p class="text-xs text-stone-500 dark:text-stone-400">سامانه جامع نسخه‌های خطی و کتاب‌شناسی مکتوب فهارس</p>
        </div>

        <!-- Session Flash Alerts -->
        @if (session('warning'))
            <div class="p-3.5 rounded-2xl bg-amber-500/10 border border-amber-500/20 text-xs text-amber-900 dark:text-amber-200 flex items-center gap-2">
                <svg class="w-4 h-4 shrink-0 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                <span>{{ session('warning') }}</span>
            </div>
        @endif

        @if (session('info'))
            <div class="p-3.5 rounded-2xl bg-blue-500/10 border border-blue-500/20 text-xs text-blue-900 dark:text-blue-200 flex items-center gap-2">
                <svg class="w-4 h-4 shrink-0 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>{{ session('info') }}</span>
            </div>
        @endif

        <!-- Form -->
        <form action="{{ route('login.post') }}" method="POST" class="space-y-5">
            @csrf

            <!-- Email -->
            <div class="space-y-1.5">
                <label for="email" class="block text-xs font-bold text-stone-700 dark:text-stone-300">نشانی رایانامه (ایمیل):</label>
                <div class="relative">
                    <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus dir="ltr"
                           placeholder="your-email@domain.com"
                           class="w-full px-4 py-2.5 rounded-xl border @error('email') border-red-400 bg-red-50/30 @else border-stone-200 dark:border-stone-700 bg-stone-50 dark:bg-stone-800/80 @enderror text-stone-900 dark:text-stone-100 text-sm focus:border-[#B38A50] focus:ring-2 focus:ring-[#B38A50]/20 transition outline-none text-left">
                </div>
                @error('email')
                    <p class="text-[11px] text-red-500 font-medium">{{ $message }}</p>
                @enderror
            </div>

            <!-- Password -->
            <div class="space-y-1.5">
                <label for="password" class="block text-xs font-bold text-stone-700 dark:text-stone-300">گذرواژه:</label>
                <div class="relative" x-data="{ show: false }">
                    <input :type="show ? 'text' : 'password'" id="password" name="password" required dir="ltr"
                           placeholder="••••••••"
                           class="w-full px-4 py-2.5 rounded-xl border @error('password') border-red-400 bg-red-50/30 @else border-stone-200 dark:border-stone-700 bg-stone-50 dark:bg-stone-800/80 @enderror text-stone-900 dark:text-stone-100 text-sm focus:border-[#B38A50] focus:ring-2 focus:ring-[#B38A50]/20 transition outline-none text-left pl-10">
                    <button type="button" @click="show = !show" class="absolute left-3 top-1/2 -translate-y-1/2 text-stone-400 hover:text-stone-600 dark:hover:text-stone-200 text-xs">
                        <span x-show="!show">نمایش</span>
                        <span x-show="show" x-cloak>مخفی</span>
                    </button>
                </div>
                @error('password')
                    <p class="text-[11px] text-red-500 font-medium">{{ $message }}</p>
                @enderror
            </div>

            <!-- Remember Me -->
            <div class="flex items-center justify-between text-xs">
                <label class="flex items-center gap-2 cursor-pointer select-none text-stone-600 dark:text-stone-400">
                    <input type="checkbox" name="remember" value="1" class="rounded border-stone-300 text-[#B38A50] focus:ring-[#B38A50]/30 w-4 h-4">
                    <span>مرا به خاطر بسپار</span>
                </label>
            </div>

            <!-- Submit Button -->
            <button type="submit" 
                    class="w-full py-3 px-4 rounded-xl bg-gradient-to-r from-[#292C56] via-[#333768] to-[#292C56] hover:from-[#1E2040] hover:to-[#1E2040] text-amber-100 font-bold text-sm shadow-md shadow-[#292C56]/20 transition duration-150 flex items-center justify-center gap-2">
                <svg class="w-4 h-4 text-[#B38A50]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/>
                </svg>
                <span>ورود به سامانه</span>
            </button>
        </form>

        <!-- Footer Links -->
        <div class="pt-4 border-t border-stone-100 dark:border-stone-800 text-center text-xs space-y-2">
            <p class="text-stone-500 dark:text-stone-400">
                حساب کاربری پژوهشی ندارید؟
                <a href="{{ route('register') }}" class="font-bold text-[#B38A50] hover:text-[#9C753F] hover:underline mr-1">
                    ثبت‌نام سریع پژوهشگران
                </a>
            </p>
            <p class="text-[11px] text-stone-400">
                <a href="{{ route('home') }}" class="hover:text-stone-600 dark:hover:text-stone-300">
                    بازگشت به صفحه اصلی پایگاه
                </a>
            </p>
        </div>

    </div>
</div>
@endsection

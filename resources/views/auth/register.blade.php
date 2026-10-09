@extends('layouts.app')

@section('title', 'ثبت‌نام پژوهشگران | فهارس')

@section('content')
<div class="min-h-[75vh] flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-md w-full space-y-7 bg-white dark:bg-stone-900 p-8 rounded-3xl border border-[#B38A50]/30 shadow-xl shadow-stone-900/5 relative overflow-hidden">
        
        <!-- Subtle Top Gold Accent Bar -->
        <div class="absolute top-0 inset-x-0 h-1.5 bg-gradient-to-r from-[#292C56] via-[#B38A50] to-[#292C56]"></div>

        <!-- Header -->
        <div class="text-center space-y-2">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-[#B38A50]/10 border border-[#B38A50]/20 text-[#B38A50] mb-2">
                <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                </svg>
            </div>
            <h2 class="text-2xl font-black text-stone-900 dark:text-stone-100">ثبت‌نام پژوهشگر</h2>
            <p class="text-xs text-stone-500 dark:text-stone-400">عضویت در پایگاه نسخه‌های خطی جهت ثبت اصلاحیات و یادداشت‌های علمی</p>
        </div>

        <!-- Benefits Note -->
        <div class="p-3 rounded-2xl bg-amber-500/10 border border-amber-500/20 text-[11px] text-amber-900 dark:text-amber-200 leading-relaxed">
            با عضویت در پایگاه، پیشنهادات اصلاحی و استنادات نسخه‌پژوهی شما به نام خودتان ثبت شده و بدون نیاز به ورود مکرر مشخصات بررسی می‌گردد.
        </div>

        <!-- Form -->
        <form action="{{ route('register.post') }}" method="POST" class="space-y-4">
            @csrf

            <!-- Name -->
            <div class="space-y-1">
                <label for="name" class="block text-xs font-bold text-stone-700 dark:text-stone-300">نام و نام خانوادگی:</label>
                <input type="text" id="name" name="name" value="{{ old('name') }}" required autofocus
                       placeholder="مثال: دکتر علی رضایی"
                       class="w-full px-4 py-2.5 rounded-xl border @error('name') border-red-400 bg-red-50/30 @else border-stone-200 dark:border-stone-700 bg-stone-50 dark:bg-stone-800/80 @enderror text-stone-900 dark:text-stone-100 text-sm focus:border-[#B38A50] focus:ring-2 focus:ring-[#B38A50]/20 transition outline-none">
                @error('name')
                    <p class="text-[11px] text-red-500 font-medium">{{ $message }}</p>
                @enderror
            </div>

            <!-- Email -->
            <div class="space-y-1">
                <label for="email" class="block text-xs font-bold text-stone-700 dark:text-stone-300">نشانی رایانامه (ایمیل):</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required dir="ltr"
                       placeholder="your-email@domain.com"
                       class="w-full px-4 py-2.5 rounded-xl border @error('email') border-red-400 bg-red-50/30 @else border-stone-200 dark:border-stone-700 bg-stone-50 dark:bg-stone-800/80 @enderror text-stone-900 dark:text-stone-100 text-sm focus:border-[#B38A50] focus:ring-2 focus:ring-[#B38A50]/20 transition outline-none text-left">
                @error('email')
                    <p class="text-[11px] text-red-500 font-medium">{{ $message }}</p>
                @enderror
            </div>

            <!-- Scientific Affiliation -->
            <div class="space-y-1">
                <label for="affiliation" class="block text-xs font-bold text-stone-700 dark:text-stone-300">
                    وابستگی دانشگاهی / حوزوی / پژوهشی <span class="text-stone-400 font-normal">(اختیاری)</span>:
                </label>
                <input type="text" id="affiliation" name="affiliation" value="{{ old('affiliation') }}"
                       placeholder="مثال: دانشگاه تهران، پژوهشکده زبان و ادبیات..."
                       class="w-full px-4 py-2.5 rounded-xl border border-stone-200 dark:border-stone-700 bg-stone-50 dark:bg-stone-800/80 text-stone-900 dark:text-stone-100 text-sm focus:border-[#B38A50] focus:ring-2 focus:ring-[#B38A50]/20 transition outline-none">
            </div>

            <!-- Password -->
            <div class="space-y-1">
                <label for="password" class="block text-xs font-bold text-stone-700 dark:text-stone-300">گذرواژه (حداقل ۸ نویسه):</label>
                <input type="password" id="password" name="password" required dir="ltr"
                       placeholder="••••••••"
                       class="w-full px-4 py-2.5 rounded-xl border @error('password') border-red-400 bg-red-50/30 @else border-stone-200 dark:border-stone-700 bg-stone-50 dark:bg-stone-800/80 @enderror text-stone-900 dark:text-stone-100 text-sm focus:border-[#B38A50] focus:ring-2 focus:ring-[#B38A50]/20 transition outline-none text-left">
                @error('password')
                    <p class="text-[11px] text-red-500 font-medium">{{ $message }}</p>
                @enderror
            </div>

            <!-- Password Confirmation -->
            <div class="space-y-1">
                <label for="password_confirmation" class="block text-xs font-bold text-stone-700 dark:text-stone-300">تکرار گذرواژه:</label>
                <input type="password" id="password_confirmation" name="password_confirmation" required dir="ltr"
                       placeholder="••••••••"
                       class="w-full px-4 py-2.5 rounded-xl border border-stone-200 dark:border-stone-700 bg-stone-50 dark:bg-stone-800/80 text-stone-900 dark:text-stone-100 text-sm focus:border-[#B38A50] focus:ring-2 focus:ring-[#B38A50]/20 transition outline-none text-left">
            </div>

            <!-- Submit Button -->
            <div class="pt-2">
                <button type="submit" 
                        class="w-full py-3 px-4 rounded-xl bg-gradient-to-r from-[#292C56] via-[#333768] to-[#292C56] hover:from-[#1E2040] hover:to-[#1E2040] text-amber-100 font-bold text-sm shadow-md shadow-[#292C56]/20 transition duration-150 flex items-center justify-center gap-2">
                    <svg class="w-4 h-4 text-[#B38A50]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                    <span>ایجاد حساب پژوهشگر</span>
                </button>
            </div>
        </form>

        <!-- Footer Links -->
        <div class="pt-4 border-t border-stone-100 dark:border-stone-800 text-center text-xs space-y-2">
            <p class="text-stone-500 dark:text-stone-400">
                پیش‌تر ثبت‌نام کرده‌اید؟
                <a href="{{ route('login') }}" class="font-bold text-[#B38A50] hover:text-[#9C753F] hover:underline mr-1">
                    ورود به حساب کاربری
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

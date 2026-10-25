@extends('layouts.app')

@section('title', 'ثبت‌نام پژوهشگران | فهارس')

@section('content')
<div class="min-h-[75vh] flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-md w-full space-y-7 bg-surface p-8 border border-gold shadow-[inset_0_0_0_4px_var(--surface),inset_0_0_0_5px_var(--line)] relative">

        <!-- Header -->
        <div class="text-center space-y-2">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-sm bg-cinnabar/5 border border-cinnabar/20 text-cinnabar mb-2">
                <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                </svg>
            </div>
            <h2 class="font-naskh text-[28px] font-bold text-ink leading-relaxed">ثبت‌نام پژوهشگر</h2>
            <p class="text-xs text-muted">عضویت در پایگاه نسخه‌های خطی جهت ثبت اصلاحیات و یادداشت‌های علمی</p>
        </div>

        <!-- Benefits Note -->
        <div class="p-3 rounded-sm bg-amber-500/10 border border-amber-500/20 text-[11px] text-amber-900 dark:text-amber-200 leading-relaxed">
            ثبت پیشنهاد اصلاح، یادداشت و منبع پژوهشی ویژه‌ی اعضاست. با عضویت، پیشنهادهای شما به نام خودتان ثبت می‌شود و اگر بخواهید، نامتان کنار اصلاحاتتان منتشر می‌شود.
        </div>

        @include('auth.partials.google-button', ['label' => 'ثبت‌نام با حساب گوگل'])

        <!-- Form -->
        <form action="{{ route('register.post') }}" method="POST" class="space-y-4">
            @csrf

            <!-- Name -->
            @include('auth.partials.name-field', ['autofocus' => true, 'inputClass' => 'w-full px-4 py-2.5 rounded-sm border ' . ($errors->has('name') ? 'border-red-400 bg-red-50/30' : 'border-line-strong bg-paper') . ' text-ink text-sm focus:border-cinnabar focus:ring-4 focus:ring-cinnabar/15 transition outline-none'])

            <!-- Email -->
            <div class="space-y-1">
                <label for="email" class="block text-xs font-bold text-ink">نشانی رایانامه (ایمیل):</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required dir="ltr"
                       placeholder="your-email@domain.com"
                       class="w-full px-4 py-2.5 rounded-sm border @error('email') border-red-400 bg-red-50/30 @else border-line-strong bg-paper @enderror text-ink text-sm focus:border-cinnabar focus:ring-4 focus:ring-cinnabar/15 transition outline-none text-left">
                @error('email')
                    <p class="text-[11px] text-red-500 font-medium">{{ $message }}</p>
                @enderror
            </div>

            <!-- Scientific Affiliation -->
            <div class="space-y-1">
                <label for="affiliation" class="block text-xs font-bold text-ink">
                    وابستگی دانشگاهی / حوزوی / پژوهشی <span class="text-muted font-normal">(اختیاری)</span>:
                </label>
                <input type="text" id="affiliation" name="affiliation" value="{{ old('affiliation') }}"
                       placeholder="مثال: دانشگاه تهران، پژوهشکده زبان و ادبیات..."
                       class="w-full px-4 py-2.5 rounded-sm border border-line-strong bg-paper text-ink text-sm focus:border-cinnabar focus:ring-4 focus:ring-cinnabar/15 transition outline-none">
            </div>

            <!-- Phone (optional) -->
            @include('auth.partials.phone-field', ['inputClass' => 'w-full px-4 py-2.5 rounded-sm border ' . ($errors->has('phone') ? 'border-red-400 bg-red-50/30' : 'border-line-strong bg-paper') . ' text-ink text-sm focus:border-cinnabar focus:ring-4 focus:ring-cinnabar/15 transition outline-none'])

            <!-- Password -->
            <div class="space-y-1">
                <label for="password" class="block text-xs font-bold text-ink">گذرواژه (حداقل ۸ نویسه):</label>
                <div class="relative" x-data="{ show: false }">
                    <input type="password" :type="show ? 'text' : 'password'" id="password" name="password" required dir="ltr"
                           placeholder="••••••••"
                           class="w-full px-4 py-2.5 rounded-sm border @error('password') border-red-400 bg-red-50/30 @else border-line-strong bg-paper @enderror text-ink text-sm focus:border-cinnabar focus:ring-4 focus:ring-cinnabar/15 transition outline-none text-left pl-11">
                    <x-password-toggle />
                </div>
                @error('password')
                    <p class="text-[11px] text-red-500 font-medium">{{ $message }}</p>
                @enderror
            </div>

            <!-- Password Confirmation -->
            <div class="space-y-1">
                <label for="password_confirmation" class="block text-xs font-bold text-ink">تکرار گذرواژه:</label>
                <div class="relative" x-data="{ show: false }">
                    <input type="password" :type="show ? 'text' : 'password'" id="password_confirmation" name="password_confirmation" required dir="ltr"
                           placeholder="••••••••"
                           class="w-full px-4 py-2.5 rounded-sm border border-line-strong bg-paper text-ink text-sm focus:border-cinnabar focus:ring-4 focus:ring-cinnabar/15 transition outline-none text-left pl-11">
                    <x-password-toggle />
                </div>
            </div>

            <!-- Submit Button -->
            <div class="pt-2">
                <button type="submit" 
                        class="w-full py-3 px-4 rounded-sm bg-cinnabar text-on-cinnabar font-bold text-sm hover:opacity-90 transition duration-150 flex items-center justify-center gap-2">
                    <svg class="w-4 h-4 text-cinnabar" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                    <span>ایجاد حساب پژوهشگر</span>
                </button>
            </div>
        </form>

        <!-- Footer Links -->
        <div class="pt-4 border-t border-line text-center text-xs space-y-2">
            <p class="text-muted">
                پیش‌تر ثبت‌نام کرده‌اید؟
                <a href="{{ route('login') }}" class="font-bold text-cinnabar hover:opacity-80 hover:underline mr-1">
                    ورود به حساب کاربری
                </a>
            </p>
            <p class="text-[11px] text-muted">
                <a href="{{ route('home') }}" class="hover:text-ink">
                    بازگشت به صفحه اصلی پایگاه
                </a>
            </p>
        </div>

    </div>
</div>
@endsection

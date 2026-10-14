@extends('layouts.app')

@section('title', 'تعیین گذرواژه‌ی تازه | فهارس')

@section('content')
@php $input = 'w-full px-4 py-2.5 rounded-xl border border-stone-200 dark:border-stone-700 bg-stone-50 dark:bg-stone-800/80 text-stone-900 dark:text-stone-100 text-sm focus:border-[#B38A50] focus:ring-2 focus:ring-[#B38A50]/20 transition outline-none text-left'; @endphp
<div class="min-h-[60vh] flex items-center justify-center py-12 px-4">
    <div class="max-w-md w-full space-y-6 bg-white dark:bg-stone-900 p-8 rounded-3xl border border-[#B38A50]/30 shadow-xl shadow-stone-900/5">
        <h1 class="text-center text-xl font-black text-stone-900 dark:text-stone-100">تعیین گذرواژه‌ی تازه</h1>
        <form action="{{ route('password.update') }}" method="POST" class="space-y-4">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <div class="space-y-1">
                <label for="email" class="block text-xs font-bold text-stone-700 dark:text-stone-300">نشانی ایمیل:</label>
                <input id="email" type="email" name="email" value="{{ old('email', $email) }}" required dir="ltr" autocomplete="email" class="{{ $input }}">
                @error('email')<p class="text-xs text-red-600 dark:text-red-400 font-bold">{{ $message }}</p>@enderror
            </div>
            <div class="space-y-1">
                <label for="password" class="block text-xs font-bold text-stone-700 dark:text-stone-300">گذرواژه‌ی تازه (دست‌کم ۸ نویسه):</label>
                <input id="password" type="password" name="password" required dir="ltr" autocomplete="new-password" class="{{ $input }}">
                @error('password')<p class="text-xs text-red-600 dark:text-red-400 font-bold">{{ $message }}</p>@enderror
            </div>
            <div class="space-y-1">
                <label for="password_confirmation" class="block text-xs font-bold text-stone-700 dark:text-stone-300">تکرار گذرواژه‌ی تازه:</label>
                <input id="password_confirmation" type="password" name="password_confirmation" required dir="ltr" autocomplete="new-password" class="{{ $input }}">
            </div>
            <button type="submit" class="w-full py-3 px-4 rounded-xl bg-[#292C56] hover:bg-[#1E2040] text-amber-100 font-bold text-sm shadow-md transition">ذخیره‌ی گذرواژه</button>
        </form>
    </div>
</div>
@endsection

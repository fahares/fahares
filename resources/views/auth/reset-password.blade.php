@extends('layouts.app')

@section('title', 'تعیین گذرواژه‌ی تازه | فهارس')

@section('content')
@php $input = 'w-full px-4 py-2.5 rounded-sm border border-line-strong bg-paper text-ink text-sm focus:border-cinnabar focus:ring-4 focus:ring-cinnabar/15 transition outline-none text-left'; @endphp
<div class="min-h-[60vh] flex items-center justify-center py-12 px-4">
    <div class="max-w-md w-full space-y-6 bg-surface p-8 rounded-sm border border-gold shadow-[inset_0_0_0_4px_var(--surface),inset_0_0_0_5px_var(--line)]">
        <h1 class="text-center text-xl font-black text-ink">تعیین گذرواژه‌ی تازه</h1>
        <form action="{{ route('password.update') }}" method="POST" class="space-y-4">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <div class="space-y-1">
                <label for="email" class="block text-xs font-bold text-ink">نشانی ایمیل:</label>
                <input id="email" type="email" name="email" value="{{ old('email', $email) }}" required dir="ltr" autocomplete="email" class="{{ $input }}">
                @error('email')<p class="text-xs text-red-600 dark:text-red-400 font-bold">{{ $message }}</p>@enderror
            </div>
            <div class="space-y-1">
                <label for="password" class="block text-xs font-bold text-ink">گذرواژه‌ی تازه (دست‌کم ۸ نویسه):</label>
                <div class="relative" x-data="{ show: false }">
                    <input type="password" :type="show ? 'text' : 'password'" id="password" name="password" required dir="ltr" autocomplete="new-password" class="{{ $input }} pl-11">
                    <x-password-toggle />
                </div>
                @error('password')<p class="text-xs text-red-600 dark:text-red-400 font-bold">{{ $message }}</p>@enderror
            </div>
            <div class="space-y-1">
                <label for="password_confirmation" class="block text-xs font-bold text-ink">تکرار گذرواژه‌ی تازه:</label>
                <div class="relative" x-data="{ show: false }">
                    <input type="password" :type="show ? 'text' : 'password'" id="password_confirmation" name="password_confirmation" required dir="ltr" autocomplete="new-password" class="{{ $input }} pl-11">
                    <x-password-toggle />
                </div>
            </div>
            <button type="submit" class="w-full py-3 px-4 rounded-sm bg-cinnabar text-on-cinnabar hover:opacity-90 font-bold text-sm transition">ذخیره‌ی گذرواژه</button>
        </form>
    </div>
</div>
@endsection

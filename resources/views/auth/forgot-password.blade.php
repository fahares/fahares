@extends('layouts.app')

@section('title', 'بازیابی گذرواژه | فهارس')

@section('content')
@php $input = 'w-full px-4 py-2.5 rounded-sm border border-line-strong bg-paper text-ink text-sm focus:border-cinnabar focus:ring-4 focus:ring-cinnabar/15 transition outline-none text-left'; @endphp
<div class="min-h-[60vh] flex items-center justify-center py-12 px-4">
    <div class="max-w-md w-full space-y-6 bg-surface p-8 rounded-sm border border-gold shadow-[inset_0_0_0_4px_var(--surface),inset_0_0_0_5px_var(--line)]">
        <div class="text-center space-y-2">
            <h1 class="text-xl font-black text-ink">بازیابی گذرواژه</h1>
            <p class="text-xs text-muted">ایمیل حساب خود را بنویسید تا پیوند تعیین گذرواژه‌ی تازه برایتان فرستاده شود.</p>
        </div>
        <form action="{{ route('password.email') }}" method="POST" class="space-y-4">
            @csrf
            <div class="space-y-1">
                <label for="email" class="block text-xs font-bold text-ink">نشانی ایمیل:</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required dir="ltr" autocomplete="email" class="{{ $input }}">
                @error('email')<p class="text-xs text-red-600 dark:text-red-400 font-bold">{{ $message }}</p>@enderror
            </div>
            <button type="submit" class="w-full py-3 px-4 rounded-sm bg-cinnabar text-on-cinnabar hover:opacity-90 font-bold text-sm transition">فرستادن پیوند بازیابی</button>
        </form>
        <p class="text-center text-xs"><a href="{{ route('login') }}" class="font-bold text-cinnabar hover:underline">بازگشت به صفحه‌ی ورود</a></p>
    </div>
</div>
@endsection

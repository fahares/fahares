@extends('layouts.app')

@section('title', 'تأیید ایمیل | فهارس')

@section('content')
<div class="max-w-xl mx-auto px-4 sm:px-6 py-12">
    <div class="p-8 rounded-3xl bg-white dark:bg-[#15192C] border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-4 text-center">
        <h1 class="text-lg font-bold text-stone-900 dark:text-stone-100">تأیید ایمیل</h1>
        <p class="text-sm text-stone-600 dark:text-stone-300 leading-relaxed">
            پیوند تأیید به <strong dir="ltr">{{ auth()->user()->email }}</strong> فرستاده می‌شود. با تأیید ایمیل، پیشنهادهایی که پیش‌تر بدون ورود و با همین ایمیل فرستاده‌اید به حساب شما وصل می‌شوند.
        </p>
        @if(session('info'))
            <div class="p-3 rounded-2xl bg-sky-50 dark:bg-sky-950/30 border border-sky-200 dark:border-sky-900 text-sky-800 dark:text-sky-200 text-xs font-bold">{{ session('info') }}</div>
        @endif
        <form action="{{ route('verification.send') }}" method="POST">
            @csrf
            <button type="submit" class="px-5 py-2.5 bg-[#B38A50] hover:bg-[#9C753F] text-white rounded-xl text-sm font-bold shadow-md transition">فرستادن پیوند تأیید</button>
        </form>
    </div>
</div>
@endsection

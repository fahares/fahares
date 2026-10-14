@extends('layouts.app')

@section('title', 'لغو اشتراک | فهارس')

@section('content')
<div class="max-w-xl mx-auto px-4 sm:px-6 py-12">
    <div class="p-8 rounded-3xl bg-white dark:bg-[#15192C] border border-[#EADFCF] dark:border-[#272F4C] shadow-sm text-center space-y-3">
        <h1 class="text-lg font-bold text-stone-900 dark:text-stone-100">اشتراک لغو شد</h1>
        <p class="text-sm text-stone-600 dark:text-stone-300">دیگر برای «{{ $label }}» از راه {{ $channel }} اعلانی دریافت نمی‌کنید.</p>
        <p class="text-xs text-stone-500 dark:text-stone-400">هر زمان بخواهید، می‌توانید آن را در <a href="{{ route('notifications.settings') }}" class="text-[#B38A50] hover:underline font-bold">تنظیمات اعلان</a> دوباره روشن کنید.</p>
    </div>
</div>
@endsection

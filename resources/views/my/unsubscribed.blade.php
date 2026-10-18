@extends('layouts.app')

@section('title', 'لغو اشتراک | فهارس')

@section('content')
<div class="max-w-xl mx-auto px-4 sm:px-6 py-12">
    <div class="p-8 rounded-sm bg-surface border border-line shadow-sm text-center space-y-3">
        <h1 class="text-lg font-bold text-ink">اشتراک لغو شد</h1>
        <p class="text-sm text-ink/80">دیگر برای «{{ $label }}» از راه {{ $channel }} اعلانی دریافت نمی‌کنید.</p>
        <p class="text-xs text-muted">هر زمان بخواهید، می‌توانید آن را در <a href="{{ route('notifications.settings') }}" class="text-cinnabar hover:underline font-bold">تنظیمات اعلان</a> دوباره روشن کنید.</p>
    </div>
</div>
@endsection

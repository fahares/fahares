@extends('layouts.app')

@section('title', 'حساب من | فهارس')

@section('content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
    <div>
        <h1 class="font-naskh text-2xl sm:text-3xl font-bold text-ink leading-relaxed">حساب من</h1>
        <p class="mt-1 text-xs text-muted">{{ $user->name }} · <span class="dir-ltr inline-block">{{ $user->email }}</span></p>
    </div>

    @if(session('success'))
        <div class="p-3 rounded-sm border border-emerald-600/30 bg-emerald-600/5 text-emerald-800 dark:text-emerald-300 text-sm">{{ session('success') }}</div>
    @endif

    <x-ui.card class="space-y-5">
        <h2 class="rubric text-lg">تصویر کاربر</h2>
        <div class="flex flex-col sm:flex-row sm:items-center gap-5">
            <x-ui.avatar :user="$user" size="xl" />
            <div class="space-y-3 text-xs sm:text-sm text-ink/85 leading-6 min-w-0">
                <p class="m-0">
                    @switch($user->avatar_source)
                        @case('upload') تصویری که خودتان بارگذاری کرده‌اید. @break
                        @case('google') رونوشت تصویر حساب گوگل شما؛ با هر ورود با گوگل تازه می‌شود. @break
                        @default حرف نخست نام شما. می‌توانید تصویری بارگذاری کنید.
                    @endswitch
                </p>
                <p class="m-0 text-muted text-xs">
                    تصویر شما در صفحه‌های عمومی فقط کنار پیشنهادهایی می‌آید که اجازه‌ی نمایش نامتان را داده‌اید.
                    تصویر به‌شکل مربع از میانه برش داده و کوچک می‌شود.
                </p>
            </div>
        </div>

        <form action="{{ route('profile.avatar') }}" method="POST" enctype="multipart/form-data" class="flex flex-wrap items-center gap-2 pt-4 border-t border-line">
            @csrf
            <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp,image/gif" required
                class="flex-1 min-w-0 text-xs text-ink file:me-3 file:px-3 file:py-2 file:rounded-sm file:border file:border-line-strong file:bg-surface file:text-ink file:font-semibold file:cursor-pointer">
            <x-ui.btn type="submit" variant="primary">بارگذاری تصویر</x-ui.btn>
        </form>
        @error('avatar')
            <p class="text-xs text-red-600 dark:text-red-400 -mt-3">{{ $message }}</p>
        @enderror
        <p class="text-[11px] text-muted -mt-3">JPG، PNG، WebP یا GIF، تا ۵ مگابایت.</p>

        @if($user->avatar_path)
            <form action="{{ route('profile.avatar.destroy') }}" method="POST" onsubmit="return confirm('تصویر برداشته شود؟')">
                @csrf
                @method('DELETE')
                <x-ui.btn type="submit" variant="ghost" class="!px-0">برداشتن تصویر و بازگشت به حرف نخست</x-ui.btn>
            </form>
        @endif
    </x-ui.card>

    <div class="flex flex-wrap gap-2 text-xs">
        <x-ui.btn :href="route('my.suggestions')">پیشنهادهای من</x-ui.btn>
        <x-ui.btn :href="route('notifications.settings')">تنظیمات اعلان</x-ui.btn>
    </div>
</div>
@endsection

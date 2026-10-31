@extends('layouts.admin')

@section('title', 'کش و نمایه‌سازی')
@section('page_title', 'کش و نمایه‌سازی')

@section('content')
@php
    // The environment's settings, by name; the values are technical and stay as they are
    $labels = [
        'php_version' => 'نسخه‌ی PHP',
        'laravel_version' => 'نسخه‌ی Laravel',
        'db_connection' => 'پایگاه داده',
        'cache_driver' => 'کش',
        'session_driver' => 'نشست‌ها',
        'queue_connection' => 'صف کارها',
        'scout_driver' => 'موتور جست‌وجو',
        'app_env' => 'محیط',
        'app_debug' => 'حالت اشکال‌زدایی',
    ];
    $manuscripts = \App\Support\Fa::approx(\App\Support\SiteStats::all()['manuscripts']);
@endphp
<div class="max-w-4xl space-y-5 text-[13px]">

    <x-ui.card class="flex flex-wrap items-center justify-between gap-4">
        <div class="min-w-0">
            <h2 class="rubric text-base">پاک کردن کش</h2>
            <p class="text-xs text-muted mt-1 leading-6">کش برنامه، قالب‌های ساخته‌شده و مسیرهای ثبت‌شده پاک و در نخستین درخواست بعدی از نو ساخته می‌شوند.</p>
        </div>
        <form action="{{ route('admin.system.clear-cache') }}" method="POST" onsubmit="return confirm('همه‌ی کش‌های سامانه پاک شود؟')">
            @csrf
            <x-ui.btn type="submit">پاک کردن کش</x-ui.btn>
        </form>
    </x-ui.card>

    <x-ui.card class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 class="rubric text-base">نمایه‌ی جست‌وجو (Meilisearch)</h2>
                <p class="text-xs text-muted mt-1 leading-6">جست‌وجوی سایت از این نمایه‌ها می‌خواند. بازنمایه‌سازی در پس‌زمینه انجام می‌شود و چند دقیقه طول می‌کشد.</p>
            </div>
            <x-ui.chip :tone="$meiliStats['healthy'] ? 'good' : 'accent'">{{ $meiliStats['healthy'] ? 'در دسترس' : 'در دسترس نیست' }}</x-ui.chip>
        </div>

        @if($meiliStats['healthy'] && ! empty($meiliStats['indexes']))
            <ul class="space-y-1">
                @foreach($meiliStats['indexes'] as $idx)
                    <li class="flex items-baseline gap-2">
                        <code class="text-ink" dir="ltr">{{ $idx['uid'] ?? '—' }}</code>
                        <span class="leader"></span>
                        <span class="text-xs text-muted">کلید <code dir="ltr">{{ $idx['primaryKey'] ?? 'id' }}</code></span>
                    </li>
                @endforeach
            </ul>
        @elseif(! $meiliStats['healthy'])
            <x-admin.notice tone="warn">موتور جست‌وجو پاسخ نمی‌دهد. تا دوباره برقرار شود، جست‌وجوی سایت ممکن است درست کار نکند.</x-admin.notice>
        @endif

        <div class="flex flex-wrap items-center gap-2 pt-3 border-t border-line">
            <span class="font-bold text-ink">بازنمایه‌سازی:</span>
            <form action="{{ route('admin.system.reindex') }}" method="POST" onsubmit="return confirm('همه‌ی آثار دوباره نمایه شوند؟')">
                @csrf
                <input type="hidden" name="model" value="Work">
                <x-ui.btn type="submit" size="sm">آثار</x-ui.btn>
            </form>
            <form action="{{ route('admin.system.reindex') }}" method="POST" onsubmit="return confirm(@js('حدود ' . $manuscripts . ' نسخه دوباره نمایه شوند؟ این کار در پس‌زمینه انجام می‌شود.'))">
                @csrf
                <input type="hidden" name="model" value="Manuscript">
                <x-ui.btn type="submit" size="sm">نسخه‌ها <span class="text-muted">(حدود {{ $manuscripts }})</span></x-ui.btn>
            </form>
        </div>
    </x-ui.card>

    <x-ui.card class="space-y-3">
        <h2 class="rubric text-base">محیط اجرا</h2>
        <ul class="grid gap-x-8 gap-y-1 sm:grid-cols-2">
            @foreach($systemInfo as $key => $val)
                <li class="flex items-baseline gap-2">
                    <span class="text-ink">{{ $labels[$key] ?? $key }}</span>
                    <span class="leader"></span>
                    <code class="text-xs text-muted" dir="ltr">{{ $val }}</code>
                </li>
            @endforeach
        </ul>
    </x-ui.card>
</div>
@endsection

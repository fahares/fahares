@extends('layouts.admin')

@section('title', 'قالب ایمیل‌ها')
@section('page_title', 'قالب ایمیل‌ها')

@php
    $input = 'admin-field w-full';
    $label = 'block font-bold text-ink mb-1';
@endphp

@section('content')
<div class="space-y-6 text-[13px]">

    <p class="text-muted max-w-3xl leading-7">سربرگ (شمسه و نام فهارس)، امضا و پانویسی که همه‌ی ایمیل‌های سایت (تأیید ایمیل، بازیابی گذرواژه، اعلان‌ها و پیام‌ها) با آن فرستاده می‌شوند. متن خود هر ایمیل از اعلان مربوط به آن می‌آید.</p>

    @if($errors->any())
        <x-admin.notice tone="error" class="space-y-1">
            @foreach($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </x-admin.notice>
    @endif

    <x-ui.card>
        <form action="{{ route('admin.mail.update') }}" method="POST" class="grid gap-4 md:grid-cols-2">
            @csrf
            @method('PUT')
            @foreach($settings as $key => $s)
                @php $field = str_replace('.', '__', $key); @endphp
                @if($key === 'mail.show_logo')
                    <label class="md:col-span-2 inline-flex items-center gap-2 font-bold text-ink">
                        <input type="checkbox" name="{{ $field }}" value="1" @checked(old($field, $s['value']) === '1')>
                        {{ $s['label'] }}
                    </label>
                @else
                    <div class="{{ $key === 'mail.footer' ? 'md:col-span-2' : '' }}">
                        <label for="{{ $field }}" class="{{ $label }}">{{ $s['label'] }}</label>
                        <input type="text" name="{{ $field }}" id="{{ $field }}" class="{{ $input }}" placeholder="{{ $s['default'] }}" value="{{ old($field, $s['value'] === $s['default'] ? '' : $s['value']) }}">
                    </div>
                @endif
            @endforeach
            <p class="md:col-span-2 text-xs text-muted">خانه‌ی خالی یعنی متن پیش‌فرض (که کم‌رنگ نشان داده شده) به کار می‌رود. در پانویس می‌توان پیوند را به شکل <code dir="ltr">[متن](https://…)</code> نوشت.</p>
            <div class="md:col-span-2"><x-ui.btn type="submit" variant="primary">ذخیره</x-ui.btn></div>
        </form>
    </x-ui.card>

    <x-ui.card class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="rubric text-base">پیش‌نمایش</h2>
            <x-ui.btn :href="route('admin.mail.preview')" target="_blank" size="sm">باز کردن در زبانه‌ی تازه ↗</x-ui.btn>
        </div>
        <iframe src="{{ route('admin.mail.preview') }}" title="پیش‌نمایش ایمیل" class="w-full h-[640px] border border-line bg-white"></iframe>
    </x-ui.card>
</div>
@endsection

@extends('layouts.app')

{{-- «تماس با ما» (backlog item 173): the ways to reach the site's team and a form whose messages go to the panel's feedback queue (ContactController) --}}
@section('title', 'تماس با ما | فهارس')
@section('meta_description', 'راه‌های تماس با گروه فهارس: ایمیل، تلفن، واتساپ، تلگرام، ایتا و فرم پیام.')

@php
    $inputClass = 'w-full p-2.5 rounded-xl border border-line-strong bg-paper text-ink focus:border-gold';
    $chipClass = 'inline-flex items-center px-3 py-1.5 rounded-lg border border-line-strong text-xs font-semibold hover:text-cinnabar hover:border-cinnabar transition';
@endphp

@section('content')
<article class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

    <nav class="flex items-center gap-2 text-xs text-muted" aria-label="مسیر">
        <a href="{{ route('home') }}" class="hover:text-cinnabar">فهارس</a>
        <span aria-hidden="true">/</span>
        <span class="text-ink font-semibold truncate">تماس با ما</span>
    </nav>

    <header class="text-center pb-2">
        <h1 class="font-naskh text-[26px] sm:text-[36px] font-bold leading-[1.6] text-ink text-balance">تماس با ما</h1>
    </header>

    <div class="jadval" aria-hidden="true"></div>

    <div class="post-body">
        <p>پرسش، پیشنهاد، گزارش خطا یا پیشنهاد همکاری را از هر یک از راه‌های زیر با گروه فهارس در میان بگذارید. برای اصلاح اطلاعات یک نسخه، اثر، شخص یا کتابخانه، دکمۀ «پیشنهاد اصلاح» در صفحۀ همان رکورد دقیق‌تر است.</p>
    </div>

    <section class="grid grid-cols-1 sm:grid-cols-2 gap-4" aria-label="راه‌های تماس">
        @if ($email)
            <div class="p-5 rounded-2xl border border-line bg-surface space-y-2 sm:col-span-2">
                <h2 class="font-bold text-ink text-sm">ایمیل</h2>
                <a href="mailto:{{ $email }}" dir="ltr" class="inline-block text-cinnabar font-bold hover:underline" data-contact="email">{{ $email }}</a>
            </div>
        @endif

        @foreach ($phones as $phone)
            <div class="p-5 rounded-2xl border border-line bg-surface space-y-3" data-contact="phone">
                <h2 class="font-bold text-ink text-sm">تلفن {{ $phone['label'] }}</h2>
                <a href="tel:{{ $phone['number'] }}" dir="ltr" class="inline-block text-ink font-bold tracking-wide hover:text-cinnabar">{{ $phone['number'] }}</a>
                <div class="flex flex-wrap gap-2">
                    @if ($phone['whatsapp_url'])
                        <a href="{{ $phone['whatsapp_url'] }}" target="_blank" rel="noopener" class="{{ $chipClass }}">واتساپ</a>
                    @endif
                    @if ($phone['telegram_url'])
                        <a href="{{ $phone['telegram_url'] }}" target="_blank" rel="noopener" class="{{ $chipClass }}">تلگرام</a>
                    @endif
                    @if ($phone['eitaa_url'])
                        <a href="{{ $phone['eitaa_url'] }}" target="_blank" rel="noopener" class="{{ $chipClass }}">ایتا</a>
                    @endif
                </div>
            </div>
        @endforeach
    </section>

    <section class="p-5 sm:p-6 rounded-2xl border border-line-strong bg-surface space-y-4" id="contact-form" aria-labelledby="contact-form-title">
        <div class="flex items-center gap-2">
            <span class="w-2 h-5 bg-cinnabar rounded-sm"></span>
            <h2 id="contact-form-title" class="font-bold text-base text-ink">فرستادن پیام</h2>
        </div>

        @if (session('contact_sent'))
            <div class="p-4 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-300 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200 font-bold leading-relaxed text-sm" role="status">{{ session('contact_sent') }}</div>
        @endif

        @if ($errors->any())
            <div class="p-3 rounded-xl bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 font-bold text-xs space-y-1" role="alert">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('contact.store') }}" class="space-y-4 text-xs">
            @csrf
            <input type="hidden" name="started" value="{{ $startedAt }}">
            @guest
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="space-y-1">
                        <label for="contact-name" class="block font-semibold text-ink">نام شما:</label>
                        <input id="contact-name" type="text" name="name" value="{{ old('name') }}" required maxlength="100" class="{{ $inputClass }}">
                    </div>
                    <div class="space-y-1">
                        <label for="contact-email" class="block font-semibold text-ink">ایمیل (برای پاسخ):</label>
                        <input id="contact-email" type="email" name="email" value="{{ old('email') }}" required maxlength="100" dir="ltr" class="{{ $inputClass }}">
                    </div>
                </div>
            @else
                <p class="text-muted leading-relaxed">پیام به نام {{ auth()->user()->name }} فرستاده می‌شود و پاسخ آن در اعلان‌های حساب شما می‌آید.</p>
            @endguest
            <div class="space-y-1">
                <label for="contact-subject" class="block font-semibold text-ink">موضوع (اختیاری):</label>
                <input id="contact-subject" type="text" name="subject" value="{{ old('subject') }}" maxlength="200" class="{{ $inputClass }}">
            </div>
            <div class="space-y-1">
                <label for="contact-message" class="block font-semibold text-ink">متن پیام:</label>
                <textarea id="contact-message" name="message" rows="6" required minlength="10" maxlength="5000" class="{{ $inputClass }}">{{ old('message') }}</textarea>
            </div>
            {{-- Honeypot: hidden from people, filled by bots --}}
            <div class="hidden" aria-hidden="true"><label>وب‌سایت <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
            <div class="flex justify-end">
                <button type="submit" class="px-5 py-2.5 bg-cinnabar hover:opacity-90 text-on-cinnabar rounded-xl font-bold shadow-md transition">ارسال پیام</button>
            </div>
        </form>
    </section>
</article>
@endsection

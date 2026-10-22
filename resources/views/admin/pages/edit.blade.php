@extends('layouts.admin')

@php
    $heading = "ویرایش صفحه‌ی «{$page->title}»";
    $box = 'bg-surface rounded-2xl border border-line shadow-xs';
    $input = 'w-full p-2.5 rounded-xl border border-line-strong bg-paper text-ink focus:border-gold outline-none';
    $label = 'block font-bold text-ink mb-1';
    $btn = 'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold border border-line-strong hover:border-gold hover:text-cinnabar transition cursor-pointer';
    $btnPri = 'inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-xs font-bold bg-cinnabar text-on-cinnabar hover:opacity-90 transition cursor-pointer';
@endphp

@section('title', $heading)
@section('page_title', $heading)
@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-cinnabar">پیشخوان</a>
    <span>/</span>
    <span class="text-stone-400">{{ $page->title }}</span>
@endsection

@section('content')
<div class="space-y-6 text-xs">

    @if($errors->any())
        <div class="p-4 rounded-xl border border-red-300 bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 space-y-1">
            @foreach($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line pb-4">
        <div class="space-y-1">
            <p class="text-stone-500">نشانی صفحه:</p>
            <code class="font-mono text-ink font-bold" dir="ltr">{{ parse_url($page->url(), PHP_URL_PATH) }}</code>
        </div>
        <div class="flex items-center gap-3">
            @if($page->user)
                <span class="text-stone-400">آخرین ویرایش: {{ $page->user->name }}، @jdate($page->updated_at, 'long')</span>
            @endif
            <a href="{{ $page->url() }}" target="_blank" class="{{ $btn }}">دیدن در سایت ↗</a>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">

        <form action="{{ route('admin.pages.update', $page) }}" method="POST" class="{{ $box }} p-6 space-y-5">
            @csrf
            @method('PUT')

            <div>
                <label for="title" class="{{ $label }}">عنوان</label>
                <input type="text" name="title" id="title" value="{{ old('title', $page->title) }}" required class="{{ $input }} text-sm font-bold">
            </div>

            <div>
                <label for="body" class="{{ $label }}">متن (Markdown)</label>
                <textarea name="body" id="body" rows="32" class="{{ $input }} font-mono text-[13px] leading-7">{{ old('body', $page->body) }}</textarea>
            </div>

            <div class="flex items-center border-t border-line pt-4">
                <button type="submit" class="mr-auto {{ $btnPri }}">ذخیره</button>
            </div>
        </form>

        <aside class="space-y-6">
            <section class="{{ $box }} p-5 space-y-2 text-stone-600 dark:text-stone-300">
                <h2 class="font-bold text-ink text-sm">راهنمای کوتاه Markdown</h2>
                <dl class="space-y-1.5 [&_code]:font-mono [&_code]:text-[11px] [&_code]:text-ink [&_dt]:inline [&_dd]:inline">
                    <div><dt><code>## عنوان</code></dt> <dd>— تیتر بخش (<code>###</code> برای زیرتیتر)</dd></div>
                    <div><dt><code>**پررنگ**</code></dt> <dd>— پررنگ؛ <code>*کج*</code> برای کج</dd></div>
                    <div><dt><code>- مورد</code></dt> <dd>— فهرست؛ <code>1. مورد</code> برای فهرست شماره‌دار</dd></div>
                    <div><dt><code>&gt; متن</code></dt> <dd>— نقل‌قول</dd></div>
                    <div><dt><code>[متن](/works/123)</code></dt> <dd>— پیوند</dd></div>
                    <div><dt><code>---</code></dt> <dd>— خط جداکننده</dd></div>
                </dl>
                <p class="text-stone-400">برای آغاز بند تازه، یک سطر خالی بگذارید. سطر «بسم الله …» در آغاز متن، وسط‌چین نمایش داده می‌شود.</p>
                <p class="text-stone-400">هر ذخیره در «گزارش رخدادها» ثبت می‌شود و از آنجا برگشت‌پذیر است.</p>
            </section>
        </aside>
    </div>
</div>
@endsection

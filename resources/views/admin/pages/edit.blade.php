@extends('layouts.admin')

@php
    $heading = $page->title;
    $box = 'bg-surface border border-line';
    $input = 'admin-field w-full';
    $label = 'block font-bold text-ink mb-1';
    $btnPri = 'inline-flex items-center gap-1.5 px-4 py-2 rounded-sm text-xs font-semibold bg-cinnabar text-on-cinnabar hover:opacity-90 transition cursor-pointer';
@endphp

@section('title', $heading)
@section('page_title', $heading)
@section('breadcrumb')
    <span>صفحه‌ها</span>
    <span>/</span>
    <span>ویرایش</span>
@endsection
@section('page_actions')
    <x-ui.btn :href="$page->url()" target="_blank" size="sm">دیدن در سایت ↗</x-ui.btn>
@endsection

@section('content')
<div class="space-y-5 text-[13px]">

    @if($errors->any())
        <x-admin.notice tone="error" class="space-y-1">
            @foreach($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </x-admin.notice>
    @endif

    <p class="flex flex-wrap gap-x-6 gap-y-1 text-muted">
        <span>نشانی صفحه: <code class="text-ink font-bold" dir="ltr">{{ parse_url($page->url(), PHP_URL_PATH) }}</code></span>
        @if($page->user)
            <span>آخرین ویرایش: {{ $page->user->name }}، @jdate($page->updated_at, 'long')</span>
        @endif
    </p>

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_320px] items-start">

        <form action="{{ route('admin.pages.update', $page) }}" method="POST" class="{{ $box }} p-4 sm:p-5 space-y-5">
            @csrf
            @method('PUT')

            <div>
                <label for="title" class="{{ $label }}">عنوان</label>
                <input type="text" name="title" id="title" value="{{ old('title', $page->title) }}" required class="{{ $input }} text-base font-bold">
            </div>

            <div>
                <label for="body" class="{{ $label }}">متن <span class="font-normal text-muted">(Markdown)</span></label>
                <textarea name="body" id="body" rows="32" class="{{ $input }} text-sm leading-8">{{ old('body', $page->body) }}</textarea>
            </div>

            <div class="flex items-center border-t border-line pt-4">
                <button type="submit" class="ms-auto {{ $btnPri }}">ذخیره</button>
            </div>
        </form>

        <aside class="space-y-5">
            @include('admin.partials.markdown-guide', ['note' => ' سطر «بسم الله …» در آغاز متن، وسط‌چین نمایش داده می‌شود. هر ذخیره در لاگ ممیزی ثبت می‌شود و از آنجا برگشت‌پذیر است.'])
        </aside>
    </div>
</div>
@endsection

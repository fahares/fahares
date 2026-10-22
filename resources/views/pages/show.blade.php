@extends('layouts.app')

@section('title', $page->title . ' | فهارس')
@section('meta_description', $page->excerpt)

@section('content')
<article class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

    <nav class="flex items-center gap-2 text-xs text-muted" aria-label="مسیر">
        <a href="{{ route('home') }}" class="hover:text-cinnabar">فهارس</a>
        <span aria-hidden="true">/</span>
        <span class="text-ink font-semibold truncate">{{ $page->title }}</span>
    </nav>

    <header class="text-center pb-2">
        <h1 class="font-naskh text-[26px] sm:text-[36px] font-bold leading-[1.6] text-ink text-balance">{{ $page->title }}</h1>
    </header>

    <div class="jadval" aria-hidden="true"></div>

    <div class="post-body">{!! $page->body_html !!}</div>

    @if(auth()->user()?->canAccessAdmin())
        <p class="pt-6 border-t border-line text-xs">
            <a href="{{ route('admin.pages.edit', $page) }}" class="text-cinnabar hover:underline font-bold">ویرایش این صفحه در پنل</a>
        </p>
    @endif
</article>
@endsection

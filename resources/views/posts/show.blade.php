@extends('layouts.app')

@section('title', $post->title . ' | فهارس')
@section('meta_description', $post->excerpt)
@section('og_type', 'article')
@if($post->cover_url)
    @section('og_image', $post->cover_url)
@endif

@section('content')
<article class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

    <nav class="flex items-center gap-2 text-xs text-muted" aria-label="مسیر">
        <a href="{{ route('home') }}" class="hover:text-cinnabar">فهارس</a>
        <span aria-hidden="true">/</span>
        <a href="{{ route('posts.index') }}" class="hover:text-cinnabar">جریده</a>
        <span aria-hidden="true">/</span>
        <span class="text-ink font-semibold truncate">{{ $post->title }}</span>
    </nav>

    @unless($post->isPublished())
        <p class="p-3 rounded-sm border border-amber-300 bg-amber-50 dark:bg-amber-950/30 text-amber-800 dark:text-amber-200 text-sm">
            پیش‌نویس: این صفحه هنوز منتشر نشده و فقط برای کاربران پنل مدیریت دیده می‌شود.
            <a href="{{ route('admin.posts.edit', $post) }}" class="font-bold underline">ویرایش</a>
        </p>
    @endunless

    <header class="text-center grid justify-items-center gap-2 pb-2">
        <h1 class="font-naskh text-[26px] sm:text-[36px] font-bold leading-[1.6] text-ink text-balance">{{ $post->title }}</h1>
        <p class="text-sm text-muted">
            @if($post->byline){{ $post->byline }}@endif
            @if($post->byline && $post->published_at)<span aria-hidden="true"> · </span>@endif
            @if($post->published_at)<time datetime="{{ $post->published_at->toIso8601String() }}">@jdate($post->published_at, 'long')</time>@endif
        </p>
    </header>

    <div class="jadval" aria-hidden="true"></div>

    @if($post->cover_url)
        <figure class="space-y-2">
            <img src="{{ $post->cover_url }}" alt="{{ $post->cover_caption ?: $post->title }}" class="w-full rounded-sm border border-line">
            @if($post->cover_caption)
                <figcaption class="text-xs text-muted text-center">{{ $post->cover_caption }}</figcaption>
            @endif
        </figure>
    @endif

    <div class="post-body">{!! $post->body_html !!}</div>

    @if(auth()->user()?->can('posts.manage'))
        <p class="pt-6 border-t border-line text-xs">
            <a href="{{ route('admin.posts.edit', $post) }}" class="text-cinnabar hover:underline font-bold">ویرایش این یادداشت در پنل</a>
        </p>
    @endif
</article>
@endsection

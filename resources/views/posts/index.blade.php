@extends('layouts.app')

@section('title', 'جریده | فهارس')
@section('meta_description', 'گزارش نشست‌های علمی، معرفی نسخه‌های خطی و یادداشت‌های دیگر در فهارس')

@section('content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

    <header class="text-center grid justify-items-center gap-2 pb-2">
        <h1 class="font-naskh text-[28px] sm:text-[38px] font-bold leading-[1.6] text-ink text-balance">جریده</h1>
        <p class="text-sm text-muted max-w-2xl leading-relaxed">گزارش نشست‌های علمی، معرفی نسخه‌ها و نوشته‌های دیگر.</p>
    </header>

    <div class="jadval" aria-hidden="true"></div>

    @if($posts->isEmpty())
        <p class="py-12 text-center text-muted">هنوز یادداشتی منتشر نشده است.</p>
    @else
        <ul class="divide-y divide-line">
            @foreach($posts as $post)
                <li>
                    <a href="{{ route('posts.show', $post) }}" class="flex gap-4 py-5 group">
                        @if($post->cover_url)
                            <img src="{{ $post->cover_url }}" alt="" class="w-24 h-24 sm:w-32 sm:h-24 object-cover rounded-sm border border-line shrink-0" loading="lazy">
                        @endif
                        <span class="min-w-0 space-y-1">
                            <span class="block font-naskh text-lg font-bold text-ink group-hover:text-cinnabar transition leading-relaxed">{{ $post->title }}</span>
                            <span class="block text-xs text-muted">
                                {{ $post->byline ? $post->byline . ' · ' : '' }}@jdate($post->published_at, 'long')
                            </span>
                            <span class="block text-sm text-ink/80 leading-relaxed line-clamp-3">{{ $post->excerpt }}</span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>

        {{ $posts->links() }}
    @endif
</div>
@endsection

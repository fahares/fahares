@extends('layouts.admin')

@section('title', 'جریده')
@section('page_title', 'جریده')
@section('page_actions')
    <x-ui.btn :href="route('posts.index')" target="_blank" size="sm">فهرست در سایت ↗</x-ui.btn>
    <x-ui.btn :href="route('admin.posts.create')" size="sm" variant="primary">یادداشت تازه</x-ui.btn>
@endsection

@section('content')
<p class="text-[13px] text-muted leading-7 mb-4 max-w-3xl">گزارش نشست‌ها، معرفی نسخه‌ها و نوشته‌های دیگری که در سایت منتشر می‌شوند. پیش‌نویس‌ها فقط برای کاربران پنل دیده می‌شوند.</p>

<x-admin.table :paginator="$posts" :is-empty="$posts->isEmpty()" empty="هنوز یادداشتی نوشته نشده است.">
    <x-slot:head>
        <x-admin.th>عنوان</x-admin.th>
        <x-admin.th>وضعیت</x-admin.th>
        <x-admin.th>نشانی برای پیوند</x-admin.th>
        <x-admin.th><span class="sr-only">کنش‌ها</span></x-admin.th>
    </x-slot:head>
    @foreach($posts as $post)
        <x-admin.row>
            <x-admin.cell main>
                <a href="{{ route('admin.posts.edit', $post) }}" class="font-bold text-ink hover:text-cinnabar">{{ $post->title }}</a>
                <span class="text-xs text-muted tabular-nums">#{{ \App\Support\Fa::digits($post->id) }}</span>
                @if($post->byline)<span class="block text-xs text-muted">{{ $post->byline }}</span>@endif
            </x-admin.cell>
            <x-admin.cell label="وضعیت" class="whitespace-nowrap">
                @if($post->isPublished())
                    <x-ui.chip tone="good">منتشرشده، @jdate($post->published_at, 'long')</x-ui.chip>
                @else
                    <x-ui.chip>پیش‌نویس</x-ui.chip>
                @endif
            </x-admin.cell>
            <x-admin.cell label="نشانی"><code class="text-xs text-muted" dir="ltr">/posts/{{ $post->id }}</code></x-admin.cell>
            <x-admin.cell actions>
                <x-ui.btn :href="route('admin.posts.edit', $post)" size="sm">ویرایش</x-ui.btn>
                <x-ui.btn :href="route('posts.show', $post)" target="_blank" size="sm" variant="ghost">{{ $post->isPublished() ? 'دیدن' : 'پیش‌نمایش' }} ↗</x-ui.btn>
            </x-admin.cell>
        </x-admin.row>
    @endforeach
</x-admin.table>
@endsection

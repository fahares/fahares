@extends('layouts.admin')

@section('title', 'یادداشت‌ها')
@section('page_title', 'یادداشت‌ها')
@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-cinnabar">پیشخوان</a>
    <span>/</span>
    <span class="text-stone-400">یادداشت‌ها</span>
@endsection

@php
    $btn = 'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold border border-line-strong hover:border-gold hover:text-cinnabar transition cursor-pointer';
    $btnPri = 'inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-xs font-bold bg-cinnabar text-on-cinnabar hover:opacity-90 transition cursor-pointer';
@endphp

@section('content')
<div class="space-y-6 text-xs">

    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line pb-4">
        <p class="text-stone-500 max-w-3xl">گزارش نشست‌ها، معرفی نسخه‌ها و نوشته‌های دیگری که در سایت منتشر می‌شوند. پیش‌نویس‌ها فقط برای کاربران پنل دیده می‌شوند.</p>
        <div class="flex items-center gap-2">
            <a href="{{ route('posts.index') }}" target="_blank" class="{{ $btn }}">فهرست در سایت ↗</a>
            <a href="{{ route('admin.posts.create') }}" class="{{ $btnPri }}">+ یادداشت تازه</a>
        </div>
    </div>

    <div class="bg-surface rounded-2xl border border-line shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-paper text-stone-500 border-b border-line">
                    <tr>
                        <th class="p-4 font-bold">شناسه</th>
                        <th class="p-4 font-bold">عنوان</th>
                        <th class="p-4 font-bold">وضعیت</th>
                        <th class="p-4 font-bold">نشانی برای پیوند</th>
                        <th class="p-4 font-bold text-center">عملیات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse($posts as $post)
                        <tr class="hover:bg-paper transition">
                            <td class="p-4 font-mono font-bold text-stone-400">#{{ $post->id }}</td>
                            <td class="p-4">
                                <a href="{{ route('admin.posts.edit', $post) }}" class="font-bold text-ink hover:text-cinnabar">{{ $post->title }}</a>
                                @if($post->byline)<span class="block text-stone-400 mt-0.5">{{ $post->byline }}</span>@endif
                            </td>
                            <td class="p-4 whitespace-nowrap">
                                @if($post->isPublished())
                                    <span class="px-2 py-0.5 rounded-full font-bold bg-emerald-500/10 text-emerald-600">منتشرشده، @jdate($post->published_at, 'long')</span>
                                @else
                                    <span class="px-2 py-0.5 rounded-full font-bold bg-amber-500/10 text-amber-600">پیش‌نویس</span>
                                @endif
                            </td>
                            <td class="p-4 font-mono text-stone-500" dir="ltr">/posts/{{ $post->id }}</td>
                            <td class="p-4 text-center whitespace-nowrap space-x-2 space-x-reverse">
                                <a href="{{ route('admin.posts.edit', $post) }}" class="{{ $btn }}">ویرایش</a>
                                <a href="{{ route('posts.show', $post) }}" target="_blank" class="{{ $btn }}">{{ $post->isPublished() ? 'دیدن' : 'پیش‌نمایش' }} ↗</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="p-10 text-center text-stone-400">هنوز یادداشتی نوشته نشده است.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{ $posts->links() }}
</div>
@endsection

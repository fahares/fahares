@extends('layouts.admin')

@php
    $exists = $post->exists;
    $heading = $exists ? "ویرایش یادداشت: {$post->title}" : 'یادداشت تازه';
    $box = 'bg-surface rounded-2xl border border-line shadow-xs';
    $input = 'w-full p-2.5 rounded-xl border border-line-strong bg-paper text-ink focus:border-gold outline-none';
    $label = 'block font-bold text-ink mb-1';
    $hint = 'text-[11px] text-stone-400 mt-1';
    $btn = 'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold border border-line-strong hover:border-gold hover:text-cinnabar transition cursor-pointer';
    $btnPri = 'inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-xs font-bold bg-cinnabar text-on-cinnabar hover:opacity-90 transition cursor-pointer';
@endphp

@section('title', $heading)
@section('page_title', $heading)
@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-cinnabar">پیشخوان</a>
    <span>/</span>
    <a href="{{ route('admin.posts.index') }}" class="hover:text-cinnabar">یادداشت‌ها</a>
    <span>/</span>
    <span class="text-stone-400">{{ $exists ? "ویرایش #{$post->id}" : 'تازه' }}</span>
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

    @if($exists)
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line pb-4">
            <div class="space-y-1">
                <p class="text-stone-500">نشانی صفحه، برای پیوند بنر یا جاهای دیگر:</p>
                <code class="font-mono text-ink font-bold" dir="ltr">/posts/{{ $post->id }}</code>
            </div>
            <a href="{{ route('posts.show', $post) }}" target="_blank" class="{{ $btn }}">{{ $post->isPublished() ? 'دیدن در سایت' : 'پیش‌نمایش' }} ↗</a>
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">

        <form action="{{ $exists ? route('admin.posts.update', $post) : route('admin.posts.store') }}" method="POST" enctype="multipart/form-data" class="{{ $box }} p-6 space-y-5">
            @csrf
            @if($exists) @method('PUT') @endif

            <div>
                <label for="title" class="{{ $label }}">عنوان</label>
                <input type="text" name="title" id="title" value="{{ old('title', $post->title) }}" required class="{{ $input }} text-sm font-bold">
            </div>

            <div>
                <label for="byline" class="{{ $label }}">نویسنده یا گزارشگر (اختیاری)</label>
                <input type="text" name="byline" id="byline" value="{{ old('byline', $post->byline) }}" class="{{ $input }}" placeholder="مثلاً: گزارش از …">
            </div>

            <div>
                <label for="summary" class="{{ $label }}">چکیده (اختیاری)</label>
                <textarea name="summary" id="summary" rows="2" class="{{ $input }}">{{ old('summary', $post->summary) }}</textarea>
                <p class="{{ $hint }}">در فهرست یادداشت‌ها و در پیش‌نمایش پیوند (شبکه‌های اجتماعی و موتورهای جستجو) می‌آید. اگر خالی بماند، آغاز متن به کار می‌رود.</p>
            </div>

            <div>
                <label for="body" class="{{ $label }}">متن (Markdown)</label>
                <textarea name="body" id="body" rows="24" class="{{ $input }} font-mono text-[13px] leading-7">{{ old('body', $post->body) }}</textarea>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="cover" class="{{ $label }}">{{ $post->cover_path ? 'تصویر شاخص تازه (اختیاری)' : 'تصویر شاخص (اختیاری)' }}</label>
                    <input type="file" name="cover" id="cover" accept="image/jpeg,image/png,image/webp" class="{{ $input }} file:ml-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-cinnabar file:text-on-cinnabar">
                    @if($post->cover_path)
                        <div class="mt-2 flex items-center gap-3">
                            <img src="{{ $post->cover_url }}" alt="" class="h-16 rounded-lg border border-line">
                            <label class="inline-flex items-center gap-2"><input type="checkbox" name="remove_cover" value="1"> حذف تصویر شاخص</label>
                        </div>
                    @endif
                </div>
                <div>
                    <label for="cover_caption" class="{{ $label }}">زیرنویس تصویر شاخص (اختیاری)</label>
                    <input type="text" name="cover_caption" id="cover_caption" value="{{ old('cover_caption', $post->cover_caption) }}" class="{{ $input }}">
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-5 border-t border-line pt-4">
                <label class="inline-flex items-center gap-2 font-bold">
                    <input type="checkbox" name="is_published" value="1" {{ old('is_published', $post->published_at !== null) ? 'checked' : '' }}>
                    منتشر شود
                </label>
                @if($post->published_at)
                    <span class="text-stone-400">تاریخ انتشار: @jdate($post->published_at, 'long')</span>
                @endif
                <button type="submit" class="mr-auto {{ $btnPri }}">{{ $exists ? 'ذخیره' : 'ذخیره و ادامه' }}</button>
            </div>
        </form>

        <aside class="space-y-6">
            <section id="images" class="{{ $box }} p-5 space-y-4">
                <h2 class="font-bold text-ink text-sm">تصویرهای متن</h2>
                @if(! $exists)
                    <p class="text-stone-500">پس از نخستین ذخیره می‌توانید تصویر بارگذاری کنید.</p>
                @else
                    <form action="{{ route('admin.posts.images.store', $post) }}" method="POST" enctype="multipart/form-data" class="space-y-2">
                        @csrf
                        <input type="file" name="images[]" multiple required accept="image/jpeg,image/png,image/webp" class="{{ $input }} file:ml-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-cinnabar file:text-on-cinnabar">
                        <button type="submit" class="{{ $btn }}">بارگذاری</button>
                    </form>
                    <p class="text-stone-500">نشانی هر تصویر را رونوشت کنید و در متن، هر جا که تصویر باید بیاید، بگذارید. متن پس از ذخیره روی صفحه دیده می‌شود.</p>
                    <ul class="space-y-3">
                        @forelse($post->images() as $image)
                            @php $snippet = "![توضیح تصویر]({$image['url']})"; @endphp
                            <li class="flex gap-3 items-start border-t border-line pt-3" x-data="{ copied: false }">
                                <a href="{{ $image['url'] }}" target="_blank" class="shrink-0"><img src="{{ $image['url'] }}" alt="" class="w-16 h-16 object-cover rounded-lg border border-line"></a>
                                <div class="min-w-0 flex-1 space-y-1.5">
                                    <code class="block font-mono text-[10px] text-stone-500 break-all" dir="ltr">{{ $snippet }}</code>
                                    <div class="flex items-center gap-2">
                                        <button type="button" class="{{ $btn }}" @click="navigator.clipboard.writeText(@js($snippet)); copied = true; setTimeout(() => copied = false, 1500)">
                                            <span x-text="copied ? 'رونوشت شد' : 'رونوشت'">رونوشت</span>
                                        </button>
                                        <form action="{{ route('admin.posts.images.destroy', $post) }}" method="POST" onsubmit="return confirm('این تصویر حذف شود؟ اگر در متن به کار رفته باشد، از صفحه هم برداشته می‌شود.')">
                                            @csrf @method('DELETE')
                                            <input type="hidden" name="name" value="{{ $image['name'] }}">
                                            <button type="submit" class="text-red-600 hover:underline cursor-pointer">حذف</button>
                                        </form>
                                    </div>
                                </div>
                            </li>
                        @empty
                            <li class="text-stone-400">هنوز تصویری بارگذاری نشده است.</li>
                        @endforelse
                    </ul>
                @endif
            </section>

            <section class="{{ $box }} p-5 space-y-2 text-stone-600 dark:text-stone-300">
                <h2 class="font-bold text-ink text-sm">راهنمای کوتاه Markdown</h2>
                <dl class="space-y-1.5 [&_code]:font-mono [&_code]:text-[11px] [&_code]:text-ink [&_dt]:inline [&_dd]:inline">
                    <div><dt><code>## عنوان</code></dt> <dd>— تیتر بخش (<code>###</code> برای زیرتیتر)</dd></div>
                    <div><dt><code>**پررنگ**</code></dt> <dd>— پررنگ؛ <code>*کج*</code> برای کج</dd></div>
                    <div><dt><code>- مورد</code></dt> <dd>— فهرست؛ <code>1. مورد</code> برای فهرست شماره‌دار</dd></div>
                    <div><dt><code>&gt; متن</code></dt> <dd>— نقل‌قول</dd></div>
                    <div><dt><code>[متن](/works/123)</code></dt> <dd>— پیوند</dd></div>
                    <div><dt><code>![توضیح](نشانی)</code></dt> <dd>— تصویر</dd></div>
                    <div><dt><code>---</code></dt> <dd>— خط جداکننده</dd></div>
                </dl>
                <p class="text-stone-400">برای آغاز بند تازه، یک سطر خالی بگذارید.</p>
            </section>

            @if($exists)
                <form action="{{ route('admin.posts.destroy', $post) }}" method="POST" onsubmit="return confirm('این یادداشت و همه‌ی تصویرهایش حذف شود؟ این کار برگشت‌پذیر نیست.')" class="{{ $box }} p-5">
                    @csrf @method('DELETE')
                    <button type="submit" class="text-red-600 font-bold hover:underline cursor-pointer">حذف این یادداشت</button>
                </form>
            @endif
        </aside>
    </div>
</div>
@endsection

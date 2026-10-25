@extends('layouts.admin')

@php
    $exists = $post->exists;
    $heading = $exists ? $post->title : 'یادداشت تازه';
    $box = 'bg-surface border border-line';
    $input = 'admin-field w-full';
    $label = 'block font-bold text-ink mb-1';
    $hint = 'text-xs text-muted leading-6 mt-1';
    $btn = 'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-sm text-xs font-semibold border border-line-strong bg-surface text-ink hover:border-cinnabar hover:text-cinnabar transition cursor-pointer';
    $btnPri = 'inline-flex items-center gap-1.5 px-4 py-2 rounded-sm text-xs font-semibold bg-cinnabar text-on-cinnabar hover:opacity-90 transition cursor-pointer';
@endphp

@section('title', $heading)
@section('page_title', $heading)
@section('breadcrumb')
    <a href="{{ route('admin.posts.index') }}" class="hover:text-cinnabar">جریده</a>
    <span>/</span>
    <span>{{ $exists ? 'ویرایش #' . \App\Support\Fa::digits($post->id) : 'یادداشت تازه' }}</span>
@endsection
@if($exists)
    @section('page_actions')
        <x-ui.btn :href="route('posts.show', $post)" target="_blank" size="sm">{{ $post->isPublished() ? 'دیدن در سایت' : 'پیش‌نمایش' }} ↗</x-ui.btn>
    @endsection
@endif

@section('content')
<div class="space-y-5 text-[13px]">

    @if($errors->any())
        <x-admin.notice tone="error" class="space-y-1">
            @foreach($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </x-admin.notice>
    @endif

    @if($exists)
        <p class="text-muted">نشانی صفحه، برای پیوند بنر یا جاهای دیگر: <code class="text-ink font-bold" dir="ltr">/posts/{{ $post->id }}</code></p>
    @endif

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_320px] items-start">

        <form action="{{ $exists ? route('admin.posts.update', $post) : route('admin.posts.store') }}" method="POST" enctype="multipart/form-data" class="{{ $box }} p-4 sm:p-5 space-y-5">
            @csrf
            @if($exists) @method('PUT') @endif

            <div>
                <label for="title" class="{{ $label }}">عنوان</label>
                <input type="text" name="title" id="title" value="{{ old('title', $post->title) }}" required class="{{ $input }} text-base font-bold">
            </div>

            <div>
                <label for="byline" class="{{ $label }}">نویسنده یا گزارشگر (اختیاری)</label>
                <input type="text" name="byline" id="byline" value="{{ old('byline', $post->byline) }}" class="{{ $input }}" placeholder="مثلاً: گزارش از …">
            </div>

            <div>
                <label for="summary" class="{{ $label }}">چکیده (اختیاری)</label>
                <textarea name="summary" id="summary" rows="2" class="{{ $input }}">{{ old('summary', $post->summary) }}</textarea>
                <p class="{{ $hint }}">در فهرست جریده و در پیش‌نمایش پیوند (شبکه‌های اجتماعی و موتورهای جستجو) می‌آید. اگر خالی بماند، آغاز متن به کار می‌رود.</p>
            </div>

            <div>
                <label for="body" class="{{ $label }}">متن <span class="font-normal text-muted">(Markdown)</span></label>
                <textarea name="body" id="body" rows="24" class="{{ $input }} text-sm leading-8">{{ old('body', $post->body) }}</textarea>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="cover" class="{{ $label }}">{{ $post->cover_path ? 'تصویر شاخص تازه (اختیاری)' : 'تصویر شاخص (اختیاری)' }}</label>
                    <x-admin.file name="cover" accept="image/jpeg,image/png,image/webp" />
                    @if($post->cover_path)
                        <div class="mt-2 flex items-center gap-3">
                            <img src="{{ $post->cover_url }}" alt="" class="h-16 rounded-sm border border-line">
                            <label class="inline-flex items-center gap-2 cursor-pointer"><input type="checkbox" name="remove_cover" value="1" class="accent-cinnabar"> حذف تصویر شاخص</label>
                        </div>
                    @endif
                </div>
                <div>
                    <label for="cover_caption" class="{{ $label }}">زیرنویس تصویر شاخص (اختیاری)</label>
                    <input type="text" name="cover_caption" id="cover_caption" value="{{ old('cover_caption', $post->cover_caption) }}" class="{{ $input }}">
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-5 border-t border-line pt-4">
                <label class="inline-flex items-center gap-2 font-bold cursor-pointer">
                    <input type="checkbox" name="is_published" value="1" {{ old('is_published', $post->published_at !== null) ? 'checked' : '' }} class="size-4 accent-cinnabar">
                    منتشر شود
                </label>
                @if($post->published_at)
                    <span class="text-xs text-muted">تاریخ انتشار: @jdate($post->published_at, 'long')</span>
                @endif
                <button type="submit" class="ms-auto {{ $btnPri }}">{{ $exists ? 'ذخیره' : 'ذخیره و ادامه' }}</button>
            </div>
        </form>

        <aside class="space-y-5">
            <section id="images" class="{{ $box }} p-4 sm:p-5 space-y-4">
                <h2 class="rubric text-base">تصویرهای متن</h2>
                @if(! $exists)
                    <p class="text-muted">پس از نخستین ذخیره می‌توانید تصویر بارگذاری کنید.</p>
                @else
                    <form action="{{ route('admin.posts.images.store', $post) }}" method="POST" enctype="multipart/form-data" class="space-y-2">
                        @csrf
                        <x-admin.file name="images[]" id="post_images" accept="image/jpeg,image/png,image/webp" multiple required />
                        <button type="submit" class="{{ $btn }}">بارگذاری</button>
                    </form>
                    <p class="text-xs text-muted leading-6">نشانی هر تصویر را رونوشت کنید و در متن، هر جا که تصویر باید بیاید، در سطری جداگانه بگذارید. به جای «توضیح تصویر» زیرنویس آن را بنویسید تا زیر تصویر بیاید. متن پس از ذخیره روی صفحه دیده می‌شود.</p>
                    <ul class="space-y-3">
                        @forelse($post->images() as $image)
                            @php $snippet = '![' . \App\Models\Post::IMAGE_ALT_PLACEHOLDER . "]({$image['url']})"; @endphp
                            <li class="flex gap-3 items-start border-t border-line pt-3" x-data="{ copied: false }">
                                <a href="{{ $image['url'] }}" target="_blank" class="shrink-0"><img src="{{ $image['url'] }}" alt="" class="w-16 h-16 object-cover rounded-sm border border-line"></a>
                                <div class="min-w-0 flex-1 space-y-1.5">
                                    <code class="block text-[11px] text-muted break-all" dir="ltr">{{ $snippet }}</code>
                                    <div class="flex items-center gap-2">
                                        <button type="button" class="{{ $btn }}" @click="navigator.clipboard.writeText(@js($snippet)); copied = true; setTimeout(() => copied = false, 1500)">
                                            <span x-text="copied ? 'رونوشت شد' : 'رونوشت'">رونوشت</span>
                                        </button>
                                        <form action="{{ route('admin.posts.images.destroy', $post) }}" method="POST" onsubmit="return confirm('این تصویر حذف شود؟ اگر در متن به کار رفته باشد، از صفحه هم برداشته می‌شود.')">
                                            @csrf @method('DELETE')
                                            <input type="hidden" name="name" value="{{ $image['name'] }}">
                                            <button type="submit" class="text-xs text-cinnabar hover:underline cursor-pointer">حذف</button>
                                        </form>
                                    </div>
                                </div>
                            </li>
                        @empty
                            <li class="text-muted">هنوز تصویری بارگذاری نشده است.</li>
                        @endforelse
                    </ul>
                @endif
            </section>

            @include('admin.partials.markdown-guide', ['images' => true])

            @if($exists)
                <form action="{{ route('admin.posts.destroy', $post) }}" method="POST" onsubmit="return confirm('این یادداشت و همه‌ی تصویرهایش حذف شود؟ این کار برگشت‌پذیر نیست.')" class="{{ $box }} p-4 sm:p-5">
                    @csrf @method('DELETE')
                    <button type="submit" class="text-cinnabar font-bold hover:underline cursor-pointer">حذف این یادداشت</button>
                </form>
            @endif
        </aside>
    </div>
</div>
@endsection

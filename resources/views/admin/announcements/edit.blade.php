@extends('layouts.admin')

@php
    $exists = $announcement->exists;
    $heading = $exists ? $announcement->title : 'اعلان تازه';
    $status = $exists ? $announcement->status() : 'off';
    $input = 'admin-field w-full';
    $posted = $exists && $announcement->telegram_message_id;
    $postUrl = $exists ? \App\Support\AnnouncementTelegram::postUrl($announcement) : null;
    // What the Telegram preview below starts from; the fields keep it up to date
    $draft = [
        'title' => (string) old('title', $announcement->title), 'body' => (string) old('body', $announcement->body),
        'link' => (string) old('link_url', $announcement->link_url), 'label' => (string) old('link_label', $announcement->link_label),
        'own' => (string) old('telegram_text', $announcement->telegram_text), 'audience' => (string) old('audience', $announcement->audience),
        'telegram' => (bool) old('telegram', $announcement->telegram),
        'footer' => trim((string) \App\Models\SiteSetting::get('telegram.footer')),
        'more' => \App\Support\AnnouncementTelegram::DEFAULT_LINK_LABEL,
    ];
@endphp

@section('title', $heading)
@section('page_title', $heading)
@section('breadcrumb')
    <a href="{{ route('admin.announcements.index') }}" class="hover:text-cinnabar">اعلان‌های سایت</a>
    <span>/</span>
    <span>{{ $exists ? 'ویرایش #' . \App\Support\Fa::digits($announcement->id) : 'اعلان تازه' }}</span>
@endsection
@if($exists)
    @section('page_actions')
        <x-ui.btn :href="route('home', ['announcement_preview' => $announcement->id])" target="_blank" size="sm">پیش‌نمایش در سایت ↗</x-ui.btn>
    @endsection
@endif

@section('content')
<div class="space-y-5 text-[13px] max-w-3xl">

    @if($errors->any())
        <x-admin.notice tone="error" class="space-y-1">
            @foreach($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </x-admin.notice>
    @endif

    @if($exists)
        <p class="text-muted leading-7">
            @if($status === 'running')
                این اعلان روشن است و تا @jdate($announcement->ends_at, 'long') نشان داده می‌شود.
            @elseif($status === 'ended')
                دوره‌ی نمایش این اعلان در @jdate($announcement->ends_at, 'long') پایان یافت.
            @else
                این اعلان خاموش است و کسی آن را نمی‌بیند.
            @endif
            تاکنون {{ \App\Support\Fa::num($announcement->views_count) }} بار دیده شده@if($announcement->link_url) و {{ \App\Support\Fa::num($announcement->clicks_count) }} بار روی پیوندش کلیک شده@endif است.
            @if($posted)
                پست آن در @jdate($announcement->telegram_sent_at, 'long') به کانال تلگرام رفت@if($postUrl) (<a href="{{ $postUrl }}" target="_blank" rel="noopener" class="text-cinnabar hover:underline">دیدن پست ↗</a>)@endif.
            @endif
        </p>
        @if($announcement->telegram_error)
            <x-admin.notice tone="error" class="flex flex-wrap items-center justify-between gap-3">
                <span>{{ $posted ? 'ویرایش پست کانال تلگرام انجام نشد' : 'این اعلان به کانال تلگرام نرفت' }}: {{ $announcement->telegram_error }}</span>
                <form action="{{ route('admin.announcements.telegram', $announcement) }}" method="POST">
                    @csrf
                    <button type="submit" class="text-xs font-bold text-cinnabar hover:underline cursor-pointer">دوباره بفرست</button>
                </form>
            </x-admin.notice>
        @endif
    @endif

    <form action="{{ $exists ? route('admin.announcements.update', $announcement) : route('admin.announcements.store') }}" method="POST" enctype="multipart/form-data" class="bg-surface border border-line p-4 sm:p-5 space-y-5"
          x-data="{ ...@js($draft), get count() { return [this.own.trim() || [this.title.trim(), this.body.trim()].filter(Boolean).join('\n\n'), this.link.trim() ? '🔗 ' + (this.label.trim() || this.more) : '', this.footer].filter(Boolean).join('\n\n').length } }">
        @csrf
        @if($exists) @method('PUT') @endif

        <x-admin.field label="عنوان" for="title" hint="کوتاه و گویا، مانند «کانال تلگرام فهارس راه افتاد».">
            <input type="text" name="title" id="title" x-model="title" required maxlength="120" class="{{ $input }} text-base font-bold">
        </x-admin.field>

        <x-admin.field label="متن (اختیاری)" for="body" hint="یک تا سه جمله. متن ساده است و شکستن سطر در آن می‌ماند. اگر مطلب بلند است، آن را در «نوشته‌ها» بنویسید و اینجا به آن پیوند بدهید.">
            <textarea name="body" id="body" rows="3" maxlength="600" x-model="body" class="{{ $input }} leading-7"></textarea>
        </x-admin.field>

        <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_200px]">
            <x-admin.field label="پیوند دکمه (اختیاری)" for="link_url" hint="نشانی صفحه‌ای از همین سایت، مانند ‎/posts/12‎، یا نشانی کامل با https://">
                <input type="text" name="link_url" id="link_url" dir="ltr" x-model="link" maxlength="500" class="{{ $input }}" placeholder="/posts/12">
            </x-admin.field>
            <x-admin.field label="نوشته‌ی دکمه" for="link_label" hint="اگر خالی بماند: «بیشتر».">
                <input type="text" name="link_label" id="link_label" x-model="label" maxlength="40" class="{{ $input }}" placeholder="بیشتر">
            </x-admin.field>
        </div>

        <x-admin.field :label="$announcement->image_path ? 'تصویر تازه (اختیاری)' : 'تصویر (اختیاری)'" for="image" hint="بالای اعلان می‌آید؛ تصویر افقی بهتر می‌نشیند. حداکثر ۲ مگابایت.">
            <x-admin.file name="image" accept="image/jpeg,image/png,image/webp" />
            @if($announcement->image_path)
                <div class="mt-2 flex items-center gap-3">
                    <img src="{{ $announcement->image_url }}" alt="" class="h-16 rounded-sm border border-line">
                    <label class="inline-flex items-center gap-2 cursor-pointer"><input type="checkbox" name="remove_image" value="1" class="accent-cinnabar"> حذف تصویر</label>
                </div>
            @endif
        </x-admin.field>

        <div class="grid gap-4 sm:grid-cols-3">
            <x-admin.field label="شکل نمایش" for="style" hint="پنجره صفحه را می‌پوشاند؛ آن را برای خبرهای مهم و کم‌شمار نگه دارید.">
                <select name="style" id="style" class="{{ $input }}">
                    @foreach(\App\Models\Announcement::STYLES as $key => $label)
                        <option value="{{ $key }}" @selected(old('style', $announcement->style) === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </x-admin.field>
            <x-admin.field label="مخاطب" for="audience">
                <select name="audience" id="audience" x-model="audience" class="{{ $input }}">
                    @foreach(\App\Models\Announcement::AUDIENCES as $key => $label)
                        <option value="{{ $key }}" @selected(old('audience', $announcement->audience) === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </x-admin.field>
            <x-admin.field label="مدت نمایش (روز)" for="days" hint="از لحظه‌ی روشن شدن شمرده می‌شود.">
                <input type="number" name="days" id="days" min="1" max="365" value="{{ old('days', $announcement->days) }}" required class="{{ $input }}" dir="ltr">
            </x-admin.field>
        </div>

        {{-- The same notice as a post of the site's Telegram channel --}}
        <div class="border-t border-line pt-4 space-y-3">
            <div class="space-y-1">
                <label class="inline-flex items-center gap-2 font-bold cursor-pointer" :class="audience !== 'all' && 'opacity-50'">
                    <input type="checkbox" name="telegram" value="1" x-model="telegram" :disabled="audience !== 'all'" class="size-4 accent-cinnabar">
                    در کانال تلگرام هم منتشر شود
                </label>
                <p class="text-xs text-muted leading-6">
                    @if($posted)
                        پست این اعلان در کانال هست. با ذخیره‌ی تغییر متن، همان پست ویرایش می‌شود؛ تصویر آن عوض نمی‌شود و با خاموش یا حذف شدن اعلان، پست می‌ماند.
                    @else
                        یک بار، هنگامی که اعلان نخستین بار روشن می‌شود، به کانال می‌رود. پست کانال برگشت‌پذیر نیست؛ پیش از روشن کردن، پیش‌نمایش پایین را بخوانید.
                    @endif
                    <span x-show="audience !== 'all'" x-cloak>اعلانی که مخاطبش «همه» نیست به کانال نمی‌رود.</span>
                </p>
            </div>

            <div x-show="telegram && audience === 'all'" x-cloak class="space-y-3">
                <x-admin.field label="متن تلگرام (اختیاری)" for="telegram_text" error="telegram_text" hint="اگر خالی بماند، عنوان و متن بالا می‌رود. اگر پر شود، همین نوشته به‌جای آن دو می‌رود (برای emoji یا عبارت‌بندی دیگر). پیوند و پانویس در هر دو حال خودکار افزوده می‌شوند.">
                    <textarea name="telegram_text" id="telegram_text" rows="4" maxlength="3500" x-model="own" class="{{ $input }} leading-7"></textarea>
                </x-admin.field>

                <div class="space-y-1">
                    <div class="flex flex-wrap items-center justify-between gap-x-3">
                        <span class="font-bold text-ink">پیش‌نمایش پست کانال</span>
                        <span class="text-xs text-muted tabular-nums"><span x-text="count.toLocaleString('fa-IR')"></span> نویسه (حد: ۱٬۰۲۴ با تصویر، ۴٬۰۹۶ بدون تصویر)</span>
                    </div>
                    <div class="max-w-md bg-paper border border-line rounded-lg px-4 py-3 text-sm leading-7 text-ink whitespace-pre-line break-words" dir="auto"><template x-if="own.trim()"><span x-text="own.trim()"></span></template><template x-if="!own.trim()"><span><b x-text="title.trim()"></b><span x-show="body.trim()" x-text="'\n\n' + body.trim()"></span></span></template><span x-show="link.trim()" x-text="'\n\n🔗 '"></span><span x-show="link.trim()" class="underline text-cinnabar" x-text="label.trim() || more"></span><span x-show="footer" x-text="'\n\n' + footer"></span></div>
                </div>
            </div>
        </div>

        <div class="border-t border-line pt-4 space-y-1">
            <label class="inline-flex items-center gap-2 font-bold cursor-pointer">
                <input type="checkbox" name="is_active" value="1" {{ old('is_active', $status === 'running') ? 'checked' : '' }} class="size-4 accent-cinnabar">
                روشن باشد
            </label>
            <p class="text-xs text-muted leading-6">با روشن شدن، دوره‌ی نمایش از همین لحظه آغاز می‌شود. کسی که اعلان را پیش‌تر دیده، با روشن شدنِ دوباره آن را نمی‌بیند.</p>
        </div>

        <x-admin.form-actions :cancel="route('admin.announcements.index')" :submit="$exists ? 'ذخیره‌ی تغییرات' : 'ذخیره'" />
    </form>

    @if($exists)
        <form action="{{ route('admin.announcements.destroy', $announcement) }}" method="POST" onsubmit="return confirm('این اعلان حذف شود؟ این کار برگشت‌پذیر نیست.')" class="bg-surface border border-line p-4 sm:p-5 flex items-center justify-between gap-3">
            @csrf @method('DELETE')
            <span class="text-muted">برای برداشتن موقت اعلان، خاموش کردنش کافی است.</span>
            <button type="submit" class="text-xs font-bold text-cinnabar hover:underline cursor-pointer">حذف اعلان</button>
        </form>
    @endif
</div>
@endsection

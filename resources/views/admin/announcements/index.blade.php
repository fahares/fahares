@extends('layouts.admin')

@section('title', 'اعلان‌های سایت')
@section('page_title', 'اعلان‌های سایت')
@section('page_actions')
    <x-ui.btn :href="route('admin.announcements.create')" size="sm" variant="primary">اعلان تازه</x-ui.btn>
@endsection

@section('content')
<p class="text-[13px] text-muted leading-7 mb-4 max-w-3xl">خبرهای کوتاهی که به هر بازدیدکننده یک بار نشان داده می‌شود. اگر چند اعلان هم‌زمان روشن باشد، هر کس همه را می‌بیند، ولی یکی‌یکی: نخست تازه‌ترین، و بعدی دست‌کم {{ \App\Support\Fa::num(\App\Models\Announcement::GAP_HOURS) }} ساعت پس از آن. کسی که نخستین بار به سایت آمده، تا یک ساعت اعلانی نمی‌بیند. اعلانی که مخاطبش اعضا را هم دربر می‌گیرد، با نخستین روشن شدن در اعلان‌های هر عضو هم گذاشته می‌شود («تازه‌های فهارس» در تنظیمات اعلان او).</p>

<x-admin.table :paginator="$announcements" :is-empty="$announcements->isEmpty()" empty="هنوز اعلانی نوشته نشده است.">
    <x-slot:head>
        <x-admin.th>عنوان</x-admin.th>
        <x-admin.th>وضعیت</x-admin.th>
        <x-admin.th>شکل و مخاطب</x-admin.th>
        <x-admin.th>دیده‌شده</x-admin.th>
        <x-admin.th>کلیک روی پیوند</x-admin.th>
        <x-admin.th><span class="sr-only">کنش‌ها</span></x-admin.th>
    </x-slot:head>
    @foreach($announcements as $announcement)
        @php $status = $announcement->status(); @endphp
        <x-admin.row>
            <x-admin.cell main>
                <a href="{{ route('admin.announcements.edit', $announcement) }}" class="font-bold text-ink hover:text-cinnabar">{{ $announcement->title }}</a>
                @if($announcement->link_url)<code class="block text-xs text-muted truncate max-w-xs" dir="ltr">{{ $announcement->link_url }}</code>@endif
            </x-admin.cell>
            <x-admin.cell label="وضعیت" class="whitespace-nowrap">
                @if(! $announcement->on_site)
                    <x-ui.chip>فقط تلگرام{{ $announcement->telegram_message_id ? '، فرستاده‌شده' : '' }}</x-ui.chip>
                @elseif($status === 'running')
                    <x-ui.chip tone="good">روشن، تا @jdate($announcement->ends_at, 'long')</x-ui.chip>
                @elseif($status === 'ended')
                    <x-ui.chip>پایان‌یافته، @jdate($announcement->ends_at, 'long')</x-ui.chip>
                @else
                    <x-ui.chip>خاموش</x-ui.chip>
                @endif
            </x-admin.cell>
            <x-admin.cell label="شکل و مخاطب" class="text-muted">{{ $announcement->style === 'modal' ? 'پنجره' : 'کارت' }}، {{ \App\Models\Announcement::AUDIENCES[$announcement->audience] ?? $announcement->audience }}</x-admin.cell>
            <x-admin.cell label="دیده‌شده" class="tabular-nums">{{ \App\Support\Fa::num($announcement->views_count) }}</x-admin.cell>
            <x-admin.cell label="کلیک روی پیوند" class="tabular-nums">
                @if($announcement->link_url){{ \App\Support\Fa::num($announcement->clicks_count) }}@else<span class="text-muted">—</span>@endif
            </x-admin.cell>
            <x-admin.cell actions>
                <x-ui.btn :href="route('admin.announcements.edit', $announcement)" size="sm">ویرایش</x-ui.btn>
                <x-ui.btn :href="route('home', ['announcement_preview' => $announcement->id])" target="_blank" size="sm" variant="ghost">پیش‌نمایش ↗</x-ui.btn>
            </x-admin.cell>
        </x-admin.row>
    @endforeach
</x-admin.table>

{{-- Where the notices go on Telegram, and the line that closes every post there --}}
<form action="{{ route('admin.announcements.telegram.settings') }}" method="POST" class="mt-6 max-w-3xl bg-surface border border-line p-4 sm:p-5 space-y-4 text-[13px]">
    @csrf @method('PUT')
    <h2 class="font-bold text-ink text-sm">کانال تلگرام</h2>
    <p class="text-muted leading-7">اعلانی که تیک «در کانال تلگرام هم منتشر شود» دارد، با نخستین روشن شدن به این کانال می‌رود. ربات اعلان‌های فهارس باید مدیر کانال باشد و اجازه‌ی فرستادن و ویرایش پیام داشته باشد.</p>
    <div class="grid gap-4 sm:grid-cols-[200px_minmax(0,1fr)]">
        <x-admin.field label="نام کانال" for="channel" error="channel">
            <input type="text" name="channel" id="channel" dir="ltr" maxlength="64" value="{{ old('channel', \App\Models\SiteSetting::get('telegram.channel')) }}" class="admin-field w-full" placeholder="@fahares_net">
        </x-admin.field>
        <x-admin.field label="پانویس همه‌ی پست‌ها" for="footer" error="footer" hint="در پایان هر پست می‌آید. اگر خالی بماند، پست‌ها پانویس ندارند.">
            <input type="text" name="footer" id="footer" maxlength="200" value="{{ old('footer', \App\Models\SiteSetting::get('telegram.footer')) }}" class="admin-field w-full">
        </x-admin.field>
    </div>
    <div><x-ui.btn type="submit" size="sm" variant="primary">ذخیره‌ی تنظیمات تلگرام</x-ui.btn></div>
</form>
@endsection

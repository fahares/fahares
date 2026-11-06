@extends('layouts.admin')

@section('title', 'ثبت منبع تازه')
@section('page_title', 'ثبت منبع تازه')
@section('breadcrumb')
    <a href="{{ route('admin.sources.index') }}" class="hover:text-cinnabar">منابع</a>
    <span>/</span>
    <span>تازه</span>
@endsection

@section('content')
<div class="space-y-5 text-[13px] max-w-3xl">
    <x-admin.notice class="text-xs">
        این صفحه گام ۲ پروتکل افزودن منبع است: ساختن منبع و جلدهایش. پیش از ساختن، منابع هم‌عنوان جست‌وجو می‌شوند.
        پس از ساختن، الگوی ارجاع منبع را می‌افزایید تا مآخذ آثار به آن وصل شوند. تصویر برگه‌ها و متن صفحه‌ها همچنان با فرمان و طبق پروتکل افزودن منبع افزوده می‌شوند.
    </x-admin.notice>

    @if($similar->isNotEmpty())
        <x-admin.notice tone="warn" class="text-xs">
            <p class="font-bold mb-1">منابعی با عنوان نزدیک:</p>
            <ul class="space-y-0.5">
                @foreach($similar as $s)
                    <li><a href="{{ route('admin.sources.show', $s) }}" target="_blank" class="text-cinnabar">{{ $s->title }}</a>
                        <span class="text-muted" dir="ltr">{{ $s->code }}</span> · {{ \App\Support\Fa::num($s->volumes_count) }} جلد</li>
                @endforeach
            </ul>
        </x-admin.notice>
    @endif

    <x-ui.card>
        <form action="{{ route('admin.sources.store') }}" method="POST" class="space-y-5">
            @csrf
            <x-admin.field label="عنوان کامل" for="title" error="title">
                <input type="text" name="title" id="title" value="{{ old('title') }}" required class="admin-field w-full font-bold">
            </x-admin.field>
            <div class="grid gap-4 md:grid-cols-2">
                <x-admin.field label="نام کوتاه" for="short_name" error="short_name" hint="برای نمایش، مانند «دنا».">
                    <input type="text" name="short_name" id="short_name" value="{{ old('short_name') }}" class="admin-field w-full">
                </x-admin.field>
                <x-admin.field label="کد" for="code" error="code" hint="کد یکتا و پایدار، در نشانی صفحه‌ی منبع: حرف کوچک لاتین، رقم و خط تیره.">
                    <input type="text" name="code" id="code" value="{{ old('code') }}" required dir="ltr" class="admin-field w-full" placeholder="dena">
                </x-admin.field>
                <x-admin.field label="نوع" for="kind" error="kind">
                    <select name="kind" id="kind" class="admin-field w-full">
                        @foreach(\App\Models\Source::KIND_LABELS as $key => $label)
                            <option value="{{ $key }}" @selected(old('kind', 'bibliography') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </x-admin.field>
                <x-admin.field label="رتبه" for="rank" error="rank" hint="خالی: پیش‌فرض نوع.">
                    <input type="number" name="rank" id="rank" min="0" max="999" value="{{ old('rank') }}" class="admin-field w-full" dir="ltr">
                </x-admin.field>
                <x-admin.field label="پدیدآورنده" for="compiler">
                    <input type="text" name="compiler" id="compiler" value="{{ old('compiler') }}" class="admin-field w-full">
                </x-admin.field>
                <x-admin.field label="ناشر" for="publisher">
                    <input type="text" name="publisher" id="publisher" value="{{ old('publisher') }}" class="admin-field w-full">
                </x-admin.field>
                <x-admin.field label="سال نشر" for="publication_year">
                    <input type="text" name="publication_year" id="publication_year" value="{{ old('publication_year') }}" class="admin-field w-full">
                </x-admin.field>
                <x-admin.field label="چاپ" for="edition_label" hint="مانند «چاپ اول».">
                    <input type="text" name="edition_label" id="edition_label" value="{{ old('edition_label') }}" class="admin-field w-full">
                </x-admin.field>
                <x-admin.field label="چاپ دیگرِ منبع" for="edition_of_id" error="edition_of_id" hint="اگر این چاپ دیگری از منبعی موجود است.">
                    <select name="edition_of_id" id="edition_of_id" class="admin-field w-full">
                        <option value="">—</option>
                        @foreach($sources as $s)
                            <option value="{{ $s->id }}" @selected((int) old('edition_of_id') === $s->id)>{{ $s->title }}</option>
                        @endforeach
                    </select>
                </x-admin.field>
                <x-admin.field label="کتابخانه" for="library_id" error="library_id" hint="فقط برای فهرست یک کتابخانه.">
                    <select name="library_id" id="library_id" class="admin-field w-full">
                        <option value="">—</option>
                        @foreach($libraries as $lib)
                            <option value="{{ $lib->id }}" @selected((int) old('library_id') === $lib->id)>{{ $lib->name }} ({{ $lib->city }})</option>
                        @endforeach
                    </select>
                </x-admin.field>
            </div>

            <div class="grid gap-4 md:grid-cols-3 items-start">
                <x-admin.field label="شمار جلد" for="volumes" error="volumes">
                    <input type="number" name="volumes" id="volumes" min="1" max="200" value="{{ old('volumes', 1) }}" required class="admin-field w-full" dir="ltr">
                </x-admin.field>
                <x-admin.field label="واحد" for="unit">
                    <select name="unit" id="unit" class="admin-field w-full">
                        <option value="صفحه" @selected(old('unit', 'صفحه') === 'صفحه')>صفحه</option>
                        <option value="ستون" @selected(old('unit') === 'ستون')>ستون</option>
                    </select>
                </x-admin.field>
                <label class="flex items-center gap-2 cursor-pointer pt-7">
                    <input type="hidden" name="runs_on" value="0">
                    <input type="checkbox" name="runs_on" value="1" class="accent-cinnabar" @checked(old('runs_on'))>
                    صفحه‌ها در جلدها پیوسته است
                </label>
            </div>
            <x-admin.field label="صفحه‌ی آخر هر جلد (اختیاری)" for="last_pages" error="last_pages"
                           hint="به ترتیب جلدها، هر کدام در یک سطر یا با «،». با آن، ارجاعی که صفحه‌اش بیرون از جلد است پذیرفته نمی‌شود، و صفحه‌ی تنها جلدش را خودش می‌گوید.">
                <textarea name="last_pages" id="last_pages" rows="3" dir="ltr" class="admin-field w-full">{{ old('last_pages') }}</textarea>
            </x-admin.field>

            <div class="pt-3 flex flex-wrap items-center justify-between gap-2 border-t border-line">
                <x-ui.btn :href="route('admin.sources.index')" variant="ghost">انصراف</x-ui.btn>
                <span class="flex gap-2">
                    @if($similar->isNotEmpty())
                        <x-ui.btn type="submit" name="despite_similar" value="1">با وجود منبع مشابه بساز</x-ui.btn>
                    @endif
                    <x-ui.btn type="submit" variant="primary">ساختن منبع</x-ui.btn>
                </span>
            </div>
        </form>
    </x-ui.card>
</div>
@endsection

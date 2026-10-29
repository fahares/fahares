@extends('layouts.admin')

@section('title', 'ابزارهای ساختاری')
@section('page_title', 'ابزارهای ساختاری')

@section('content')
<x-ui.card class="max-w-2xl space-y-5 text-[13px]">
    <x-admin.notice>
        ادغام، تفکیک، انتقال، حذف رکورد زائد و دیگر اصلاح‌های ساختاری را بی‌آنکه پیشنهادی ثبت و تأیید کنید، مستقیم انجام دهید.
        پیش از اجرا، اثر تغییر را می‌بینید. هر اقدام در لاگ ممیزی ثبت می‌شود و بازگرداندنی است.
        این ابزارها را در صفحه‌ی هر رکورد هم، در فرم «پیشنهاد کلی»، با دکمه‌ی «اعمال مستقیم» دارید.
    </x-admin.notice>
    <form action="{{ route('admin.structure.index') }}" method="GET" class="space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <x-admin.field label="نوع رکورد" for="type">
                <select name="type" id="type" class="admin-field w-full">
                    @foreach(array_keys(\App\Suggestions\SuggestionRegistry::TYPES) as $t)
                        <option value="{{ $t }}" @selected(old('type', request('type')) === $t)>{{ \App\Suggestions\SuggestionRegistry::typeLabel($t) }}</option>
                    @endforeach
                </select>
            </x-admin.field>
            <x-admin.field label="پیوند یا شناسه‌ی فهارس" for="ref" error="ref" class="sm:col-span-2">
                <input type="text" name="ref" id="ref" value="{{ old('ref') }}" required dir="ltr" placeholder="fahares.net/w/123 یا 123" class="admin-field w-full">
            </x-admin.field>
        </div>
        <x-ui.btn variant="primary" type="submit">رفتن به ابزارهای رکورد</x-ui.btn>
    </form>
</x-ui.card>
@endsection

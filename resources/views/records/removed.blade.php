{{-- The former address of a record deleted as redundant (see RecordRemover); served with status 410 --}}
@extends('layouts.app')

@section('title', 'رکورد حذف شده است | فهارس')
@section('meta_description', 'این رکورد پس از بررسی هیئت علمی فهارس زائد شناخته و حذف شده است.')

@section('content')
<div class="max-w-2xl mx-auto px-4 sm:px-6 py-16">
    <div class="bg-white dark:bg-[#15192C] rounded-3xl p-8 border border-[#EADFCF] dark:border-[#272F4C] shadow-md space-y-4 text-center">
        <h1 class="text-xl font-black text-[#292C56] dark:text-amber-100">این {{ $label }} حذف شده است</h1>
        <p class="text-sm text-stone-600 dark:text-stone-300 leading-loose">
            رکورد «{{ $title }}» پس از بررسی هیئت علمی فهارس زائد شناخته شد و از پایگاه داده حذف شده است؛
            برای نمونه، توضیح یا سرعنوانی بوده که هنگام استخراج از فهرست، به‌اشتباه {{ $label }} ثبت شده بود.
        </p>
        <a href="{{ route('home') }}" class="inline-block px-5 py-2.5 rounded-xl bg-[#B38A50] hover:bg-[#9C753F] text-white text-sm font-bold">بازگشت به صفحه‌ی اصلی</a>
    </div>
</div>
@endsection

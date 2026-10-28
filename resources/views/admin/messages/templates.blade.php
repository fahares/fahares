@extends('layouts.admin')

@section('title', 'قالب‌های پیام')
@section('page_title', 'قالب‌های آماده‌ی پیام')
@section('breadcrumb')
    <a href="{{ route('admin.messages.index') }}" class="hover:text-cinnabar">پیام به کاربران</a>
    <span>/</span>
    <span>قالب‌ها</span>
@endsection

@section('content')
<div class="max-w-3xl space-y-5 text-[13px]">
    <x-admin.notice>
        در موضوع و متن این نشانه‌ها برای هر گیرنده پر می‌شوند:
        @foreach(\App\Models\MessageTemplate::VARIABLES as $token => $label)
            <code class="px-1 bg-paper border border-line">{{ $token }}</code> {{ $label }}@if(! $loop->last)، @endif
        @endforeach
        . متن پیش از فرستادن هم ویرایش‌پذیر است.
    </x-admin.notice>
    @foreach($templates as $t)
        <x-ui.card>
            <form action="{{ route('admin.messages.templates.update', $t) }}" method="POST" class="space-y-3">
                @csrf
                @method('PUT')
                <x-admin.field label="نام قالب" for="title-{{ $t->id }}">
                    <input type="text" name="title" id="title-{{ $t->id }}" value="{{ $t->title }}" required maxlength="100" class="admin-field w-full font-bold">
                </x-admin.field>
                <x-admin.field label="موضوع" for="subject-{{ $t->id }}">
                    <input type="text" name="subject" id="subject-{{ $t->id }}" value="{{ $t->subject }}" required maxlength="200" class="admin-field w-full">
                </x-admin.field>
                <x-admin.field label="متن" for="body-{{ $t->id }}">
                    <textarea name="body" id="body-{{ $t->id }}" rows="7" required maxlength="{{ \App\Models\Message::MAX_LENGTH }}" class="admin-field w-full leading-7">{{ $t->body }}</textarea>
                </x-admin.field>
                <x-ui.btn type="submit" variant="primary">ذخیره‌ی قالب</x-ui.btn>
            </form>
        </x-ui.card>
    @endforeach
</div>
@endsection

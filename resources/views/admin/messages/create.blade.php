@extends('layouts.admin')

@section('title', 'پیام تازه')
@section('page_title', 'پیام تازه به یک کاربر')
@section('breadcrumb')
    <a href="{{ route('admin.messages.index') }}" class="hover:text-cinnabar">پیام به کاربران</a>
    <span>/</span>
    <span>پیام تازه</span>
@endsection

@section('content')
<x-ui.card class="max-w-3xl space-y-5 text-[13px]">
    @if(! $recipient)
        <form method="GET" action="{{ route('admin.messages.create') }}" class="space-y-2">
            <x-admin.field label="گیرنده" for="q" hint="نام کاربر را بنویسید و از فهرست برگزینید.">
                <div class="flex gap-2">
                    <input type="search" name="q" id="q" value="{{ $q }}" minlength="2" required class="admin-field flex-1" autofocus>
                    <x-ui.btn type="submit">جستجو</x-ui.btn>
                </div>
            </x-admin.field>
        </form>
        @if($q !== '')
            <ul class="divide-y divide-line border border-line">
                @forelse($matches as $u)
                    <li><a href="{{ route('admin.messages.create', ['user' => $u->id]) }}" class="flex items-center gap-2 px-3 py-2 hover:bg-paper"><x-ui.avatar :user="$u" size="sm" /> <b>{{ $u->name }}</b> <span class="text-xs text-muted">#{{ \App\Support\Fa::digits($u->id) }}</span></a></li>
                @empty
                    <li class="px-3 py-2 text-muted">کاربری با این نام یافت نشد.</li>
                @endforelse
            </ul>
        @endif
    @else
        <form action="{{ route('admin.messages.store') }}" method="POST" class="space-y-5"
              x-data="{ filled: @js($filled), subject: @js(old('subject', '')), body: @js(old('body', '')), pick(key) { if (this.filled[key]) { this.subject = this.filled[key].subject; this.body = this.filled[key].body } } }">
            @csrf
            <input type="hidden" name="user_id" value="{{ $recipient->id }}">
            <p class="flex items-center gap-2">گیرنده: <x-ui.avatar :user="$recipient" size="sm" /> <b>{{ $recipient->name }}</b>
                <a href="{{ route('admin.messages.create') }}" class="text-xs text-cinnabar hover:underline">تغییر</a></p>

            @if($templates->isNotEmpty())
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs text-muted">قالب آماده:</span>
                    @foreach($templates as $t)
                        <x-ui.btn size="sm" x-on:click="pick('{{ $t->key }}')">{{ $t->title }}</x-ui.btn>
                    @endforeach
                    @can('users.message')<a href="{{ route('admin.messages.templates') }}" class="text-xs text-muted hover:text-cinnabar">ویرایش قالب‌ها</a>@endcan
                </div>
            @endif

            <x-admin.field label="موضوع" for="subject" error="subject">
                <input type="text" name="subject" id="subject" x-model="subject" required maxlength="200" class="admin-field w-full font-bold">
            </x-admin.field>
            <x-admin.field label="متن" for="body" error="body" hint="کاربر فرستنده را «مدیریت فهارس» می‌بیند و می‌تواند در «پیام‌ها»ی خود یا با پاسخ به ایمیل پاسخ دهد. پیام در سایت همیشه می‌رسد و ایمیلش تابع تنظیمات اعلان اوست.">
                <textarea name="body" id="body" x-model="body" rows="10" required maxlength="{{ \App\Models\Message::MAX_LENGTH }}" class="admin-field w-full leading-7"></textarea>
            </x-admin.field>
            <x-admin.form-actions :cancel="route('admin.messages.index')" submit="فرستادن پیام" />
        </form>
    @endif
</x-ui.card>
@endsection

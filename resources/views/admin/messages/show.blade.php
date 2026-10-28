@extends('layouts.admin')

@section('title', $thread->subject)
@section('page_title', $thread->subject)
@section('breadcrumb')
    <a href="{{ route('admin.messages.index') }}" class="hover:text-cinnabar">پیام به کاربران</a>
    <span>/</span>
    <span>{{ $thread->user?->name }}</span>
@endsection
@section('page_actions')
    @if($thread->reported_at)<x-ui.chip tone="accent">کاربر این پیام را گزارش کرد، @jago($thread->reported_at)</x-ui.chip>@endif
    <form action="{{ route('admin.messages.toggle', $thread) }}" method="POST">
        @csrf
        <x-ui.btn type="submit" size="sm">{{ $thread->closed ? 'بازکردن دوباره' : 'بستن گفت‌وگو' }}</x-ui.btn>
    </form>
@endsection

@section('content')
<x-ui.card class="max-w-3xl space-y-4 text-[13px]">
    <p class="text-xs text-muted">
        به <b class="text-ink">{{ $thread->user?->name }}</b>
        @can('users.manage') · <a href="{{ route('admin.users.edit', $thread->user_id) }}" class="text-cinnabar hover:underline">صفحه‌ی کاربر</a>@endcan
        · کاربر فرستنده را «{{ $thread->staffLabel() }}» می‌بیند.
    </p>
    @include('messages.partials.letters', ['thread' => $thread, 'staffView' => true])

    @if(! $thread->closed)
        <form action="{{ route('admin.messages.reply', $thread) }}" method="POST" class="space-y-2 pt-3 border-t border-line">
            @csrf
            <label for="body" class="block font-bold text-ink">پیام تازه در همین گفت‌وگو</label>
            <textarea id="body" name="body" rows="4" required maxlength="{{ \App\Models\Message::MAX_LENGTH }}" class="admin-field w-full">{{ old('body') }}</textarea>
            @error('body')<p class="text-xs text-cinnabar">{{ $message }}</p>@enderror
            <x-ui.btn type="submit" variant="primary">فرستادن</x-ui.btn>
        </form>
    @else
        <p class="text-xs text-muted pt-3 border-t border-line">گفت‌وگو بسته است؛ کاربر آن را می‌خواند ولی پاسخ نمی‌دهد.</p>
    @endif
</x-ui.card>
@endsection

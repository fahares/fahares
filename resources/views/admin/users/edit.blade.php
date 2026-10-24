@extends('layouts.admin')

@section('title', 'ویرایش ' . $user->name)
@section('page_title', $user->name)
@section('breadcrumb')
    <a href="{{ route('admin.users.index') }}" class="hover:text-cinnabar">کاربران</a>
    <span>/</span>
    <span>ویرایش #{{ \App\Support\Fa::digits($user->id) }}</span>
@endsection

@section('content')
<x-ui.card class="max-w-2xl space-y-5 text-[13px]">
    <div class="flex items-center gap-3">
        <x-ui.avatar :user="$user" size="lg" />
        <div class="min-w-0">
            <p class="text-muted break-all" dir="ltr">{{ $user->email }}</p>
            <p class="text-xs text-muted">عضو از @jdate($user->created_at)</p>
        </div>
    </div>

    <form action="{{ route('admin.users.update', $user) }}" method="POST" class="space-y-5">
        @csrf
        @method('PUT')

        <x-admin.field label="نام و نام خانوادگی" for="name" error="name">
            <input type="text" name="name" id="name" value="{{ old('name', $user->name) }}" required class="admin-field w-full font-bold">
        </x-admin.field>

        <x-admin.field label="نقش" for="role" error="role">
            <select name="role" id="role" required class="admin-field w-full">
                @foreach(\App\Models\Role::options() as $key => $label)
                    <option value="{{ $key }}" @selected(old('role', $user->role) === $key)>{{ $label }}</option>
                @endforeach
            </select>
            @can('roles.manage')
                <p class="text-xs text-muted">دسترسی هر نقش در <a href="{{ route('admin.roles.index') }}" class="text-cinnabar hover:underline">ماتریس دسترسی</a> تعیین می‌شود.</p>
            @endcan
        </x-admin.field>

        <x-admin.field label="وابستگی علمی" for="affiliation">
            <input type="text" name="affiliation" id="affiliation" value="{{ old('affiliation', $user->affiliation) }}"
                   placeholder="مثال: دانشگاه تهران، فرهنگستان زبان و ادب فارسی" class="admin-field w-full">
        </x-admin.field>

        <label class="flex items-start gap-2 cursor-pointer">
            <input type="checkbox" name="is_verified_scholar" value="1" @checked($user->is_verified_scholar) class="mt-1 size-4 accent-cinnabar">
            <span><b>پژوهشگر تأییدشده</b> <span class="text-xs text-muted">(نشان تأیید علمی و اعتماد سامانه)</span></span>
        </label>

        <x-admin.form-actions :cancel="route('admin.users.index')" />
    </form>
</x-ui.card>
@endsection

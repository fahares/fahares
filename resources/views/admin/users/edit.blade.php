@extends('layouts.admin')

@section('title', "ویرایش کاربر: {$user->name}")
@section('page_title', "ویرایش مشخصات و سطح دسترسی کاربر: {$user->name}")
@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-cinnabar">پیشخوان</a>
    <span>/</span>
    <a href="{{ route('admin.users.index') }}" class="hover:text-cinnabar">کاربران</a>
    <span>/</span>
    <span class="text-stone-400">ویرایش #{{ $user->id }}</span>
@endsection

@section('content')
<div class="max-w-2xl mx-auto p-8 rounded-2xl bg-surface border border-line shadow-xs space-y-6">

    <div class="border-b border-line pb-4">
        <span class="text-xs text-stone-400">شناسه کاربر: #{{ $user->id }}</span>
        <div class="text-xs text-stone-500 font-mono mt-0.5">{{ $user->email }}</div>
    </div>

    <form action="{{ route('admin.users.update', $user) }}" method="POST" class="space-y-5 text-xs">
        @csrf
        @method('PUT')

        <!-- Name -->
        <div class="space-y-1.5">
            <label for="name" class="block font-bold text-ink">نام و نام خانوادگی:</label>
            <input type="text" name="name" id="name" value="{{ old('name', $user->name) }}" required 
                   class="w-full p-3 rounded-xl border border-line-strong bg-paper text-ink font-bold focus:border-gold outline-none">
            @error('name')
                <p class="text-[11px] text-red-500">{{ $message }}</p>
            @enderror
        </div>

        <!-- Role -->
        <div class="space-y-1.5">
            <label for="role" class="block font-bold text-ink">نقش کاربری و سطح دسترسی:</label>
            <select name="role" id="role" required class="w-full p-3 rounded-xl border border-line-strong bg-paper text-ink font-semibold focus:border-gold outline-none">
                @foreach(\App\Models\Role::options() as $key => $label)
                    <option value="{{ $key }}" @selected(old('role', $user->role) === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <p class="text-[11px] text-stone-400">دسترسی هر نقش در <a href="{{ route('admin.roles.index') }}" class="text-cinnabar hover:underline">ماتریس دسترسی</a> تعیین می‌شود.</p>
        </div>

        <!-- Affiliation -->
        <div class="space-y-1.5">
            <label for="affiliation" class="block font-bold text-ink">وابستگی دانشگاهی / پژوهشی:</label>
            <input type="text" name="affiliation" id="affiliation" value="{{ old('affiliation', $user->affiliation) }}" 
                   placeholder="مثال: دانشگاه تهران، فرهنگستان زبان و ادب فارسی..."
                   class="w-full p-3 rounded-xl border border-line-strong bg-paper text-ink focus:border-gold outline-none">
        </div>

        <!-- Verified Scholar Toggle -->
        <div class="pt-2">
            <label class="flex items-center gap-2 cursor-pointer font-bold text-ink">
                <input type="checkbox" name="is_verified_scholar" value="1" {{ $user->is_verified_scholar ? 'checked' : '' }} class="rounded border-stone-300 text-cinnabar focus:ring-cinnabar w-4 h-4">
                <span>پژوهشگر تاییدشده (نشان تایید علمی و اعتماد سامانه)</span>
            </label>
        </div>

        <!-- Submit -->
        <div class="pt-4 flex items-center justify-between border-t border-line">
            <a href="{{ route('admin.users.index') }}" class="px-4 py-2 text-stone-500 hover:text-stone-800 font-bold">انصراف</a>
            <button type="submit" class="px-6 py-2.5 rounded-xl bg-cinnabar hover:opacity-90 text-on-cinnabar font-bold shadow-md transition">
                ذخیره تغییرات
            </button>
        </div>

    </form>

</div>
@endsection

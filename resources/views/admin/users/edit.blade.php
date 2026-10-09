@extends('layouts.admin')

@section('title', "ویرایش کاربر: {$user->name}")
@section('page_title', "ویرایش مشخصات و سطح دسترسی کاربر: {$user->name}")
@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-[#B38A50]">پیشخوان</a>
    <span>/</span>
    <a href="{{ route('admin.users.index') }}" class="hover:text-[#B38A50]">کاربران</a>
    <span>/</span>
    <span class="text-stone-400">ویرایش #{{ $user->id }}</span>
@endsection

@section('content')
<div class="max-w-2xl mx-auto p-8 rounded-2xl bg-white dark:bg-[#15192C] border border-stone-200 dark:border-stone-800 shadow-xs space-y-6">

    <div class="border-b border-stone-100 dark:border-stone-800 pb-4">
        <span class="text-xs text-stone-400">شناسه کاربر: #{{ $user->id }}</span>
        <div class="text-xs text-stone-500 font-mono mt-0.5">{{ $user->email }}</div>
    </div>

    <form action="{{ route('admin.users.update', $user) }}" method="POST" class="space-y-5 text-xs">
        @csrf
        @method('PUT')

        <!-- Name -->
        <div class="space-y-1.5">
            <label for="name" class="block font-bold text-stone-700 dark:text-stone-300">نام و نام خانوادگی:</label>
            <input type="text" name="name" id="name" value="{{ old('name', $user->name) }}" required 
                   class="w-full p-3 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 font-bold focus:border-[#B38A50] outline-none">
            @error('name')
                <p class="text-[11px] text-red-500">{{ $message }}</p>
            @enderror
        </div>

        <!-- Role -->
        <div class="space-y-1.5">
            <label for="role" class="block font-bold text-stone-700 dark:text-stone-300">نقش کاربری و سطح دسترسی:</label>
            <select name="role" id="role" required class="w-full p-3 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 font-semibold focus:border-[#B38A50] outline-none">
                <option value="admin" {{ old('role', $user->role) === 'admin' ? 'selected' : '' }}>مدیر ارشد (Super Admin) - دسترسی کامل</option>
                <option value="editor" {{ old('role', $user->role) === 'editor' ? 'selected' : '' }}>دبیر علمی (Editor) - داوری، ادغام و ویرایش داده‌ها</option>
                <option value="researcher" {{ old('role', $user->role) === 'researcher' ? 'selected' : '' }}>پژوهشگر ارشد (Researcher)</option>
                <option value="user" {{ old('role', $user->role) === 'user' ? 'selected' : '' }}>کاربر عادی (User)</option>
            </select>
        </div>

        <!-- Affiliation -->
        <div class="space-y-1.5">
            <label for="affiliation" class="block font-bold text-stone-700 dark:text-stone-300">وابستگی دانشگاهی / پژوهشی:</label>
            <input type="text" name="affiliation" id="affiliation" value="{{ old('affiliation', $user->affiliation) }}" 
                   placeholder="مثال: دانشگاه تهران، فرهنگستان زبان و ادب فارسی..."
                   class="w-full p-3 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 focus:border-[#B38A50] outline-none">
        </div>

        <!-- Verified Scholar Toggle -->
        <div class="pt-2">
            <label class="flex items-center gap-2 cursor-pointer font-bold text-stone-700 dark:text-stone-300">
                <input type="checkbox" name="is_verified_scholar" value="1" {{ $user->is_verified_scholar ? 'checked' : '' }} class="rounded border-stone-300 text-[#B38A50] focus:ring-[#B38A50] w-4 h-4">
                <span>پژوهشگر تاییدشده (نشان تایید علمی و اعتماد سامانه)</span>
            </label>
        </div>

        <!-- Submit -->
        <div class="pt-4 flex items-center justify-between border-t border-stone-100 dark:border-stone-800">
            <a href="{{ route('admin.users.index') }}" class="px-4 py-2 text-stone-500 hover:text-stone-800 font-bold">انصراف</a>
            <button type="submit" class="px-6 py-2.5 rounded-xl bg-[#B38A50] hover:bg-[#9C753F] text-white font-bold shadow-md transition">
                ذخیره تغییرات
            </button>
        </div>

    </form>

</div>
@endsection

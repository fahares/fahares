@extends('layouts.admin')

@section('title', "ویرایش موضوع: {$subject->name}")
@section('page_title', "ویرایش موضوع: {$subject->name}")
@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-[#B38A50]">پیشخوان</a>
    <span>/</span>
    <a href="{{ route('admin.subjects.index') }}" class="hover:text-[#B38A50]">موضوعات</a>
    <span>/</span>
    <span class="text-stone-400">ویرایش #{{ $subject->id }}</span>
@endsection

@section('content')
<div class="max-w-2xl mx-auto p-8 rounded-2xl bg-white dark:bg-[#15192C] border border-stone-200 dark:border-stone-800 shadow-xs space-y-6">

    <div class="flex items-center justify-between border-b border-stone-100 dark:border-stone-800 pb-4">
        <div>
            <span class="text-xs text-stone-400">شناسه: #{{ $subject->id }}</span>
            <div class="text-sm font-bold text-stone-800 dark:text-stone-200 mt-0.5">
                تعداد آثار متصل: <span class="font-mono text-[#B38A50]">{{ number_format($subject->works_count) }} اثر</span>
            </div>
        </div>
        <a href="{{ route('subjects.show', $subject) }}" target="_blank" class="text-xs text-[#B38A50] hover:underline font-bold">
            مشاهده در سایت ↗
        </a>
    </div>

    <form action="{{ route('admin.subjects.update', $subject) }}" method="POST" class="space-y-5 text-xs">
        @csrf
        @method('PUT')

        <!-- Name -->
        <div class="space-y-1.5">
            <label for="name" class="block font-bold text-stone-700 dark:text-stone-300">عنوان موضوع:</label>
            <input type="text" name="name" id="name" value="{{ old('name', $subject->name) }}" required 
                   class="w-full p-3 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 font-semibold focus:border-[#B38A50] outline-none">
            @error('name')
                <p class="text-[11px] text-red-500">{{ $message }}</p>
            @enderror
        </div>

        <!-- Parent Category -->
        <div class="space-y-1.5">
            <label for="parent_id" class="block font-bold text-stone-700 dark:text-stone-300">شاخه والد (در صورت فرعی بودن):</label>
            <select name="parent_id" id="parent_id" class="w-full p-3 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 font-semibold focus:border-[#B38A50] outline-none">
                <option value="">-- شاخه اصلی (Root Category) --</option>
                @foreach($parents as $parent)
                    <option value="{{ $parent->id }}" {{ old('parent_id', $subject->parent_id) == $parent->id ? 'selected' : '' }}>
                        {{ $parent->name }}
                    </option>
                @endforeach
            </select>
        </div>

        <!-- Description -->
        <div class="space-y-1.5">
            <label for="description" class="block font-bold text-stone-700 dark:text-stone-300">توضیحات و دامنه شمول علمی (اختیاری):</label>
            <textarea name="description" id="description" rows="3" class="w-full p-3 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 focus:border-[#B38A50] outline-none">{{ old('description', $subject->description) }}</textarea>
        </div>

        <!-- Submit -->
        <div class="pt-4 flex items-center justify-between border-t border-stone-100 dark:border-stone-800">
            <a href="{{ route('admin.subjects.index') }}" class="px-4 py-2 text-stone-500 hover:text-stone-800 font-bold">انصراف</a>
            <button type="submit" class="px-6 py-2.5 rounded-xl bg-[#B38A50] hover:bg-[#9C753F] text-white font-bold shadow-md transition">
                ذخیره تغییرات
            </button>
        </div>
    </form>

</div>
@endsection

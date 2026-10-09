@extends('layouts.admin')

@section('title', 'افزودن موضوع جدید')
@section('page_title', 'تعریف موضوع یا رده دانشی جدید')
@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-[#B38A50]">پیشخوان</a>
    <span>/</span>
    <a href="{{ route('admin.subjects.index') }}" class="hover:text-[#B38A50]">موضوعات</a>
    <span>/</span>
    <span class="text-stone-400">موضوع جدید</span>
@endsection

@section('content')
<div class="max-w-2xl mx-auto p-8 rounded-2xl bg-white dark:bg-[#15192C] border border-stone-200 dark:border-stone-800 shadow-xs space-y-6">

    <form action="{{ route('admin.subjects.store') }}" method="POST" class="space-y-5 text-xs">
        @csrf

        <!-- Name -->
        <div class="space-y-1.5">
            <label for="name" class="block font-bold text-stone-700 dark:text-stone-300">عنوان موضوع:</label>
            <input type="text" name="name" id="name" value="{{ old('name') }}" required 
                   placeholder="مثال: فقه و احکام، علم کلام، ادبیات کهن..."
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
                    <option value="{{ $parent->id }}" {{ old('parent_id') == $parent->id ? 'selected' : '' }}>
                        {{ $parent->name }}
                    </option>
                @endforeach
            </select>
        </div>

        <!-- Description -->
        <div class="space-y-1.5">
            <label for="description" class="block font-bold text-stone-700 dark:text-stone-300">توضیحات و دامنه شمول علمی (اختیاری):</label>
            <textarea name="description" id="description" rows="3" placeholder="توضیح مختصر درباره این موضوع..." class="w-full p-3 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 focus:border-[#B38A50] outline-none">{{ old('description') }}</textarea>
        </div>

        <!-- Submit -->
        <div class="pt-4 flex items-center justify-between border-t border-stone-100 dark:border-stone-800">
            <a href="{{ route('admin.subjects.index') }}" class="px-4 py-2 text-stone-500 hover:text-stone-800 font-bold">انصراف</a>
            <button type="submit" class="px-6 py-2.5 rounded-xl bg-[#B38A50] hover:bg-[#9C753F] text-white font-bold shadow-md transition">
                ثبت موضوع
            </button>
        </div>
    </form>

</div>
@endsection

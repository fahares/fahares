@extends('layouts.admin')

@section('title', 'افزودن موضوع جدید')
@section('page_title', 'تعریف موضوع یا رده دانشی جدید')
@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-cinnabar">پیشخوان</a>
    <span>/</span>
    <a href="{{ route('admin.subjects.index') }}" class="hover:text-cinnabar">موضوعات</a>
    <span>/</span>
    <span class="text-stone-400">موضوع جدید</span>
@endsection

@section('content')
<div class="max-w-2xl mx-auto p-8 rounded-2xl bg-surface border border-line shadow-xs space-y-6">

    <form action="{{ route('admin.subjects.store') }}" method="POST" class="space-y-5 text-xs">
        @csrf

        <!-- Name -->
        <div class="space-y-1.5">
            <label for="name" class="block font-bold text-ink">عنوان موضوع:</label>
            <input type="text" name="name" id="name" value="{{ old('name') }}" required 
                   placeholder="مثال: فقه و احکام، علم کلام، ادبیات کهن..."
                   class="w-full p-3 rounded-xl border border-line-strong bg-paper text-ink font-semibold focus:border-gold outline-none">
            @error('name')
                <p class="text-[11px] text-red-500">{{ $message }}</p>
            @enderror
        </div>

        <!-- Parent Category -->
        <div class="space-y-1.5">
            <label for="parent_id" class="block font-bold text-ink">شاخه والد (در صورت فرعی بودن):</label>
            <select name="parent_id" id="parent_id" class="w-full p-3 rounded-xl border border-line-strong bg-paper text-ink font-semibold focus:border-gold outline-none">
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
            <label for="description" class="block font-bold text-ink">توضیحات و دامنه شمول علمی (اختیاری):</label>
            <textarea name="description" id="description" rows="3" placeholder="توضیح مختصر درباره این موضوع..." class="w-full p-3 rounded-xl border border-line-strong bg-paper text-ink focus:border-gold outline-none">{{ old('description') }}</textarea>
        </div>

        <!-- Submit -->
        <div class="pt-4 flex items-center justify-between border-t border-line">
            <a href="{{ route('admin.subjects.index') }}" class="px-4 py-2 text-stone-500 hover:text-stone-800 font-bold">انصراف</a>
            <button type="submit" class="px-6 py-2.5 rounded-xl bg-cinnabar hover:opacity-90 text-on-cinnabar font-bold shadow-md transition">
                ثبت موضوع
            </button>
        </div>
    </form>

</div>
@endsection

@extends('layouts.admin')

@section('title', "ویرایش موضوع: {$subject->name}")
@section('page_title', "ویرایش موضوع: {$subject->name}")
@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-cinnabar">پیشخوان</a>
    <span>/</span>
    <a href="{{ route('admin.subjects.index') }}" class="hover:text-cinnabar">موضوعات</a>
    <span>/</span>
    <span class="text-stone-400">ویرایش #{{ $subject->id }}</span>
@endsection

@section('content')
<div class="max-w-2xl mx-auto p-8 rounded-2xl bg-surface border border-line shadow-xs space-y-6">

    <div class="flex items-center justify-between border-b border-line pb-4">
        <div>
            <span class="text-xs text-stone-400">شناسه: #{{ $subject->id }}</span>
            <div class="text-sm font-bold text-ink mt-0.5">
                تعداد آثار متصل: <span class="font-mono text-cinnabar">{{ number_format($subject->works_count) }} اثر</span>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.subjects.merge', ['source_subject_id' => $subject->id]) }}" 
               class="px-3 py-1.5 rounded-xl border border-amber-500/30 bg-amber-500/10 hover:bg-amber-600 hover:text-white text-amber-800 dark:text-amber-300 font-bold transition flex items-center gap-1.5 text-xs shadow-xs">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                <span>ادغام با موضوع دیگر...</span>
            </a>
            <a href="{{ route('subjects.show', $subject) }}" target="_blank" class="text-xs text-cinnabar hover:underline font-bold">
                مشاهده در سایت ↗
            </a>
        </div>
    </div>

    <form action="{{ route('admin.subjects.update', $subject) }}" method="POST" class="space-y-5 text-xs">
        @csrf
        @method('PUT')

        <!-- Name -->
        <div class="space-y-1.5">
            <label for="name" class="block font-bold text-ink">عنوان موضوع:</label>
            <input type="text" name="name" id="name" value="{{ old('name', $subject->name) }}" required 
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
                    <option value="{{ $parent->id }}" {{ old('parent_id', $subject->parent_id) == $parent->id ? 'selected' : '' }}>
                        {{ $parent->name }}
                    </option>
                @endforeach
            </select>
        </div>

        <!-- Description -->
        <div class="space-y-1.5">
            <label for="description" class="block font-bold text-ink">توضیحات و دامنه شمول علمی (اختیاری):</label>
            <textarea name="description" id="description" rows="3" class="w-full p-3 rounded-xl border border-line-strong bg-paper text-ink focus:border-gold outline-none">{{ old('description', $subject->description) }}</textarea>
        </div>

        <!-- Submit -->
        <div class="pt-4 flex items-center justify-between border-t border-line">
            <a href="{{ route('admin.subjects.index') }}" class="px-4 py-2 text-stone-500 hover:text-stone-800 font-bold">انصراف</a>
            <button type="submit" class="px-6 py-2.5 rounded-xl bg-cinnabar hover:opacity-90 text-on-cinnabar font-bold shadow-md transition">
                ذخیره تغییرات
            </button>
        </div>
    </form>

</div>
@endsection

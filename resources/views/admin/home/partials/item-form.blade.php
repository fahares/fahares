@php
    $key = $item ? "item{$item->id}" : "new_{$section}";
    $input = 'w-full p-2.5 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 focus:border-[#B38A50] outline-none';
    $label = 'block font-bold text-stone-700 dark:text-stone-300 mb-1';
@endphp
<form action="{{ $item ? route('admin.home.items.update', $item) : route('admin.home.items.store') }}" method="POST" enctype="multipart/form-data" class="grid gap-3 sm:grid-cols-2">
    @csrf
    @if($item) @method('PUT') @else <input type="hidden" name="section" value="{{ $section }}"> @endif

    <div>
        <label for="{{ $key }}_title" class="{{ $label }}">عنوان</label>
        <input type="text" name="title" id="{{ $key }}_title" value="{{ $item?->title }}" class="{{ $input }}" placeholder="{{ in_array($section, ['slides', 'hero']) ? 'متن جایگزین تصویر، برای نابینایان و موتورهای جستجو' : 'مثلاً: ترقیمه' }}">
    </div>
    <div>
        <label for="{{ $key }}_credit" class="{{ $label }}">منبع تصویر</label>
        <input type="text" name="credit" id="{{ $key }}_credit" value="{{ $item?->credit }}" class="{{ $input }}" placeholder="مثلاً: موزه‌ی والترز W.634">
    </div>
    <div class="sm:col-span-2">
        <label for="{{ $key }}_subtitle" class="{{ $label }}">توضیح</label>
        <textarea name="subtitle" id="{{ $key }}_subtitle" rows="2" class="{{ $input }}">{{ $item?->subtitle }}</textarea>
    </div>
    <div>
        <label for="{{ $key }}_link" class="{{ $label }}">پیوند (اختیاری)</label>
        <input type="text" name="link_url" id="{{ $key }}_link" value="{{ $item?->link_url }}" class="{{ $input }}" dir="ltr" placeholder="/works/49903 یا https://…">
    </div>
    <div>
        <label for="{{ $key }}_image" class="{{ $label }}">{{ $item ? 'تصویر تازه (اختیاری)' : 'تصویر' }}</label>
        <input type="file" name="image" id="{{ $key }}_image" accept="image/jpeg,image/png,image/webp" {{ $item ? '' : 'required' }} class="{{ $input }} file:ml-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-[#B38A50] file:text-white">
    </div>
    <div class="sm:col-span-2 flex flex-wrap items-center gap-5">
        <label class="inline-flex items-center gap-2"><input type="checkbox" name="is_active" value="1" {{ ($item?->is_active ?? true) ? 'checked' : '' }}> نمایش در سایت</label>
        @if($section === 'slides')
            <label class="inline-flex items-center gap-2"><input type="checkbox" name="show_caption" value="1" {{ $item?->show_caption ? 'checked' : '' }}> عنوان و توضیح روی بنر نوشته شود (برای تصویری که خودش نوشته ندارد)</label>
        @endif
        <button type="submit" class="mr-auto inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-xs font-bold bg-[#B38A50] text-white hover:bg-[#9C753F] transition cursor-pointer">{{ $item ? 'ذخیره' : 'افزودن' }}</button>
    </div>
</form>

@php
    $key = $item ? "item{$item->id}" : "new_{$section}";
    $input = 'admin-field w-full';
    $label = 'block font-bold text-ink mb-1';
@endphp
<form action="{{ $item ? route('admin.home.items.update', $item) : route('admin.home.items.store') }}" method="POST" enctype="multipart/form-data" class="grid gap-3 sm:grid-cols-2">
    @csrf
    @if($item) @method('PUT') @else <input type="hidden" name="section" value="{{ $section }}"> @endif

    <div>
        <label for="{{ $key }}_title" class="{{ $label }}">عنوان</label>
        <input type="text" name="title" id="{{ $key }}_title" value="{{ $item?->title }}" class="{{ $input }}" placeholder="{{ in_array($section, ['slides', 'hero']) ? 'متن جایگزین تصویر، برای نابینایان و موتورهای جست‌وجو' : 'مثلاً: ترقیمه' }}">
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
        <x-admin.file name="image" :id="$key . '_image'" accept="image/jpeg,image/png,image/webp" :required="! $item" />
    </div>
    <div class="sm:col-span-2 flex flex-wrap items-center gap-5">
        <label class="inline-flex items-center gap-2 cursor-pointer"><input type="checkbox" name="is_active" value="1" {{ ($item?->is_active ?? true) ? 'checked' : '' }} class="accent-cinnabar"> نمایش در سایت</label>
        @if($section === 'slides')
            <label class="inline-flex items-center gap-2 cursor-pointer"><input type="checkbox" name="show_caption" value="1" {{ $item?->show_caption ? 'checked' : '' }} class="accent-cinnabar"> عنوان و توضیح روی بنر نوشته شود (برای تصویری که خودش نوشته ندارد)</label>
        @endif
        <button type="submit" class="ms-auto inline-flex items-center gap-1.5 px-4 py-2 rounded-sm text-xs font-semibold bg-cinnabar text-on-cinnabar hover:opacity-90 transition cursor-pointer">{{ $item ? 'ذخیره' : 'افزودن' }}</button>
    </div>
</form>

@props(['name', 'id' => null, 'accept' => null])

{{-- A file input in Persian: the browser's own control (with its «Choose File» in the browser's language) is hidden
     behind a button, and the chosen file's name is shown beside it --}}
<div x-data="{ file: '' }" class="flex flex-wrap items-center gap-2">
    <input type="file" name="{{ $name }}" id="{{ $id ?? $name }}" @if($accept) accept="{{ $accept }}" @endif {{ $attributes }}
           @change="const f = $event.target.files; file = f.length > 1 ? f.length.toLocaleString('fa-IR') + ' پرونده' : (f[0]?.name ?? '')" class="peer sr-only">
    <label for="{{ $id ?? $name }}" class="inline-flex items-center px-3 py-1.5 rounded-sm border border-line-strong bg-surface text-ink text-xs font-semibold hover:border-cinnabar hover:text-cinnabar peer-focus-visible:border-gold cursor-pointer transition">انتخاب پرونده</label>
    <span class="text-xs text-muted" x-text="file || 'پرونده‌ای انتخاب نشده است'" dir="auto">پرونده‌ای انتخاب نشده است</span>
</div>

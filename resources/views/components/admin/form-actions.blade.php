@props(['cancel', 'submit' => 'ذخیره‌ی تغییرات'])

{{-- The foot of a panel form: back to the list, and save --}}
<div class="pt-4 flex flex-wrap items-center justify-between gap-3 border-t border-line">
    <x-ui.btn :href="$cancel" variant="ghost">انصراف</x-ui.btn>
    <x-ui.btn type="submit" variant="primary">{{ $submit }}</x-ui.btn>
</div>

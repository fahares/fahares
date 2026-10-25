{{-- The account name, published beside the user's corrections: a fixed hint to write it in Persian and a soft,
     non-blocking warning for a Latin-only name (backlog item 75). $value: the current name; $inputClass: the input's look --}}
<div class="space-y-1" x-data="{ name: @js(old('name', $value ?? '')) }">
    <label for="name" class="block text-xs font-bold text-ink">نام و نام خانوادگی:</label>
    <input type="text" id="name" name="name" x-model="name" value="{{ old('name', $value ?? '') }}" required {{ ! empty($autofocus) ? 'autofocus' : '' }} maxlength="255"
           placeholder="مثال: دکتر علی رضایی" class="{{ $inputClass }}">
    <p class="text-[11px] text-muted leading-5">لطفاً نام و نام خانوادگی را به فارسی بنویسید؛ این نام کنار اصلاحات شما منتشر می‌شود.</p>
    <p x-show="/\p{Script=Latin}/u.test(name) && !/\p{Script=Arabic}/u.test(name)" x-cloak
       class="text-[11px] text-amber-800 dark:text-amber-300 leading-5">نام با حروف لاتین نوشته شده است. اگر فارسی‌زبان هستید، آن را به فارسی بنویسید؛ وگرنه همین‌گونه ثبت می‌شود.</p>
    @error('name')
        <p class="text-[11px] text-red-500 font-medium">{{ $message }}</p>
    @enderror
</div>

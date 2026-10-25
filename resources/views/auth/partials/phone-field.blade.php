{{-- Optional contact number, stored in E.164 and never shown publicly (backlog item 74). $value, $inputClass --}}
<div class="space-y-1">
    <label for="phone" class="block text-xs font-bold text-ink">شماره‌ی تماس <span class="text-muted font-normal">(اختیاری)</span>:</label>
    <input type="tel" id="phone" name="phone" value="{{ old('phone', $value ?? '') }}" dir="ltr" maxlength="40" autocomplete="tel" inputmode="tel"
           placeholder="0912 123 4567" class="{{ $inputClass }} text-left">
    <p class="text-[11px] text-muted leading-5">فقط برای هماهنگی همکاری علمی؛ نمایش داده نمی‌شود.</p>
    @error('phone')
        <p class="text-[11px] text-red-500 font-medium">{{ $message }}</p>
    @enderror
</div>

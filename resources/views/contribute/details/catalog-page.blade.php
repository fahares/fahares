{{-- «در صفحه‌ی دیگری است»: the page where the copy is described (CatalogMatchQueue::otherPage reads it) --}}
@use('App\Support\Fa')
<label class="flex-1 min-w-40 space-y-1">
    <span class="block text-[11px] text-muted">نشانی درست در همین فهرست</span>
    <input type="text" name="page" value="{{ old('page', $previous?->suggested_value) }}" maxlength="50"
           placeholder="{{ $volume ? 'مثلاً ' . Fa::digits('45') . ' یا «ج ' . Fa::digits((string) $volume) . '، ص ' . Fa::digits('45') . '»' : 'مثلاً ۴۵' }}"
           class="w-full p-2 rounded-sm border border-line-strong bg-surface text-ink text-sm">
</label>
<x-ui.btn type="submit" name="answer" value="page" variant="primary">ثبت نشانی</x-ui.btn>

{{-- «برخی از نفر دیگری است»: the copies are ticked in the list above; the other scribe's name if known --}}
<p class="w-full m-0 text-xs text-ink leading-6">در فهرست بالا نسخه‌هایی را که کاتب دیگری نوشته علامت بزنید.</p>
<label class="flex-1 min-w-40 space-y-1">
    <span class="block text-[11px] text-muted">نام آن کاتب، اگر می‌دانید (اختیاری)</span>
    <input type="text" name="name" value="{{ old('name', $previous?->payload['name'] ?? '') }}" maxlength="300"
           class="w-full p-2 rounded-sm border border-line-strong bg-surface text-ink text-sm">
</label>
<x-ui.btn type="submit" name="answer" value="split" variant="primary">ثبت</x-ui.btn>

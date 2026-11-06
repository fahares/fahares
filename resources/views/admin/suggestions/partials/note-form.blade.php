{{-- Publishes or saves a research note; its fields sit in the desk's main column with form="note-form" (step 14.72) --}}
<form id="note-form" action="{{ route('admin.suggestions.note', $suggestion) }}" method="POST" class="space-y-3">
    @csrf
    <div class="space-y-1">
        <label for="note_public_note" class="block font-bold text-ink">یادداشت عمومی <span class="font-normal text-xs text-muted">(پیشنهاددهنده آن را می‌بیند و زیر یادداشت در صفحه نمایش داده می‌شود؛ اختیاری)</span></label>
        <textarea id="note_public_note" name="public_note" rows="2" class="{{ $input }}">{{ old('public_note', $suggestion->public_note) }}</textarea>
    </div>
    <div class="space-y-1">
        <label for="note_admin_notes" class="block font-bold text-ink">یادداشت داخلی <span class="font-normal text-xs text-muted">(فقط داوران)</span></label>
        <input id="note_admin_notes" type="text" name="admin_notes" value="{{ old('admin_notes', $suggestion->admin_notes) }}" class="{{ $input }}">
    </div>
    <p class="px-3 py-2 bg-paper border border-line text-xs text-ink leading-6" data-approve-summary>
        <b>با این کار:</b> یادداشت با متن ستون اصلی در صفحه‌ی رکورد {{ $suggestion->status === 'approved' ? 'به‌روز می‌شود' : 'منتشر می‌شود' }}{{ ! empty($suggestion->payload['files']) ? '؛ تصویرهای علامت‌خورده پاک می‌شوند' : '' }}.
    </p>
    <button type="submit" class="w-full py-2.5 px-4 rounded-sm bg-emerald-700 hover:bg-emerald-800 text-white font-bold transition cursor-pointer">{{ $button }}</button>
</form>

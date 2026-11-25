{{-- Where the knowledge of a new person or work came from (backlog item 161): a source of the site gives the record its card; the editor's words stay in the audit log --}}
<fieldset class="space-y-4">
    <legend class="rubric text-base">منبع اطلاع</legend>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <x-admin.field class="sm:col-span-2" label="منبع (منبع سایت)" error="source_id" hint="اگر {{ $record }} در فهرست، کتاب‌شناسی یا تراجم‌نامه‌ای از منابع سایت آمده است، رکورد کارت همان منبع را می‌گیرد.">
            <x-admin.record-picker name="source_id" :setup="$picker('source_id', 'source')" placeholder="نام یا کد منبع…" />
        </x-admin.field>
        <x-admin.field label="نشانی در منبع" for="source_place" error="source_place" hint="«ج ۶، ص ۶۰۱»">
            <input type="text" name="source_place" id="source_place" value="{{ old('source_place') }}" maxlength="60" class="admin-field w-full">
        </x-admin.field>
    </div>
    <x-admin.field label="یادداشت منبع" for="source_text" error="source_text" hint="منبعی که در سایت نیست، یا نام پژوهشگر؛ در لاگ ممیزی می‌ماند.">
        <input type="text" name="source_text" id="source_text" value="{{ old('source_text') }}" maxlength="300" class="admin-field w-full">
    </x-admin.field>
</fieldset>

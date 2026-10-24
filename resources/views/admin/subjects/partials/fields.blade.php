{{-- The fields of a subject, shared by the create and edit forms --}}
<x-admin.field label="نام موضوع" for="name" error="name">
    <input type="text" name="name" id="name" value="{{ old('name', $subject?->name) }}" required
           placeholder="مثال: فقه، کلام، ادبیات" class="admin-field w-full font-bold">
</x-admin.field>

<x-admin.field label="شاخه‌ی بالاتر" for="parent_id" error="parent_id" hint="خالی یعنی این موضوع خودش شاخه‌ی اصلی است.">
    <select name="parent_id" id="parent_id" class="admin-field w-full">
        <option value="">شاخه‌ی اصلی</option>
        @foreach($parents as $parent)
            <option value="{{ $parent->id }}" @selected(old('parent_id', $subject?->parent_id) == $parent->id)>{{ $parent->name }}</option>
        @endforeach
    </select>
</x-admin.field>

<x-admin.field label="توضیح و دامنه‌ی موضوع (اختیاری)" for="description">
    <textarea name="description" id="description" rows="3" class="admin-field w-full leading-7">{{ old('description', $subject?->description) }}</textarea>
</x-admin.field>

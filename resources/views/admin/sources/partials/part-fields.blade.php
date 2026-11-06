{{-- The fields of a volume part, for its edit and for a new one; «bag» names the form's error bag --}}
@php($err = $errors->getBag($bag))
<div class="grid gap-4 md:grid-cols-2">
    <div class="space-y-1 md:col-span-2">
        <label for="{{ $bag }}-title" class="block font-bold text-ink">عنوان بخش</label>
        <input type="text" name="title" id="{{ $bag }}-title" value="{{ $values['title'] }}" required placeholder="مثال: کتابخانه مدرسه منصوریه" class="admin-field w-full">
        @if($err->has('title'))<p class="text-xs font-bold text-cinnabar">{{ $err->first('title') }}</p>@endif
    </div>
    <div class="space-y-1">
        <label for="{{ $bag }}-library" class="block font-bold text-ink">کتابخانه</label>
        <select name="library_id" id="{{ $bag }}-library" class="admin-field w-full">
            <option value="">بی‌کتابخانه</option>
            @foreach($libraries as $lib)
                <option value="{{ $lib->id }}" @selected((int) $values['library_id'] === $lib->id)>{{ $lib->name }} ({{ $lib->city }})</option>
            @endforeach
        </select>
        @if($err->has('library_id'))<p class="text-xs font-bold text-cinnabar">{{ $err->first('library_id') }}</p>@endif
    </div>
    <div class="grid grid-cols-3 gap-2">
        <div class="space-y-1">
            <label for="{{ $bag }}-first" class="block font-bold text-ink">از صفحه</label>
            <input type="number" min="1" name="page_first" id="{{ $bag }}-first" value="{{ $values['page_first'] }}" class="admin-field w-full" dir="ltr">
        </div>
        <div class="space-y-1">
            <label for="{{ $bag }}-last" class="block font-bold text-ink">تا صفحه</label>
            <input type="number" min="1" name="page_last" id="{{ $bag }}-last" value="{{ $values['page_last'] }}" class="admin-field w-full" dir="ltr">
        </div>
        <div class="space-y-1">
            <label for="{{ $bag }}-sort" class="block font-bold text-ink">ترتیب</label>
            <input type="number" min="0" name="sort" id="{{ $bag }}-sort" value="{{ $values['sort'] }}" class="admin-field w-full" dir="ltr">
        </div>
        @foreach(['page_first', 'page_last', 'sort'] as $key)
            @if($err->has($key))<p class="col-span-3 text-xs font-bold text-cinnabar">{{ $err->first($key) }}</p>@endif
        @endforeach
    </div>
    <div class="space-y-1 md:col-span-2">
        <p class="font-bold text-ink">فهرست‌نگاران بخش</p>
        <p class="text-xs text-muted">نخستین فهرست‌نگار، فهرست‌نگار نسخه‌های بخش می‌شود.</p>
        <div class="grid gap-2 sm:grid-cols-2">
            @foreach($values['catalogers'] as $i => $id)
                <select name="catalogers[]" aria-label="فهرست‌نگار {{ \App\Support\Fa::num($i + 1) }}" class="admin-field w-full">
                    <option value="">—</option>
                    @foreach($catalogers as $c)
                        <option value="{{ $c->id }}" @selected((int) $id === $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>
            @endforeach
        </div>
    </div>
</div>

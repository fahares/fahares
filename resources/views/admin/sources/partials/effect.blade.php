{{-- What a change of the volume did (or, in a preview, would do) to its copies, from VolumeEditor::run --}}
@php
    $fa = fn ($n) => \App\Support\Fa::num($n);
    $lines = array_filter([
        $effect['joined'] ? $fa($effect['joined']) . ' نسخه بخش می‌گیرند' : null,
        $effect['moved'] ? $fa($effect['moved']) . ' نسخه به بخش دیگری می‌روند' : null,
        $effect['left'] ? $fa($effect['left']) . ' نسخه بی‌بخش می‌شوند' : null,
        $effect['cataloger'] ? 'فهرست‌نگار ' . $fa($effect['cataloger']) . ' نسخه عوض می‌شود' : null,
    ]);
    $after = array_filter([
        $effect['outside'] || ($effect['outside_before'] ?? 0)
            ? $fa($effect['outside']) . ' نسخه بیرون از بازه‌ی صفحه‌های بخش کتابخانه‌شان'
                . (isset($effect['outside_before']) && $effect['outside_before'] !== $effect['outside'] ? ' (پیش از این ' . $fa($effect['outside_before']) . ')' : '')
            : null,
        $effect['by_page'] ? $fa($effect['by_page']) . ' نسخه‌ی بی‌بخش که صفحه‌شان در بازه‌ی تنها یک بخش است (کتابخانه‌شان در بخش‌ها نیست)' : null,
        $effect['none'] ? $fa($effect['none']) . ' نسخه‌ی بی‌بخش در جلد' : null,
    ]);
@endphp
<x-admin.notice :tone="$done ? 'good' : 'warn'" class="text-xs">
    <p class="font-bold">{{ $done ? 'ذخیره شد.' : 'پیش‌نمایش: هنوز چیزی ذخیره نشده است.' }}
        {{ $lines ? implode('، ', $lines) . '.' : ($done ? 'بخش و فهرست‌نگار هیچ نسخه‌ای عوض نشد.' : 'بخش و فهرست‌نگار هیچ نسخه‌ای عوض نمی‌شود.') }}</p>
    @if($after)
        <p>در کل جلد، پس از این تغییر: {{ implode('؛ ', $after) }}.</p>
    @endif
</x-admin.notice>

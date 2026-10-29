{{--
    The comparison step of a merge (backlog items 46 and 90; see MergeComparison): each field the removed record
    holds that the kept one lacks or holds differently, with the admin's choice. The defaults are checked, so the
    merge stays one click. Takes $rows, $inputName (e.g. «fields[target]») and optionally $keptLabel / $removedLabel.
--}}
@php
    $keptLabel ??= 'رکورد ماندنی';
    $removedLabel ??= 'رکورد حذف‌شونده';
    $lines = fn (array $values) => $values ? implode("\n", $values) : '—';
@endphp
<div class="space-y-2">
    <p class="font-bold text-ink">مقایسه‌ی فیلدها: سرنوشت داده‌ی {{ $removedLabel }}</p>
    @if(! $rows)
        <p class="text-muted">{{ $removedLabel }} داده‌ای ندارد که در {{ $keptLabel }} نباشد یا با آن فرق کند.</p>
    @else
        <p class="text-xs text-muted leading-6">پیش‌فرض‌ها انتخاب شده‌اند: جای خالی پر می‌شود، فهرست‌ها و «بله»ها یکی می‌شوند و در اختلاف، مقدار {{ $keptLabel }} می‌ماند. انتخاب‌ها در لاگ ممیزی ثبت و با بازگردانی ادغام برگردانده می‌شوند.</p>
        <div class="divide-y divide-line border border-line bg-paper">
            @foreach($rows as $row)
                <fieldset class="p-3 space-y-2" data-merge-field="{{ $row['key'] }}">
                    <legend class="sr-only">{{ $row['label'] }}</legend>
                    <p class="font-bold text-ink">{{ $row['label'] }}</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <div class="min-w-0">
                            <span class="block text-xs text-muted">{{ $keptLabel }}</span>
                            <p class="whitespace-pre-line break-words text-ink">{{ \Illuminate\Support\Str::limit($lines($row['target']), 600) }}</p>
                        </div>
                        <div class="min-w-0">
                            <span class="block text-xs text-muted">{{ $removedLabel }}</span>
                            <p class="whitespace-pre-line break-words text-ink">{{ \Illuminate\Support\Str::limit($lines($row['source']), 600) }}</p>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-x-5 gap-y-1">
                        @foreach($row['options'] as $value => $label)
                            <label class="inline-flex items-start gap-1.5">
                                <input type="radio" name="{{ $inputName }}[{{ $row['key'] }}]" value="{{ $value }}" @checked(old(str_replace(['[', ']'], ['.', ''], $inputName) . '.' . $row['key'], $row['default']) === $value) class="mt-1 accent-cinnabar">
                                <span>{{ $label }}@if($value === $row['default']) <span class="text-xs text-muted">(پیش‌فرض)</span>@endif</span>
                            </label>
                        @endforeach
                    </div>
                    @isset($row['note'])<p class="text-xs text-muted">{{ $row['note'] }}</p>@endisset
                </fieldset>
            @endforeach
        </div>
    @endif
</div>

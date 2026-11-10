@props(['record'])
{{-- Relations with other works or manuscripts in both directions, grouped by the label for this page (see RecordRelations) --}}
@php
    $groups = \App\Suggestions\RecordRelations::for($record);
    $isManuscript = $record instanceof \App\Models\Manuscript;
@endphp
@php
    $title = $isManuscript ? 'رابطه با نسخه‌های دیگر' : 'رابطه با آثار دیگر';
    $payload = ['general' => true, 'relation' => $isManuscript ? 'manuscript_relation' : 'work_relation'];
@endphp
{{-- Empty: a row of the contribution box instead of an empty heading (step 14.132) --}}
@if($groups->isEmpty())
    <x-suggest.contribute-item :title="$title" label="افزودن رابطه" :payload="$payload"
        :hint="$isManuscript ? 'اگر این نسخه از روی نسخه‌ی دیگری استنساخ شده، با نسخه‌ای مقابله شده، مجلد دیگری از همان دوره است یا نسخه‌ی عکسی یا میکروفیلمی از آن هست.' : 'اگر این اثر شرح، حاشیه، ترجمه، تلخیص، نظم، تکمله یا ردیه‌ی اثر دیگری است، یا اثر دیگری بر آن نوشته شده.'" />
@else
<x-ui.section x-data :title="$title">
    <x-slot:actions>
        <button type="button" @click="$dispatch('suggest-field', @js($payload))"
            class="font-bold text-cinnabar hover:underline cursor-pointer">+ افزودن رابطه</button>
    </x-slot:actions>
        <dl class="space-y-3 text-sm">
            @foreach($groups as $label => $items)
                <div class="space-y-1">
                    <dt class="text-[13px] text-muted">{{ $label }}:</dt>
                    <dd class="space-y-1">
                        @foreach($items as $item)
                            @php $info = \App\Suggestions\SuggestionRegistry::describe($item['record']); @endphp
                            <div>
                                <a href="{{ $info['url'] }}" class="font-semibold text-ink border-b border-line-strong hover:text-cinnabar hover:border-cinnabar transition">{{ $info['label'] }}</a>
                                @if($info['sub'])<span class="text-[11px] text-muted"> · {{ $info['sub'] }}</span>@endif
                                @if($item['note'])<span class="text-[11px] text-muted"> ({{ $item['note'] }})</span>@endif
                                @if($item['credit'] ?? null)<span class="block text-[10px] text-muted">به پیشنهاد {{ $item['credit'] }}</span>@endif
                            </div>
                        @endforeach
                    </dd>
                </div>
            @endforeach
        </dl>
</x-ui.section>
@endif

@props(['record'])
{{-- Relations with other works or manuscripts in both directions, grouped by the label for this page (see RecordRelations) --}}
@php
    $groups = \App\Suggestions\RecordRelations::for($record);
    $isManuscript = $record instanceof \App\Models\Manuscript;
@endphp
<div x-data class="bg-white dark:bg-[#15192C] rounded-3xl p-5 sm:p-6 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-3">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-sm font-bold text-[#292C56] dark:text-amber-200 border-r-2 border-[#B38A50] pr-2">{{ $isManuscript ? 'رابطه با نسخه‌های دیگر' : 'رابطه با آثار دیگر' }}</h3>
        <button type="button" @click="$dispatch('suggest-field', { general: true, relation: @js($isManuscript ? 'manuscript_relation' : 'work_relation') })"
            class="text-[11px] font-bold text-[#B38A50] hover:underline cursor-pointer">+ افزودن رابطه</button>
    </div>
    @if($groups->isEmpty())
        <p class="text-xs text-stone-500 dark:text-stone-400 leading-relaxed">
            {{ $isManuscript ? 'اگر می‌دانید این نسخه از روی نسخه‌ی دیگری استنساخ شده، با نسخه‌ای مقابله شده، مجلد دیگری از همان دوره است یا نسخه‌ی عکسی یا میکروفیلمی از آن هست، آن را بیفزایید.' : 'اگر این اثر شرح، حاشیه، ترجمه، تلخیص، نظم، تکمله یا ردیه‌ی اثر دیگری است، یا اثر دیگری بر آن نوشته شده، آن را بیفزایید.' }}
        </p>
    @else
        <dl class="space-y-3 text-xs">
            @foreach($groups as $label => $items)
                <div class="space-y-1">
                    <dt class="font-bold text-stone-500 dark:text-stone-400">{{ $label }}:</dt>
                    <dd class="space-y-1">
                        @foreach($items as $item)
                            @php $info = \App\Suggestions\SuggestionRegistry::describe($item['record']); @endphp
                            <div>
                                <a href="{{ $info['url'] }}" class="font-bold text-[#B38A50] hover:underline">{{ $info['label'] }}</a>
                                @if($info['sub'])<span class="text-[11px] text-stone-400"> · {{ $info['sub'] }}</span>@endif
                                @if($item['note'])<span class="text-[11px] text-stone-500"> ({{ $item['note'] }})</span>@endif
                            </div>
                        @endforeach
                    </dd>
                </div>
            @endforeach
        </dl>
    @endif
</div>

@use('App\Support\Fa')
{{-- The conflicts of one date (step 14.169), as a row of its own under the date's row in x-ui.meta-list: it takes the
     list's full width, since a sidebar's value column is too narrow for it (step 14.176) --}}
<div class="col-span-full space-y-1.5 mb-1 sm:-mt-1.5 text-[12px] leading-6" data-date-conflicts>
    @foreach($notes as $note)
        <div class="px-3 py-2 bg-cinnabar/5 border-s-2 border-cinnabar" x-data="{ open: false }" data-date-conflict="{{ $note['id'] }}">
            <div>
                <b class="text-cinnabar">ناسازگاری تاریخ:</b>
                <span class="text-ink">{{ $note['label'] }}</span>
            </div>
            <div class="text-muted">
                {{ $note['otherField'] }}@if($note['other']) <a href="{{ $note['other']['url'] }}" class="underline hover:text-cinnabar">{{ $note['other']['title'] }}</a>@endif{{ $note['otherDate'] !== null ? '، ' . Fa::digits($note['otherDate']) . ' ق' : '' }}
                · فاصله {{ Fa::num($note['gap']) }} سال
            </div>
            <button type="button" class="font-bold text-cinnabar hover:underline cursor-pointer" @click="open = ! open" :aria-expanded="open ? 'true' : 'false'">کدام نادرست است؟</button>
            <div class="mt-1.5 flex flex-wrap gap-1.5" x-show="open" x-cloak>
                @foreach($note['choices'] as $choice)
                    @if($choice['url'])
                        <a href="{{ $choice['url'] }}" class="px-2 py-0.5 border border-line-strong rounded-sm bg-surface text-ink hover:border-cinnabar hover:text-cinnabar">{{ $choice['label'] }} ←</a>
                    @else
                        <button type="button" class="px-2 py-0.5 border border-line-strong rounded-sm bg-surface text-ink text-start hover:border-cinnabar hover:text-cinnabar cursor-pointer"
                            @click="open = false; $dispatch('suggest-field', @js($choice['detail']))">{{ $choice['label'] }}</button>
                    @endif
                @endforeach
            </div>
        </div>
    @endforeach
    @if($more)
        <div class="text-muted">و {{ Fa::num($more) }} ناسازگاری دیگر</div>
    @endif
</div>

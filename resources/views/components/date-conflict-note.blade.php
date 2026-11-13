@use('App\Support\Fa')
{{-- The conflicts of one date, under it (step 14.169): what it does not agree with, and the answers a reader can give --}}
<span class="block mt-1 space-y-1.5 text-[12px] leading-6" data-date-conflicts>
    @foreach($notes as $note)
        <span class="block ps-2 border-s-2 border-cinnabar" x-data="{ open: false }" data-date-conflict="{{ $note['id'] }}">
            <span class="text-cinnabar font-bold">ناسازگاری تاریخ:</span>
            {{ $note['label'] }}
            <span class="text-muted">
                ({{ $note['otherField'] }}@if($note['other']) <a href="{{ $note['other']['url'] }}" class="underline hover:text-cinnabar">{{ $note['other']['title'] }}</a>@endif{{ $note['otherDate'] !== null ? '، ' . Fa::digits($note['otherDate']) . ' ق' : '' }}؛
                فاصله {{ Fa::num($note['gap']) }} سال)
            </span>
            <button type="button" class="font-bold text-cinnabar hover:underline cursor-pointer" @click="open = ! open" :aria-expanded="open ? 'true' : 'false'">کدام نادرست است؟</button>
            <span class="block mt-1 space-y-1" x-show="open" x-cloak>
                @foreach($note['choices'] as $choice)
                    @if($choice['url'])
                        <a href="{{ $choice['url'] }}" class="block w-fit px-2 py-0.5 border border-line bg-surface text-ink hover:border-cinnabar hover:text-cinnabar">{{ $choice['label'] }} ←</a>
                    @else
                        <button type="button" class="block w-fit px-2 py-0.5 border border-line bg-surface text-ink text-start hover:border-cinnabar hover:text-cinnabar cursor-pointer"
                            @click="open = false; $dispatch('suggest-field', @js($choice['detail']))">{{ $choice['label'] }}</button>
                    @endif
                @endforeach
            </span>
        </span>
    @endforeach
    @if($more)
        <span class="block text-muted">و {{ Fa::num($more) }} ناسازگاری دیگر</span>
    @endif
</span>

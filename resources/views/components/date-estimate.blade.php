@props(['estimate'])
@use('App\Support\Fa')
{{-- A date nothing documents, as fahares:estimate-dates reckons it (step 14.170): set apart from a documented date by
     its «تخمینی» mark, with the reason of each bound a click away --}}
<span x-data="{ open: false }" data-date-estimate>
    <span class="text-muted">{{ Fa::digits($estimate['text']) }}</span>
    <span class="ms-1 px-1.5 py-px border border-line rounded-sm text-[11px] text-muted align-middle">تخمینی</span>
    <button type="button" class="ms-1 text-[12px] font-bold text-cinnabar hover:underline cursor-pointer" @click="open = ! open" :aria-expanded="open ? 'true' : 'false'">چرا؟</button>
    <span class="block mt-1 ps-2 border-s-2 border-line space-y-1 text-[12px] leading-6 text-muted" x-show="open" x-cloak>
        @foreach($estimate['reasons'] as $reason)
            <span class="block">
                <b class="text-ink">{{ Fa::digits($reason['bound']) }}:</b>
                {{ $reason['text'] }}
                @if($reason['url'])<a href="{{ $reason['url'] }}" class="underline hover:text-cinnabar">{{ Fa::digits($reason['title']) }}</a>@endif
            </span>
        @endforeach
        <span class="block">این تاریخ در فهرست‌ها نیامده و از روی تاریخ‌های ثبت‌شده‌ی آثار، اشخاص و نسخه‌های مرتبط حساب شده است.</span>
    </span>
</span>

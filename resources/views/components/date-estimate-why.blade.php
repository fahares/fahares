@props(['estimate', 'name'])
@use('App\Support\Fa')
{{-- The reasons of an estimate (x-date-estimate), in a row of x-ui.meta-list of its own so they have the list's full
     width (step 14.176) --}}
<div class="col-span-full mb-1 sm:-mt-1.5 px-3 py-2 bg-paper border-s-2 border-line-strong space-y-1 text-[12px] leading-6 text-muted"
     x-data="{ open: false }" x-show="open" x-cloak @estimate-why.window="if ($event.detail === @js($name)) open = ! open" data-date-estimate-why>
    @foreach($estimate['reasons'] as $reason)
        <div>
            <b class="text-ink">{{ Fa::digits($reason['bound']) }}:</b>
            {{ $reason['text'] }}
            @if($reason['url'])<a href="{{ $reason['url'] }}" class="underline hover:text-cinnabar">{{ Fa::digits($reason['title']) }}</a>@endif
        </div>
    @endforeach
    <div>این تاریخ در فهرست‌ها نیامده و از روی تاریخ‌های ثبت‌شده‌ی آثار، اشخاص و نسخه‌های مرتبط حساب شده است.</div>
</div>

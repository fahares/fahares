{{-- Two recorded dates that cannot both be right (DateConflictQueue::card, step 14.212): the later one first. --}}
@use('App\Support\Fa')
<div class="space-y-1">
    <h2 class="font-naskh text-lg sm:text-xl font-bold text-ink leading-relaxed m-0">کدام نادرست است؟</h2>
    <p class="text-sm text-ink leading-7 m-0">
        <b class="text-cinnabar">{{ $conflict->label }}</b>
        <span class="text-muted">· فاصله {{ Fa::num($conflict->gap) }} سال</span>
    </p>
    <p class="text-xs sm:text-sm text-muted leading-6 m-0">
        این دو تاریخ با هم نمی‌خوانند، پس دست‌کم یکی نادرست است: یکی از دو تاریخ، یا پیوند این دو رکورد با هم. هر رکورد را باز کنید و با مأخذش بسنجید.
        اگر تاریخ درست را می‌دانید، در توضیح بنویسید.
    </p>
</div>

<div class="grid gap-4 sm:grid-cols-2">
    @foreach($sides as $side)
        <section class="p-3 border border-line bg-paper space-y-1.5 min-w-0" data-conflict-side="{{ $loop->first ? 'a' : 'b' }}">
            <h3 class="text-[11px] font-bold text-muted m-0">{{ $side['type'] }}</h3>
            @if($side['url'])
                <a href="{{ $side['url'] }}" target="_blank" class="block font-bold text-ink hover:text-cinnabar break-words leading-7">{{ $side['title'] }}</a>
                @if($side['detail'])<p class="m-0 text-xs text-muted break-words">{{ Fa::digits($side['detail']) }}</p>@endif
                <p class="m-0 text-sm text-ink">
                    {{ $side['field'] }}:
                    <b class="tabular-nums">{{ $side['date'] !== null ? Fa::digits($side['date']) . ' ق' : '—' }}</b>
                </p>
                @if($side['raw'])
                    <p class="m-0 text-xs text-muted break-words">متن تاریخ در فهرست: «{{ Fa::digits(\Illuminate\Support\Str::limit($side['raw'], 120)) }}»</p>
                @endif
            @else
                <p class="m-0 text-xs text-muted">این رکورد دیگر وجود ندارد.</p>
            @endif
        </section>
    @endforeach
</div>

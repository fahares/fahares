{{-- «تصحیح جمعی» (backlog item 80): the panel's review queues open to researchers, each with what is left. The title
     comes from config/contributions.php; the anchor #review and the /contribute addresses never carry it. --}}
@use('App\Support\Fa')
<section id="review" class="scroll-mt-24 space-y-3">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 class="rubric text-lg m-0">{{ config('contributions.review_title') }}</h2>
        @if($review['mine'] && $review['mine']['answers'])
            <p class="text-xs text-muted m-0">پاسخ‌های شما: <b class="text-ink tabular-nums">{{ Fa::num($review['mine']['answers']) }}</b>@if($review['mine']['approved']) · پذیرفته: <b class="text-ink tabular-nums">{{ Fa::num($review['mine']['approved']) }}</b>@endif</p>
        @endif
    </div>
    <p class="text-xs sm:text-sm text-muted leading-6 m-0">
        بخشی از داده‌ها را ماشین ساخته و هنوز چشم انسانی آن را ندیده است. با دیدن شواهد به چند پرسش ساده پاسخ دهید؛
        پاسخ شما مستقیم چیزی را تغییر نمی‌دهد، کنار پاسخ دیگران به داوران می‌رسد و اگر پذیرفته شد، به نام شما ثبت می‌شود.
    </p>
    <div class="grid gap-3 sm:grid-cols-2">
        @foreach($review['queues'] as $key => $q)
            <a href="{{ route('contribute.next', $key) }}" class="group block bg-surface border border-line hover:border-cinnabar transition p-4 space-y-1.5" data-review-queue="{{ $key }}">
                <span class="flex items-baseline justify-between gap-2">
                    <span class="font-bold text-ink group-hover:text-cinnabar">{{ $q['queue']->title() }}</span>
                    <span class="text-xs text-muted tabular-nums shrink-0"><b class="text-cinnabar">{{ Fa::num($q['waiting']) }}</b> مورد مانده</span>
                </span>
                <span class="block text-xs text-muted leading-6">{{ $q['queue']->blurb() }}</span>
                <span class="inline-block text-xs font-semibold text-cinnabar">شروع ←</span>
            </a>
        @endforeach
    </div>
</section>

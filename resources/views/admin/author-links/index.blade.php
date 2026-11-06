@extends('layouts.admin')
@use('App\Support\Fa')

@section('title', 'پیوند پدیدآور')
@section('page_title', 'پیوند پدیدآور: نامی در فهرست، شخصی در سایت')

@section('content')
<div class="space-y-5 max-w-5xl text-[13px]">
    <p class="text-muted leading-7 max-w-3xl">
        این آثار فقط نام پدیدآور را دارند. شخصی با همین نام و آوانویسی در سایت هست، ولی نام عام است و تاریخی در فهرست نیامده. «پیوند بده» آن شخص را پدیدآور اثر می‌کند؛ «شخص دیگری است» نام را همان‌طور که هست نگه می‌دارد. پاسخ‌های «{{ config('contributions.review_title') }}» نخست آمده‌اند.
    </p>

    <x-admin.tabs :options="[
        ['label' => 'در انتظار', 'count' => ($counts['open'] ?? 0) + ($counts['deferred'] ?? 0), 'active' => ! $done, 'url' => route('admin.author-links.index')],
        ['label' => 'تصمیم‌گرفته', 'count' => ($counts['linked'] ?? 0) + ($counts['not_linked'] ?? 0), 'active' => $done, 'url' => route('admin.author-links.index', ['view' => 'done'])],
    ]" />

    @if($errors->any())
        <x-admin.notice tone="error">{{ $errors->first() }}</x-admin.notice>
    @endif

    @forelse($reviews as $review)
        @php $c = $cards[$review->id]; $work = $c['work']; $person = $c['person']; @endphp
        <article id="author-link-{{ $review->id }}" class="bg-surface border border-line p-4 sm:p-5 space-y-3 scroll-mt-20">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="min-w-0 space-y-1">
                    <div class="text-[11px] text-muted">اثر</div>
                    @if($work)
                        <a href="{{ route('works.show', $work) }}" target="_blank" class="font-bold text-ink hover:text-cinnabar">{{ $work->primary_title }}</a>
                        <div>پدیدآور در فهرست: <b>{{ $work->author_name }}</b> @if($c['translit'])<span class="text-muted" dir="ltr">{{ $c['translit'] }}</span>@endif</div>
                        <div class="text-muted">{{ Fa::num($c['copies']) }} نسخه @if($c['earliest']) · کهن‌ترین کتابت {{ Fa::digits((string) $c['earliest']) }} ق @endif</div>
                    @else
                        <div class="text-muted">اثر دیگر وجود ندارد.</div>
                    @endif
                </div>
                <div class="min-w-0 space-y-1">
                    <div class="text-[11px] text-muted">شخص</div>
                    @if($person)
                        <a href="{{ route('people.show', $person) }}" target="_blank" class="font-bold text-ink hover:text-cinnabar">{{ $person->name }}</a>
                        <span class="text-muted tabular-nums">#{{ Fa::digits((string) $person->id) }}</span>
                        <div class="text-muted">
                            {{ implode(' · ', array_filter([
                                $person->death_year_hijri ? 'درگذشت ' . Fa::digits((string) $person->death_year_hijri) . ' ق' : null,
                                $person->life_dates_raw ? '«' . Fa::digits($person->life_dates_raw) . '»' : null,
                                $person->manuscripts_count ? Fa::num($person->manuscripts_count) . ' نسخه' : null,
                            ])) }}
                        </div>
                    @endif
                </div>
            </div>

            @if($item = $opinions['author:' . $review->id] ?? null)
                <div class="text-xs px-3 py-2 leading-6 bg-paper border border-s-4 border-gold">
                    <b>{{ config('contributions.review_title') }}:</b>
                    @foreach($item['answers'] as $a){{ $loop->first ? '' : '؛ ' }}{{ Fa::num($a['count']) }} نفر «{{ $a['label'] }}» (وزن {{ Fa::num($a['weight']) }})@endforeach
                </div>
            @endif

            @if($review->isOpen())
                <div class="flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('admin.author-links.link', $review) }}">@csrf
                        <button class="px-3 py-1.5 bg-ink text-paper text-xs font-bold">پیوند بده</button>
                    </form>
                    <form method="POST" action="{{ route('admin.author-links.reject', $review) }}">@csrf
                        <button class="px-3 py-1.5 border border-line text-xs">شخص دیگری است</button>
                    </form>
                </div>
            @else
                <div class="text-xs text-muted">{{ \App\Models\PersonReview::STATUSES[$review->status] ?? $review->status }} @if($review->decider) · {{ $review->decider->name }} @endif</div>
            @endif
        </article>
    @empty
        <p class="text-muted">موردی نیست.</p>
    @endforelse

    {{ $reviews->links('admin.partials.pagination') }}
</div>
@endsection

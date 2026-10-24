@extends('layouts.app')

@use('App\Support\Fa')
@use('App\Support\Jalali')
@php
    $title = $researcher ? 'اصلاحات ' . $researcher['name'] : config('contributions.title');
    $route = $researcher ? route('contributors.show', $researcher['user']) : route('contributions.index');
    $filtering = array_filter($filters);
    $select = 'w-full p-2 rounded-sm border border-line-strong bg-paper text-ink text-xs sm:text-sm';
@endphp

@section('title', $title . ' | فهارس')
@section('meta_description', $researcher
    ? 'اصلاحات تأییدشده‌ی ' . $researcher['name'] . ' در داده‌های فهارس نسخه‌های خطی'
    : 'گزارش اصلاحاتی که پژوهشگران در داده‌های فهارس نسخه‌های خطی پیشنهاد کرده‌اند و داوران تأیید کرده‌اند')

@section('content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

    {{-- Heading: the site-wide figures, or the researcher's card --}}
    <x-ui.card framed class="space-y-5 sm:p-8">
        @if($researcher)
            <div class="flex items-center gap-4">
                <x-ui.avatar :user="$researcher['user']" :name="$researcher['name']" size="xl" class="!w-16 !h-16 sm:!w-24 sm:!h-24 !text-2xl sm:!text-4xl" />
                <div class="min-w-0">
                    <p class="text-xs text-muted"><a href="{{ route('contributions.index') }}" class="hover:text-cinnabar">{{ config('contributions.title') }}</a></p>
                    <h1 class="font-naskh text-2xl sm:text-3xl font-bold text-ink leading-relaxed break-words">{{ $researcher['name'] }}</h1>
                    <p class="text-xs sm:text-sm text-muted">
                        {{ Fa::num($researcher['count']) }} اصلاح تأییدشده در {{ Fa::num($researcher['records']) }} رکورد
                        @if($researcher['first']) · نخستین اصلاح: {{ Jalali::format(\Illuminate\Support\Carbon::parse($researcher['first']), 'long') }}@endif
                    </p>
                </div>
            </div>
        @else
            <div>
                <h1 class="font-naskh text-2xl sm:text-3xl font-bold text-ink leading-relaxed">{{ config('contributions.title') }}</h1>
                <p class="mt-1 text-xs sm:text-sm text-muted leading-6">
                    اصلاحاتی که پژوهشگران در داده‌های فهارس پیشنهاد کرده‌اند و داوران پس از بررسی پذیرفته‌اند، تازه‌ترین در بالا.
                    نام هر پژوهشگر فقط با اجازه‌ی خود او آمده است. در صفحه‌ی هر نسخه، اثر، شخص یا کتابخانه می‌توانید با «پیشنهاد اصلاح» در این کار سهیم شوید.
                </p>
            </div>
            <dl class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-center m-0">
                @foreach([['total', 'اصلاح تأییدشده'], ['contributors', 'مشارکت‌کننده'], ['records', 'رکورد اصلاح‌شده'], ['recent', 'در ۳۰ روز اخیر']] as [$key, $label])
                    <div class="p-3 rounded-sm bg-paper border border-line">
                        <dd class="m-0 text-xl font-bold text-cinnabar tabular-nums">{{ Fa::num($stats[$key]) }}</dd>
                        <dt class="text-[11px] text-muted">{{ $label }}</dt>
                    </div>
                @endforeach
            </dl>
        @endif
    </x-ui.card>

    @if($review)
        @include('contributions.review')
    @endif

    {{-- Thanks: the researchers shown by name --}}
    @if($researchers->isNotEmpty())
        <x-ui.section title="با سپاس از">
            <ul class="flex flex-wrap gap-2 m-0 p-0 list-none">
                @foreach($researchers as $r)
                    <li>
                        <a href="{{ route('contributors.show', $r['user']) }}" class="inline-flex items-center gap-2 ps-1 pe-3 py-1 rounded-full border border-line bg-surface hover:border-cinnabar hover:text-cinnabar transition text-xs sm:text-sm text-ink">
                            <x-ui.avatar :user="$r['user']" :name="$r['name']" size="md" />
                            <span class="font-bold">{{ $r['name'] }}</span>
                            <span class="text-muted tabular-nums">{{ Fa::num($r['count']) }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </x-ui.section>
    @endif

    {{-- Filters --}}
    <form method="GET" action="{{ $route }}" class="grid grid-cols-2 {{ $researcher ? 'sm:grid-cols-4' : 'sm:grid-cols-5' }} gap-2 items-end p-3 rounded-sm bg-surface border border-line">
        @unless($researcher)
            <label class="col-span-2 sm:col-span-1 space-y-1">
                <span class="block text-[11px] text-muted">پژوهشگر</span>
                <select name="researcher" class="{{ $select }}">
                    <option value="">همه</option>
                    @foreach($researchers as $r)
                        <option value="{{ $r['user']->id }}" @selected($filters['researcher'] === $r['user']->id)>{{ $r['name'] }}</option>
                    @endforeach
                </select>
            </label>
        @endunless
        <label class="space-y-1">
            <span class="block text-[11px] text-muted">نوع رکورد</span>
            <select name="type" class="{{ $select }}">
                <option value="">همه</option>
                @foreach(array_keys(\App\Suggestions\SuggestionRegistry::TYPES) as $type)
                    <option value="{{ $type }}" @selected($filters['type'] === $type)>{{ \App\Suggestions\SuggestionRegistry::typeLabel($type) }}</option>
                @endforeach
            </select>
        </label>
        <label class="space-y-1">
            <span class="block text-[11px] text-muted">نوع اصلاح</span>
            <select name="kind" class="{{ $select }}">
                <option value="">همه</option>
                @foreach(\App\Suggestions\Contributions::KINDS as $kind => $label)
                    <option value="{{ $kind }}" @selected($filters['kind'] === $kind)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="space-y-1">
            <span class="block text-[11px] text-muted">بازه</span>
            <select name="period" class="{{ $select }}">
                <option value="">همه</option>
                @foreach(\App\Http\Controllers\ContributionsController::PERIODS as $days => $label)
                    <option value="{{ $days }}" @selected($filters['period'] === (string) $days)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <div class="flex gap-2">
            <x-ui.btn type="submit" variant="primary" class="flex-1">نمایش</x-ui.btn>
            @if($filtering)
                <x-ui.btn :href="$route" variant="ghost">همه</x-ui.btn>
            @endif
        </div>
    </form>

    {{-- The corrections, under a heading per Jalali month --}}
    @php $month = null; @endphp
    <div class="space-y-3">
        @forelse($items as $item)
            @php
                $s = $item['suggestion'];
                $c = $item['contributor'];
                $thisMonth = Jalali::format($item['date'], 'MMMM y');
            @endphp
            @if($thisMonth !== $month)
                @php $month = $thisMonth; @endphp
                <h2 class="rubric text-base pt-2">{{ $month }}</h2>
            @endif
            <article class="bg-surface p-4 sm:p-5 rounded-sm border border-line space-y-2.5 text-xs sm:text-sm" data-contribution="{{ $s->id }}">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2 min-w-0">
                        <x-ui.avatar :user="$c['user']" :name="$c['name']" :kind="$c['kind']" size="md" />
                        @if($c['url'] && ! $researcher)
                            <a href="{{ $c['url'] }}" class="font-bold text-ink hover:text-cinnabar truncate">{{ $c['name'] }}</a>
                        @else
                            <span class="font-bold {{ $c['kind'] === 'credited' ? 'text-ink' : 'text-muted' }} truncate">{{ $c['name'] }}</span>
                        @endif
                    </div>
                    <time datetime="{{ $item['date']?->toIso8601String() }}" class="text-[11px] text-muted shrink-0">{{ Jalali::format($item['date'], 'long') }}</time>
                </div>

                <div class="flex flex-wrap items-center gap-1.5">
                    <x-ui.chip>{{ $item['type'] }}</x-ui.chip>
                    <x-ui.chip tone="accent">{{ $item['action'] }}</x-ui.chip>
                    @if($s->kind === 'field' && $s->standing === 'adopted')
                        <span class="inline-block size-2.5 rounded-full bg-emerald-500 shrink-0 self-center" title="مقدار منتخب" aria-label="مقدار منتخب" role="img"></span>
                    @elseif($item['standing'])
                        <x-ui.chip>{{ $item['standing'] }}</x-ui.chip>
                    @endif
                </div>

                @if($item['record'])
                    <p class="m-0 font-bold leading-7 break-words">
                        @if($item['record']['url'])
                            <a href="{{ $item['record']['url'] }}" class="text-ink hover:text-cinnabar">{{ $item['record']['label'] }}</a>
                        @else
                            <span class="text-ink">{{ $item['record']['label'] }}</span>
                        @endif
                    </p>
                @endif

                @if($item['text'])
                    <p class="m-0 text-ink/85 leading-7 break-words">{{ $item['text'] }}</p>
                @endif

                @if($item['before'] || $item['after'])
                    <div class="grid gap-1.5 {{ $item['before'] && $item['after'] ? 'sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] sm:items-center' : '' }}">
                        @foreach(['before' => 'پیشین', 'after' => 'تازه'] as $key => $label)
                            @if($item[$key])
                                <div class="p-2.5 rounded-sm border leading-7 break-words {{ $key === 'before' ? 'bg-paper border-line text-muted' : 'bg-emerald-600/5 border-emerald-600/25 text-ink' }}">
                                    <span class="block text-[10px] font-bold {{ $key === 'before' ? 'text-muted' : 'text-emerald-700 dark:text-emerald-300' }}">{{ $label }}</span>
                                    @if($item[$key]['url'])
                                        <a href="{{ $item[$key]['url'] }}" class="hover:text-cinnabar">{{ $item[$key]['label'] }}</a>
                                    @else
                                        {{ $item[$key]['label'] }}
                                    @endif
                                </div>
                                @if($key === 'before' && $item['after'])
                                    <svg class="hidden sm:block w-4 h-4 text-muted rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                                @endif
                            @endif
                        @endforeach
                    </div>
                @endif
            </article>
        @empty
            <div class="p-8 rounded-sm bg-surface border border-line text-center text-sm text-muted">
                {{ $filtering ? 'با این فیلترها اصلاحی پیدا نشد.' : 'هنوز اصلاح تأییدشده‌ای ثبت نشده است.' }}
            </div>
        @endforelse
    </div>

    {{ $items->links() }}
</div>
@endsection

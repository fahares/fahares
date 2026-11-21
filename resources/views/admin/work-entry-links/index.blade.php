@extends('layouts.admin')
@use('App\Support\Fa')
@use('App\Models\WorkEntryLink')
@use('App\Http\Controllers\Admin\WorkEntryLinkController')

@section('title', 'پیوند آثار مرتبط')
@section('page_title', 'پیوند آثار مرتبط: مدخلی در فهرست، اثری در سایت')

@section('content')
<div class="space-y-5 max-w-5xl text-[13px]">
    <p class="text-muted leading-7 max-w-3xl">
        فهرست در «وابسته به» یا «شروح و حواشی» هر اثر، آثار دیگری را نام برده است. این مدخل‌ها با عنوان و پدیدآور به اثری از سایت نرسیدند و مدل زبانی برای هر کدام یک اثر پیشنهاد کرده است، بی اطمینان کامل. «پیوند بده» مدخل را در صفحۀ اثر به آن اثر لینک می‌کند و اگر دو اثر هنوز رابطه‌ای ندارند، رابطه را هم می‌سازد. «اثر دیگری است» مدخل را همان‌طور که هست نگه می‌دارد.
    </p>

    <x-admin.tabs :options="[
        ['label' => 'در انتظار', 'count' => $counts[WorkEntryLink::PROPOSED] ?? 0, 'active' => ! $done, 'url' => route('admin.work-entry-links.index')],
        ['label' => 'تصمیم‌گرفته', 'count' => ($counts[WorkEntryLink::LINKED] ?? 0) + ($counts[WorkEntryLink::REJECTED] ?? 0), 'active' => $done, 'url' => route('admin.work-entry-links.index', ['view' => 'done'])],
    ]" />

    @if($errors->any())
        <x-admin.notice tone="error">{{ $errors->first() }}</x-admin.notice>
    @endif

    @forelse($links as $link)
        @php $host = $link->work; $found = $link->linkedWork; @endphp
        <article id="entry-link-{{ $link->id }}" class="bg-surface border border-line p-4 sm:p-5 space-y-3 scroll-mt-20">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="min-w-0 space-y-1">
                    <div class="text-[11px] text-muted">اثر میزبان</div>
                    @if($host)
                        <a href="{{ route('works.show', $host) }}" target="_blank" class="font-bold text-ink hover:text-cinnabar">{{ $host->clean_title }}</a>
                        <span class="text-muted tabular-nums">#{{ Fa::digits((string) $host->id) }}</span>
                        <div>{{ $host->author_name ?: 'بی نام پدیدآور' }}</div>
                    @else
                        <div class="text-muted">اثر دیگر وجود ندارد.</div>
                    @endif
                    <div class="pt-2 text-[11px] text-muted">مدخل در «{{ $link->side === 'related' ? 'وابسته به' : 'شروح و حواشی' }}»</div>
                    <div class="px-3 py-2 bg-paper border border-s-4 border-gold leading-7">{{ Fa::digits($link->text) }}</div>
                    <div class="text-muted">{{ WorkEntryLinkController::SIDES[$link->side] ?? $link->side }}</div>
                </div>
                <div class="min-w-0 space-y-1">
                    <div class="text-[11px] text-muted">اثر پیشنهادی</div>
                    @if($found)
                        <a href="{{ route('works.show', $found) }}" target="_blank" class="font-bold text-ink hover:text-cinnabar">{{ $found->clean_title }}</a>
                        <span class="text-muted tabular-nums">#{{ Fa::digits((string) $found->id) }}</span>
                        <div>{{ $found->author_name ?: 'بی نام پدیدآور' }}</div>
                        @php $alts = collect($found->alternative_titles ?? [])->map(fn ($a) => is_array($a) ? ($a['title'] ?? '') : $a)->filter()->take(4); @endphp
                        @if($alts->isNotEmpty())
                            <div class="text-muted">عنوان‌های دیگر: {{ $alts->implode(' = ') }}</div>
                        @endif
                        <div class="text-muted">
                            {{ implode(' · ', array_filter([
                                $found->language_summary,
                                $found->subject_summary,
                                $found->manuscripts_count ? Fa::num($found->manuscripts_count) . ' نسخه' : null,
                            ])) }}
                        </div>
                        @if($base = $madeOn[$found->id] ?? null)
                            <div class="text-muted leading-6">وابسته به: {{ Fa::digits(\Illuminate\Support\Str::limit($base, 220)) }}</div>
                        @endif
                    @else
                        <div class="text-muted">اثر دیگر وجود ندارد.</div>
                    @endif
                </div>
            </div>

            @if($link->why)
                <div class="text-xs text-muted leading-6">دلیل مدل: <span dir="ltr" class="inline-block">{{ $link->why }}</span></div>
            @endif

            @if($link->link_status === WorkEntryLink::PROPOSED)
                <div class="flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('admin.work-entry-links.link', $link) }}">@csrf
                        <button class="px-3 py-1.5 bg-ink text-paper text-xs font-bold">پیوند بده</button>
                    </form>
                    <form method="POST" action="{{ route('admin.work-entry-links.reject', $link) }}">@csrf
                        <button class="px-3 py-1.5 border border-line text-xs">اثر دیگری است</button>
                    </form>
                </div>
            @else
                <div class="flex flex-wrap items-center gap-3 text-xs text-muted">
                    <span>{{ WorkEntryLink::STATUSES[$link->link_status] ?? $link->link_status }} @if($link->decider) · {{ $link->decider->name }} @endif</span>
                    <form method="POST" action="{{ route('admin.work-entry-links.reopen', $link) }}">@csrf
                        <button class="px-2 py-1 border border-line">بازگشایی</button>
                    </form>
                </div>
            @endif
        </article>
    @empty
        <p class="text-muted">موردی نیست.</p>
    @endforelse

    {{ $links->links('admin.partials.pagination') }}
</div>
@endsection

@extends('layouts.admin')
@use('App\Support\Fa')
@use('App\Models\Claim')
@use('App\Http\Controllers\Admin\ClaimController')

@php
    $url = fn (array $change) => route('admin.claims.index', array_filter(array_merge([
        'status' => $status === Claim::STATUS_CANDIDATE ? null : $status, 'field' => $field, 'kind' => $kind, 'source' => $sourceId,
    ], $change)));
    $statusLabels = [Claim::STATUS_CANDIDATE => 'در انتظار داوری', Claim::STATUS_ACCEPTED => 'پذیرفته', Claim::STATUS_REJECTED => 'ردشده'];
@endphp

@section('title', 'ادعاهای فهرست‌های چاپی')
@section('page_title', 'ادعاهای فهرست‌های چاپی: جایی که فهرست خود کتابخانه با رکورد نمی‌خواند')

@section('content')
<div class="space-y-5 max-w-5xl text-[13px]">
    <p class="text-muted leading-7 max-w-3xl">
        سطر هر نسخه در فهرست چاپی کتابخانه با رکورد سنجیده شده است. رکوردها از فنخا ساخته شده‌اند. هر مورد این‌جا یعنی فهرست چیز دیگری می‌گوید، یا فیلدی را پر می‌کند که رکورد ندارد.
        برگۀ چاپی را ببینید. اگر فهرست درست است، «فهرست درست است» مورد را به صفحۀ پیشنهاد می‌برد تا مانند هر اصلاحی تأیید شود. اگر رکورد درست است، «فنخا درست است» را بزنید.
    </p>

    <div class="flex flex-wrap items-start gap-x-6">
        <x-admin.tabs :options="collect($statusLabels)->map(fn ($label, $key) => [
            'label' => $label, 'count' => (int) ($statusCounts[$key] ?? 0), 'active' => $status === $key,
            'url' => $url(['status' => $key === Claim::STATUS_CANDIDATE ? null : $key, 'field' => null, 'kind' => null]),
        ])->values()->all()" />
        <x-admin.tabs :options="[
            ['label' => 'هر دو', 'active' => $kind === null, 'url' => $url(['kind' => null])],
            ['label' => 'اختلاف', 'count' => $kindCounts['differs'], 'active' => $kind === 'differs', 'url' => $url(['kind' => 'differs'])],
            ['label' => 'پرکردن', 'count' => $kindCounts['fills'], 'active' => $kind === 'fills', 'url' => $url(['kind' => 'fills'])],
        ]" />
    </div>
    @php
        $fieldTabs = [['label' => 'همۀ فیلدها', 'count' => $fieldCounts->sum(), 'active' => $field === null, 'url' => $url(['field' => null])]];
        foreach (ClaimController::FIELD_LABELS as $key => $label) {
            if ($fieldCounts->get($key)) {
                $fieldTabs[] = ['label' => $label, 'count' => $fieldCounts[$key], 'active' => $field === $key, 'url' => $url(['field' => $key])];
            }
        }
        $sourceTabs = [['label' => 'همۀ فهرست‌ها', 'active' => $sourceId === null, 'url' => $url(['source' => null])]];
        foreach ($sources as $source) {
            $sourceTabs[] = ['label' => $source->short_name ?: $source->title, 'active' => $sourceId === $source->id, 'url' => $url(['source' => $source->id])];
        }
    @endphp
    <x-admin.tabs :options="$fieldTabs" />
    @if($sources->count() > 1)<x-admin.tabs :options="$sourceTabs" />@endif

    @forelse($claims as $claim)
        @php
            $ms = $claim->subject;
            $entry = $claim->sourceEntry;
            $differs = ClaimController::kindOf($claim) === 'differs';
            $viewer = $entry?->viewer();
            $image = $viewer ? asset('storage/catalogs/' . $viewer['catalog'] . '/v' . str_pad($viewer['volume'], 2, '0', STR_PAD_LEFT) . '/p' . str_pad($viewer['page'], 4, '0', STR_PAD_LEFT) . '.webp') : null;
        @endphp
        <article id="claim-{{ $claim->id }}" class="bg-surface border border-line p-4 sm:p-5 space-y-3 scroll-mt-20" data-claim="{{ $claim->id }}">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <div class="min-w-0">
                    @if($ms)
                        <a href="{{ route('manuscripts.permalink', $ms->id) }}" target="_blank" class="font-bold text-ink hover:text-cinnabar">{{ $ms->work?->primary_title ?? 'نسخۀ #' . $ms->id }}</a>
                        <span class="text-muted">· {{ $ms->libraryRecord?->name ?? $ms->library }} · <span dir="ltr">{{ Fa::digits((string) $ms->shelfmark) }}</span></span>
                    @else
                        <span class="text-muted">رکورد دیگر وجود ندارد.</span>
                    @endif
                </div>
                <span class="text-xs font-bold {{ $differs ? 'text-cinnabar' : 'text-emerald-700 dark:text-emerald-300' }}">
                    {{ ClaimController::FIELD_LABELS[$claim->field] ?? $claim->field }} · {{ $differs ? 'اختلاف' : 'پرکردن' }}
                </span>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <div class="min-w-0 px-3 py-2 bg-paper border border-s-4 border-gold">
                    <div class="text-[11px] text-muted">فهرست چاپی کتابخانه</div>
                    <div class="font-bold text-ink text-sm" data-claim-value>{{ Fa::digits((string) $claim->value_text) }}</div>
                    @if($claim->raw_text && $claim->raw_text !== $claim->value_text)
                        <div class="text-xs text-muted mt-0.5">متن فهرست: «{{ Fa::digits(\Illuminate\Support\Str::limit($claim->raw_text, 140)) }}»</div>
                    @endif
                </div>
                <div class="min-w-0 px-3 py-2 bg-paper border border-s-4 border-line-strong">
                    <div class="text-[11px] text-muted">رکورد (از فنخا)</div>
                    <div class="font-bold text-ink text-sm" data-claim-current>{{ filled($claim->current_text) ? Fa::digits((string) $claim->current_text) : '—' }}</div>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs">
                @if($entry)<span class="text-muted">{{ $entry->cardLabel($ms) }}</span>@endif
                @if($image)<a href="{{ $image }}" target="_blank" class="font-semibold text-cinnabar hover:underline">برگۀ چاپی ↗</a>@endif
                @if($entry && ($pageText = $entry->pageTextUrl()))<a href="{{ $pageText }}" target="_blank" class="font-semibold text-cinnabar hover:underline">متن صفحه ↗</a>@endif
                @if($claim->suggestion_id)<a href="{{ route('admin.suggestions.show', $claim->suggestion_id) }}" class="font-semibold text-ink hover:text-cinnabar">پیشنهاد #{{ Fa::digits((string) $claim->suggestion_id) }}</a>@endif
                @if($claim->reviewed_at)<span class="text-muted">{{ $statusLabels[$claim->status] }} · {{ Fa::digits(\App\Support\Jalali::format($claim->reviewed_at)) }}</span>@endif
            </div>

            <div class="flex flex-wrap items-center gap-2">
                @if($claim->status === Claim::STATUS_CANDIDATE)
                    <form method="POST" action="{{ route('admin.claims.suggest', $claim) }}">@csrf
                        <button class="px-3 py-1.5 bg-cinnabar text-white text-xs font-bold hover:opacity-90 cursor-pointer">فهرست درست است</button>
                    </form>
                    <form method="POST" action="{{ route('admin.claims.reject', $claim) }}">@csrf
                        <button class="px-3 py-1.5 border border-line text-xs text-ink hover:border-cinnabar hover:text-cinnabar cursor-pointer">فنخا درست است</button>
                    </form>
                @elseif($claim->status === Claim::STATUS_REJECTED)
                    <form method="POST" action="{{ route('admin.claims.reopen', $claim) }}">@csrf
                        <button class="px-3 py-1.5 border border-line text-xs text-ink cursor-pointer">بازگرداندن به فهرست</button>
                    </form>
                @endif
            </div>
        </article>
    @empty
        <div class="bg-surface border border-line p-6 text-center text-muted">موردی نیست.</div>
    @endforelse

    {{ $claims->links() }}
</div>
@endsection

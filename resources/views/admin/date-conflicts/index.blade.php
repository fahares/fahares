@extends('layouts.admin')
@use('App\Support\Fa')
@use('App\Models\DateConflict')

@php
    $permalinks = ['person' => 'people.permalink', 'work' => 'works.permalink', 'manuscript' => 'manuscripts.permalink'];
    $typeLabels = ['person' => 'شخص', 'work' => 'اثر', 'manuscript' => 'نسخه'];
    $count = fn (bool $isDirect, bool $isDismissed) => $counts->filter(fn ($c) => (bool) $c->direct === $isDirect && (bool) $c->dismissed === $isDismissed)->sum('n');
    $url = fn (array $change) => route('admin.date-conflicts.index', array_filter(array_merge([
        'kind' => $direct ? null : 'indirect', 'view' => $dismissed ? 'dismissed' : null, 'rule' => $rule, 'sort' => $smallFirst ? 'small' : null,
    ], $change)));
@endphp

@section('title', 'تناقض‌های تاریخی')
@section('page_title', 'تناقض‌های تاریخی: دو تاریخ که با هم نمی‌خوانند')

@section('content')
<div class="space-y-5 max-w-5xl text-[13px]">
    <p class="text-muted leading-7 max-w-3xl">
        @if($direct)
            در هر مورد دو تاریخ مستند با هم ناسازگارند، پس دست‌کم یکی نادرست است: تاریخ، انتساب اثر یا نسخه به شخص، یا ادغام دو شخص هم‌نام. رکورد را باز کنید و اصلاح کنید؛ مورد در اجرای شبانه‌ی بعد از فهرست می‌رود. اگر هر دو تاریخ درست‌اند، «خطا نیست» را بزنید.
        @else
            این موارد از چند قاعده‌ی پیاپی به دست آمده‌اند: دو رکورد برای تاریخ سومی سالی باقی نمی‌گذارند. بیشترشان از کاتبان هم‌نامِ یکی‌شده می‌آیند و از موارد مستقیم کم‌اطمینان‌ترند.
        @endif
        @if($lastRun) <span class="whitespace-nowrap">آخرین اجرا: {{ Fa::digits(\App\Support\Jalali::format($lastRun)) }}</span> @endif
    </p>

    <div class="flex flex-wrap items-start gap-x-6">
        <x-admin.tabs :options="[
            ['label' => 'مستقیم', 'count' => $count(true, $dismissed), 'active' => $direct, 'url' => $url(['kind' => null, 'rule' => null])],
            ['label' => 'غیرمستقیم', 'count' => $count(false, $dismissed), 'active' => ! $direct, 'url' => $url(['kind' => 'indirect', 'rule' => null])],
        ]" />
        <x-admin.tabs :options="[
            ['label' => 'باز', 'count' => $count($direct, false), 'active' => ! $dismissed, 'url' => $url(['view' => null, 'rule' => null])],
            ['label' => 'خطا نیست', 'count' => $count($direct, true), 'active' => $dismissed, 'url' => $url(['view' => 'dismissed', 'rule' => null])],
        ]" />
        <x-admin.tabs :options="[
            ['label' => 'بیشترین فاصله', 'active' => ! $smallFirst, 'url' => $url(['sort' => null])],
            ['label' => 'کمترین فاصله', 'active' => $smallFirst, 'url' => $url(['sort' => 'small'])],
        ]" />
    </div>

    @php
        $ruleTabs = [['label' => 'همه‌ی قاعده‌ها', 'count' => $ruleCounts->sum(), 'active' => $rule === null, 'url' => $url(['rule' => null])]];
        foreach (DateConflict::RULE_LABELS as $key => $label) {
            if ($ruleCounts->get($key)) {
                $ruleTabs[] = ['label' => $label, 'count' => $ruleCounts[$key], 'active' => $rule === $key, 'url' => $url(['rule' => $key])];
            }
        }
    @endphp
    <x-admin.tabs :options="$ruleTabs" />

    @forelse($conflicts as $conflict)
        @php
            $sides = [
                ['type' => $conflict->a_type, 'id' => $conflict->a_id, 'field' => $conflict->a_field],
                ['type' => $conflict->b_type, 'id' => $conflict->b_id, 'field' => $conflict->b_field],
            ];
        @endphp
        <article id="date-conflict-{{ $conflict->id }}" class="bg-surface border border-line p-4 sm:p-5 space-y-3 scroll-mt-20">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h3 class="font-bold text-ink">{{ $conflict->label }}</h3>
                <span class="text-xs text-muted tabular-nums">فاصله: <b class="text-cinnabar">{{ Fa::num($conflict->gap) }}</b> سال</span>
            </div>

            @unless($conflict->direct)
                @php $via = $records[$conflict->via_type . ':' . $conflict->via_id] ?? null; @endphp
                <div class="text-xs px-3 py-2 leading-6 bg-paper border border-s-4 border-gold">
                    {{ DateConflict::FIELD_LABELS[$conflict->via_field] ?? $conflict->via_field }}ِ
                    <a href="{{ route($permalinks[$conflict->via_type], $conflict->via_id) }}" target="_blank" class="font-bold text-ink hover:text-cinnabar">{{ $via['title'] ?? $typeLabels[$conflict->via_type] . ' #' . Fa::digits((string) $conflict->via_id) }}</a>
                    از رکورد نخست باید پس از <b class="tabular-nums">{{ Fa::digits((string) $conflict->a_from) }}</b> باشد و از رکورد دوم پیش از <b class="tabular-nums">{{ Fa::digits((string) $conflict->b_to) }}</b>.
                </div>
            @endunless

            <div class="grid gap-4 sm:grid-cols-2">
                @foreach($sides as $side)
                    @php $record = $records[$side['type'] . ':' . $side['id']] ?? null; @endphp
                    <div class="min-w-0 space-y-1">
                        <div class="text-[11px] text-muted">{{ $typeLabels[$side['type']] }} <span class="tabular-nums">#{{ Fa::digits((string) $side['id']) }}</span></div>
                        @if($record)
                            <a href="{{ route($permalinks[$side['type']], $side['id']) }}" target="_blank" class="font-bold text-ink hover:text-cinnabar">{{ $record['title'] }}</a>
                            @if($record['detail'])<div class="text-muted">{{ Fa::digits($record['detail']) }}</div>@endif
                            <div>
                                {{ DateConflict::FIELD_LABELS[$side['field']] }}:
                                <b class="tabular-nums">{{ ($record['dates'][$side['field']] ?? null) !== null ? Fa::digits($record['dates'][$side['field']]) . ' ق' : '—' }}</b>
                                @if(! empty($record['raw']))<span class="text-muted">«{{ Fa::digits(\Illuminate\Support\Str::limit($record['raw'], 80)) }}»</span>@endif
                            </div>
                        @else
                            <div class="text-muted">رکورد دیگر وجود ندارد.</div>
                        @endif
                    </div>
                @endforeach
            </div>

            @if($conflict->suggestions->isNotEmpty())
                <div class="text-xs px-3 py-2 leading-6 bg-paper border border-s-4 border-gold space-y-1">
                    <b>پاسخ خوانندگان:</b>
                    @foreach($conflict->suggestions as $suggestion)
                        <div>
                            <a href="{{ route('admin.suggestions.show', $suggestion) }}" class="font-bold text-ink hover:text-cinnabar">{{ \Illuminate\Support\Str::limit($suggestion->suggested_value, 90) }}</a>
                            <span class="text-muted">· {{ $suggestion->guest_name }} · {{ ['pending' => 'در انتظار داوری', 'approved' => 'تأییدشده', 'rejected' => 'ردشده'][$suggestion->status] ?? $suggestion->status }}</span>
                        </div>
                    @endforeach
                </div>
            @endif

            @if($conflict->dismissed_at)
                <div class="flex flex-wrap items-center gap-3 text-xs text-muted">
                    <span>خطا نیست · {{ $conflict->dismisser?->name }} · {{ Fa::digits(\App\Support\Jalali::format($conflict->dismissed_at)) }}@if($conflict->dismiss_note) · «{{ $conflict->dismiss_note }}»@endif</span>
                    <form method="POST" action="{{ route('admin.date-conflicts.reopen', $conflict) }}">@csrf
                        <button class="px-3 py-1.5 border border-line text-xs text-ink">بازگرداندن به فهرست</button>
                    </form>
                </div>
            @else
                <form method="POST" action="{{ route('admin.date-conflicts.dismiss', $conflict) }}" class="flex flex-wrap items-center gap-2">@csrf
                    <input type="text" name="note" maxlength="500" placeholder="چرا خطا نیست؟ (اختیاری)" class="flex-1 min-w-48 px-2 py-1.5 border border-line bg-paper text-xs">
                    <button class="px-3 py-1.5 border border-line text-xs">خطا نیست</button>
                </form>
            @endif
        </article>
    @empty
        <p class="text-muted">موردی نیست.</p>
    @endforelse

    {{ $conflicts->links('admin.partials.pagination') }}
</div>
@endsection

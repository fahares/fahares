@extends('layouts.admin')

@section('title', 'پیشنهادهای اصلاح')
@section('page_title', 'پیشنهادهای اصلاح')

@section('content')
@php
    $fa = fn ($v) => \App\Support\Fa::digits($v);
    $kind = request('kind');
    $kindLabels = ['field' => 'اصلاح فیلد و کلی', 'relation' => 'پیشنهاد ساختاری', 'note' => 'یادداشت پژوهشی', 'feedback' => 'گزارش اشکال سایت', 'collective' => config('contributions.review_title')];
    $statusTabs = [];
    foreach (['pending' => 'در انتظار', 'awaiting' => 'منتظر پاسخ پیشنهاددهنده', 'approved' => 'تأییدشده', 'rejected' => 'ردشده', 'all' => 'همه'] as $key => $label) {
        $statusTabs[] = ['label' => $label, 'url' => route('admin.suggestions.index', array_filter(['status' => $key, 'type' => $type, 'kind' => $kind])),
            'active' => $status === $key, 'count' => $stats[$key === 'all' ? 'total' : $key]];
    }
    $typeTabs = [];
    foreach (['' => 'همه‌ی رکوردها', 'Manuscript' => 'نسخه‌ها', 'Work' => 'آثار', 'Person' => 'اشخاص', 'feedback' => 'بازخورد سایت'] as $key => $label) {
        $typeTabs[] = ['label' => $label, 'url' => route('admin.suggestions.index', array_filter(['status' => $status, 'type' => $key, 'kind' => $kind])), 'active' => (string) $type === $key];
    }
    $statusChip = function ($sug) {
        return match (true) {
            $sug->status === 'pending' && $sug->awaiting_reply_since => ['منتظر پاسخ', 'default'],
            $sug->status === 'pending' && $sug->reply_state === 'answered' => ['پاسخ داد', 'good'],
            $sug->status === 'pending' && $sug->reply_state === 'unanswered' => ['بی‌پاسخ ماند', 'accent'],
            $sug->status === 'pending' => ['در انتظار', 'accent'],
            $sug->status === 'approved' => [$sug->kind === 'note' ? 'منتشر شد' : (! empty($sug->payload['direct']) ? 'اقدام مستقیم' : ($sug->standing_label ?? 'تأیید شد')), 'good'],
            $sug->status === 'converted' => ['تبدیل شد', 'default'],
            $sug->status === 'withdrawn' => ['برداشته شد', 'default'],
            $sug->status === 'undone' => ['اقدام مستقیم، بازگردانده شد', 'default'],
            default => ['رد شد', 'default'],
        };
    };
@endphp

<x-admin.tabs :options="$statusTabs" />
<div class="flex flex-wrap items-center gap-2 mb-4">
    <x-admin.tabs :options="$typeTabs" class="!mb-0" />
    {{-- The kind chosen in the dashboard's queue --}}
    @if(isset($kindLabels[$kind]))
        <x-ui.chip tone="accent" :href="route('admin.suggestions.index', array_filter(['status' => $status, 'type' => $type]))" title="برداشتن این فیلتر">فقط {{ $kindLabels[$kind] }} ✕</x-ui.chip>
    @endif
    <span class="ms-auto text-xs text-muted"><b class="text-ink tabular-nums">{{ \App\Support\Fa::num($suggestions->total()) }}</b> پیشنهاد</span>
</div>

<x-admin.table :paginator="$suggestions" :is-empty="$suggestions->isEmpty()" empty="پیشنهادی با این فیلترها یافت نشد.">
    <x-slot:head>
        <x-admin.th>رکورد</x-admin.th>
        <x-admin.th>فیلد یا نوع</x-admin.th>
        <x-admin.th>مقدار پیشنهادی</x-admin.th>
        <x-admin.th>پیشنهاددهنده</x-admin.th>
        <x-admin.th>وضعیت</x-admin.th>
        <x-admin.th><span class="sr-only">کنش‌ها</span></x-admin.th>
    </x-slot:head>
    @foreach($suggestions as $sug)
        @php [$statusLabel, $statusTone] = $statusChip($sug); @endphp
        <x-admin.row>
            <x-admin.cell main>
                <a href="{{ route('admin.suggestions.show', $sug) }}" class="font-bold text-ink hover:text-cinnabar">{{ $fa(\Illuminate\Support\Str::limit($sug->place_label, 60)) }}</a>
                <span class="block text-xs text-muted">
                    <span class="tabular-nums">#{{ $fa($sug->id) }}</span> · @jago($sug->created_at)
                    @if($sug->place_url)
                        · <a href="{{ $sug->place_url }}" target="_blank" class="hover:text-cinnabar">صفحه در سایت ↗</a>
                    @endif
                </span>
            </x-admin.cell>
            <x-admin.cell label="فیلد">
                <x-ui.chip :tone="$sug->kind === 'relation' ? 'accent' : 'default'" title="{{ $sug->field_name }}">{{ $sug->field_label }}</x-ui.chip>
            </x-admin.cell>
            <x-admin.cell label="مقدار" class="md:max-w-xs">
                @if($sug->review_queue && ($queue = \App\Review\CollectiveReview::queue($sug->review_queue)))
                    {{-- An answer of «تصحیح جمعی»: what it says, and how the opinions on its item stand (backlog item 80) --}}
                    <span class="text-ink">{{ $queue->describe($sug) }}</span>
                    @if($item = $opinions[$sug->review_queue . ':' . $sug->review_item_id] ?? null)
                        <span class="block mt-1 text-[11px] text-muted">{{ config('contributions.review_title') }}:
                            @foreach($item['answers'] as $a){{ $loop->first ? '' : '؛ ' }}{{ \App\Support\Fa::num($a['count']) }} «{{ \Illuminate\Support\Str::limit($a['label'], 30) }}» (وزن {{ \App\Support\Fa::num($a['weight']) }})@endforeach
                        </span>
                    @endif
                @else
                    <span class="text-ink" title="{{ $sug->suggested_value }}">{{ \Illuminate\Support\Str::limit($sug->suggested_value, 70) ?: '—' }}</span>
                @endif
            </x-admin.cell>
            <x-admin.cell label="از">
                <span class="inline-flex items-center gap-1.5 align-middle">
                    <x-ui.avatar :user="$sug->user" :name="$sug->guest_name" size="sm" />
                    <span>{{ $sug->user?->name ?: ($sug->guest_name ?: 'مهمان') }}</span>
                </span>
            </x-admin.cell>
            <x-admin.cell label="وضعیت" class="whitespace-nowrap"><x-ui.chip :tone="$statusTone">{{ $statusLabel }}</x-ui.chip></x-admin.cell>
            <x-admin.cell actions>
                <x-ui.btn :href="route('admin.suggestions.show', $sug)" size="sm" :variant="$sug->status === 'pending' ? 'primary' : 'default'">{{ $sug->status === 'pending' ? 'داوری' : 'مشاهده' }}</x-ui.btn>
            </x-admin.cell>
        </x-admin.row>
    @endforeach
</x-admin.table>
@endsection

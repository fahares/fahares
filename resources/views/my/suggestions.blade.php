@extends('layouts.app')

@section('title', 'پیشنهادهای من | فهارس')
@section('meta_description', 'پیشنهادهای اصلاحی شما در فهارس و وضعیت داوری آن‌ها')

@section('content')
@php
    $formatter = class_exists(\IntlDateFormatter::class)
        ? new \IntlDateFormatter('fa_IR@calendar=persian', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'Asia/Tehran', \IntlDateFormatter::TRADITIONAL, 'd MMMM y')
        : null;
    $date = fn ($d) => $d ? ($formatter ? $formatter->format($d) : $d->format('Y/m/d')) : '';
    $pill = 'px-2.5 py-0.5 rounded-full text-[11px] font-bold border';
    $pills = [
        'pending' => ['در انتظار داوری', 'bg-amber-50 dark:bg-amber-950/30 text-amber-800 dark:text-amber-300 border-amber-200 dark:border-amber-900/60'],
        'rejected' => ['رد شد', 'bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 border-red-200 dark:border-red-900/60'],
        'adopted' => ['قول مختار', 'bg-emerald-50 dark:bg-emerald-950/30 text-emerald-800 dark:text-emerald-300 border-emerald-200 dark:border-emerald-900/60'],
        'alternative' => ['قول دیگر', 'bg-sky-50 dark:bg-sky-950/30 text-sky-800 dark:text-sky-300 border-sky-200 dark:border-sky-900/60'],
        'superseded' => ['منسوخ', 'bg-stone-100 dark:bg-stone-800 text-stone-600 dark:text-stone-300 border-stone-200 dark:border-stone-700'],
        'edit' => ['ویرایش اعمال شد', 'bg-stone-100 dark:bg-stone-800 text-stone-600 dark:text-stone-300 border-stone-200 dark:border-stone-700'],
        'published' => ['منتشر شد', 'bg-emerald-50 dark:bg-emerald-950/30 text-emerald-800 dark:text-emerald-300 border-emerald-200 dark:border-emerald-900/60'],
        'converted' => ['تبدیل شد', 'bg-sky-50 dark:bg-sky-950/30 text-sky-800 dark:text-sky-300 border-sky-200 dark:border-sky-900/60'],
        'withdrawn' => ['برداشته شد', 'bg-stone-100 dark:bg-stone-800 text-stone-600 dark:text-stone-300 border-stone-200 dark:border-stone-700'],
        'approved' => ['رسیدگی شد', 'bg-emerald-50 dark:bg-emerald-950/30 text-emerald-800 dark:text-emerald-300 border-emerald-200 dark:border-emerald-900/60'],
    ];
@endphp
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

    <div class="bg-white dark:bg-[#15192C] p-6 sm:p-8 rounded-3xl border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-5">
        <div>
            <h1 class="text-lg sm:text-xl font-bold text-stone-900 dark:text-stone-100">پیشنهادهای من</h1>
            <p class="mt-1 text-xs text-stone-500 dark:text-stone-400">پیشنهادهایی که با این حساب فرستاده‌اید، با وضعیت داوری هر کدام.</p>
        </div>
        <dl class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-center">
            @foreach([['total', 'همه‌ی پیشنهادها'], ['approved', 'تأییدشده'], ['adopted', 'قول مختار'], ['pending', 'در انتظار داوری']] as [$key, $label])
                <div class="p-3 rounded-2xl bg-stone-50 dark:bg-stone-800/50 border border-stone-100 dark:border-stone-800">
                    <dd class="text-xl font-bold text-[#B38A50]">{{ number_format($stats[$key]) }}</dd>
                    <dt class="text-[11px] text-stone-500 dark:text-stone-400">{{ $label }}</dt>
                </div>
            @endforeach
        </dl>
    </div>


    @forelse($suggestions as $s)
        @php
            $record = $s->suggestable ? \App\Suggestions\SuggestionRegistry::describe($s->suggestable) : null;
            $pillKey = match (true) {
                $s->kind === 'note' && $s->status === 'approved' => 'published',
                $s->status === 'approved' => $s->standing ?? 'approved',
                default => $s->status,
            };
            [$statusLabel, $statusClass] = $pills[$pillKey] ?? $pills['pending'];
        @endphp
        <article class="bg-white dark:bg-[#15192C] p-5 sm:p-6 rounded-3xl border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-3 text-xs sm:text-sm">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="space-y-0.5 min-w-0">
                    @if($record)
                        <a href="{{ $record['url'] }}" class="font-bold text-[#292C56] dark:text-amber-200 hover:text-[#B38A50] break-words">{{ $record['label'] }}</a>
                    @else
                        <span class="font-bold text-stone-500">رکورد دیگر وجود ندارد (شاید ادغام شده است)</span>
                    @endif
                    <div class="text-[11px] text-stone-500 dark:text-stone-400">#{{ $s->id }} · {{ $s->field_label }} · فرستاده در {{ $date($s->created_at) }}@if($s->reviewed_at) · داوری در {{ $date($s->reviewed_at) }}@endif</div>
                </div>
                <span class="{{ $pill }} {{ $statusClass }} shrink-0">{{ $statusLabel }}</span>
            </div>

            <div class="p-3 rounded-xl bg-stone-50 dark:bg-stone-800/50 text-stone-700 dark:text-stone-200 whitespace-pre-line break-words">{{ \Illuminate\Support\Str::limit($s->suggested_value, 600) }}</div>

            @if($s->applied_value !== null && trim($s->applied_value) !== trim($s->suggested_value))
                <p class="text-stone-600 dark:text-stone-300"><span class="font-bold text-stone-500 dark:text-stone-400">مقدار ثبت‌شده:</span> {{ $s->display_value }}</p>
            @endif
            @if($s->standing === 'superseded' && $s->supersededBy)
                <p class="text-stone-600 dark:text-stone-300">این قول جای خود را به قول «{{ \Illuminate\Support\Str::limit($s->supersededBy->display_value, 120) }}» داد.</p>
            @elseif($s->standing === 'alternative' && $s->superseded_by)
                <p class="text-stone-600 dark:text-stone-300">این قول پیش‌تر مختار بود و اکنون در کنار قول مختار تازه به‌عنوان «قول دیگر» نمایش داده می‌شود.</p>
            @endif
            @if($s->status === 'converted')
                <p class="text-stone-600 dark:text-stone-300">داور این پیشنهاد را به شکل استاندارد درآورد{{ count($s->resolution['converted_to'] ?? []) > 1 ? ' و به چند پیشنهاد تفکیک کرد' : '' }}: {{ collect($s->resolution['converted_to'] ?? [])->map(fn ($id) => '#' . $id)->join('، ') }}. وضعیت {{ count($s->resolution['converted_to'] ?? []) > 1 ? 'آن‌ها' : 'آن' }} را در همین فهرست می‌بینید.</p>
            @endif
            @if(! empty($s->payload['derived_from']))
                <p class="text-[11px] text-stone-500">ساخته‌شده از پیشنهاد #{{ $s->payload['derived_from'] }} شما</p>
            @endif
            @if($s->public_note)
                <p class="text-stone-600 dark:text-stone-300 whitespace-pre-line"><span class="font-bold text-stone-500 dark:text-stone-400">یادداشت داور:</span> {{ $s->public_note }}</p>
            @endif

            @if(in_array($s->kind, ['field', 'note'], true) && $s->status !== 'rejected')
                <form action="{{ route('my.suggestions.credit', $s) }}" method="POST" class="flex flex-wrap items-center gap-2 pt-2 border-t border-stone-100 dark:border-stone-800 text-xs">
                    @csrf
                    <label class="text-stone-500 dark:text-stone-400" for="credit-{{ $s->id }}">نام در صفحه‌ی عمومی:</label>
                    <input id="credit-{{ $s->id }}" type="text" name="credit_name" value="{{ $s->credit_name }}" maxlength="150" placeholder="خالی: بدون نام"
                        class="flex-1 min-w-40 p-2 rounded-lg border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-800 dark:text-stone-100">
                    <button type="submit" class="px-3 py-2 rounded-lg bg-[#B38A50] hover:bg-[#9C753F] text-white font-bold">ذخیره</button>
                </form>
            @endif
        </article>
    @empty
        <div class="p-8 rounded-3xl bg-white dark:bg-[#15192C] border border-[#EADFCF] dark:border-[#272F4C] text-center text-sm text-stone-500 dark:text-stone-400">
            هنوز پیشنهادی با این حساب نفرستاده‌اید. در صفحه‌ی هر نسخه، اثر یا شخص، «پیشنهاد اصلاح» را بزنید.
        </div>
    @endforelse

    {{ $suggestions->links() }}
</div>
@endsection

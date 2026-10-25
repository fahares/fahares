@extends('layouts.admin')

@php
    $isMixed = $type === 'mixed';
    $reasonText = function (?string $r) {
        if (! $r) return '';
        if ($r === 'generic name') return 'نام عام است و به‌تنهایی کسی را مشخص نمی‌کند';
        if ($r === 'an author and a scribe with no dates to compare') return 'مؤلف و کاتبِ هم‌نام، بی‌تاریخ برای مقایسه';
        if (str_starts_with($r, 'different details in parentheses')) return 'توضیح داخل پرانتز در رکوردها فرق دارد';
        if (str_starts_with($r, 'record #')) return 'یکی از رکوردها خودش نسخه‌هایی با فاصله‌ی چند نسل دارد';
        if ($r === 'dates agree' || $r === 'same distinctive name, no dates') return 'نام متمایز است و تاریخ‌ها با هم می‌خوانند';
        if (preg_match('/^copies (\d+)–(\d+) \((\d+)\)$/', $r, $m)) return "تاریخ کتابتِ {$m[3]} نسخه از {$m[1]} تا {$m[2]} ق";
        return $r;
    };
    $count = fn ($status, $auto = null) => $counts->filter(fn ($c) => $c->type === $type && in_array($c->status, (array) $status, true) && ($auto === null || (bool) $c->auto === $auto))->sum('n');
    $viewCounts = [
        'open' => $count('open'),
        'deferred' => $count('deferred'),
        'auto' => $count('distinct', true),
        'done' => $count(['merged', 'distinct', 'split', 'dismissed'], false),
    ];
@endphp

@section('title', $isMixed ? 'رکوردهای قاطی‌شده' : 'اشخاص هم‌نام')
@section('page_title', $isMixed ? 'رکوردهای قاطی‌شده: یک رکورد، چند نفر' : 'اشخاص هم‌نام: چند رکورد، یک نفر')
@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-cinnabar">پیشخوان</a>
    <span>/</span>
    <span class="text-stone-400">بازبینی اشخاص</span>
@endsection

@section('content')
<div class="space-y-5 max-w-5xl">

    <p class="text-xs text-muted leading-6 max-w-3xl">
        @if($isMixed)
            این اشخاص نسخه‌هایی دارند که تاریخ کتابتشان بیش از {{ \App\Services\PersonReviewQueue::MIXED_SPAN }} سال پراکنده است؛ احتمالاً چند کاتبِ هم‌نام زیر یک رکورد آمده‌اند. نسخه‌ها به ترتیب تاریخ آمده‌اند. نسخه‌هایی را که مال نفر دیگرند علامت بزنید و نامش را بنویسید تا به رکورد تازه‌ای منتقل شوند. تفکیک از لاگ ممیزی قابل بازگردانی است.
        @else
            نام این رکوردها فقط در املا، فاصله، «ال»، «ابن/بن» یا ترتیب «نام خانوادگی، نام» فرق دارد، ولی قرینه‌ها برای ادغام خودکار کافی نبود. رکوردهایی را که یک نفرند علامت بزنید، رکورد اصلی را انتخاب کنید و «ادغام» را بزنید. نام چاپی کاتب و پدیدآور روی نسخه‌ها دست نمی‌خورد و ادغام از لاگ ممیزی قابل بازگردانی است.
        @endif
    </p>

    <div class="flex flex-wrap items-center gap-2 text-xs">
        @can('people.merge')
            <a href="{{ route('admin.person-reviews.index') }}" class="px-3 py-1.5 rounded-full border font-bold {{ ! $isMixed ? 'bg-ink text-paper border-ink' : 'border-line bg-surface' }}">اشخاص هم‌نام</a>
        @endcan
        @can('structure.split')
            <a href="{{ route('admin.person-reviews.index', ['type' => 'mixed']) }}" class="px-3 py-1.5 rounded-full border font-bold {{ $isMixed ? 'bg-ink text-paper border-ink' : 'border-line bg-surface' }}">رکوردهای قاطی‌شده</a>
        @endcan
        <span class="mx-2 text-line-strong">|</span>
        @foreach(\App\Http\Controllers\Admin\PersonReviewController::VIEWS as $key => $v)
            @continue($key === 'auto' && $isMixed)
            <a href="{{ route('admin.person-reviews.index', array_filter(['type' => $isMixed ? 'mixed' : null, 'view' => $key === 'open' ? null : $key])) }}"
               class="px-3 py-1.5 rounded-full border {{ $view === $key ? 'border-cinnabar text-cinnabar font-bold' : 'border-line bg-surface text-muted' }}">
                {{ $v['label'] }} ({{ number_format($viewCounts[$key]) }})
            </a>
        @endforeach
    </div>

    @if($errors->any())
        <div class="rounded-xl border border-rose-300 bg-rose-50 dark:bg-rose-950/30 text-rose-800 dark:text-rose-200 text-xs p-3">{{ $errors->first() }}</div>
    @endif

    @forelse($reviews as $review)
        @php
            $suggestion = (array) ($review->suggestion ?? []);
            $suggestedIds = $suggestion['merge_ids'] ?? [];
            $targetId = $suggestion['target_id'] ?? ($review->person_ids[0] ?? null);
            $members = collect($review->person_ids)->map(fn ($id) => $people[$id] ?? null)->filter();
        @endphp
        <article id="person-review-{{ $review->id }}" class="bg-surface rounded-2xl border border-line shadow-xs p-4 sm:p-5 space-y-3 scroll-mt-20">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <div class="flex flex-wrap items-baseline gap-2 text-xs">
                    <span class="text-muted tabular-nums">#{{ $review->id }}</span>
                    <span class="font-bold">{{ $reasonText($review->reason) }}</span>
                    @if($review->tier === 'likely')
                        <span class="px-2 py-0.5 rounded-full bg-emerald-500/15 text-emerald-800 dark:text-emerald-300">قرینه‌ها کافی است</span>
                    @endif
                </div>
                @unless($review->isOpen())
                    <span class="text-[11px] px-2 py-0.5 rounded-full bg-paper border border-line">
                        {{ \App\Models\PersonReview::STATUSES[$review->status] ?? $review->status }}
                        @if($review->decider) · {{ $review->decider->name }} @elseif($review->status === 'distinct') · داوری خودکار @endif
                        @if($review->decided_at) · {{ \App\Support\Jalali::format($review->decided_at) }} @endif
                    </span>
                @endunless
            </div>

            @if(! empty($suggestion['comment']) || ! empty($suggestion['decision']))
                <div class="text-xs rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-900 dark:text-amber-200 px-3 py-2 leading-6">
                    <b>داوری پیشین:</b>
                    {{ ['merge' => 'ادغام', 'skip' => 'جدا بمانند', 'unsure' => 'نامطمئن'][$suggestion['decision'] ?? ''] ?? '' }}
                    {{ $suggestion['comment'] ?? '' }}
                </div>
            @endif
            @if($review->note)
                <div class="text-xs text-muted">یادداشت: {{ $review->note }}</div>
            @endif
            @if($item = $opinions[$queue . ':' . $review->id] ?? null)
                {{-- What the researchers of «تصحیح جمعی» answered (backlog item 80); deciding here settles their answers --}}
                <div class="text-xs rounded-xl bg-paper border border-line px-3 py-2 leading-6" data-collective-opinions>
                    <b>{{ config('contributions.review_title') }}:</b>
                    @foreach($item['answers'] as $a){{ $loop->first ? '' : '؛ ' }}{{ \App\Support\Fa::num($a['count']) }} نفر «{{ $a['label'] }}» (وزن {{ \App\Support\Fa::num($a['weight']) }})@endforeach
                    @foreach($item['answers'] as $a)
                        @foreach($a['opinions'] as $o)
                            @if($o->rationale_citation)<span class="block text-muted">{{ $o->user?->name }}: {{ \Illuminate\Support\Str::limit($o->rationale_citation, 200) }}</span>@endif
                        @endforeach
                    @endforeach
                </div>
            @endif

            @if($isMixed)
                @php
                    $m = $members->first();
                    // how many researchers of «تصحیح جمعی» marked each copy as another scribe's
                    $markedBy = collect($item['answers'] ?? [])->where('answer', 'split')->flatMap(fn ($a) => $a['opinions'])
                        ->flatMap(fn ($o) => $o->payload['manuscript_ids'] ?? [])->countBy();
                @endphp
                @if($m)
                    <form method="POST" action="{{ route('admin.person-reviews.split', $review) }}" class="space-y-3">
                        @csrf
                        <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                            <a href="{{ route('people.show', $m['person']) }}" target="_blank" class="font-bold text-base text-cinnabar hover:underline">{{ $m['person']->name }}</a>
                            <span class="text-xs text-muted tabular-nums">#{{ $m['person']->id }} · {{ number_format($m['person']->manuscripts_count) }} نسخه · {{ number_format($m['person']->works_count) }} اثر
                                @if($m['person']->death_year_hijri) · د. {{ $m['person']->death_year_hijri }}ق @endif</span>
                        </div>
                        <div class="overflow-x-auto border border-line rounded-xl max-h-96 overflow-y-auto">
                            <table class="w-full text-xs text-right">
                                <thead class="bg-paper text-muted sticky top-0">
                                    <tr><th class="p-2 w-8"></th><th class="p-2">تاریخ</th><th class="p-2">اثر</th><th class="p-2">کتابخانه</th><th class="p-2">نام کاتب در فهرست</th></tr>
                                </thead>
                                <tbody class="divide-y divide-line">
                                    @foreach($m['copies'] as $copy)
                                        <tr class="hover:bg-paper">
                                            <td class="p-2"><input type="checkbox" name="manuscript_ids[]" value="{{ $copy->id }}" id="r{{ $review->id }}-m{{ $copy->id }}" class="accent-emerald-600" @checked(in_array($copy->id, old('manuscript_ids', [])))></td>
                                            <td class="p-2 tabular-nums whitespace-nowrap"><label for="r{{ $review->id }}-m{{ $copy->id }}">{{ $copy->year ? $copy->year . 'ق' : '—' }}</label></td>
                                            <td class="p-2"><a href="{{ route('manuscripts.permalink', $copy->id) }}" target="_blank" class="hover:text-cinnabar">{{ $copy->title }}</a>@if($markedBy[$copy->id] ?? 0) <span class="ms-1 px-1.5 rounded-full bg-amber-500/15 text-amber-800 dark:text-amber-300 text-[10px] whitespace-nowrap">کاتب دیگر به نظر {{ \App\Support\Fa::num($markedBy[$copy->id]) }} پژوهشگر</span>@endif</td>
                                            <td class="p-2 text-muted">{{ $copy->city }}، {{ $copy->library }} {{ $copy->shelfmark }}</td>
                                            <td class="p-2 text-muted">{{ $copy->scribe_name }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if($review->isOpen())
                            <div class="grid sm:grid-cols-[1fr_8rem] gap-2">
                                <input type="text" name="new_name" value="{{ old('new_name', $m['person']->name) }}" aria-label="نام شخص تازه" placeholder="نام شخص تازه"
                                       class="w-full px-3 py-2 rounded-xl border border-line-strong bg-surface text-xs focus:border-gold outline-none">
                                <input type="number" name="death_year_hijri" aria-label="سال وفات (اختیاری)" placeholder="سال وفات"
                                       class="w-full px-3 py-2 rounded-xl border border-line-strong bg-surface text-xs focus:border-gold outline-none">
                            </div>
                            <textarea name="note" rows="1" placeholder="یادداشت (اختیاری)" aria-label="یادداشت" class="w-full px-3 py-2 rounded-xl border border-line-strong bg-surface text-xs focus:border-gold outline-none"></textarea>
                            <div class="flex flex-wrap gap-2 text-xs">
                                <button type="submit" class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold">انتقال نسخه‌های علامت‌خورده به شخص تازه</button>
                                <button type="submit" formaction="{{ route('admin.person-reviews.status', $review) }}" name="status" value="dismissed" class="px-4 py-2 rounded-xl border border-line bg-surface font-bold">یک نفر است</button>
                                <button type="submit" formaction="{{ route('admin.person-reviews.status', $review) }}" name="status" value="deferred" class="px-4 py-2 rounded-xl border border-line bg-surface">بعداً</button>
                            </div>
                        @else
                            <button type="submit" formaction="{{ route('admin.person-reviews.status', $review) }}" name="status" value="open" class="px-4 py-2 rounded-xl border border-line bg-surface text-xs">بازگشایی</button>
                        @endif
                    </form>
                @endif
            @else
                <form method="POST" action="{{ route('admin.person-reviews.merge', $review) }}" class="space-y-3">
                    @csrf
                    <div class="grid gap-2">
                        @foreach($members as $m)
                            @php
                                $p = $m['person'];
                                $checked = in_array($p->id, $suggestedIds, true) || $review->tier === 'likely';
                            @endphp
                            <div class="grid grid-cols-[auto_1fr] gap-3 items-start rounded-xl border border-line p-3">
                                <input type="checkbox" name="merge_ids[]" value="{{ $p->id }}" id="r{{ $review->id }}-p{{ $p->id }}" class="mt-1 w-4 h-4 accent-emerald-600" @checked($checked) aria-label="همین شخص است: {{ $p->name }}">
                                <div class="min-w-0 space-y-1">
                                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                        <a href="{{ route('people.show', $p) }}" target="_blank" class="font-bold text-sm hover:text-cinnabar break-words">{{ $p->name }}</a>
                                        <label class="text-[11px] text-emerald-700 dark:text-emerald-400 inline-flex items-center gap-1">
                                            <input type="radio" name="target_id" value="{{ $p->id }}" @checked($targetId === $p->id) class="accent-emerald-600"> رکورد اصلی
                                        </label>
                                    </div>
                                    <div class="text-[11px] text-muted tabular-nums flex flex-wrap gap-x-3">
                                        <span>#{{ $p->id }}</span>
                                        @if($p->death_year_hijri)<span>د. {{ $p->death_year_hijri }}ق</span>@elseif($p->century_hijri)<span>سده‌ی {{ $p->century_hijri }}</span>@endif
                                        @if($m['span'])<span>کتابت {{ $m['span'][0] }}{{ $m['span'][1] !== $m['span'][0] ? '–' . $m['span'][1] : '' }}ق</span>@endif
                                        <span>{{ number_format($p->manuscripts_count) }} نسخه</span>
                                        <span>{{ number_format($p->works_count) }} اثر</span>
                                    </div>
                                    @if($m['works']->isNotEmpty() || $m['copies']->isNotEmpty())
                                        <ul class="text-[11px] text-muted list-disc pr-4 space-y-0.5">
                                            @foreach($m['works'] as $w)<li>تألیف: {{ $w->title }}</li>@endforeach
                                            @foreach($m['copies'] as $c)<li>کتابت: {{ $c->title }} ({{ $c->city }}{{ $c->year ? '، ' . $c->year . 'ق' : '' }})</li>@endforeach
                                        </ul>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                    @if($review->isOpen())
                        <textarea name="note" rows="1" placeholder="یادداشت (اختیاری)" aria-label="یادداشت" class="w-full px-3 py-2 rounded-xl border border-line-strong bg-surface text-xs focus:border-gold outline-none"></textarea>
                        <div class="flex flex-wrap gap-2 text-xs">
                            <button type="submit" class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold">ادغام رکوردهای علامت‌خورده</button>
                            <button type="submit" formaction="{{ route('admin.person-reviews.status', $review) }}" name="status" value="distinct" class="px-4 py-2 rounded-xl border border-line bg-surface font-bold">چند نفرند، جدا بمانند</button>
                            <button type="submit" formaction="{{ route('admin.person-reviews.status', $review) }}" name="status" value="deferred" class="px-4 py-2 rounded-xl border border-line bg-surface">بعداً</button>
                        </div>
                    @else
                        <button type="submit" formaction="{{ route('admin.person-reviews.status', $review) }}" name="status" value="open" class="px-4 py-2 rounded-xl border border-line bg-surface text-xs">بازگشایی</button>
                    @endif
                </form>
            @endif
        </article>
    @empty
        <div class="bg-surface rounded-2xl border border-dashed border-line p-8 text-center text-sm text-muted">در این بخش موردی نیست.</div>
    @endforelse

    {{ $reviews->links() }}
</div>
@endsection

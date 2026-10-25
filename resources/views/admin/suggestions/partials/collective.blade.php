{{-- An answer of «تصحیح جمعی» (backlog item 80): every pending opinion on the same item side by side, with the weighted
     count of each answer (a verified scholar's weighs more). Approving one settles the others with it. --}}
@php
    $collectiveQueue = \App\Review\CollectiveReview::queue($suggestion->review_queue);
    $collective = \App\Review\CollectiveReview::opinionsFor([$suggestion], true)[$suggestion->review_queue . ':' . $suggestion->review_item_id] ?? null;
    $collectiveUrl = $collectiveQueue?->reviewUrl((int) $suggestion->review_item_id);
@endphp
<x-ui.card class="space-y-3" data-collective-opinions>
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h3 class="rubric text-base">{{ config('contributions.review_title') }}: نظرهای پژوهشگران درباره‌ی همین مورد</h3>
        @if($collectiveUrl)
            <a href="{{ $collectiveUrl }}" class="text-xs text-cinnabar hover:underline">دیدن مورد در صف {{ $collectiveQueue->title() }}</a>
        @endif
    </div>
    @if($collective)
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach($collective['answers'] as $answer)
                <div @class(['p-3 border space-y-2', 'border-emerald-600/40 bg-emerald-600/5' => $loop->first && count($collective['answers']) > 1, 'border-line' => ! ($loop->first && count($collective['answers']) > 1)])>
                    <div class="flex items-baseline justify-between gap-2">
                        <b class="text-ink">{{ $answer['label'] }}</b>
                        <span class="text-xs text-muted shrink-0 tabular-nums">{{ \App\Support\Fa::num($answer['count']) }} نظر · وزن {{ \App\Support\Fa::num($answer['weight']) }}</span>
                    </div>
                    <ul class="space-y-1.5 text-xs m-0 p-0 list-none">
                        @foreach($answer['opinions'] as $opinion)
                            <li class="flex items-start gap-1.5">
                                <x-ui.avatar :user="$opinion->user" :name="$opinion->guest_name" size="sm" />
                                <span class="min-w-0">
                                    <a href="{{ route('admin.suggestions.show', $opinion) }}" class="font-bold {{ $opinion->id === $suggestion->id ? 'text-cinnabar' : 'text-ink hover:text-cinnabar' }}">{{ $opinion->user?->name ?? $opinion->guest_name }}</a>
                                    @if($opinion->user?->is_verified_scholar)<span class="text-emerald-700 dark:text-emerald-300">· پژوهشگر تأییدشده</span>@endif
                                    @if($opinion->rationale_citation)<span class="block text-muted leading-6 break-words">{{ \Illuminate\Support\Str::limit($opinion->rationale_citation, 240) }}</span>@endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
        <p class="text-xs text-muted m-0">با تأیید این پیشنهاد، نظرهای هم‌سو با آن تأیید و بقیه رد می‌شوند. تصمیم در صف پنل هم همین کار را می‌کند.</p>
    @else
        <p class="text-xs text-muted m-0">نظر در انتظاری بر این مورد نمانده است.</p>
    @endif
</x-ui.card>

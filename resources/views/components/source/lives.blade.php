@props(['person'])

{{-- The person's own life in the books of lives (step 14.83, sources:tie-lives-to-people): each with the source, the place
     of the life and its heading, linking the entry's page. Ties the machine found from his works carry the badge until a
     reviewer verifies them. --}}
@php
    $lives = \App\Models\SourceEntry::with(['source:id,code,title,short_name,rank', 'sourceItem'])
        ->where('citable_type', \App\Models\Person::class)->where('citable_id', $person->id)->whereNotNull('source_item_id')
        ->where('status', '!=', \App\Models\SourceEntry::STATUS_REJECTED)->get()
        ->filter(fn ($e) => $e->source && $e->sourceItem)->sortBy(fn ($e) => [$e->source->rank, $e->volume_number, $e->page_start])->values();
@endphp
@if($lives->isNotEmpty())
    <x-ui.section title="زندگی‌نامه در منابع" :count="$lives->count()">
        <ul class="divide-y divide-line border-y border-line text-sm" data-person-lives>
            @foreach($lives as $life)
                <li class="py-2.5 flex flex-wrap items-baseline gap-x-2 gap-y-1">
                    <a href="{{ $life->sourceItem->url() }}" class="font-semibold text-ink hover:text-cinnabar transition">{{ $life->source->short_name ?: $life->source->title }}، {{ $life->sourceItem->placeLabel() }}</a>
                    <span class="font-naskh text-[15px] text-muted">{{ \App\Support\Fa::digits($life->sourceItem->title) }}</span>
                    @if($life->status !== \App\Models\SourceEntry::STATUS_VERIFIED)
                        <x-ui.machine-badge text="این پیوند را هوشواره از روی آثار این شخص و نام او در سرعنوان زندگی‌نامه یافته است، و هنوز بازبینی نشده است." />
                    @endif
                </li>
            @endforeach
        </ul>
    </x-ui.section>
@endif

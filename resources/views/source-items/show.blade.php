@extends('layouts.app')

@php
    use App\Support\Fa;
    $sourceName = $item->source->short_name ?: $item->source->title;
    $heading = $item->title !== '' ? $item->title : 'پیش از نخستین مدخل';
@endphp

@section('title', $heading . ' | ' . $sourceName . '، ' . $item->placeLabel())
@section('meta_description', $sourceName . '، ' . $item->placeLabel() . ': ' . mb_substr(preg_replace('/\s+/u', ' ', $item->text), 0, 150))

@section('content')
{{-- One printed entry of a source (plan docs/plans/dharia-text.md): its text with the page turns marked, the page images,
     the works of the site tied to it (ⓘ until reviewed), and the entries around it. --}}
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
    <nav class="flex items-center gap-2 text-xs text-muted" aria-label="مسیر">
        <a href="{{ route('home') }}" class="hover:text-cinnabar">فهارس</a>
        <span aria-hidden="true">/</span>
        <span>{{ $sourceName }}</span>
        <span aria-hidden="true">/</span>
        <span class="text-ink font-semibold">{{ Fa::digits('ج ' . $item->volume_number) }}</span>
    </nav>

    <header class="text-center grid justify-items-center gap-2 pb-6">
        <p class="text-xs font-bold text-cinnabar">مدخل {{ $item->source->title }}</p>
        <h1 class="font-naskh text-[26px] sm:text-[38px] font-bold leading-[1.6] text-ink text-balance">{{ $heading }}</h1>
        <p class="text-sm text-muted" data-item-place>{{ $item->placeLabel() }}</p>
        @if($item->parent)
            <p class="text-xs text-muted">ذیل <a href="{{ $item->parent->url() }}" class="text-cinnabar hover:underline">«{{ $item->parent->title }}»{{ $item->parent->entry_number ? Fa::digits(' (ش ' . $item->parent->entry_number . ')') : '' }}</a></p>
        @endif
    </header>

    <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_280px] items-start">
        <div class="min-w-0 space-y-8">
            <x-ui.source-text :title="$item->source->title" :address="$item->placeLabel()"
                :viewer="$item->viewer() ? '$dispatch(\'open-catalog-viewer\', ' . \Illuminate\Support\Js::from($item->viewer()) . ')' : null">
                @foreach($item->pageSegments() as $i => $segment)
                    @if($i > 0)
                        <div class="my-3 flex items-center gap-2 text-[11px] text-muted" data-page-turn>
                            <span class="flex-1 border-t border-dashed border-line"></span><a href="{{ $item->pageUrl($segment['page']) }}" class="hover:text-cinnabar" title="متن کامل صفحه">{{ Fa::digits(($item->source->unit === 'ستون' ? 'ستون ' : 'ص ') . $segment['page']) }}</a><span class="flex-1 border-t border-dashed border-line"></span>
                        </div>
                    @endif
                    <div class="whitespace-pre-line font-naskh text-base" data-copy>{{ Fa::digits($segment['text']) }}</div>
                @endforeach
                @if($item->footnotes)
                    <div class="mt-4 pt-3 border-t border-line text-xs text-muted whitespace-pre-line" data-footnotes>{{ Fa::digits($item->footnotes) }}</div>
                @endif
            </x-ui.source-text>

            @if($item->children->isNotEmpty())
                <x-ui.section title="مدخل‌های بی‌شماره‌ی ذیل آن">
                    <ul class="space-y-1 text-sm">
                        @foreach($item->children as $child)
                            <li><a href="{{ $child->url() }}" class="text-cinnabar hover:underline">{{ $child->title }}</a></li>
                        @endforeach
                    </ul>
                </x-ui.section>
            @endif

            <nav class="flex justify-between gap-4 text-sm" aria-label="مدخل‌های پیش و پس">
                @if($previous)
                    <a href="{{ $previous->url() }}" class="text-cinnabar hover:underline" rel="prev">→ {{ $previous->title ?: 'پیش‌گفتار' }}{{ $previous->entry_number ? Fa::digits(' (' . $previous->entry_number . ')') : '' }}</a>
                @else<span></span>@endif
                @if($next)
                    <a href="{{ $next->url() }}" class="text-cinnabar hover:underline" rel="next">{{ $next->title }}{{ $next->entry_number ? Fa::digits(' (' . $next->entry_number . ')') : '' }} ←</a>
                @endif
            </nav>
        </div>

        <aside class="space-y-4 lg:order-last lg:sticky lg:top-24">
            <x-ui.card class="space-y-3">
                <div class="text-xs text-muted">آثار فهارس</div>
                @forelse($links as $link)
                    <div class="flex items-start gap-1 text-sm" data-machine-item="{{ $link->reviewKey() }}" data-item-link>
                        <div class="min-w-0">
                            <a href="{{ route('works.show', $link->citable) }}" class="font-bold text-ink hover:text-cinnabar">{{ $link->citable->primary_title }}</a>
                            @if($link->citable->author_name)<div class="text-xs text-muted">{{ $link->citable->author_name }}</div>@endif
                            @if($link->isMention())<div class="text-[11px] text-muted">فقط در متن این مدخل یاد شده است</div>@endif
                        </div>
                        @if($link->isAwaitingReview())
                            <x-ui.machine-badge class="shrink-0 mt-0.5" :text="$link->machineNote()" :review="$link->badgeReview()" />
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-muted">هنوز اثری از فهارس به این مدخل پیوند نخورده است.</p>
                @endforelse
            </x-ui.card>

            @if($item->viewer())
                <x-ui.card class="space-y-4">
                    <div class="text-xs text-muted">برگه‌ی چاپی</div>
                    @foreach(range($item->page_start, $item->page_end) as $page)
                        @php $v = $item->viewer($page); $thumb = asset('storage/catalogs/' . $v['catalog'] . '/v' . str_pad($v['volume'], 2, '0', STR_PAD_LEFT) . '/p' . str_pad($page, 4, '0', STR_PAD_LEFT) . '.webp'); @endphp
                        <button type="button" @click="$dispatch('open-catalog-viewer', @js($v))" class="block w-full group cursor-pointer" title="دیدن برگه در {{ $sourceName }}" data-viewer-card>
                            <div class="aspect-[4/3] overflow-hidden border border-line bg-white">
                                <img src="{{ $thumb }}" alt="{{ Fa::digits('برگه‌ی ص ' . $page) }}" loading="lazy" class="w-full h-full object-cover object-top group-hover:opacity-90 transition" onerror="this.closest('[data-viewer-card]').remove()">
                            </div>
                            <span class="block mt-1.5 text-xs font-semibold text-cinnabar">{{ Fa::digits('دیدن برگه‌ی ص ' . $page) }}</span>
                        </button>
                        <a href="{{ $item->pageUrl($page) }}" class="block -mt-2 text-xs text-muted hover:text-cinnabar" data-text-page>{{ Fa::digits('متن کامل ص ' . $page) }}</a>
                    @endforeach
                </x-ui.card>
            @else
                <x-ui.card class="space-y-2">
                    <div class="text-xs text-muted">صفحه‌ی چاپی</div>
                    @foreach(range($item->page_start, $item->page_end) as $page)
                        <a href="{{ $item->pageUrl($page) }}" class="block text-sm font-semibold text-cinnabar hover:underline" data-text-page>{{ Fa::digits('متن کامل ' . ($item->source->unit === 'ستون' ? 'ستون ' : 'ص ') . $page) }} ←</a>
                    @endforeach
                </x-ui.card>
            @endif
        </aside>
    </div>
</div>
@endsection

@props(['record'])
@use('App\Support\Fa')
{{-- Relations with other works or manuscripts in both directions, grouped by the label for this page (see RecordRelations) --}}
@php
    $groups = \App\Suggestions\RecordRelations::for($record);
    $isManuscript = $record instanceof \App\Models\Manuscript;
    $isWork = $record instanceof \App\Models\Work;
    // A work's related works the catalogue records as text (step 14.277, backlog item 6): the entries tied to no work
    // stand in this section as text, under the label of their type; a tied one shows the catalogue's words where
    // they are not its work's title and author. So do the relations with works that have no record, added by hand
    // (work_text_relations, step 14.280).
    $printed = $notes = [];
    if ($isWork) {
        $rows = \App\Support\WorkRelationLinker::pageRows($record);
        $printed = $rows['printed'];
        $notes = $rows['notes'];
        foreach (\App\Suggestions\RecordRelations::texts($record) as $label => $items) {
            $groups->put($label, ($groups->get($label) ?? collect())->concat($items->map(fn ($item) => $item + ['manual' => true])));
        }
        foreach ($rows['unlinked'] as $row) {
            $label = \App\Suggestions\RecordRelations::WORK_TYPES[$row['type']][$row['side'] === 'related' ? 0 : 1] ?? null;
            if ($label) {
                $groups->put($label, ($groups->get($label) ?? collect())->push(['text' => $row['text']]));
            }
        }
        // the kinds in their own order, «وابسته» (the kind not told) last
        $order = collect(\App\Suggestions\RecordRelations::WORK_TYPES)->flatMap(fn ($labels) => $labels)->values()->flip();
        $groups = $groups->sortBy(fn ($items, $label) => $order[$label] ?? 99);
    }
@endphp
@php
    $title = $isManuscript ? 'رابطه با نسخه‌های دیگر' : 'رابطه با آثار دیگر';
    $payload = ['general' => true, 'relation' => $isManuscript ? 'manuscript_relation' : 'work_relation'];
    $textPayload = ['general' => true, 'relation' => 'work_relation_text'];
@endphp
{{-- Empty: a row of the contribution box instead of an empty heading (step 14.132) --}}
@if($groups->isEmpty())
    <x-suggest.contribute-item :title="$title" label="افزودن رابطه" :payload="$payload"
        :hint="$isManuscript ? 'اگر این نسخه از روی نسخۀ دیگری استنساخ شده، با نسخه‌ای مقابله شده، مجلد دیگری از همان دوره است یا نسخۀ عکسی یا میکروفیلمی از آن هست.' : 'اگر این اثر شرح، حاشیه، ترجمه، تلخیص، نظم، تکمله یا ردیۀ اثر دیگری است، یا اثر دیگری بر آن نوشته شده.'" />
@else
<x-ui.section x-data :title="$title">
    <x-slot:actions>
        <button type="button" @click="$dispatch('suggest-field', @js($payload))"
            class="font-bold text-cinnabar hover:underline cursor-pointer">+ افزودن رابطه</button>
        @if($isWork)
            {{-- the other work has no record on the site: its title and author as text (step 14.280) --}}
            <button type="button" @click="$dispatch('suggest-field', @js($textPayload))"
                class="ms-3 font-bold text-cinnabar hover:underline cursor-pointer">+ اثری که در فهارس نیست</button>
        @endif
    </x-slot:actions>
        <dl class="space-y-3 text-sm">
            @foreach($groups as $label => $items)
                {{-- a long list (the commentaries of a much-read text) opens on its first twelve --}}
                <div class="space-y-1" x-data="{ all: false }">
                    <dt class="text-[13px] text-muted">{{ $label }}:@if($items->count() > 12) <span class="tabular-nums">({{ Fa::num($items->count()) }})</span>@endif</dt>
                    <dd class="space-y-1">
                        @foreach($items as $item)
                            <div @if($loop->index >= 12) x-show="all" x-cloak @endif>
                                @if(isset($item['record']))
                                    @php $info = \App\Suggestions\SuggestionRegistry::describe($item['record']); @endphp
                                    <a href="{{ $info['url'] }}" class="font-semibold text-ink border-b border-line-strong hover:text-cinnabar hover:border-cinnabar transition">{{ $info['label'] }}</a>
                                    @if($info['sub'])<span class="text-[11px] text-muted"> · {{ $info['sub'] }}</span>@endif
                                    @if($item['note'])<span class="text-[11px] text-muted"> ({{ $item['note'] }})</span>@endif
                                    @if($words = $printed[$item['record']->id] ?? null)<span class="block text-[11px] text-muted">در فهرست: {{ Fa::digits($words) }}</span>@endif
                                    @if($item['credit'] ?? null)<span class="block text-[10px] text-muted">به پیشنهاد {{ $item['credit'] }}</span>@endif
                                @else
                                    {{-- a work with no record on the site: an entry of the catalogue tied to no work, or a relation added by hand --}}
                                    <span class="text-ink">{{ Fa::digits($item['text']) }}</span>
                                    @if($item['note'] ?? null)<span class="text-[11px] text-muted"> ({{ $item['note'] }})</span>@endif
                                    <span class="text-[11px] text-muted whitespace-nowrap"> · بی پیوند به رکورد اثر</span>
                                    @if($item['manual'] ?? false)
                                        @can('structure.link')
                                            <form method="POST" action="{{ route('admin.work-text-relations.destroy', $item['id']) }}" class="inline" onsubmit="return confirm('این رابطه برداشته شود؟')">
                                                @csrf @method('DELETE')
                                                <button class="text-[11px] text-cinnabar hover:underline cursor-pointer">برداشتن</button>
                                            </form>
                                        @endcan
                                        @if($item['credit'] ?? null)<span class="block text-[10px] text-muted">به پیشنهاد {{ $item['credit'] }}</span>@endif
                                    @endif
                                @endif
                            </div>
                        @endforeach
                        @if($items->count() > 12)
                            <button type="button" x-show="! all" @click="all = true" class="text-xs font-semibold text-cinnabar hover:underline cursor-pointer">نمایش همۀ {{ Fa::num($items->count()) }} مورد</button>
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>
        {{-- what the catalogue says after the works it names («وابسته به» of several entries) --}}
        @foreach($notes as $note)
            <p class="mt-3 text-[13px] text-muted leading-relaxed max-w-[72ch]">یادداشت فهرست: {{ Fa::digits($note) }}</p>
        @endforeach
</x-ui.section>
@endif

{{-- How the source is cited in the others and how it cites them (step 14.57): one row per citation form --}}
@php
    $fa = fn ($n) => \App\Support\Fa::num($n);
    $describe = function ($form) {
        $o = (array) ($form->options ?? []);

        return collect([
            isset($o['volumes']) ? \App\Support\Fa::num($o['volumes']) . ' جلد' : null,
            ($o['unit'] ?? null) === 'ستون' ? 'ستونی' : null,
            ! empty($o['pairs_after_name']) ? 'جلد پیش از صفحه، با «:» یا «-»' : null,
            ! empty($o['series_volumes']) ? 'شماره‌ی سری: ' . collect($o['series_volumes'])->map(fn ($to, $from) => \App\Support\Fa::digits("{$from}→{$to}"))->implode('، ') : null,
            ! empty($o['other_edition']) ? 'چاپ دیگر را کنار می‌گذارد' : null,
        ])->filter()->implode(' · ');
    };
    $contexts = \App\Models\SourceCitationForm::CONTEXTS;
@endphp
<x-ui.section title="ارجاع به این منبع در منابع دیگر" :count="$cited->count()">
    @can('sources.patterns')
        <x-slot:actions>
            <x-ui.btn :href="route('admin.sources.citations.create', $source)" size="sm">افزودن الگو</x-ui.btn>
        </x-slot:actions>
    @endcan
    <p class="text-xs text-muted leading-6 mb-2">
        هر الگو می‌گوید منبعی دیگر (یا «هر منبع») این منبع را با چه نامی و چگونه ارجاع می‌دهد. الگوی ویژه‌ی یک منبع بر الگوی «هر منبع» برتری دارد.
        @if($bibliography) اکنون {{ $fa($bibliography) }} رشته‌ی مأخذ آثار به این منبع وصل است. @endif
    </p>
    @if($cited->isEmpty())
        <x-admin.notice tone="warn" class="text-xs">هیچ الگویی ندارد. پس هیچ مأخذی به این منبع وصل نمی‌شود.</x-admin.notice>
    @else
        <ul class="bg-surface border border-line divide-y divide-line text-xs">
            @foreach($cited as $form)
                <li class="px-4 py-2.5 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <x-ui.chip>{{ $form->citing ? ($form->citing->short_name ?: $form->citing->title) : 'هر منبع' }}</x-ui.chip>
                    <span class="text-muted">{{ $contexts[$form->context] ?? $form->context }}</span>
                    <span class="text-ink font-bold">{{ $form->names ? implode('، ', $form->names) : '' }}</span>
                    @if($form->regex)<code dir="ltr" class="text-[11px] text-muted break-all">{{ \Illuminate\Support\Str::limit($form->regex, 90) }}</code>@endif
                    @if($d = $describe($form))<span class="text-muted">{{ $d }}</span>@endif
                    @can('sources.patterns')
                        <x-ui.btn :href="route('admin.sources.citations.edit', [$source, $form])" size="sm" variant="ghost" class="ms-auto">ویرایش</x-ui.btn>
                    @endcan
                </li>
            @endforeach
        </ul>
    @endif
</x-ui.section>

@if($citing->isNotEmpty())
    <x-ui.section title="ارجاع‌های این منبع به منابع دیگر" :count="$citing->count()">
        <ul class="bg-surface border border-line divide-y divide-line text-xs">
            @foreach($citing as $form)
                <li class="px-4 py-2.5 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <a href="{{ route('admin.sources.show', $form->cited_source_id) }}" class="font-bold text-ink hover:text-cinnabar">{{ $form->cited->short_name ?: $form->cited->title }}</a>
                    <span class="text-muted">{{ $contexts[$form->context] ?? $form->context }}</span>
                    <span>{{ $form->names ? implode('، ', $form->names) : '' }}</span>
                    @if($d = $describe($form))<span class="text-muted">{{ $d }}</span>@endif
                    @can('sources.patterns')
                        <x-ui.btn :href="route('admin.sources.citations.edit', [$form->cited_source_id, $form])" size="sm" variant="ghost" class="ms-auto">ویرایش</x-ui.btn>
                    @endcan
                </li>
            @endforeach
        </ul>
    </x-ui.section>
@endif

@extends('layouts.admin')

@section('title', 'زندگی‌نامه‌های خودکار اشخاص')
@section('page_title', 'زندگی‌نامه‌های خودکار اشخاص')

@section('content')
@php
    use App\Http\Controllers\Admin\CatalogReferenceController as Refs;
    use App\Http\Controllers\Admin\PersonLifeController as Queue;
    use App\Http\Controllers\Admin\SourceLinkController as Links;
    use App\Models\SourceEntry;
    use App\Support\Fa;
    $csrf = \Illuminate\Support\Js::from(csrf_token());
    $tabs = [];
    foreach (Refs::STATUSES as $key => $label) {
        $tabs[] = ['label' => $label, 'url' => route('admin.person-lives.index', ['status' => $key] + $query), 'active' => $status === $key, 'count' => $counts[$key] ?? 0, 'key' => $key];
    }
@endphp

<p class="text-[13px] text-muted leading-7 mb-4 max-w-3xl">
    هوشواره این مدخل‌های کتاب‌های تراجم را زندگی‌نامه‌ی این اشخاص دانسته است. نام، تاریخ‌ها و آثار شخص را با سرعنوان و آغاز مدخل بسنجید:
    پدر و پسر هم‌نام، و سال تولد یا تألیف به جای سال وفات، خطاهای رایج‌اند. پیوندهای نامطمئن‌تر نخست می‌آیند.
    با تأیید، نشان ⓘ از صفحه‌ی شخص برداشته می‌شود و با رد، زندگی‌نامه از آن صفحه پنهان می‌شود.
</p>

<x-admin.tabs :options="$tabs" />

<x-admin.filters :action="route('admin.person-lives.index')" :total="$lives->total()" noun="پیوند">
    <input type="hidden" name="status" value="{{ $status }}">
    <select name="source" aria-label="مأخذ" class="admin-field max-w-xs">
        <option value="">همه‌ی مأخذها</option>
        @foreach($sources as $s)
            <option value="{{ $s->code }}" @selected(($filters['source'] ?? null)?->id === $s->id)>{{ $s->short_name ?: $s->title }}</option>
        @endforeach
    </select>
    <select name="method" aria-label="روش" class="admin-field">
        <option value="">همه‌ی روش‌ها</option>
        @foreach(Queue::METHODS as $key => $label)
            <option value="{{ $key }}" @selected(($filters['method'] ?? null) === $key)>{{ $label }}</option>
        @endforeach
    </select>
</x-admin.filters>

<x-admin.table :paginator="$lives" :is-empty="$lives->isEmpty()" empty="پیوندی با این پالایه نیست.">
    <x-slot:head>
        <x-admin.th>شخص</x-admin.th>
        <x-admin.th>مدخل در مأخذ</x-admin.th>
        <x-admin.th>روش و اطمینان</x-admin.th>
        <x-admin.th><span class="sr-only">داوری</span></x-admin.th>
    </x-slot:head>
    @foreach($lives as $life)
        @php
            $item = $life->sourceItem;
            $person = $life->citable;
            $tone = $life->confidence >= 90 ? 'border-emerald-600/40 text-emerald-800 dark:text-emerald-300'
                : ($life->confidence >= 75 ? 'border-amber-600/50 text-amber-800 dark:text-amber-300' : 'border-cinnabar/50 text-cinnabar');
        @endphp
        <x-admin.row x-data="referenceReview({ csrf: {{ $csrf }} })" ::class="status !== 'unverified' && 'opacity-60'" data-person-life="{{ $life->id }}">
            <x-admin.cell main class="md:min-w-56 md:max-w-72">
                @if($person)
                    <a href="{{ route('people.show', $person) }}" target="_blank" class="font-bold text-ink hover:text-cinnabar">{{ $person->name }}</a>
                    @if($person->life_dates_raw || $person->death_year_hijri)
                        <span class="block text-xs text-ink mt-0.5">{{ Fa::digits($person->life_dates_raw ?: 'د. ' . $person->death_year_hijri) }}</span>
                    @elseif($person->century_hijri)
                        <span class="block text-xs text-muted mt-0.5">سده‌ی {{ Fa::digits($person->century_hijri) }}</span>
                    @endif
                    @foreach(array_filter((array) $person->aliases, 'is_string') as $alias)
                        <span class="block text-xs text-muted">{{ $alias }}</span>
                    @endforeach
                    @if($of = $works[$person->id] ?? null)
                        <span class="block text-xs text-muted mt-1 leading-5">{{ $of->pluck('primary_title')->implode('؛ ') }}@if($person->works_count > $of->count()) و {{ Fa::num($person->works_count - $of->count()) }} اثر دیگر@endif</span>
                    @endif
                @endif
            </x-admin.cell>
            <x-admin.cell class="basis-full md:min-w-72">
                @if($item)
                    <span class="inline-flex flex-wrap items-center gap-x-3 gap-y-1">
                        <a href="{{ $item->url() }}" target="_blank" class="font-bold text-cinnabar hover:underline">{{ $item->title ?: 'بی‌عنوان' }}</a>
                        <span class="text-xs text-muted">{{ $life->sourceName() }}، {{ $item->placeLabel() }}</span>
                    </span>
                    <span class="block mt-1.5 text-ink leading-6 line-clamp-4">{{ Links::excerpt($item->text, 600) }}</span>
                    @if($life->notes)<span class="block mt-1 text-xs text-muted">{{ Fa::digits($life->notes) }}</span>@endif
                @endif
            </x-admin.cell>
            <x-admin.cell label="روش" class="md:max-w-44">
                <span class="font-bold text-ink">{{ Queue::methodLabel($life->match_method) }}</span>
                <span class="inline-block md:block md:w-fit mt-1 px-2 rounded-full border bg-surface text-xs font-bold tabular-nums {{ $tone }}" title="اطمینان">{{ Fa::digits($life->confidence) }}٪</span>
            </x-admin.cell>
            <x-admin.cell actions>
                @if($life->status === SourceEntry::STATUS_UNVERIFIED)
                    <span x-show="status === 'unverified'" class="inline-flex items-center gap-1.5">
                        <form method="POST" action="{{ route('admin.catalog-references.verify', $life) }}" @submit.prevent="send($el)">
                            @csrf
                            <button type="submit" :disabled="busy" class="px-3 py-1 rounded-sm bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-bold transition cursor-pointer disabled:opacity-50">تأیید</button>
                        </form>
                        <form method="POST" action="{{ route('admin.catalog-references.reject', $life) }}" @submit.prevent="send($el)">
                            @csrf
                            <x-ui.btn type="submit" size="sm" ::disabled="busy" class="disabled:opacity-50">رد</x-ui.btn>
                        </form>
                    </span>
                    <span x-show="status !== 'unverified'" x-cloak class="inline-flex items-center gap-1.5">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full border text-xs leading-5" :class="status === 'verified' ? 'border-emerald-600/40 text-emerald-700 dark:text-emerald-300 bg-emerald-600/5' : 'border-cinnabar/40 text-cinnabar bg-cinnabar/5'" x-text="label"></span>
                        <x-ui.btn size="sm" variant="ghost" @click="undo()" ::disabled="busy">بازگردانی</x-ui.btn>
                    </span>
                    <span x-show="error" x-cloak role="alert" class="block mt-1 text-xs text-cinnabar md:max-w-56 md:whitespace-normal" x-text="error"></span>
                @else
                    <x-ui.chip :tone="$life->status === 'verified' ? 'good' : 'accent'">{{ Refs::STATUSES[$life->status] }}</x-ui.chip>
                    <span class="block text-xs text-muted mt-0.5">{{ $life->reviewer?->name }}@if($life->reviewed_at)، {{ \App\Support\Jalali::format($life->reviewed_at) }}@endif</span>
                @endif
            </x-admin.cell>
        </x-admin.row>
    @endforeach
</x-admin.table>
@endsection

@extends('layouts.admin')

@section('title', 'پیوندهای خودکار آثار')
@section('page_title', 'پیوندهای خودکار آثار')

@section('content')
@php
    use App\Http\Controllers\Admin\CatalogReferenceController as Refs;
    use App\Http\Controllers\Admin\SourceLinkController as Queue;
    use App\Models\SourceEntry;
    use App\Support\Fa;
    $csrf = \Illuminate\Support\Js::from(csrf_token());
    $tabs = [];
    foreach (Refs::STATUSES as $key => $label) {
        $tabs[] = ['label' => $label, 'url' => route('admin.source-links.index', ['status' => $key] + $query), 'active' => $status === $key, 'count' => $counts[$key] ?? 0, 'key' => $key];
    }
    $source = $filters['source'] ?? null;
@endphp

<p class="text-[13px] text-muted leading-7 mb-4 max-w-3xl">
    هوشواره این آثار را با سنجیدن عنوان و مؤلف به مدخلی از صفحه‌ای که فنخا نشانی داده پیوند داده است. پیوندهای نامطمئن‌تر نخست می‌آیند:
    مبهم، مؤلف و سال وفات، صفحه‌ی همسایه، فقط عنوان و یادکرد در متن. با «بررسی» متن مدخل و متن صفحه‌ها را می‌بینید و می‌توانید پیوند را به مدخل دیگری از همان صفحه یا صفحه‌ی همسایه ببرید.
    با تأیید، نشان ⓘ از صفحه‌ی اثر برداشته می‌شود و با رد، پیوند پنهان می‌شود.
</p>

<x-admin.tabs :options="$tabs" />

<x-admin.filters :action="route('admin.source-links.index')" :total="$links->total()" noun="پیوند">
    <input type="hidden" name="status" value="{{ $status }}">
    <select name="source" aria-label="مأخذ" class="admin-field max-w-xs" onchange="this.form.volume && (this.form.volume.value = '')">
        <option value="all">همه‌ی مأخذها</option>
        @foreach($sources as $s)
            <option value="{{ $s->code }}" @selected($source?->id === $s->id)>{{ $s->short_name ?: $s->title }}</option>
        @endforeach
    </select>
    @if($volumes->isNotEmpty())
        <select name="volume" aria-label="جلد" class="admin-field">
            <option value="">همه‌ی جلدها</option>
            @foreach($volumes as $volume)
                <option value="{{ $volume->id }}" @selected(($filters['volume'] ?? null) === $volume->id)>ج {{ Fa::digits($volume->volume_number) }}</option>
            @endforeach
        </select>
    @endif
    <select name="method" aria-label="روش" class="admin-field">
        <option value="">همه‌ی روش‌ها</option>
        @foreach(Queue::METHODS as $key => $label)
            @if(isset($methods[$key]) || ($filters['method'] ?? null) === $key)
                <option value="{{ $key }}" @selected(($filters['method'] ?? null) === $key)>{{ $label }} ({{ Fa::num($methods[$key] ?? 0) }})</option>
            @endif
        @endforeach
    </select>
    <select name="role" aria-label="نقش" class="admin-field">
        <option value="">مدخل و یادکرد</option>
        @foreach(Queue::ROLES as $key => $label)
            <option value="{{ $key }}" @selected(($filters['role'] ?? null) === $key)>{{ $label }}</option>
        @endforeach
    </select>
    <select name="confidence" aria-label="اطمینان" class="admin-field">
        <option value="">هر اطمینانی</option>
        @foreach(Refs::CONFIDENCE_BANDS as $key => $band)
            <option value="{{ $key }}" @selected(($filters['confidence'] ?? null) === $key)>{{ $band['label'] }}</option>
        @endforeach
    </select>
</x-admin.filters>

<x-admin.table :paginator="$links" :is-empty="$links->isEmpty()" empty="پیوندی با این پالایه نیست.">
    <x-slot:head>
        <x-admin.th>اثر</x-admin.th>
        <x-admin.th>مدخل در مأخذ</x-admin.th>
        <x-admin.th>روش و اطمینان</x-admin.th>
        <x-admin.th><span class="sr-only">داوری</span></x-admin.th>
    </x-slot:head>
    @foreach($links as $link)
        @php
            $item = $link->sourceItem;
            $tone = $link->confidence >= 60 ? 'border-emerald-600/40 text-emerald-800 dark:text-emerald-300'
                : ($link->confidence >= 35 ? 'border-amber-600/50 text-amber-800 dark:text-amber-300' : 'border-cinnabar/50 text-cinnabar');
        @endphp
        <x-admin.row x-data="referenceReview({ csrf: {{ $csrf }} })" ::class="status !== 'unverified' && 'opacity-60'">
            <x-admin.cell main class="md:min-w-48 md:max-w-64">
                @if($link->citable)
                    <a href="{{ route('works.show', $link->citable) }}" target="_blank" class="font-bold text-ink hover:text-cinnabar">{{ $link->citable->primary_title }}</a>
                    @if($link->citable->author_name)<span class="block text-xs text-muted">{{ $link->citable->author_name }}</span>@endif
                @endif
                @if($link->citation_text)
                    <span class="block text-xs text-muted mt-1" title="ارجاع فنخا">{{ Fa::digits($link->citation_text) }}</span>
                @endif
            </x-admin.cell>
            <x-admin.cell class="basis-full md:min-w-72">
                @if($item)
                    <span class="inline-flex flex-wrap items-center gap-x-3 gap-y-1">
                        <a href="{{ $item->url() }}" target="_blank" class="font-bold text-cinnabar hover:underline">{{ $item->title ?: 'بی‌عنوان' }}</a>
                        <span class="text-xs text-muted">{{ $link->sourceName() }}، {{ $item->placeLabel() }}</span>
                        @if($link->isMention())<x-ui.chip>یادکرد در متن</x-ui.chip>@endif
                    </span>
                    @if($item->author_text)<span class="block text-xs text-muted mt-0.5">{{ $item->author_text }}</span>@endif
                    <span class="block mt-1.5 text-ink leading-6 line-clamp-3">{{ Queue::excerpt($item->text) }}</span>
                @endif
            </x-admin.cell>
            <x-admin.cell label="روش" class="md:max-w-44">
                <span class="font-bold text-ink">{{ Queue::methodLabel($link->match_method) }}</span>
                <span class="inline-block md:block md:w-fit mt-1 px-2 rounded-full border bg-surface text-xs font-bold tabular-nums {{ $tone }}" title="اطمینان">{{ Fa::digits($link->confidence) }}٪</span>
            </x-admin.cell>
            <x-admin.cell actions>
                <x-ui.btn size="sm" :href="route('admin.source-links.show', [$link] + $query)">بررسی</x-ui.btn>
                @if($link->status === SourceEntry::STATUS_UNVERIFIED)
                    <span x-show="status === 'unverified'" class="inline-flex items-center gap-1.5">
                        <form method="POST" action="{{ route('admin.catalog-references.verify', $link) }}" @submit.prevent="send($el)">
                            @csrf
                            <button type="submit" :disabled="busy" class="px-3 py-1 rounded-sm bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-bold transition cursor-pointer disabled:opacity-50">تأیید</button>
                        </form>
                        <form method="POST" action="{{ route('admin.catalog-references.reject', $link) }}" @submit.prevent="send($el)">
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
                    <x-ui.chip :tone="$link->status === 'verified' ? 'good' : 'accent'">{{ Refs::STATUSES[$link->status] }}</x-ui.chip>
                    <span class="block text-xs text-muted mt-0.5">{{ $link->reviewer?->name }}@if($link->reviewed_at)، {{ \App\Support\Jalali::format($link->reviewed_at) }}@endif</span>
                @endif
            </x-admin.cell>
        </x-admin.row>
    @endforeach
</x-admin.table>
@endsection

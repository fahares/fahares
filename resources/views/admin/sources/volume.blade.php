@extends('layouts.admin')

@php
    $fa = fn ($n) => \App\Support\Fa::num($n);
    $label = $sourceVolume->volume_number ? 'جلد ' . $fa($sourceVolume->volume_number) : 'جلد بی‌شماره';
    $roles = \App\Http\Controllers\Admin\SourceController::ROLES;
    $preview = session('preview');
    // a form shows what was sent only after its own preview or its own failed check
    $sent = fn (string $form) => ($preview['form'] ?? null) === $form || $errors->getBag($form === 'volume' ? 'default' : $form)->any();
    $val = fn (string $form, string $key, $default) => $sent($form) ? old($key, $default) : $default;
    $parted = $catalogVolume->parts->isNotEmpty();
@endphp

@section('title', $label . ' — ' . $source->title)
@section('page_title', $label)
@section('breadcrumb')
    <a href="{{ route('admin.sources.index') }}" class="hover:text-cinnabar">منابع</a>
    <span>/</span>
    <a href="{{ route('admin.sources.show', $source) }}" class="hover:text-cinnabar">{{ \Illuminate\Support\Str::limit($source->title, 60) }}</a>
    <span>/</span>
    <span>#{{ \App\Support\Fa::digits($sourceVolume->id) }}</span>
@endsection
@section('page_actions')
    <x-ui.btn :href="route('sources.show', $source->code)" target="_blank" size="sm">منبع در سایت ↗</x-ui.btn>
@endsection

@section('content')
<div class="space-y-6 text-[13px]">
    <x-ui.card class="space-y-1">
        <p class="font-bold text-ink">{{ \App\Support\Fa::digits($sourceVolume->title) }}</p>
        <p class="text-xs text-muted">
            {{ $fa($catalogVolume->manuscripts_count) }} نسخه
            @if($parted) · {{ $fa($catalogVolume->parts->count()) }} بخش · {{ $fa($partless) }} نسخه‌ی بی‌بخش @endif
            @if($catalogVolume->publication_year) · چاپ {{ \App\Support\Fa::digits($catalogVolume->publication_year) }}@endif
            @if($catalogVolume->pages_count) · {{ $fa($catalogVolume->pages_count) }} صفحه@endif
            @if($catalogVolume->mtif_entry_id) · MTIF <span dir="ltr">{{ $catalogVolume->mtif_entry_id }}</span>@endif
        </p>
    </x-ui.card>

    @if(session('effect'))
        @include('admin.sources.partials.effect', ['effect' => session('effect'), 'done' => true])
    @endif

    {{-- The volume's library and people --}}
    <x-ui.section title="کتابخانه و فهرست‌نگاران جلد">
        <x-ui.card>
            <form action="{{ route('admin.sources.volume.update', [$source, $sourceVolume->id]) }}" method="POST" class="space-y-4">
                @csrf
                @method('PUT')
                @if(($preview['form'] ?? null) === 'volume')
                    @include('admin.sources.partials.effect', ['effect' => $preview['effect'], 'done' => false])
                @endif
                <x-admin.field label="کتابخانه‌ی جلد" for="library_id" error="library_id" class="max-w-xl"
                               hint="جلدی که فهرست چند کتابخانه است کتابخانه ندارد و کتابخانه‌ی هر بخش را بخش می‌گوید.">
                    @include('admin.sources.partials.lazy-select', ['name' => 'library_id', 'id' => 'library_id', 'list' => 'libraries', 'value' => $val('volume', 'library_id', $catalogVolume->library_id), 'empty' => 'بی‌کتابخانه'])
                </x-admin.field>

                @php
                    $people = $val('volume', 'people', $catalogVolume->catalogers->map(fn ($c) => ['cataloger_id' => $c->id, 'role' => $c->pivot->role])->all());
                    $people = array_values(array_filter((array) $people, fn ($p) => ! empty($p['cataloger_id'])));
                    $people = array_merge($people, array_fill(0, 2, ['cataloger_id' => null, 'role' => $parted ? 'supervisor' : 'cataloger']));
                @endphp
                <div class="space-y-2">
                    <p class="font-bold text-ink">فهرست‌نگاران جلد</p>
                    <p class="text-xs text-muted leading-6">
                        @if($parted)
                            این جلد بخش دارد: نسخه‌ها از راه بخششان به فهرست‌نگار می‌رسند، و کسی که به خود جلد وصل است همه‌ی نسخه‌های جلد را «زیر نظر» دارد.
                        @else
                            نخستین «فهرست‌نگار» جلد، فهرست‌نگار نسخه‌های جلد است. برای حذف، نام را روی «—» بگذارید.
                        @endif
                    </p>
                    @foreach($people as $i => $person)
                        <div class="flex flex-wrap gap-2">
                            @include('admin.sources.partials.lazy-select', ['name' => "people[{$i}][cataloger_id]", 'label' => 'فهرست‌نگار ' . $fa($i + 1), 'list' => 'catalogers', 'value' => $person['cataloger_id'], 'empty' => '—', 'class' => 'flex-1 basis-56'])
                            <select name="people[{{ $i }}][role]" aria-label="نقش {{ $fa($i + 1) }}" class="admin-field">
                                @foreach($roles as $key => $name)
                                    <option value="{{ $key }}" @selected(($person['role'] ?? 'cataloger') === $key)>{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                </div>
                <div class="pt-3 flex flex-wrap items-center justify-end gap-2 border-t border-line">
                    <x-ui.btn type="submit" name="preview" value="1">پیش‌نمایش اثر</x-ui.btn>
                    <x-ui.btn type="submit" variant="primary">ذخیره</x-ui.btn>
                </div>
            </form>
        </x-ui.card>
    </x-ui.section>

    {{-- The parts --}}
    <x-ui.section title="بخش‌های جلد" :count="$catalogVolume->parts->count()">
        <x-admin.notice class="mb-3 text-xs">
            بخش نسخه از روی کتابخانه‌ی نسخه گرفته می‌شود. اگر کتابخانه در این جلد بیش از یک بخش داشته باشد، صفحه‌ی ارجاع نسخه بخش را تعیین می‌کند. پس بازه‌ی صفحه فقط در این حالت نسخه‌ها را جابه‌جا می‌کند. در بقیه‌ی حالت‌ها، فقط شمار نسخه‌های «بیرون از بازه» را تغییر می‌دهد.
            نسخه‌ای که کتابخانه‌اش در جلد بخشی ندارد، بی‌بخش می‌ماند. هر تغییر را پیش از ذخیره با «پیش‌نمایش اثر» ببینید.
        </x-admin.notice>

        <div class="space-y-3">
            @foreach($catalogVolume->parts as $part)
                @php
                    $form = 'part' . $part->id;
                    $partCatalogers = $val($form, 'catalogers', $part->catalogers->pluck('id')->all());
                    $partCatalogers = array_merge(array_values(array_filter((array) $partCatalogers)), [null]);
                    $open = $sent($form);
                @endphp
                <details id="{{ $form }}" class="bg-surface border border-line scroll-mt-24 group" @if($open) open @endif>
                    <summary class="px-4 py-3 cursor-pointer flex flex-wrap items-baseline gap-x-4 gap-y-1">
                        <span class="text-xs text-muted tabular-nums">{{ $fa($part->sort) }}.</span>
                        <span class="font-bold text-ink">{{ $part->title }}</span>
                        <span class="text-xs text-muted">{{ $part->library ? $part->library->name . '، ' . $part->library->city : 'بی‌کتابخانه' }}</span>
                        <span class="text-xs text-muted">{{ $part->page_first ? \App\Support\Fa::digits($part->pages_text) : 'بی‌صفحه' }}</span>
                        <span class="text-xs text-muted">{{ $part->catalogers->pluck('name')->implode('، ') ?: 'بی‌فهرست‌نگار' }}</span>
                        <span class="ms-auto text-xs tabular-nums">{{ $fa($part->manuscripts_count) }} نسخه
                            @if(! empty($outside[$part->id]))<span class="text-amber-700 dark:text-amber-300"> · {{ $fa($outside[$part->id]) }} بیرون از بازه</span>@endif
                        </span>
                    </summary>
                    <div class="px-4 pb-4 pt-1 border-t border-line">
                        <form action="{{ route('admin.sources.parts.update', [$source, $sourceVolume->id, $part]) }}" method="POST" class="space-y-4 pt-3">
                            @csrf
                            @method('PUT')
                            @if(($preview['form'] ?? null) === $form)
                                @include('admin.sources.partials.effect', ['effect' => $preview['effect'], 'done' => false])
                            @endif
                            @include('admin.sources.partials.part-fields', ['bag' => $form, 'values' => [
                                'title' => $val($form, 'title', $part->title), 'library_id' => $val($form, 'library_id', $part->library_id),
                                'page_first' => $val($form, 'page_first', $part->page_first), 'page_last' => $val($form, 'page_last', $part->page_last),
                                'sort' => $val($form, 'sort', $part->sort), 'catalogers' => $partCatalogers]])
                            <div class="pt-3 flex flex-wrap items-center justify-end gap-2 border-t border-line">
                                <x-ui.btn type="submit" name="preview" value="1">پیش‌نمایش اثر</x-ui.btn>
                                <x-ui.btn type="submit" variant="primary">ذخیره‌ی بخش</x-ui.btn>
                            </div>
                        </form>
                        <form action="{{ route('admin.sources.parts.destroy', [$source, $sourceVolume->id, $part]) }}" method="POST" class="mt-2 text-left"
                              onsubmit="return confirm('بخش «{{ e($part->title) }}» حذف شود؟ {{ $fa($part->manuscripts_count) }} نسخه‌ی آن بی‌بخش می‌شوند، مگر آنکه کتابخانه‌شان بخش دیگری در این جلد داشته باشد.')">
                            @csrf
                            @method('DELETE')
                            <x-ui.btn type="submit" size="sm" variant="ghost">حذف بخش</x-ui.btn>
                        </form>
                    </div>
                </details>
            @endforeach
        </div>

        <details id="new" class="mt-4 bg-surface border border-dashed border-line-strong scroll-mt-24" @if($sent('new')) open @endif>
            <summary class="px-4 py-3 cursor-pointer font-bold text-ink">+ افزودن بخش</summary>
            <form action="{{ route('admin.sources.parts.store', [$source, $sourceVolume->id]) }}" method="POST" class="px-4 pb-4 pt-3 space-y-4 border-t border-line">
                @csrf
                @if(($preview['form'] ?? null) === 'new')
                    @include('admin.sources.partials.effect', ['effect' => $preview['effect'], 'done' => false])
                @endif
                @include('admin.sources.partials.part-fields', ['bag' => 'new', 'values' => [
                    'title' => $val('new', 'title', ''), 'library_id' => $val('new', 'library_id', null),
                    'page_first' => $val('new', 'page_first', null), 'page_last' => $val('new', 'page_last', null),
                    'sort' => $val('new', 'sort', null), 'catalogers' => array_merge(array_values(array_filter((array) $val('new', 'catalogers', []))), [null, null])]])
                <div class="pt-3 flex flex-wrap items-center justify-end gap-2 border-t border-line">
                    <x-ui.btn type="submit" name="preview" value="1">پیش‌نمایش اثر</x-ui.btn>
                    <x-ui.btn type="submit" variant="primary">افزودن بخش</x-ui.btn>
                </div>
            </form>
        </details>
    </x-ui.section>

    @if($copyLibraries->isNotEmpty())
        <x-ui.section title="کتابخانه‌های نسخه‌های این جلد" :count="$copyLibraries->count()">
            <x-admin.table>
                <x-slot:head>
                    <x-admin.th>کتابخانه</x-admin.th>
                    <x-admin.th>نسخه</x-admin.th>
                    @if($parted)<x-admin.th>بی‌بخش</x-admin.th>@endif
                </x-slot:head>
                @foreach($copyLibraries as $row)
                    @php $lib = $copyLibraryNames[$row->library_id] ?? null; @endphp
                    <x-admin.row>
                        <x-admin.cell main>{{ $lib ? $lib->name . '، ' . $lib->city : '#' . \App\Support\Fa::digits($row->library_id) }}</x-admin.cell>
                        <x-admin.cell label="نسخه" class="tabular-nums">{{ $fa($row->c) }}</x-admin.cell>
                        @if($parted)
                            <x-admin.cell label="بی‌بخش" @class(['tabular-nums', 'text-amber-700 dark:text-amber-300 font-bold' => $row->partless > 0])>{{ $row->partless ? $fa($row->partless) : '—' }}</x-admin.cell>
                        @endif
                    </x-admin.row>
                @endforeach
            </x-admin.table>
        </x-ui.section>
    @endif

    @include('admin.sources.partials.history', ['history' => $history])
</div>

{{-- The libraries and catalogers once for the whole page; each select takes them when its form is opened or focused --}}
@php
    $lists = ['libraries' => $libraries->map(fn ($l) => [$l->id, $l->name . ' (' . $l->city . ')'])->values(), 'catalogers' => $catalogers->map(fn ($c) => [$c->id, $c->name])->values()];
@endphp
<script>
(() => {
    const lists = {!! json_encode($lists, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};
    const fill = (root) => root.querySelectorAll('select[data-fill]:not([data-filled])').forEach((select) => {
        const current = select.value;
        const options = document.createDocumentFragment();
        for (const [id, label] of lists[select.dataset.fill] || []) {
            if (String(id) !== current) options.appendChild(new Option(label, id));
        }
        select.appendChild(options);
        select.dataset.filled = '1';
    });
    document.querySelectorAll('details').forEach((d) => d.addEventListener('toggle', () => d.open && fill(d)));
    document.addEventListener('focusin', (e) => e.target.matches?.('select[data-fill]') && fill(e.target.parentElement));
    document.querySelectorAll('form:not(details form), details[open]').forEach(fill);
})();
</script>
@endsection

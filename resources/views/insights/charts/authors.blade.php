@php
    use App\Support\Fa;
    use App\Support\Jalali;
@endphp
{{-- 1b. Authors by death and works by composition --}}
<x-ui.section title="پدیدآورندگان و آثار در گذر سده‌ها" id="authors">
    <x-ui.card>
        @if($a['counted'] < $min && $w['counted'] < $min)
            @include('insights.thin', ['n' => max($a['counted'], $w['counted']), 'what' => 'مؤلف یا اثرِ تاریخ‌دار'])
        @else
        <div x-data="{ by: '{{ $a['counted'] >= $min ? 'authors' : 'works' }}' }">
        <div class="flex flex-wrap items-center gap-1.5 text-xs mb-4">
            <span class="text-muted me-1">بر پایۀ</span>
            @foreach(['authors' => 'سال وفات مؤلفان', 'works' => 'سال تألیف آثار'] as $key => $name)
                <button type="button" @click="by = '{{ $key }}'" :aria-pressed="(by === '{{ $key }}').toString()"
                        :class="by === '{{ $key }}' ? 'bg-cinnabar text-on-cinnabar border-cinnabar' : 'bg-surface text-ink border-line hover:border-cinnabar'"
                        class="px-2.5 py-0.5 rounded-full border transition cursor-pointer">{{ $name }}</button>
            @endforeach
        </div>

        <div x-show="by === 'authors'" @if($a['counted'] < $min) x-cloak @endif>
            @include('insights.small', ['n' => $a['counted'], 'what' => 'مؤلفِ تاریخ‌دار'])
            <p class="text-xs text-muted mb-4">شمار مؤلفان (کسانی که اثری از آنان در فهارس هست) در هر سدۀ هجری بر پایۀ سال وفات. وفاتی که تنها سده‌اش ثبت شده («ق۱۳»)، لایۀ خود را دارد.</p>
            @include('insights.century-bars', ['rows' => $a['centuries'], 'unit' => 'مؤلف', 'label' => 'شمار مؤلفان در هر سده',
                'href' => fn ($c) => $search('authors', ['role' => 'author', 'death_century' => $c])])
            <p class="mt-5 text-xs text-muted leading-relaxed">
                بر پایۀ {{ Fa::num($a['counted']) }} مؤلف، یعنی {{ $pct($a['coverage']) }} از {{ Fa::num($a['total']) }} مؤلف، که تاریخ وفاتشان ثبت شده است:
                {{ Fa::num($a['exact']) }} با سال معین و {{ Fa::num($a['range']) }} تنها با سده.
                @include('insights.doubtful-note', ['layers' => $a, 'unit' => 'مؤلف', 'what' => 'سال وفاتشان'])
            </p>
            @include('insights.explain', ['note' => $notes['authors'], 'tables' => $tables['authors']])
        </div>

        <div x-show="by === 'works'" @if($a['counted'] >= $min) x-cloak @endif>
            @include('insights.small', ['n' => $w['counted'], 'what' => 'اثرِ تاریخ‌دار'])
            <p class="text-xs text-muted mb-4">شمار آثار در هر سدۀ هجری بر پایۀ تاریخ تألیف، آن‌جا که فهرست آن را آورده است.</p>
            @include('insights.century-bars', ['rows' => $w['centuries'], 'unit' => 'اثر', 'label' => 'شمار آثار در هر سده',
                'href' => fn ($c) => $search('works', ['century' => $c])])
            <p class="mt-5 text-xs text-muted leading-relaxed">
                بر پایۀ {{ Fa::num($w['counted']) }} اثر، یعنی تنها {{ $pct($w['coverage']) }} آثار، که تاریخ تألیفشان ثبت شده است.
                @include('insights.doubtful-note', ['layers' => $w, 'unit' => 'اثر', 'what' => 'تاریخ تألیفشان'])
                @if($w['open'] > 0){{ Fa::num($w['open']) }} اثر با بازۀ باز («پیش از …») جدا شمرده شده است.@endif
                جست‌وجوی هر سده، آثاری را هم که تاریخ تألیف ندارند به سدۀ وفات مؤلفشان می‌آورد.
            </p>
            @include('insights.explain', ['note' => $notes['works'], 'tables' => $tables['works']])
        </div>
        </div>
        @endif
    </x-ui.card>
</x-ui.section>

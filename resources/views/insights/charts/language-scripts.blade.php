@php
    use App\Support\Fa;
    use App\Support\Jalali;
@endphp
{{-- 2g. Languages and scripts across the centuries --}}
@php $ls = $data['language_scripts']; @endphp
<x-ui.section title="زبان‌ها و خطوط در گذر سده‌ها" id="language-scripts">
    <x-ui.card>
        @if($ls['languages']['counted'] < $min && $ls['scripts']['counted'] < $min)
            @include('insights.thin', ['n' => max($ls['languages']['counted'], $ls['scripts']['counted']), 'what' => 'نسخۀ تاریخ‌دار با زبان یا خط'])
        @else
        <div x-data="{ by: '{{ $ls['languages']['counted'] >= $min && count($ls['languages']['rows']) > 1 ? 'languages' : 'scripts' }}' }">
            <div class="flex flex-wrap items-center gap-1.5 text-xs mb-4">
                @foreach(['languages' => 'زبان', 'scripts' => 'خط اصلی'] as $key => $name)
                    <button type="button" @click="by = '{{ $key }}'" :aria-pressed="(by === '{{ $key }}').toString()"
                            :class="by === '{{ $key }}' ? 'bg-cinnabar text-on-cinnabar border-cinnabar' : 'bg-surface text-ink border-line hover:border-cinnabar'"
                            class="px-2.5 py-0.5 rounded-full border transition cursor-pointer">{{ $name }}</button>
                @endforeach
            </div>
            <div x-show="by === 'languages'" @if(! ($ls['languages']['counted'] >= $min && count($ls['languages']['rows']) > 1)) x-cloak @endif>
                <p class="text-xs text-muted mb-3">نسخه‌های هر زبان در هر سده؛ زبان نسخه، زبان اثر آن است و اثر چندزبانه در هر زبانش شمرده شده است.</p>
                @include('insights.century-grid', ['grid' => $ls['languages'], 'label' => 'سهم زبان‌ها در نسخه‌های هر سده',
                    'href' => fn ($id, $c) => $search('manuscripts', ['language_id' => $id, 'century' => $c])])
            </div>
            <div x-show="by === 'scripts'" @if($ls['languages']['counted'] >= $min && count($ls['languages']['rows']) > 1) x-cloak @endif>
                <p class="text-xs text-muted mb-3">نسخه‌های هر خط در هر سده، بر پایۀ خط اصلی هر نسخه.</p>
                @include('insights.century-grid', ['grid' => $ls['scripts'], 'label' => 'سهم خطوط در نسخه‌های هر سده',
                    'href' => fn ($id, $c) => $search('manuscripts', ['script_id' => $id, 'century' => $c])])
            </div>
            <p class="mt-4 text-xs text-muted leading-relaxed">
                بر پایۀ نسخه‌هایی که تاریخ کتابتشان در یک سده است: {{ Fa::num($ls['languages']['counted']) }} نسخه با زبان و {{ Fa::num($ls['scripts']['counted']) }} نسخه با خط.
            </p>
            @include('insights.explain', ['note' => $notes['languageScripts'], 'tables' => $tables['languageScripts']])
        </div>
        @endif
    </x-ui.card>
</x-ui.section>

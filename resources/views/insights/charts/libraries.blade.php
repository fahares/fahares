@php
    use App\Support\Fa;
    use App\Support\Jalali;
@endphp
{{-- 2e. Scribes and libraries --}}
@php
    $sb = $data['scribes'];
    $lb = $data['libraries'];
    // Known years, or else the centuries of the copies dated by their century alone
    $years = fn (array $row) => $row['from'] !== null
        ? ($row['from'] === $row['to'] ? Fa::digits($row['from']) : Fa::digits($row['from']) . ' تا ' . Fa::digits($row['to']))
        : ($row['centuries'] ? ($row['centuries'][0] === $row['centuries'][1] ? 'سدۀ ' . Fa::digits($row['centuries'][0]) : 'سده‌های ' . Fa::digits($row['centuries'][0]) . ' تا ' . Fa::digits($row['centuries'][1])) : '');
@endphp
@if(! $scope->get('library') && count($lb['largest']) > 1)
<x-ui.section title="کتابخانه‌ها" id="libraries">
    <x-ui.card>
        <div x-data="{ by: 'largest' }">
        <div class="flex flex-wrap items-center gap-1.5 text-xs mb-3">
            @foreach(['largest' => 'بزرگ‌ترین گنجینه‌ها', 'oldest' => 'کهن‌ترین گنجینه‌ها'] as $key => $name)
                @if($key === 'largest' || $lb['oldest'])
                    <button type="button" @click="by = '{{ $key }}'" :aria-pressed="(by === '{{ $key }}').toString()"
                            :class="by === '{{ $key }}' ? 'bg-cinnabar text-on-cinnabar border-cinnabar' : 'bg-surface text-ink border-line hover:border-cinnabar'"
                            class="px-2.5 py-0.5 rounded-full border transition cursor-pointer">{{ $name }}</button>
                @endif
            @endforeach
        </div>
        @foreach(['largest', 'oldest'] as $list)
            @php $maxLib = max([1, ...array_column($lb['largest'], 'count')]); @endphp
            <ol class="text-sm" x-show="by === '{{ $list }}'" @if($list === 'oldest') x-cloak @endif>
                @foreach($lb[$list] as $row)
                    <li class="py-1.5 border-b border-line last:border-0">
                        <span class="flex items-baseline gap-2">
                            <a href="{{ route('libraries.show', $row['key']) }}" class="text-ink hover:text-cinnabar">{{ $row['name'] }}</a>
                            <span class="text-xs text-muted">{{ $row['city'] }}</span>
                            <span class="leader" aria-hidden="true"></span>
                            @if($list === 'oldest')
                                <span class="text-xs text-muted tabular-nums" title="میانگین سال کتابت {{ Fa::num($row['dated']) }} نسخۀ تاریخ‌دار{{ $row['century_only'] ? '، از جمله ' . Fa::num($row['century_only']) . ' نسخه که تنها سده یا بخشی از سده دارند' : '' }}">میانگین {{ Fa::digits($row['mean']) }}ق</span>
                            @else
                                <a href="{{ $search('manuscripts', ['library_id' => $row['id']]) }}" class="text-xs text-muted tabular-nums hover:text-cinnabar">{{ Fa::num($row['count']) }}</a>
                            @endif
                            <a href="{{ route('insights', ['library' => $row['id']] + $scope->query()) }}" class="text-[11px] text-cinnabar hover:underline" title="آمار نسخه‌های این کتابخانه{{ $scope->isEmpty() ? '' : ' در همین دامنه' }}">آمار</a>
                        </span>
                        @if($list === 'largest')
                            <span class="mt-1 block h-1 bg-line rounded-full overflow-hidden" aria-hidden="true">
                                <span class="block h-full bg-cinnabar/60 rounded-full" style="width: max(2px, {{ round($row['count'] * 100 / $maxLib, 2) }}%)"></span>
                            </span>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endforeach
        <p class="mt-4 text-xs text-muted leading-relaxed">
            {{ Fa::num($lb['count']) }} کتابخانه در این آمار نسخه دارند.
            «کهن‌ترین» بر پایۀ میانگین سال کتابت نسخه‌های تاریخ‌دار هر کتابخانه است و تنها کتابخانه‌هایی که دست‌کم {{ Fa::num($lb['min_dated']) }} نسخۀ تاریخ‌دار دارند در آن آمده‌اند.
            نسخه‌ای که تاریخش تا یک سده دامنه دارد («قرن ۱۱») با میانۀ بازه‌اش (۱۰۵۰) شمرده می‌شود. بازه‌های بازتر و تاریخ‌های باز («پیش از ۱۱۰۰») کنار می‌مانند.
        </p>
        @include('insights.explain', ['note' => $notes['libraries'], 'tables' => $tables['libraries']])
        </div>
    </x-ui.card>
</x-ui.section>
@endif

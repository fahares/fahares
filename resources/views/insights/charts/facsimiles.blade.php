@php
    use App\Support\Fa;
    use App\Support\Jalali;
@endphp
{{-- 2e2. Patrons and facsimile copies (step 14.117) --}}
@php
    $pt = $data['patrons'];
    $fx = $data['facsimiles'];
    $centuriesOf = fn (?array $c) => $c === null ? '' : ($c[0] === $c[1] ? 'سدۀ ' . Fa::digits($c[0]) : 'سده‌های ' . Fa::digits($c[0]) . ' تا ' . Fa::digits($c[1]));
    $ptCols = array_values(array_filter($pt['centuries'], fn ($c) => $c['copies'] >= config('insights.min_sample') * 10));
    $ptMax = max([0.1, ...array_column($ptCols, 'share')]);
    $showFacsimiles = $scope->get('facsimile') === null && $fx['count'] >= max(1, $min);
@endphp
@if($showFacsimiles)
<x-ui.section title="نسخه‌های عکسی" id="facsimiles">
    <x-ui.card>
        <p class="text-sm text-ink">
            <a href="{{ $search('manuscripts', ['facsimile' => '1']) }}" class="font-semibold tabular-nums hover:text-cinnabar">{{ Fa::num($fx['count']) }}</a>
            نسخه، یعنی {{ $pct($fx['share']) }} نسخه‌های این آمار، عکسی‌اند.
        </p>
        @if(count($fx['libraries']) > 1)
            <ol class="mt-3 text-sm">
                @foreach($fx['libraries'] as $row)
                    <li class="py-1.5 border-b border-line last:border-0 flex items-baseline gap-2">
                        <a href="{{ route('libraries.show', $row['key']) }}" class="text-ink hover:text-cinnabar">{{ $row['name'] }}</a>
                        <span class="text-xs text-muted">{{ $row['city'] }}</span>
                        <span class="leader" aria-hidden="true"></span>
                        <span class="text-[11px] text-muted tabular-nums" data-tip="سهم عکسی‌ها در نسخه‌های این کتابخانه">{{ $pct($row['share']) }}</span>
                        <a href="{{ $search('manuscripts', ['facsimile' => '1', 'library_id' => $row['id']]) }}" class="text-xs text-muted tabular-nums hover:text-cinnabar">{{ Fa::num($row['count']) }}</a>
                    </li>
                @endforeach
            </ol>
        @endif
        <p class="mt-4 flex flex-wrap gap-x-4 gap-y-1 text-xs">
            <a href="{{ route('insights', $scope->query() + ['facsimile' => '0']) }}" class="text-cinnabar hover:underline">آمار بی نسخه‌های عکسی</a>
            <a href="{{ route('insights', $scope->query() + ['facsimile' => '1']) }}" class="text-cinnabar hover:underline">آمار تنها نسخه‌های عکسی</a>
        </p>
    </x-ui.card>
    <p class="mt-3 text-xs text-muted leading-relaxed">
        نسخۀ عکسی، عکس نسخه‌ای است که در کتابخانۀ دیگری نگه داشته می‌شود، و تاریخ و محل کتابتش همان نسخۀ اصل است.
        پس نمودارهای این صفحه آن نسخه را، اگر اصلش هم در فهارس باشد، دو بار می‌شمارند. «آمار بی نسخه‌های عکسی» این دوباره‌شماری را کنار می‌گذارد.
    </p>
    @include('insights.explain', ['note' => $notes['facsimiles'], 'tables' => $tables['facsimiles']])
</x-ui.section>
@endif

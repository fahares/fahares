@php
    use App\Support\Fa;
    use App\Support\Jalali;
@endphp
{{-- 5b. The catalogues that describe one copy (backlog item 146) --}}
@php $ov = $data['source_overlap']; @endphp
@if($ov['described'] >= max(1, $min))
<x-ui.section title="هر نسخه در چند فهرست وصف شده" id="source-overlap">
    <x-ui.card>
        @include('insights.small', ['n' => $ov['described'], 'what' => 'نسخه با فهرست کتابخانه'])
        @php $maxOverlap = max([1, ...$ov['counts']]); @endphp
        <div class="grid lg:grid-cols-2 gap-8">
            <div>
                <p class="text-xs text-muted mb-3">شمار فهرست‌های کتابخانه‌ای که هر نسخه را وصف کرده‌اند، جدا از فنخا که همۀ نسخه‌ها را دارد.</p>
                <ul class="space-y-2.5 text-sm">
                    @foreach(['تنها در فنخا', 'یک فهرست', 'دو فهرست', 'سه فهرست و بیشتر'] as $k => $name)
                        @continue($ov['counts'][$k] === 0)
                        <li>
                            <span class="flex items-baseline gap-2">
                                <span class="text-ink">{{ $name }}</span>
                                <span class="leader" aria-hidden="true"></span>
                                <span class="text-xs text-muted tabular-nums">{{ Fa::num($ov['counts'][$k]) }} · {{ $pct(round($ov['counts'][$k] * 100 / max(1, $data['totals']['manuscripts']), 1)) }}</span>
                            </span>
                            <span class="mt-1 block h-1.5 bg-line rounded-full overflow-hidden" aria-hidden="true">
                                <span class="block h-full {{ $k === 0 ? 'bg-muted/60' : 'bg-gold/80' }} rounded-full" style="width: max(2px, {{ round($ov['counts'][$k] * 100 / $maxOverlap, 2) }}%)"></span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
            <div>
                @if($ov['pairs'])
                    <p class="text-xs text-muted mb-3">فهرست‌هایی که بیشترین نسخۀ مشترک را دارند.</p>
                    <ol class="space-y-2.5 text-sm">
                        @foreach($ov['pairs'] as $pair)
                            <li class="flex items-baseline gap-2">
                                <span class="min-w-0">
                                    @foreach($pair['sources'] as $source)
                                        <a href="{{ route('sources.show', $source['key']) }}" class="text-ink hover:text-cinnabar transition">{{ $source['title'] }}</a>@if(! $loop->last) <span class="text-muted">و</span> @endif
                                    @endforeach
                                </span>
                                <span class="leader" aria-hidden="true"></span>
                                <span class="text-xs text-muted tabular-nums">{{ Fa::num($pair['count']) }}</span>
                            </li>
                        @endforeach
                    </ol>
                @else
                    <p class="text-sm text-muted">در این دامنه نسخه‌ای نیست که دو فهرست کتابخانه وصفش کرده باشند.</p>
                @endif
            </div>
        </div>
        <p class="mt-5 text-xs text-muted leading-relaxed">
            {{ Fa::num($ov['several']) }} نسخه در بیش از یک فهرست کتابخانه وصف شده‌اند: بیشتر، فهرست کهنه و فهرست تازۀ یک کتابخانه، یا فهرست نسخه‌های اصل و فهرست عکس‌ها و میکروفیلم‌هایشان.
            فهرستی که هنوز به فهارس افزوده نشده در این شمارش نیست.
        </p>
        @include('insights.explain', ['note' => $notes['sourceOverlap'], 'tables' => $tables['sourceOverlap']])
    </x-ui.card>
</x-ui.section>
@endif

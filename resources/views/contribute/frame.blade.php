{{-- Around every card of «تصحیح جمعی» (backlog item 80): where the researcher is, how much is left, their share, and
     the narrowing to their field. The section's title comes from config/contributions.php. --}}
@use('App\Support\Fa')
@php
    $select = 'w-full p-2 rounded-sm border border-line-strong bg-paper text-ink text-xs sm:text-sm';
@endphp
<header class="space-y-2">
    <p class="text-xs text-muted m-0">
        <a href="{{ route('contributions.index') }}" class="hover:text-cinnabar">{{ config('contributions.title') }}</a>
        <span aria-hidden="true">›</span>
        <a href="{{ route('contribute.index') }}" class="hover:text-cinnabar">{{ config('contributions.review_title') }}</a>
    </p>
    <div class="flex flex-wrap items-end justify-between gap-x-4 gap-y-1">
        <h1 class="font-naskh text-xl sm:text-2xl font-bold text-ink leading-relaxed m-0">{{ $queue->title() }}</h1>
        <p class="text-xs text-muted m-0 tabular-nums">
            <b class="text-cinnabar">{{ Fa::num($waiting) }}</b> مورد مانده
            @if($mine)
                · پاسخ‌های شما: <b class="text-ink">{{ Fa::num($mine['answers']) }}</b>@if($mine['approved']) ({{ Fa::num($mine['approved']) }} پذیرفته)@endif
            @endif
        </p>
    </div>
</header>

@if($filterOptions)
    <details class="group bg-surface border border-line rounded-sm" @if($filters) open @endif>
        <summary class="cursor-pointer list-none px-3 py-2 text-xs sm:text-sm text-ink flex items-center justify-between gap-2">
            <span>حوزه‌ی خود را برگزینید @if($filters)<span class="text-cinnabar">({{ Fa::num(count($filters)) }} فیلتر)</span>@endif</span>
            <svg class="w-4 h-4 text-muted group-open:rotate-180 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
        </summary>
        <form method="GET" action="{{ route('contribute.next', $queue->key()) }}" class="grid grid-cols-1 sm:grid-cols-4 gap-2 items-end px-3 pb-3">
            @foreach($filterOptions as $name => $filter)
                <label class="space-y-1">
                    <span class="block text-[11px] text-muted">{{ $filter['label'] }}</span>
                    <select name="{{ $name }}" class="{{ $select }}">
                        <option value="">همه</option>
                        @foreach($filter['options'] as $value => $label)
                            <option value="{{ $value }}" @selected(($filters[$name] ?? null) === (string) $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            @endforeach
            <div class="flex gap-2">
                <x-ui.btn type="submit" variant="primary" class="flex-1">نمایش</x-ui.btn>
                @if($filters)<x-ui.btn :href="route('contribute.next', $queue->key())" variant="ghost">همه</x-ui.btn>@endif
            </div>
        </form>
    </details>
@endif

@if(session('info'))
    <p class="m-0 px-4 py-3 bg-surface border border-line border-s-4 border-s-gold text-sm text-ink leading-7">{{ session('info') }}</p>
@endif

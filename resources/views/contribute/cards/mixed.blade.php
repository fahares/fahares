{{-- One record whose copies span more than a lifetime (MixedRecordQueue::card): the copies in date order, the scribe's
     name as each catalogue wrote it. The boxes join the answer form for «برخی از نفر دیگری است». --}}
@use('App\Support\Fa')
@php $p = $profile['person'] ?? null; $marked = array_map('intval', (array) old('copies', $previous?->payload['manuscript_ids'] ?? [])); @endphp
<div class="space-y-1">
    <h2 class="font-naskh text-lg sm:text-xl font-bold text-ink leading-relaxed m-0">آیا همه‌ی این نسخه‌ها را یک نفر نوشته است؟</h2>
    <p class="text-xs sm:text-sm text-muted leading-6 m-0">
        این نسخه‌ها به نام یک کاتب ثبت شده‌اند، ولی تاریخ کتابتشان
        @if($profile['span'] ?? null) از {{ Fa::digits((string) $profile['span'][0]) }} تا {{ Fa::digits((string) $profile['span'][1]) }} ق @endif
        است؛ بیش از عمر یک نفر. شاید دو کاتب هم‌نام در یک رکورد آمده‌اند.
    </p>
</div>

@if($p)
    <p class="m-0">
        <a href="{{ route('people.show', $p) }}" target="_blank" class="font-bold text-ink hover:text-cinnabar">{{ $p->name }}</a>
        <span class="text-xs text-muted">· {{ Fa::num($p->manuscripts_count) }} نسخه@if($p->death_year_hijri) · درگذشت {{ Fa::digits((string) $p->death_year_hijri) }} ق@endif</span>
    </p>
@endif

<ol class="m-0 p-0 list-none border border-line divide-y divide-line max-h-[28rem] overflow-y-auto text-xs sm:text-sm">
    @foreach($copies as $c)
        <li class="flex items-start gap-2 px-3 py-2">
            <input type="checkbox" form="review-answer" name="copies[]" value="{{ $c->id }}" id="copy-{{ $c->id }}" x-show="answer === 'split'" x-cloak
                   @checked(in_array((int) $c->id, $marked, true)) class="mt-1 rounded border-line-strong text-cinnabar focus:ring-cinnabar">
            <label for="copy-{{ $c->id }}" class="w-14 shrink-0 tabular-nums font-bold text-ink">{{ $c->year ? Fa::digits((string) $c->year) . ' ق' : '—' }}</label>
            <span class="min-w-0">
                <a href="{{ route('manuscripts.permalink', $c->id) }}" target="_blank" class="text-ink hover:text-cinnabar">{{ $c->title }}</a>
                <span class="block text-[11px] text-muted">{{ implode('، ', array_filter([$c->city, $c->library, $c->shelfmark ? Fa::digits($c->shelfmark) : null])) }}@if($c->scribe_name) · در فهرست: {{ $c->scribe_name }}@endif</span>
            </span>
        </li>
    @endforeach
</ol>

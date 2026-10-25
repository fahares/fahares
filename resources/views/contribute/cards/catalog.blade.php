{{-- One machine-matched catalogue page (CatalogMatchQueue::card): the copy on one side, the page of the printed
     catalogue on the other, and the question between them. --}}
@use('App\Support\Fa')
<div class="space-y-1">
    <h2 class="font-naskh text-lg sm:text-xl font-bold text-ink leading-relaxed m-0">آیا این نسخه همان است که در این برگه‌ی فهرست وصف شده؟</h2>
    <p class="text-xs sm:text-sm text-muted leading-6 m-0">
        @if(! empty($facts['شماره‌ی نسخه']))
            در برگه دنبال شماره‌ی <b class="text-ink">{{ $facts['شماره‌ی نسخه'] }}</b> بگردید و عنوان، آغاز و انجام، کاتب و تاریخ را با این نسخه بسنجید.
        @else
            برگه را بخوانید و عنوان، آغاز و انجام، کاتب و تاریخ را با این نسخه بسنجید.
        @endif
    </p>
</div>

<div class="grid gap-4 sm:grid-cols-2">
    <section class="space-y-2 min-w-0">
        <h3 class="text-[11px] font-bold text-muted m-0">نسخه در فهارس</h3>
        <p class="m-0 font-bold text-ink leading-7 break-words">
            @if($copy)
                <a href="{{ route('manuscripts.show', $copy) }}" target="_blank" class="hover:text-cinnabar">{{ $work?->primary_title ?? 'نسخه' }}</a>
            @endif
        </p>
        @if($work?->author_name)
            <p class="m-0 text-xs sm:text-sm text-muted">{{ $work->author_name }}</p>
        @endif
        <dl class="m-0 grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1 text-xs sm:text-sm">
            @foreach($facts as $label => $value)
                <dt class="text-muted">{{ $label }}</dt>
                <dd class="m-0 text-ink break-words">{{ $value }}</dd>
            @endforeach
        </dl>
        @foreach($quotes as $label => $quote)
            <div class="text-xs sm:text-sm">
                <span class="text-muted">{{ $label }}:</span>
                <span class="font-naskh text-ink leading-8 break-words">{{ \Illuminate\Support\Str::limit($quote, 400) }}</span>
            </div>
        @endforeach
    </section>

    <section class="space-y-2 min-w-0">
        <h3 class="text-[11px] font-bold text-muted m-0">{{ $catalog }}، {{ $place }}</h3>
        @if($viewer && $image)
            <button type="button" @click="$dispatch('open-catalog-viewer', {{ \Illuminate\Support\Js::from($viewer) }})" class="block w-full group cursor-pointer text-right">
                <span class="block aspect-[3/4] max-h-[60vh] overflow-hidden border border-line bg-white">
                    <img src="{{ $image }}" alt="برگه‌ی {{ $place }} از {{ $catalog }}" class="w-full h-full object-cover object-top group-hover:opacity-90 transition"
                         onerror="this.closest('button').outerHTML = '<p class=&quot;text-xs text-muted&quot;>تصویر این برگه در دسترس نیست.</p>'">
                </span>
                <span class="block mt-1.5 text-xs font-semibold text-cinnabar">بزرگ‌نمایی برگه</span>
            </button>
        @else
            <p class="text-xs text-muted m-0">تصویر این برگه در دسترس نیست.</p>
        @endif
    </section>
</div>

@if($text)
    <details class="bg-paper border border-line rounded-sm">
        <summary class="cursor-pointer px-3 py-2 text-xs font-semibold text-ink">متن این برگه (خوانده‌شده با ماشین)</summary>
        <div class="px-3 pb-3 text-sm text-ink/90 leading-8 whitespace-pre-line break-words">{{ \Illuminate\Support\Str::limit($text, 2500) }}</div>
    </details>
@endif

@props(['manuscript'])
{{-- The other treatises bound in the same volume, found from the shelfmark («6415/8»); see Manuscript::collectionKey --}}
@php $siblings = $manuscript->collectionSiblings(); @endphp
@if($siblings->count() > 1)
    @php
        $key = \App\Models\Manuscript::collectionKey($manuscript->shelfmark);
        // In some libraries the part number is a volume of one multi-volume work («13/1» to «13/5»: وسائل الشیعة)
        $volumes = $siblings->pluck('work_id')->unique()->count() === 1;
        $number = $key['base'] . ($key['collection'] ? '-' . $key['collection'] : '');
    @endphp
    <div x-data="{ all: false }" class="bg-white dark:bg-[#15192C] rounded-3xl p-5 sm:p-6 border border-[#EADFCF] dark:border-[#272F4C] shadow-sm space-y-3">
        <div>
            <h3 class="text-sm font-bold text-[#292C56] dark:text-amber-200 border-r-2 border-[#B38A50] pr-2">{{ $volumes ? 'مجلدهای این دوره' : 'رساله‌های این مجموعه' }} ({{ number_format($siblings->count()) }})</h3>
            <p class="mt-1 text-[11px] text-stone-500 dark:text-stone-400">
                {{ $volumes ? "مجلدهای همین اثر در همین کتابخانه که زیر شماره‌ی {$number} آمده‌اند." : "نسخه‌هایی از همین کتابخانه که زیر شماره‌ی {$number} آمده‌اند و در یک مجلد صحافی شده‌اند." }}
            </p>
        </div>
        <ol class="divide-y divide-stone-100 dark:divide-stone-800 text-xs">
            @foreach($siblings as $i => $m)
                @php $current = $m->is($manuscript); @endphp
                <li @if($i >= 12) x-show="all" x-cloak @endif class="py-2 flex items-baseline gap-3 {{ $current ? 'font-bold' : '' }}">
                    <span class="shrink-0 w-14 font-mono text-stone-400" dir="ltr">{{ $m->shelfmark }}</span>
                    <span class="flex-1 min-w-0">
                        @if($current)
                            <span class="text-stone-900 dark:text-stone-100">{{ $m->work?->primary_title ?? 'نسخه' }}</span>
                            <span class="text-[10px] text-emerald-700 dark:text-emerald-400">(همین نسخه)</span>
                        @else
                            <a href="{{ route('manuscripts.show', $m) }}" class="text-[#B38A50] hover:underline">{{ $m->work?->primary_title ?? 'نسخه' }}</a>
                        @endif
                        @if($m->work?->author_name)<span class="text-[11px] text-stone-500 dark:text-stone-400"> · {{ $m->work->author_name }}</span>@endif
                    </span>
                    @if($m->folios)<span class="shrink-0 text-[11px] text-stone-400">{{ $m->folios }} برگ</span>@endif
                </li>
            @endforeach
        </ol>
        @if($siblings->count() > 12)
            <button type="button" x-show="!all" @click="all = true" class="text-[11px] font-bold text-[#B38A50] hover:underline cursor-pointer">نمایش همه‌ی {{ number_format($siblings->count()) }} {{ $volumes ? 'مجلد' : 'رساله' }}</button>
        @endif
    </div>
@endif

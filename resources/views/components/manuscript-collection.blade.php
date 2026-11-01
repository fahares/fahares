@props(['manuscript'])
{{-- The other treatises bound in the same volume, found from the shelfmark («6415/8») and in the order of their leaves; see Manuscript::collectionKey and collectionSiblings --}}
@php $siblings = $manuscript->collectionSiblings(); @endphp
@if($siblings->count() > 1)
    @php
        // In some libraries the part number is a volume of one multi-volume work («13/1» to «13/5»: وسائل الشیعة)
        $volumes = $siblings->pluck('work_id')->unique()->count() === 1;
        $number = $manuscript->collection_key ?? \App\Models\Manuscript::collectionKeyOf($manuscript->shelfmark);
    @endphp
    <x-ui.section x-data="{ all: false }" :title="$volumes ? 'مجلدهای این دوره' : 'رساله‌های این مجموعه'" :count="$siblings->count()">
        <div class="mb-2">
            <p class="text-xs text-muted">
                @if($volumes)
                    مجلدهای همین اثر در همین کتابخانه که زیر شماره‌ی {{ $number }} آمده‌اند.
                @else
                    این نسخه در مجموعه‌ی شماره‌ی <span dir="ltr">{{ \App\Support\Fa::digits($number) }}</span>{{ $manuscript->rangeLabel() ? '، ' . $manuscript->rangeLabel() : '' }}، همراه با این رساله‌ها{{ $siblings->contains(fn ($m) => $m->range_from) ? ' (به ترتیب برگ)' : '' }} صحافی شده است.
                @endif
            </p>
        </div>
        <ol class="divide-y divide-line text-sm border-y border-line">
            @foreach($siblings as $i => $m)
                @php $current = $m->is($manuscript); @endphp
                <li @if($i >= 12) x-show="all" x-cloak @endif class="py-2 flex items-baseline gap-3 {{ $current ? 'font-bold' : '' }}">
                    <span class="shrink-0 w-16 text-muted tabular-nums" dir="ltr">{{ \App\Support\Fa::digits($m->shelfmark) }}</span>
                    <span class="flex-1 min-w-0">
                        @if($current)
                            <span class="text-ink">{{ $m->work?->primary_title ?? 'نسخه' }}</span>
                            <span class="text-[10px] text-emerald-700 dark:text-emerald-400">(همین نسخه)</span>
                        @else
                            <a href="{{ route('manuscripts.show', $m) }}" class="text-ink hover:text-cinnabar transition">{{ $m->work?->primary_title ?? 'نسخه' }}</a>
                        @endif
                        @if($m->work?->author_name)<span class="text-[11px] text-muted"> · {{ $m->work->author_name }}</span>@endif
                    </span>
                    @if($m->extentLabel())<span class="shrink-0 text-[11px] text-muted">{{ $m->extentLabel() }}</span>@endif
                </li>
            @endforeach
        </ol>
        @if($siblings->count() > 12)
            <button type="button" x-show="!all" @click="all = true" class="text-[11px] font-bold text-cinnabar hover:underline cursor-pointer">نمایش همه‌ی {{ \App\Support\Fa::num($siblings->count()) }} {{ $volumes ? 'مجلد' : 'رساله' }}</button>
        @endif
    </x-ui.section>
@endif

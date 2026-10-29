@extends('layouts.admin')

@php
    use App\Suggestions\SuggestionRegistry;
    $fa = fn ($v) => \App\Support\Fa::digits($v);
    $described = SuggestionRegistry::describe($record);
    $setup = [
        'relations' => $choices,
        'relationKey' => request('relation', ''),
        'targetQuery' => request('target', ''),
        'lookupUrl' => route('suggestions.lookup'),
        'type' => $type,
        'id' => $record->id,
    ];
    $input = 'admin-field w-full';
@endphp

@section('title', 'ابزارهای ساختاری: ' . $described['label'])
@section('page_title', 'ابزارهای ساختاری')
@section('breadcrumb')
    <a href="{{ route('admin.structure.index') }}" class="hover:text-cinnabar">ابزارهای ساختاری</a>
    <span>/</span>
    <span>{{ SuggestionRegistry::typeLabel($type) }} #{{ $fa($record->id) }}</span>
@endsection
@section('page_actions')
    <x-ui.btn :href="$described['url']" target="_blank" size="sm">صفحه در سایت ↗</x-ui.btn>
@endsection

@section('content')
<div class="max-w-4xl space-y-5 text-[13px]">
    <x-ui.card class="space-y-1">
        <p class="text-xs text-muted">{{ SuggestionRegistry::typeLabel($type) }} #{{ $fa($record->id) }}</p>
        <h2 class="font-naskh text-lg font-bold text-ink">{{ $described['label'] }}</h2>
        @if($described['sub'])<p class="text-muted">{{ $described['sub'] }}</p>@endif
    </x-ui.card>

    {{-- 1. The tool and what it needs --}}
    <x-ui.card class="space-y-4">
        <h3 class="rubric text-base">۱. ابزار</h3>
        @if(! $choices)
            <x-admin.notice tone="warn">برای این نوع رکورد ابزاری که نقش شما اجازه‌اش را داشته باشد، تعریف نشده است.</x-admin.notice>
        @else
            <form action="{{ route('admin.structure.show', [$type, $record->id]) }}" method="GET" class="space-y-4" x-data="structureDesk(@js($setup))">
                @if($draftErrors)
                    <x-admin.notice tone="error">{{ collect($draftErrors)->flatten()->first() }}</x-admin.notice>
                @endif
                <div class="space-y-1.5">
                    @foreach($choices as $choice)
                        <label class="flex items-start gap-2"><input type="radio" name="relation" value="{{ $choice['key'] }}" x-model="relationKey" class="mt-1 accent-cinnabar">
                            <span><b class="text-ink">{{ $choice['action'] }}</b> <span class="text-muted">— {{ $choice['label'] }}</span></span></label>
                    @endforeach
                </div>

                <template x-if="relation && relation.options">
                    <label class="block space-y-1">
                        <span class="block font-bold text-ink" x-text="relation.apply === 'person_role' ? 'نقش:' : 'نوع رابطه‌ی دو رکورد:'"></span>
                        <select name="role" class="{{ $input }}">
                            <option value="">انتخاب کنید…</option>
                            <template x-for="(label, key) in relation.options" :key="key"><option :value="key" x-text="label" :selected="key === @js(request('role'))"></option></template>
                        </select>
                    </label>
                </template>
                <template x-if="relation && relation.target">
                    <div class="space-y-1 relative">
                        <span class="block font-bold text-ink"
                            x-text="relation.apply === 'relocate' ? 'کتابخانه‌ی تازه (اگر عوض شده است):' : (relation.target_optional ? 'شخص (اگر در فهارس ثبت شده است):' : ({work: 'اثر مقصد:', person: 'شخص مقصد:', library: 'کتابخانه‌ی مقصد:', manuscript: 'نسخه‌ی مقصد:'})[relation.target])"></span>
                        <input type="text" name="target" x-model="targetQuery" @input="searchTargets()" autocomplete="off" placeholder="پیوند یا شناسه‌ی فهارس، یا جستجوی عنوان و نام" class="{{ $input }}">
                        <ul x-show="targetResults.length" class="absolute z-10 inset-x-0 mt-1 max-h-56 overflow-y-auto rounded-sm border border-line-strong bg-surface shadow-lg">
                            <template x-for="r in targetResults" :key="r.id">
                                <li>
                                    <button type="button" @click="pickTarget(r)" class="w-full text-right px-3 py-2 hover:bg-paper">
                                        <span class="block font-bold text-ink" x-text="r.label"></span>
                                        <span class="block text-muted" x-text="'#' + r.id + (r.sub ? ' · ' + r.sub : '')"></span>
                                    </button>
                                </li>
                            </template>
                        </ul>
                        <template x-if="target">
                            <div class="px-3 py-2 bg-surface border border-s-4 border-emerald-600/40 text-emerald-800 dark:text-emerald-300">
                                ✓ <a :href="target.url" target="_blank" class="font-bold hover:underline" x-text="target.label"></a> <span x-text="'#' + target.id"></span>
                            </div>
                        </template>
                    </div>
                </template>
                <template x-if="relation && relation.apply === 'person_role'">
                    <label class="block space-y-1">
                        <span class="block font-bold text-ink">یا نام شخص، همان‌گونه که در نسخه آمده:</span>
                        <input type="text" name="role_name" value="{{ request('role_name') }}" class="{{ $input }}">
                    </label>
                </template>
                <template x-if="relation && relation.apply === 'relocate'">
                    <label class="block space-y-1">
                        <span class="block font-bold text-ink">شماره‌ی تازه‌ی نسخه (اگر عوض شده است):</span>
                        <input type="text" name="shelfmark" value="{{ request('shelfmark') }}" class="{{ $input }}">
                    </label>
                </template>
                <template x-if="relation">
                    <label class="block space-y-1">
                        <span class="block font-bold text-ink" x-text="relation.needs_text ? ({rename_library: 'نام تازه‌ی کتابخانه:', split: 'چه چیزی از رکورد دیگری است:', alias: 'نام دیگر:'})[relation.apply] : 'توضیح (اختیاری؛ در تاریخچه‌ی اصلاحات می‌آید):'"></span>
                        <textarea name="suggested_value" rows="2" class="{{ $input }}">{{ request('suggested_value') }}</textarea>
                    </label>
                </template>

                <x-ui.btn type="submit" x-bind:disabled="!relation">پیش‌نمایش تغییر</x-ui.btn>
            </form>
        @endif
    </x-ui.card>

    {{-- 2. What it would change, and the approval form of the tool itself --}}
    @if($preview)
        <x-ui.card class="space-y-4 !border-amber-600/50">
            <h3 class="rubric text-base">۲. پیش‌نمایش: {{ $draft['relation']['action'] }}</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                @foreach($preview['records'] as $i => $side)
                    <div class="p-3 bg-paper border border-line space-y-1">
                        <p class="text-xs text-muted">{{ $i === 0 ? 'این رکورد' : 'رکورد مقصد' }} · #{{ $fa($side['record']['id']) }}</p>
                        <a href="{{ $side['record']['url'] }}" target="_blank" class="block font-bold text-ink hover:text-cinnabar">{{ $side['record']['label'] }}</a>
                        @if($side['record']['sub'])<p class="text-muted">{{ $side['record']['sub'] }}</p>@endif
                        <p class="text-ink">
                            @forelse($side['counts'] as $label => $count)
                                <span class="inline-block me-3"><b class="tabular-nums">{{ \App\Support\Fa::num($count) }}</b> {{ $label }}</span>
                            @empty
                                <span class="text-muted">بی‌وابسته</span>
                            @endforelse
                        </p>
                    </div>
                @endforeach
            </div>
            @foreach($preview['warnings'] as $warning)
                <x-admin.notice tone="error">{{ $warning }}</x-admin.notice>
            @endforeach
            @if($preview['lines'])
                <ul class="list-disc ps-5 space-y-1 text-ink leading-7">
                    @foreach($preview['lines'] as $line)<li>{{ $line }}</li>@endforeach
                </ul>
            @endif
            <x-admin.notice tone="warn">با اجرا، این اقدام به نام شما و با برچسب «ویراستار فهارس» در تاریخچه‌ی اصلاحات ثبت می‌شود و از صفحه‌ی آن یا از لاگ ممیزی بازگرداندنی است.</x-admin.notice>

            @include('admin.suggestions.partials.relation-approve', [
                'suggestion' => $draft['suggestion'],
                'relation' => $draft['relation'],
                'target' => $record,
                'relationTarget' => $draft['other'],
                'formAction' => route('admin.structure.apply', [$type, $record->id]),
                'directFields' => array_filter(request()->only(['relation', 'target', 'suggested_value', 'role', 'role_name', 'shelfmark']), fn ($v) => $v !== null && $v !== ''),
            ])
        </x-ui.card>
    @endif
</div>
@endsection

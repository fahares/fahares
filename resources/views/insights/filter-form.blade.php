{{--
    The filter form of the insights page (backlog item 107): one look-up box per filter (insightsCombo) and a span of
    centuries, sent as the query string (`/insights?author=1110&century=5-8`) so a scope can be linked to. $scope.
--}}
@php
    use App\Support\Fa;

    $fields = [
        'author' => ['مؤلف', 'نام مؤلف'],
        'work' => ['اثر', 'عنوان اثر'],
        'scribe' => ['کاتب', 'نام کاتب'],
        'subject' => ['موضوع', 'فقه، کلام …'],
        'language' => ['زبان', 'عربی، فارسی …'],
        'library' => ['کتابخانه', 'نام کتابخانه یا شهر'],
        'city' => ['شهر کتابخانه', 'شهر امروزی نسخه'],
        'copy_city' => ['محل کتابت', 'شهر کتابت'],
        'script' => ['خط', 'نسخ، نستعلیق …'],
        'catalog' => ['فهرست (منبع)', 'عنوان فهرست'],
    ];
    $labelOf = function (string $field) use ($scope) {
        $r = $scope->records[$field] ?? null;

        return match (true) {
            $r === null => (string) ($scope->get($field) ?? ''),
            $field === 'work' => $r->primary_title,
            $field === 'catalog' => $r->title,
            default => $r->name,
        };
    };
    [$c0, $c1] = $scope->get('century') ?? ['', ''];
@endphp
{{-- Closed at first: on a scoped page the chips above name the filters and remove them --}}
<details class="group border border-line bg-surface">
    <summary class="flex items-center justify-between gap-3 px-4 py-3 cursor-pointer select-none text-sm list-none [&::-webkit-details-marker]:hidden">
        @if($scope->isEmpty())
            <span class="font-semibold text-ink">محدود کردن آمار <span class="font-normal text-muted text-xs">به یک مؤلف، کاتب، کتابخانه، موضوع، سده …</span></span>
        @else
            <span class="font-semibold text-ink">تغییر یا افزودن فیلتر</span>
        @endif
        <span class="text-cinnabar text-xs group-open:rotate-180 transition" aria-hidden="true">▾</span>
    </summary>
    <form method="GET" action="{{ route('insights') }}" class="px-4 pb-4 pt-1 border-t border-line">
        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-x-4 gap-y-3 mt-3">
            @foreach($fields as $field => [$name, $hint])
                <div x-data="insightsCombo({ field: @js($field), value: @js($scope->get($field)), label: @js($labelOf($field)), url: @js(route('insights.lookup')) })"
                     class="relative" @keydown.escape="open = false">
                    <label for="insights-{{ $field }}" class="block text-xs text-muted mb-1">{{ $name }}</label>
                    <input type="hidden" name="{{ $field }}" :value="value" :disabled="! value" value="{{ $scope->get($field) }}" @disabled($scope->get($field) === null)>
                    <div class="relative">
                        <input id="insights-{{ $field }}" type="text" x-model="text" @input="typed()" @focus="focus()" @blur="leave()"
                               @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.enter="enter($event)"
                               value="{{ $labelOf($field) }}" placeholder="{{ $hint }}" autocomplete="off"
                               role="combobox" :aria-expanded="open.toString()" aria-autocomplete="list" aria-controls="insights-{{ $field }}-list"
                               :class="value ? 'border-cinnabar' : 'border-line-strong'"
                               class="w-full bg-paper border px-2.5 py-1.5 pe-7 text-sm text-ink placeholder:text-muted/70 focus:outline-none focus:border-cinnabar">
                        <button type="button" x-show="text" x-cloak @click="clear()" class="absolute inset-y-0 left-0 px-2 text-muted hover:text-cinnabar" aria-label="پاک کردن {{ $name }}">×</button>
                    </div>
                    <ul id="insights-{{ $field }}-list" x-show="open" x-cloak role="listbox"
                        class="absolute z-20 inset-x-0 mt-1 max-h-64 overflow-y-auto bg-surface border border-line-strong shadow-md text-sm">
                        <template x-for="(item, i) in items" :key="item.v">
                            <li role="option" :aria-selected="(i === active).toString()" @mousedown.prevent="pick(item)" @mouseenter="active = i"
                                :class="i === active ? 'bg-paper text-cinnabar' : 'text-ink'" class="px-2.5 py-1.5 cursor-pointer">
                                <span x-text="item.label"></span>
                                <span x-show="item.note" class="block text-[11px] text-muted" x-text="item.note"></span>
                            </li>
                        </template>
                        <li x-show="! items.length" class="px-2.5 py-1.5 text-xs text-muted"
                            x-text="loading ? 'در جست‌وجو…' : (text.trim().length < 2 && ['author', 'scribe', 'work'].includes(field) ? 'دست‌کم دو حرف بنویسید' : 'چیزی پیدا نشد')"></li>
                    </ul>
                </div>
            @endforeach

            <div x-data="{ from: @js((string) $c0), to: @js((string) $c1), get span() { const a = this.from || this.to, b = this.to || this.from; return a ? (a === b ? a : `${Math.min(a, b)}-${Math.max(a, b)}`) : '' } }">
                <span class="block text-xs text-muted mb-1">سده‌ی کتابت</span>
                <input type="hidden" name="century" :value="span" :disabled="! span" value="{{ $scope->query()['century'] ?? '' }}" @disabled(! $scope->get('century'))>
                <div class="flex items-center gap-2">
                    @foreach(['from' => 'از سده', 'to' => 'تا سده'] as $end => $endName)
                        <select x-model="{{ $end }}" aria-label="{{ $endName }}" class="flex-1 bg-paper border border-line-strong px-2 py-1.5 text-sm text-ink focus:outline-none focus:border-cinnabar">
                            <option value="">{{ $endName }}</option>
                            @foreach(range(1, 15) as $c)
                                <option value="{{ $c }}" @selected(($end === 'from' ? $c0 : $c1) === $c)>{{ Fa::digits($c) }}</option>
                            @endforeach
                        </select>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="mt-4 flex flex-wrap items-center gap-3">
            <button type="submit" class="bg-cinnabar text-on-cinnabar px-4 py-1.5 text-sm font-semibold hover:brightness-110 transition cursor-pointer">نمایش آمار</button>
            @unless($scope->isEmpty())
                <a href="{{ route('insights') }}" class="text-xs text-muted hover:text-cinnabar">پاک کردن همه و بازگشت به آمار کل</a>
            @endunless
            <p class="text-[11px] text-muted basis-full sm:basis-auto sm:ms-auto">فیلترها با هم می‌آیند؛ نشانی صفحه را می‌شود نگه داشت یا فرستاد.</p>
        </div>
    </form>
</details>

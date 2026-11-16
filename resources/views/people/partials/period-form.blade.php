{{-- The form of one period of a person's life (item 139, step 14.218): $action, $period (null for a new one); for the approval of a
     suggested period (step 14.219) also $defaults (kind, note, source_text), $submitLabel and $formKey --}}
@php
    use App\Models\PersonPeriod;
    $input = $period ? $period->dateInput() : ['mode' => 'span', 'start' => null, 'start_to' => null, 'end' => null, 'end_to' => null];
    $defaults ??= [];
    $key = $formKey ?? ($period ? 'p' . $period->id : 'new');
    // old() belongs to the form that was sent
    $mine = old('period_form') === $key;
    $val = fn (string $name, $default) => $mine ? old($name) : $default;
@endphp
<form method="POST" action="{{ $action }}" class="grid gap-3 sm:grid-cols-2 text-sm bg-surface border border-line p-3" x-data="{ mode: '{{ $val('mode', $input['mode']) }}' }">
    @csrf
    @if($period) @method('PUT') @endif
    <input type="hidden" name="period_form" value="{{ $key }}">
    <label class="grid gap-1"><span class="text-xs text-muted">نوع دوره</span>
        <select name="kind" class="admin-field w-full">
            @foreach(PersonPeriod::KINDS as $kind => $label)
                <option value="{{ $kind }}" @selected($val('kind', $period?->kind ?? ($defaults['kind'] ?? null)) === $kind)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <label class="grid gap-1"><span class="text-xs text-muted">مکان (به همان عبارت مأخذ)</span>
        <input type="text" name="place" maxlength="150" value="{{ $val('place', $period?->place) }}" class="admin-field w-full">
    </label>
    <label class="grid gap-1 sm:col-span-2"><span class="text-xs text-muted">تاریخ</span>
        <select name="mode" x-model="mode" class="admin-field w-full sm:w-72">
            @foreach(PersonPeriod::MODES as $mode => $label)
                <option value="{{ $mode }}">{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <div class="sm:col-span-2 flex flex-wrap items-end gap-x-4 gap-y-2">
        <label class="grid gap-1"><span class="text-xs text-muted" x-text="mode === 'point' ? 'سال (ق)' : (mode === 'between' ? 'از سال (ق)' : 'آغاز (ق)')">آغاز (ق)</span>
            <input type="number" name="start" min="1" max="1500" value="{{ $val('start', $input['start']) }}" class="admin-field w-28 tabular-nums" dir="ltr">
        </label>
        <label class="grid gap-1" x-show="mode === 'span'"><span class="text-xs text-muted">یا تا این سال (آغاز نامطمئن)</span>
            <input type="number" name="start_to" min="1" max="1500" value="{{ $val('start_to', $input['start_to']) }}" class="admin-field w-28 tabular-nums" dir="ltr" :disabled="mode !== 'span'">
        </label>
        <label class="grid gap-1" x-show="mode !== 'point'"><span class="text-xs text-muted" x-text="mode === 'between' ? 'تا سال (ق)' : 'پایان (ق)'">پایان (ق)</span>
            <input type="number" name="end" min="1" max="1500" value="{{ $val('end', $input['end']) }}" class="admin-field w-28 tabular-nums" dir="ltr" :disabled="mode === 'point'">
        </label>
        <label class="grid gap-1" x-show="mode === 'span'"><span class="text-xs text-muted">یا تا این سال (پایان نامطمئن)</span>
            <input type="number" name="end_to" min="1" max="1500" value="{{ $val('end_to', $input['end_to']) }}" class="admin-field w-28 tabular-nums" dir="ltr" :disabled="mode !== 'span'">
        </label>
        <label class="grid gap-1"><span class="text-xs text-muted">قطعیت</span>
            <select name="certainty" class="admin-field w-32">
                <option value="">قطعی</option>
                @foreach(PersonPeriod::CERTAINTIES as $certainty => $label)
                    <option value="{{ $certainty }}" @selected($val('certainty', $period?->period_certainty) === $certainty)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
    </div>
    <label class="grid gap-1"><span class="text-xs text-muted">توضیح (مثلاً «قاضی»، «نزد شیخ بهایی»)</span>
        <input type="text" name="note" maxlength="500" value="{{ $val('note', $period?->note ?? ($defaults['note'] ?? null)) }}" class="admin-field w-full">
    </label>
    <label class="grid gap-1"><span class="text-xs text-muted">مأخذ</span>
        <input type="text" name="source_text" maxlength="500" value="{{ $val('source_text', $period?->source_text ?? ($defaults['source_text'] ?? null)) }}" class="admin-field w-full">
    </label>
    @if($mine && $errors->any())
        <p class="sm:col-span-2 text-xs text-cinnabar">{{ $errors->first() }}</p>
    @endif
    {{ $extra ?? '' }}
    <div class="sm:col-span-2 flex gap-2">
        <x-ui.btn type="submit" variant="primary" size="sm">{{ $submitLabel ?? ($period ? 'ذخیره' : 'افزودن دوره') }}</x-ui.btn>
    </div>
</form>

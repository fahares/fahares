<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Person;
use App\Models\PersonPeriod;
use App\Support\HijriYear;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The periods of a person's life, entered by a reviewer on the person's page (backlog item 139, step 14.218). Each
 * change is written to the audit log under the person.
 */
class PersonPeriodController extends Controller
{
    public function store(Request $request, Person $person): RedirectResponse
    {
        $period = $person->periods()->create($this->columns($request) + ['created_by' => $request->user()->id]);
        $this->log($person, 'create', null, $period);

        return redirect()->to(route('people.show', $person) . '#periods')->with('success', 'دوره ثبت شد.');
    }

    public function update(Request $request, Person $person, PersonPeriod $period): RedirectResponse
    {
        abort_unless($period->person_id === $person->id, 404);
        $old = $period->toArray();
        $period->update($this->columns($request));
        $this->log($person, 'update', $old, $period);

        return redirect()->to(route('people.show', $person) . '#periods')->with('success', 'دوره ویرایش شد.');
    }

    public function destroy(Request $request, Person $person, PersonPeriod $period): RedirectResponse
    {
        abort_unless($period->person_id === $person->id, 404);
        $old = $period->toArray();
        $period->delete();
        $this->log($person, 'delete', $old, null);

        return redirect()->to(route('people.show', $person) . '#periods')->with('success', 'دوره حذف شد.');
    }

    /** The validated columns of the form; a period says a place or a note, and at least one year. */
    private function columns(Request $request): array
    {
        $year = ['nullable', 'integer', 'min:1', 'max:' . HijriYear::MAX];
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(PersonPeriod::KINDS))],
            'place' => ['nullable', 'string', 'max:150', 'required_without:note'],
            'note' => ['nullable', 'string', 'max:500'],
            'source_text' => ['nullable', 'string', 'max:500'],
            'mode' => ['required', Rule::in(array_keys(PersonPeriod::MODES))],
            'start' => $year, 'start_to' => $year, 'end' => $year, 'end_to' => $year,
            'certainty' => ['nullable', Rule::in(array_keys(PersonPeriod::CERTAINTIES))],
        ], [], ['place' => 'مکان', 'note' => 'توضیح', 'kind' => 'نوع دوره', 'start' => 'سال آغاز', 'end' => 'سال پایان']);
        $date = PersonPeriod::dateColumns($data, $data['certainty'] ?? null);
        if (isset($date['error'])) {
            throw ValidationException::withMessages(['start' => $date['error']]);
        }
        $text = fn (string $key) => ($value = trim((string) ($data[$key] ?? ''))) === '' ? null : $value;

        return ['kind' => $data['kind'], 'place' => $text('place'), 'note' => $text('note'), 'source_text' => $text('source_text')] + $date;
    }

    private function log(Person $person, string $what, ?array $old, ?PersonPeriod $period): void
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'auditable_type' => Person::class,
            'auditable_id' => $person->id,
            'action' => 'update',
            'old_values' => $old ? ['person_period' => $old] : null,
            'new_values' => ['person_period' => $period?->toArray(), 'person_period_change' => $what],
            'ip_address' => request()->ip(),
        ]);
    }
}

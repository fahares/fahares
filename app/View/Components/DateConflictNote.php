<?php

namespace App\View\Components;

use App\Models\DateConflict;
use App\Support\DateEstimates\ConflictRecords;
use App\Support\DateEstimates\Loader;
use Illuminate\View\Component;

/**
 * The note under a date on a work's, a person's or a copy's page when that date is in a conflict (step 14.169):
 * what it conflicts with, and «کدام نادرست است؟» with the answers a reader can give. Each answer opens the page's
 * suggestion form (x-suggest.desk) with the conflict's id, here or on the other record's page: this date is wrong,
 * the other date is wrong, the tie between the two records is wrong, or both are right.
 *
 * Only open, direct conflicts wider than MIN_GAP years are shown: a year or two is the rounding between calendars.
 */
class DateConflictNote extends Component
{
    public const MIN_GAP = 2;

    private const SHOWN = 3;

    /** The suggestion form's field for each date. */
    private const FIELDS = ['birth' => 'birth_year_hijri', 'death' => 'death_year_hijri', 'composition' => 'composition_date_raw', 'copy' => 'copy_date_raw'];

    private const ROUTES = ['person' => 'people.show', 'work' => 'works.show', 'manuscript' => 'manuscripts.show'];

    private const OF_THIS = ['person' => 'این شخص', 'work' => 'این اثر', 'manuscript' => 'این نسخه'];

    /** The tie a rule rests on: the record whose page takes the report, and what the report says. */
    private const TIES = [
        Loader::RULE_AUTHOR => ['work', 'این اثر از این شخص نیست'],
        Loader::RULE_TRANSLATOR => ['work', 'مترجم این اثر این شخص نیست'],
        Loader::RULE_DEDICATEE => ['work', 'این اثر به این شخص اهدا نشده است'],
        Loader::RULE_COPY => ['manuscript', 'این نسخه از این اثر نیست'],
        Loader::RULE_AUTOGRAPH => ['manuscript', 'این نسخه خط مؤلف نیست'],
        Loader::RULE_SCRIBE => ['manuscript', 'کاتب این نسخه این شخص نیست'],
        Loader::RULE_PATRON => ['manuscript', 'بانی این نسخه این شخص نیست'],
        Loader::RULE_DERIVED => ['work', 'این دو اثر چنین رابطه‌ای ندارند'],
    ];

    public array $notes = [];

    public int $more = 0;

    public function __construct(public string $type, public int $id, public string $field)
    {
        // the open conflicts of a record, read once per request for the dates the page shows
        $loaded = app()->bound('date-conflict-notes') ? app('date-conflict-notes') : app()->instance('date-conflict-notes', new \ArrayObject);
        $conflicts = ($loaded["{$type}:{$id}"] ??= DateConflict::open()->where('direct', true)->where('gap', '>', self::MIN_GAP)
            ->where(fn ($q) => $q->where(fn ($a) => $a->where('a_type', $type)->where('a_id', $id))->orWhere(fn ($b) => $b->where('b_type', $type)->where('b_id', $id)))
            ->orderByDesc('gap')->limit(30)->get())
            ->filter(fn ($c) => $this->side($c) !== null)
            // a person's own birth and death conflict once, under the death, not under both
            ->reject(fn ($c) => $c->a_type === $c->b_type && $c->a_id === $c->b_id && $this->side($c) === 'b')
            // two rules over the same two dates (the scribe of a copy who is also its author) are one conflict: the widest
            ->unique(fn ($c) => $this->side($c) === 'a' ? "{$c->b_type}:{$c->b_id}:{$c->b_field}" : "{$c->a_type}:{$c->a_id}:{$c->a_field}")
            ->values();
        if ($conflicts->isEmpty()) {
            return;
        }
        $this->more = max(0, $conflicts->count() - self::SHOWN);
        $shown = $conflicts->take(self::SHOWN);
        $records = ConflictRecords::load($shown);
        foreach ($shown as $conflict) {
            $this->notes[] = $this->note($conflict, $records);
        }
    }

    /** Which side of the conflict is this record's date: 'a', 'b' or null. */
    private function side(DateConflict $c): ?string
    {
        foreach (['a', 'b'] as $side) {
            if ($c->{"{$side}_type"} === $this->type && (int) $c->{"{$side}_id"} === $this->id && $c->{"{$side}_field"} === $this->field) {
                return $side;
            }
        }

        return null;
    }

    private function note(DateConflict $c, array $records): array
    {
        $other = $this->side($c) === 'a' ? 'b' : 'a';
        [$type, $id, $field] = [$c->{"{$other}_type"}, (int) $c->{"{$other}_id"}, $c->{"{$other}_field"}];
        $record = $records["{$type}:{$id}"] ?? null;
        $sameRecord = $type === $this->type && $id === $this->id;
        $fieldLabel = DateConflict::FIELD_LABELS[$field];
        $title = $record['title'] ?? '';
        $short = \Illuminate\Support\Str::limit($title, 28);

        // the answers follow the question «کدام نادرست است؟», so each only names what is wrong
        $choices = [
            $this->choice(DateConflict::FIELD_LABELS[$this->field] . ' ' . self::OF_THIS[$this->type], $this->type, $this->id, $c,
                ['field' => self::FIELDS[$this->field]]),
            $this->choice($sameRecord ? "{$fieldLabel} " . self::OF_THIS[$type] : "{$fieldLabel} «{$short}»", $type, $id, $c,
                ['field' => self::FIELDS[$field]]),
        ];
        if ($tie = self::TIES[$c->rule] ?? null) {
            // the report goes to the work or the copy of the two, since the tie is its field
            $at = $c->a_type === $tie[0] ? 'a' : 'b';
            $choices[] = $this->choice($tie[1], $c->{"{$at}_type"}, (int) $c->{"{$at}_id"}, $c,
                ['general' => true, 'value' => $tie[1] . ($sameRecord ? '' : " («{$title}»): ")]);
        }
        $choices[] = $this->choice('هیچ‌کدام؛ هر دو درست‌اند', $this->type, $this->id, $c, ['general' => true, 'value' => "{$c->label}، ولی هر دو تاریخ درست‌اند: "]);

        return [
            'id' => $c->id, 'label' => $c->label, 'gap' => $c->gap,
            'other' => $sameRecord ? null : ['title' => $title, 'url' => route(self::ROUTES[$type], $id)],
            'otherField' => $fieldLabel, 'otherDate' => $record['dates'][$field] ?? null,
            'choices' => $choices,
        ];
    }

    /**
     * One answer: on this page the suggestion form opens in place (detail of the suggest-field event); on another
     * record's page, by its address (?suggest=…&conflict=…, which the form reads on load).
     */
    private function choice(string $label, string $type, int $id, DateConflict $c, array $detail): array
    {
        if ($type === $this->type && $id === $this->id) {
            return ['label' => $label, 'detail' => $detail + ['conflict' => $c->id], 'url' => null];
        }
        $query = ['suggest' => isset($detail['field']) ? 'field.' . $detail['field'] : 'general', 'conflict' => $c->id]
            + (isset($detail['value']) ? ['prefill' => $detail['value']] : []);

        return ['label' => $label, 'detail' => null, 'url' => route(self::ROUTES[$type], ['id' => $id] + $query)];
    }

    public function shouldRender(): bool
    {
        return $this->notes !== [];
    }

    public function render()
    {
        return view('components.date-conflict-note');
    }
}

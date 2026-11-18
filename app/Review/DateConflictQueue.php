<?php

namespace App\Review;

use App\Models\DateConflict;
use App\Models\FieldSuggestion;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Work;
use App\Support\DateEstimates\ConflictRecords;
use App\View\Components\DateConflictNote;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * «تناقض تاریخ» (step 14.212, backlog item 126): the open direct date conflicts the public pages show in a note
 * (DateConflictNote), one per card, with the note's answers: the later date is wrong («a»), the earlier one is wrong
 * («b»), the tie between the two records is wrong («tie»), or both are right («none»). An answer is a general
 * suggestion on the record it blames, tied to the conflict by date_conflict_id, so the panel's «تناقض‌های تاریخی»
 * lists it beside the readers' answers from the note.
 *
 * A reviewer decides by approving one answer on the desk (and mending the record), or by «خطا نیست» in the panel's
 * list, which is the answer «none»; either settles the other opinions. A conflict with an approved answer does not
 * come back to the queue, though the nightly run keeps it until the record is mended.
 */
class DateConflictQueue extends ReviewQueue
{
    public const ANSWERS = [
        'a' => 'تاریخ نخست نادرست است',
        'b' => 'تاریخ دوم نادرست است',
        'tie' => 'پیوند این دو نادرست است',
        'none' => 'هیچ‌کدام؛ هر دو درست‌اند',
    ];

    private const MODELS = ['person' => Person::class, 'work' => Work::class, 'manuscript' => Manuscript::class];

    private const ROUTES = ['person' => 'people.show', 'work' => 'works.show', 'manuscript' => 'manuscripts.show'];

    private const TYPES = ['person' => 'شخص', 'work' => 'اثر', 'manuscript' => 'نسخه'];

    public function key(): string
    {
        return 'date';
    }

    public function title(): string
    {
        return 'تناقض تاریخ';
    }

    public function blurb(): string
    {
        return 'دو تاریخ ثبت‌شده با هم نمی‌خوانند، مثلاً نسخه‌ای که پیش از تألیف اثرش کتابت شده است. بگویید کدام نادرست است.';
    }

    public function open(): Builder
    {
        return DateConflict::open()->where('date_conflicts.direct', true)->where('date_conflicts.gap', '>', DateConflictNote::MIN_GAP)
            ->whereDoesntHave('suggestions', fn ($q) => $q->where('status', 'approved'));
    }

    public function filterOptions(): array
    {
        return ['rule' => ['label' => 'نوع', 'options' => array_intersect_key(DateConflict::RULE_LABELS, DateConflict::LABELS)]];
    }

    public function filter(Builder $query, array $filters): Builder
    {
        return $query->when($filters['rule'] ?? null, fn ($q, $rule) => $q->where('date_conflicts.rule', $rule));
    }

    /** The widest gap first: the surest errors. */
    public function order(Builder $query): Builder
    {
        return $query->orderByDesc('date_conflicts.gap')->orderBy('date_conflicts.id');
    }

    public function card(Model $item): array
    {
        $records = ConflictRecords::load([$item]);
        $sides = [];
        foreach (['a', 'b'] as $side) {
            [$type, $id, $field] = $this->side($item, $side);
            $record = $records["{$type}:{$id}"] ?? null;
            $sides[$side] = [
                'type' => self::TYPES[$type],
                'field' => DateConflict::FIELD_LABELS[$field],
                'title' => $record['title'] ?? null,
                'detail' => $record['detail'] ?? null,
                'date' => $record['dates'][$field] ?? null,
                // a person's date text is the «detail»; a work's and a copy's is «raw»
                'raw' => $type === 'person' ? null : ($record['raw'] ?? null),
                'url' => $record ? route(self::ROUTES[$type], $id) : null,
            ];
        }
        $answers = $this->labels($item, $records);

        return [
            'conflict' => $item,
            'sides' => $sides,
            'answers' => $answers,
            // no answer is the likely one, so none is the highlighted button
            'neutral' => true,
        ];
    }

    public function answers(): array
    {
        return self::ANSWERS;
    }

    public function suggestion(Model $item, string $answer, array $input): array
    {
        $labels = $this->labels($item, ConflictRecords::load([$item]));
        if (! isset($labels[$answer])) {
            throw ValidationException::withMessages(['answer' => 'یکی از پاسخ‌ها را برگزینید.']);
        }
        $record = $this->blamed($item, $answer);

        return [
            'kind' => 'general',
            'field_name' => null,
            'current_value' => null,
            'suggested_value' => "ناسازگاری تاریخ ({$item->label}، فاصله {$item->gap} سال): " . match ($answer) {
                'a', 'b' => $labels[$answer] . ' نادرست است.',
                'tie' => $labels[$answer] . '.',
                'none' => 'هر دو تاریخ درست‌اند.',
            },
            'payload' => null,
            'review_answer' => $answer,
            'date_conflict_id' => $item->getKey(),
            // the opinion is filed on the record it blames, where a reviewer mends it
            'suggestable_type' => $record::class,
            'suggestable_id' => $record->getKey(),
        ];
    }

    public function describe(FieldSuggestion $opinion): string
    {
        // the text names the date or the tie of this very conflict; the bare answer is the fallback
        return Str::after((string) $opinion->suggested_value, '): ') ?: (self::ANSWERS[$opinion->review_answer] ?? (string) $opinion->review_answer);
    }

    public function record(Model $item): Model
    {
        return $this->blamed($item, 'a');
    }

    public function reviewUrl(int $itemId): ?string
    {
        return route('admin.date-conflicts.index', ['conflict' => $itemId]) . '#date-conflict-' . $itemId;
    }

    /** @return array{0: string, 1: int, 2: string} type, id and date field of one side */
    private function side(DateConflict $c, string $side): array
    {
        return [$c->{"{$side}_type"}, (int) $c->{"{$side}_id"}, $c->{"{$side}_field"}];
    }

    /**
     * The answers this conflict takes, each naming its own date: «تاریخ وفات «میرداماد»». A person's own birth and death
     * have no tie to doubt.
     *
     * @return array<string, string>
     */
    private function labels(DateConflict $c, array $records): array
    {
        $same = $c->a_type === $c->b_type && (int) $c->a_id === (int) $c->b_id;
        $labels = [];
        foreach (['a', 'b'] as $side) {
            [$type, $id, $field] = $this->side($c, $side);
            $title = Str::limit((string) ($records["{$type}:{$id}"]['title'] ?? self::TYPES[$type]), 40);
            $labels[$side] = DateConflict::answerLabel($field) . ($same ? ' این ' . self::TYPES[$type] : " «{$title}»");
        }
        if (! $same && ($tie = DateConflictNote::TIES[$c->rule] ?? null)) {
            $labels['tie'] = $tie[1];
        }
        $labels['none'] = self::ANSWERS['none'];

        return $labels;
    }

    /** The record an answer is filed on: the one whose date it blames, or the work or copy that holds the tie. */
    private function blamed(DateConflict $c, string $answer): Model
    {
        $side = match ($answer) {
            'b' => 'b',
            'tie' => $c->a_type === (DateConflictNote::TIES[$c->rule][0] ?? null) ? 'a' : 'b',
            default => 'a',
        };
        [$type, $id] = $this->side($c, $side);

        return self::MODELS[$type]::find($id) ?? throw ValidationException::withMessages(['answer' => 'این رکورد دیگر وجود ندارد.']);
    }
}

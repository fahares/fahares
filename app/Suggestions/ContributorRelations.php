<?php

namespace App\Suggestions;

use App\Helpers\TextNormalizer;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Work;
use App\Models\WorkPerson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Applies the relational suggestions about people: who copied a manuscript or wrote a work
 * (scribe_is/author_is, scribe_also/author_also) and another name of a person (alias).
 * Each returns what the audit log needs to undo it.
 */
class ContributorRelations
{
    /** @return array{0: string, 1: string, 2: string} pivot table, record key, primary column */
    private static function columns(Model $record): array
    {
        return $record instanceof Manuscript
            ? ['manuscript_scribe', 'manuscript_id', 'scribe_id']
            : ['work_author', 'work_id', 'author_id'];
    }

    /**
     * The person becomes the record's primary scribe or author. The previous primary person, if
     * any, leaves the list (SyncsPrimaryContributor); an unlinked primary name gives way too.
     */
    public function set(Model $record, Person $person): array
    {
        [$table, $key, $column] = self::columns($record);
        $nameColumn = $record instanceof Manuscript ? 'scribe_name' : 'author_name';
        $previous = $record->{$column};
        if ((int) $previous === $person->id) {
            throw new InvalidArgumentException('این شخص هم‌اکنون همین نقش را دارد.');
        }

        $removed = null;
        if (! $previous) {
            // The catalog's name for the primary contributor, kept as text until now; a rollback puts it back
            $removed = DB::table($table)->where($key, $record->id)->whereNull('person_id')->where('position', 0)->first();
            if ($removed) {
                DB::table($table)->where('id', $removed->id)->delete();
            }
        }
        // Linked as a further contributor before: the saved hook moves that row to the front
        $record->{$column} = $person->id;
        if (blank($record->{$nameColumn})) {
            $record->{$nameColumn} = $person->name;
        }
        if ($record instanceof Manuscript) {
            $record->is_bika = false;
        }

        $old = array_intersect_key($record->getOriginal(), $record->getDirty());
        $record->save();
        $new = $record->getChanges();
        unset($new['updated_at']);

        $this->recount([$previous, $person->id]);
        $this->reindex($record);

        if ($removed) {
            $new['removed_name_row'] = ['table' => $table] + (array) $removed;
        }

        return ['old' => $old, 'new' => $new, 'resolution' => ['person_id' => $person->id, 'previous_id' => $previous]];
    }

    /** The person is added after the record's other scribes or authors, with an optional note on their share. */
    public function add(Model $record, Person $person, ?string $note): array
    {
        [$table, $key, $column] = self::columns($record);
        if (! $record->{$column}) {
            return $this->set($record, $person);
        }
        if (DB::table($table)->where($key, $record->id)->where('person_id', $person->id)->exists()) {
            throw new InvalidArgumentException('این شخص هم‌اکنون در فهرست است.');
        }

        $before = DB::table($table)->where($key, $record->id)->orderBy('position')->pluck('person_id')->all();
        $id = DB::table($table)->insertGetId([
            $key => $record->id,
            'person_id' => $person->id,
            'position' => (int) DB::table($table)->where($key, $record->id)->max('position') + 1,
            'note' => $note ? mb_substr($note, 0, 500) : null,
        ]);

        $this->recount([$person->id]);
        $this->reindex($record);

        return [
            // A rollback deletes the added row; the list before is kept for the record
            'old' => ['contributors' => $before],
            'new' => ['added_contributor' => ['table' => $table, 'id' => $id, 'person_id' => $person->id]],
            'resolution' => ['person_id' => $person->id, 'added' => true],
        ];
    }

    /**
     * A person connected with the copy in a role other than scribe (owner, endower, corrector…), or with the
     * work in a role other than author (translator, dedicatee, commissioner…): a linked person or only a name,
     * with a note such as «مهر بیضی، ۱۲۴۰ق». The one write path for these rows: reviewed suggestions, the
     * admin's structural tools and the corpus backfill (fahares:backfill-work-people) all come through here.
     * $extra: certainty, hedge_raw and source_entry_id of a work's row.
     */
    public function addRole(Manuscript|Work $record, string $role, ?Person $person, ?string $name, ?string $note, ?int $suggestionId, array $extra = []): array
    {
        if (! array_key_exists($role, SuggestionRegistry::personRoles($record::class))) {
            throw new InvalidArgumentException('نقش انتخاب‌شده نامعتبر است.');
        }
        $name = $name !== null ? trim(preg_replace('/\s+/u', ' ', $name)) : null;
        if (! $person && ! $name) {
            throw new InvalidArgumentException('شخص را انتخاب کنید یا نامش را بنویسید.');
        }
        $rows = fn () => self::roleRows($record);
        $label = $record instanceof Work ? 'اثر' : 'نسخه';
        if ($person && $rows()->where('person_id', $person->id)->where('role', $role)->exists()) {
            throw new InvalidArgumentException("این شخص با همین نقش هم‌اکنون برای این {$label} ثبت شده است.");
        }
        if (! $person && $rows()->whereNull('person_id')->where('name', $name)->where('role', $role)->exists()) {
            throw new InvalidArgumentException("این نام با همین نقش هم‌اکنون برای این {$label} ثبت شده است.");
        }
        $extra = $record instanceof Work ? array_intersect_key($extra, array_flip(['certainty', 'hedge_raw', 'source_entry_id'])) : [];
        if (isset($extra['certainty']) && ! array_key_exists($extra['certainty'], WorkPerson::CERTAINTIES)) {
            throw new InvalidArgumentException('میزان قطعیت نامعتبر است.');
        }

        $before = $rows()->pluck('id')->all();
        $row = $rows()->create([
            'person_id' => $person?->id,
            'name' => $person ? null : mb_substr($name, 0, 600),
            'role' => $role,
            'note' => $note ? mb_substr($note, 0, 500) : null,
            'position' => (int) $rows()->max('position') + 1,
            'suggestion_id' => $suggestionId,
        ] + array_filter($extra, fn ($v) => $v !== null && $v !== ''));
        if ($person && $role === 'translator' && ! $person->is_translator) {
            $person->update(['is_translator' => true]);
        }

        return [
            'old' => ['people_roles' => $before],
            'new' => ['added_person_role' => $row->id],
            'resolution' => ['person_role_id' => $row->id, 'person_id' => $person?->id, 'role' => $role],
        ];
    }

    /** One of the work's people rows is deleted; the audit log keeps the whole row, so a rollback puts it back. */
    public function removeRole(Work $work, int $rowId): array
    {
        $row = $work->workPeople()->whereKey($rowId)->first()
            ?? throw new InvalidArgumentException('شخص مرتبطی که باید حذف شود را انتخاب کنید.');
        $snapshot = $row->getAttributes();
        $row->delete();

        return [
            'old' => ['removed_person_role' => $snapshot],
            'new' => ['removed_person_role' => $rowId],
            'resolution' => ['person_role_id' => $rowId, 'person_id' => $snapshot['person_id'], 'role' => $snapshot['role'], 'removed' => true, 'name' => $snapshot['name']],
        ];
    }

    /** The rows of people in other roles: manuscript_people for a copy, work_people for a work. */
    public static function roleRows(Manuscript|Work $record): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $record instanceof Work ? $record->workPeople() : $record->otherPeople();
    }

    /** Another name of the person, appended to their aliases unless already there. */
    public function alias(Person $person, string $name): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        if ($name === '') {
            throw new InvalidArgumentException('نام دیگر را بنویسید.');
        }
        $aliases = array_values((array) ($person->aliases ?? []));
        $known = array_map(fn ($a) => TextNormalizer::normalize($a), array_merge($aliases, [$person->name]));
        if (in_array(TextNormalizer::normalize($name), $known, true)) {
            throw new InvalidArgumentException('این نام هم‌اکنون در نام‌های این شخص هست.');
        }

        $old = ['aliases' => $person->aliases];
        $person->aliases = array_merge($aliases, [$name]);
        $person->save();
        $this->reindex($person);

        return ['old' => $old, 'new' => ['aliases' => $person->aliases], 'resolution' => ['alias' => $name]];
    }

    private function recount(array $ids): void
    {
        foreach (array_unique(array_filter($ids)) as $id) {
            Person::find($id)?->recountContributions();
        }
    }

    private function reindex(Model $record): void
    {
        if (method_exists($record, 'searchable')) {
            $record->searchable();
        }
    }
}

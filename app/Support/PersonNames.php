<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Work;
use Illuminate\Support\Facades\DB;

/**
 * A person's name as their works and copies carry it (step 14.295). `works.author_name` and `manuscripts.scribe_name`
 * are columns filled from the catalogue's text when the record was made; the text itself (`raw_text`) keeps the
 * catalogue's wording. When the person is renamed, the columns that held the person's old name take the new one, by
 * the rule the people merge tool uses: only a record of one author (or one scribe), and only where the column is the
 * old name exactly, so «الف و ب» and a hedged or longer wording stay as they are.
 *
 * Each change is logged as a "verify" on its record: the corpus sync commands read those logs (ProtectedColumns) and
 * leave the column alone, where they would otherwise write the catalogue's name back and look for a person of that
 * name. The log can be rolled back like any other.
 */
class PersonNames
{
    /**
     * The person was renamed from $old: their works and copies that carried that name follow.
     *
     * @return array{works: int[], manuscripts: int[]} the records changed
     */
    public static function follow(Person $person, string $old): array
    {
        $new = (string) $person->name;
        $changed = ['works' => [], 'manuscripts' => []];
        if (trim($old) === '' || $new === '' || $old === $new) {
            return $changed;
        }

        $changed['works'] = Work::where('author_id', $person->id)->where('author_name', $old)->where('has_other_authors', false)->pluck('id')->all();
        $changed['manuscripts'] = Manuscript::where('scribe_id', $person->id)->where('scribe_name', $old)->where('has_other_scribes', false)->pluck('id')->all();
        self::write($person, Work::class, 'author_name', $changed['works'], $old, $new);
        self::write($person, Manuscript::class, 'scribe_name', $changed['manuscripts'], $old, $new);

        return $changed;
    }

    /** One work's author text set to its linked person's name, as follow() does for a rename; false when it is already so. */
    public static function followWork(Work $work, Person $person): bool
    {
        if ((string) $work->author_name === (string) $person->name) {
            return false;
        }
        self::write($person, Work::class, 'author_name', [$work->id], (string) $work->author_name, (string) $person->name);

        return true;
    }

    private static function write(Person $person, string $class, string $column, array $ids, string $old, string $new): void
    {
        if (! $ids) {
            return;
        }
        $table = (new $class)->getTable();
        foreach (array_chunk($ids, 500) as $chunk) {
            DB::table($table)->whereIn('id', $chunk)->update([$column => $new, 'updated_at' => now()]);
            $now = now();
            AuditLog::insert(array_map(fn (int $id) => [
                'user_id' => auth()->id(),
                'auditable_type' => $class,
                'auditable_id' => $id,
                'action' => 'verify',
                'old_values' => json_encode([$column => $old], JSON_UNESCAPED_UNICODE),
                'new_values' => json_encode([$column => $new, 'followed_person' => $person->id], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
            ], $chunk));
            // the search documents carry the name
            $class::whereIn('id', $chunk)->get()->each->searchable();
        }
    }
}

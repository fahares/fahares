<?php

namespace App\Support;

use App\Models\Work;
use App\Suggestions\RecordCreator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The subjects and languages of a work, when a reviewer writes them (step 14.415). A work keeps them twice: as the
 * catalogue's wording (`subject_summary`, `language_summary`) and as links to the site's subjects and languages, which
 * the lists by subject and by language read. A corrected wording must name what the site has, so the links follow it:
 * «خوشنویسی» beside the site's «خط» would be one more spelling of one subject, found by neither list.
 *
 * Fankha's own wordings are left as they are: about two thousand of them are linked through a spelling the site
 * normalised («هیأت» → «هیئت», «فارسی و عربی»), so the links are rewritten only for a wording a reviewer wrote.
 */
class WorkTerms
{
    /** field => [table, pivot, pivot column, the word for it in a message] */
    public const FIELDS = [
        'subject_summary' => ['subjects', 'subject_work', 'subject_id', 'موضوع'],
        'language_summary' => ['languages', 'language_work', 'language_id', 'زبان'],
    ];

    /**
     * The ids the wording names, in its order. «فقه، اصول» and «فقه / اصول» are two names; «فارسی و عربی» is one name
     * if the site has it, else two.
     *
     * @return int[]
     *
     * @throws InvalidArgumentException a name the site does not have
     */
    public static function ids(string $field, ?string $wording): array
    {
        [$table, , , $word] = self::FIELDS[$field];
        $ids = $unknown = [];
        foreach (RecordCreator::terms($wording) as $term) {
            if ($id = DB::table($table)->where('name', $term)->value('id')) {
                $ids[] = $id;
                continue;
            }
            foreach (array_filter(array_map('trim', preg_split('/\s+و\s+/u', $term)), 'strlen') as $part) {
                ($id = DB::table($table)->where('name', $part)->value('id')) ? $ids[] = $id : $unknown[] = $part;
            }
        }
        if ($unknown) {
            throw new InvalidArgumentException("این {$word} در سایت نیست: «" . implode('»، «', array_unique($unknown)) . "». نام {$word} را همان‌گونه بنویسید که در سایت هست"
                . ($table === 'subjects' ? ' (مثلاً «خط»، نه «خوشنویسی»)' : '') . '؛ چند نام را با «،» جدا کنید.');
        }

        return array_values(array_unique($ids));
    }

    /**
     * Links the work to what its wording now names, and to nothing else of that kind.
     *
     * @return array{old: int[], new: int[]} the linked ids before and after
     */
    public static function sync(Work $work, string $field): array
    {
        return self::write($work, $field, self::ids($field, $work->{$field}));
    }

    /** The links as a rollback found them in the log: the ids of each table. */
    public static function restore(Work $work, array $links): void
    {
        foreach (self::FIELDS as $field => [$table]) {
            if (array_key_exists($table, $links)) {
                self::write($work, $field, array_map('intval', (array) $links[$table]));
            }
        }
    }

    private static function write(Work $work, string $field, array $ids): array
    {
        [$table, $pivot, $column] = self::FIELDS[$field];
        $old = DB::table($pivot)->where('work_id', $work->id)->orderBy($column)->pluck($column)->map(fn ($id) => (int) $id)->all();
        if ($gone = array_diff($old, $ids)) {
            DB::table($pivot)->where('work_id', $work->id)->whereIn($column, $gone)->delete();
        }
        foreach (array_diff($ids, $old) as $id) {
            DB::table($pivot)->insertOrIgnore([$column => $id, 'work_id' => $work->id]);
        }
        RecordCreator::recountTerms($table, $pivot, $column, array_merge($old, $ids));
        $work->unsetRelation($table);

        return ['old' => $old, 'new' => array_values($ids)];
    }
}

<?php

namespace App\Support\DateEstimates;

use App\Support\DateSpan;
use Illuminate\Support\Facades\DB;

/**
 * The records date conflicts name, as the panel's list and the notes on the public pages show them (steps 14.167 and
 * 14.169), read in three queries for any number of conflicts.
 */
final class ConflictRecords
{
    /**
     * What the page shows of each record a conflict names: "type:id" => [title, detail, dates as the record holds them].
     */
    public static function load(iterable $conflicts): array
    {
        $ids = [];
        foreach ($conflicts as $c) {
            foreach (['a', 'b', 'via'] as $side) {
                if ($c->{"{$side}_id"}) {
                    $ids[$c->{"{$side}_type"}][] = $c->{"{$side}_id"};
                }
            }
        }
        // a duration reads «از 1052 تا 1057», any other range «1201–1300» (DateSpan::brief, step 14.194)
        $date = fn (object $row, string $prefix) => DateSpan::fromColumns($row, $prefix)->brief();
        $records = [];
        foreach (DB::table('people')->whereIn('id', $ids['person'] ?? [])->get(['id', 'name', 'birth_year_hijri', 'death_from', 'death_to', 'death_kind', 'life_dates_raw']) as $p) {
            $records["person:{$p->id}"] = ['title' => $p->name, 'detail' => $p->life_dates_raw,
                'dates' => ['birth' => $p->birth_year_hijri === null ? null : (string) $p->birth_year_hijri, 'death' => $date($p, 'death')]];
        }
        // the years the approved periods of a person's life cover, for a conflict of the rule «period» (step 14.226)
        foreach (DB::table('person_periods')->whereIn('person_id', $ids['person'] ?? [])->where('status', 'approved')->whereNull('period_certainty')
            ->selectRaw('person_id, MIN(COALESCE(period_from, period_to)) AS first, MAX(COALESCE(period_to, period_from)) AS last')->groupBy('person_id')->get() as $span) {
            if (isset($records["person:{$span->person_id}"])) {
                $records["person:{$span->person_id}"]['dates']['period'] = $span->first === $span->last ? (string) $span->first : "{$span->first}–{$span->last}";
            }
        }
        $copies = DB::table('manuscripts')->whereIn('id', $ids['manuscript'] ?? [])
            ->get(['id', 'work_id', 'city', 'library', 'shelfmark', 'copy_date_raw', 'copy_date_from', 'copy_date_to', 'copy_date_kind', 'copy_date_start_to', 'copy_date_end_from', 'scribe_name']);
        $workIds = array_merge($ids['work'] ?? [], $copies->pluck('work_id')->filter()->all());
        $works = DB::table('works')->whereIn('id', $workIds)->get(['id', 'primary_title', 'author_name', 'composition_date_raw', 'composition_date_from', 'composition_date_to', 'composition_date_kind',
            'composition_date_start_to', 'composition_date_end_from'])->keyBy('id');
        foreach ($works as $w) {
            $records["work:{$w->id}"] = ['title' => $w->primary_title, 'detail' => $w->author_name,
                'dates' => ['composition' => $date($w, 'composition_date')], 'raw' => $w->composition_date_raw];
        }
        foreach ($copies as $m) {
            $records["manuscript:{$m->id}"] = ['title' => ($works[$m->work_id]->primary_title ?? 'نسخه'),
                'detail' => implode('، ', array_filter([$m->city, $m->library, $m->shelfmark ? 'ش ' . $m->shelfmark : null, $m->scribe_name ? 'کاتب: ' . $m->scribe_name : null])),
                'dates' => ['copy' => $date($m, 'copy_date')], 'raw' => $m->copy_date_raw];
        }

        return $records;
    }
}

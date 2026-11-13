<?php

namespace App\Support\DateEstimates;

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
        $range = fn ($from, $to) => $from === null && $to === null ? null : ($from === $to ? (string) $from : ($from ?? '؟') . '–' . ($to ?? '؟'));
        $records = [];
        foreach (DB::table('people')->whereIn('id', $ids['person'] ?? [])->get(['id', 'name', 'birth_year_hijri', 'death_from', 'death_to', 'life_dates_raw']) as $p) {
            $records["person:{$p->id}"] = ['title' => $p->name, 'detail' => $p->life_dates_raw,
                'dates' => ['birth' => $range($p->birth_year_hijri, $p->birth_year_hijri), 'death' => $range($p->death_from, $p->death_to)]];
        }
        $copies = DB::table('manuscripts')->whereIn('id', $ids['manuscript'] ?? [])
            ->get(['id', 'work_id', 'city', 'library', 'shelfmark', 'copy_date_raw', 'copy_date_from', 'copy_date_to', 'scribe_name']);
        $workIds = array_merge($ids['work'] ?? [], $copies->pluck('work_id')->filter()->all());
        $works = DB::table('works')->whereIn('id', $workIds)->get(['id', 'primary_title', 'author_name', 'composition_date_raw', 'composition_date_from', 'composition_date_to'])->keyBy('id');
        foreach ($works as $w) {
            $records["work:{$w->id}"] = ['title' => $w->primary_title, 'detail' => $w->author_name,
                'dates' => ['composition' => $range($w->composition_date_from, $w->composition_date_to)], 'raw' => $w->composition_date_raw];
        }
        foreach ($copies as $m) {
            $records["manuscript:{$m->id}"] = ['title' => ($works[$m->work_id]->primary_title ?? 'نسخه'),
                'detail' => implode('، ', array_filter([$m->city, $m->library, $m->shelfmark ? 'ش ' . $m->shelfmark : null, $m->scribe_name ? 'کاتب: ' . $m->scribe_name : null])),
                'dates' => ['copy' => $range($m->copy_date_from, $m->copy_date_to)], 'raw' => $m->copy_date_raw];
        }

        return $records;
    }
}

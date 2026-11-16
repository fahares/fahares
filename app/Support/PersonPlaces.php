<?php

namespace App\Support;

use App\Models\Person;
use App\Services\PersonReviewQueue;
use Illuminate\Support\Facades\DB;

/**
 * Where a person was, and when, as the records already say it (backlog item 139, step 14.207): a copy with a place and
 * a year shows its scribe in that place in that year, and a work with a place and a year of composition its author.
 * Nothing is stored: the list is read from the copies and the works each time.
 *
 * Counted are a date of one year and a duration (DateSpan::SPAN, «از 1052 تا 1057»); a century, «1250 یا 1270» and a
 * range of no known kind say no year. Left out as well: a hedged date, a hedged author, a photograph of a copy, a
 * place text over MAX_PLACE characters (a note, not a place), and the copies of a scribe whose dated copies lie more
 * than PersonReviewQueue::MIXED_SPAN years apart, since that record holds several namesakes.
 *
 * Places are grouped by their city (copy_city, composition_city; step 14.209), so «طهران», «دارالخلافه طهران» and
 * «تهران مدرسه سپهسالار» are one row, تهران; each record keeps the catalogue's own text of the place. A text that names
 * no city of the list («ییلاق لیمرا») is a row of its own. A place with a hedge («تهران (احتمالا)», «؟») is left out,
 * and so is a record dated after the person's death or before their birth: that is a namesake's.
 */
final class PersonPlaces
{
    public const MAX_PLACE = 60;

    /** How many records one place lists; the count says how many there are. */
    public const SHOWN = 12;

    private const HEDGE = '؟|\?|احتمال|محتمل|ظاهرا|گویا|شاید';

    /**
     * The places in the order the person is first seen in them.
     *
     * @return list<array{place: string, city: bool, from: int, to: int, count: int, items: list<array{years: string, what: string, title: string, url: string, text: ?string}>}>
     */
    public static function for(Person $person): array
    {
        $rows = [];
        $mixed = $person->scribed_from && $person->scribed_to - $person->scribed_from > PersonReviewQueue::MIXED_SPAN;
        if (! $mixed) {
            $copies = DB::table('manuscript_people as mp')->join('manuscripts as m', 'm.id', '=', 'mp.manuscript_id')
                ->leftJoin('works as w', 'w.id', '=', 'm.work_id')
                ->where('mp.person_id', $person->id)->where('mp.role', 'scribe')->where('m.is_facsimile', 0)
                ->whereNotNull('m.copy_place')->where('m.copy_place', '<>', '')->whereNull('m.copy_date_certainty')
                ->where(fn ($q) => self::dated($q, 'm.copy_date'))
                ->get(['m.id', 'm.copy_place as place', 'm.copy_city as city', 'm.copy_date_from', 'm.copy_date_to', 'm.copy_date_kind', 'm.copy_date_start_to',
                    'm.copy_date_end_from', 'w.primary_title as title', 'm.library', 'm.shelfmark']);
            foreach ($copies as $c) {
                $rows[] = ['place' => $c->place, 'city' => $c->city, 'date' => DateSpan::fromColumns($c, 'copy_date'), 'what' => 'کتابت',
                    'title' => implode('، ', array_filter([$c->title, $c->library, $c->shelfmark ? 'ش ' . $c->shelfmark : null])) ?: 'نسخه',
                    'url' => route('manuscripts.show', $c->id)];
            }
        }
        $works = DB::table('work_people as wp')->join('works as w', 'w.id', '=', 'wp.work_id')
            ->where('wp.person_id', $person->id)->where('wp.role', 'author')->whereNull('wp.certainty')
            ->whereNotNull('w.composition_place')->where('w.composition_place', '<>', '')->whereNull('w.composition_date_certainty')
            ->where(fn ($q) => self::dated($q, 'w.composition_date'))
            ->get(['w.id', 'w.composition_place as place', 'w.composition_city as city', 'w.composition_date_from', 'w.composition_date_to', 'w.composition_date_kind',
                'w.composition_date_start_to', 'w.composition_date_end_from', 'w.primary_title as title']);
        foreach ($works as $w) {
            $rows[] = ['place' => $w->place, 'city' => $w->city, 'date' => DateSpan::fromColumns($w, 'composition_date'), 'what' => 'تألیف',
                'title' => $w->title, 'url' => route('works.show', $w->id)];
        }

        $places = [];
        foreach ($rows as $row) {
            $text = trim(preg_replace('/\s+/u', ' ', $row['place']));
            if ($text === '' || mb_strlen($text) > self::MAX_PLACE || preg_match('/' . self::HEDGE . '/u', $text)) {
                continue;
            }
            // a copy or a work dated after the person's death, or before their birth, is a namesake's (a date conflict)
            if (($person->death_to !== null && $row['date']->from > $person->death_to) || ($person->birth_year_hijri && $row['date']->to < $person->birth_year_hijri)) {
                continue;
            }
            $key = $row['city'] ?: $text;
            $places[$key] ??= ['place' => $key, 'city' => (bool) $row['city'], 'from' => $row['date']->from, 'to' => $row['date']->to, 'count' => 0, 'items' => []];
            $places[$key]['from'] = min($places[$key]['from'], $row['date']->from);
            $places[$key]['to'] = max($places[$key]['to'], $row['date']->to);
            $places[$key]['count']++;
            $places[$key]['items'][] = ['from' => $row['date']->from, 'years' => $row['date']->brief(), 'what' => $row['what'], 'title' => $row['title'],
                'url' => $row['url'], 'text' => $text === $key ? null : $text];
        }
        foreach ($places as &$place) {
            usort($place['items'], fn ($a, $b) => [$a['from'], $a['title']] <=> [$b['from'], $b['title']]);
            $place['items'] = array_map(fn ($i) => array_diff_key($i, ['from' => 1]), array_slice($place['items'], 0, self::SHOWN));
        }
        unset($place);
        uasort($places, fn ($a, $b) => [$a['from'], $a['to'], $a['place']] <=> [$b['from'], $b['to'], $b['place']]);

        return array_values($places);
    }

    /** A date that says a year: one year, or a duration with both ends. */
    private static function dated($query, string $prefix): void
    {
        $query->whereColumn("{$prefix}_from", "{$prefix}_to")
            ->orWhere(fn ($q) => $q->where("{$prefix}_kind", DateSpan::SPAN)->whereNotNull("{$prefix}_from")->whereNotNull("{$prefix}_to"));
    }
}

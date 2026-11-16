<?php

namespace App\Console\Commands;

use App\Models\Person;
use App\Support\FankhaWorkLocator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Mends the death years that the review after steps 14.213 and 14.214 found to be something else (step 14.225). The
 * life dates the works carry are read from the decision file of fahares:fill-death-ranges. Five kinds of row:
 *   - text: the person's life dates are a lone year («1325 قمری») and another work writes the same year with a dash
 *     («- 1325 قمری», «1270 - 1365 قمری», «1254- قمری»). The fuller text becomes the life dates, and says whether the
 *     year is the birth or the death;
 *   - birth: a lone year marked Hijri («1294 قمری») of a person who wrote or copied something dated 15 to 110 years
 *     after it, and nothing before. The year is the birth year;
 *   - alive: any other lone year. The catalogue writes it for the living and the dead alike, and for the date of a
 *     document whose description stands where the author's name should («تاریخ ترجمه: رمضان» with «1292ق»), so it
 *     is no death: the death year and range are emptied and the century stays;
 *   - open: no life dates, and a death year that a work writes as the year before an open dash («1357 - قمری»). That
 *     text becomes the life dates and the year the birth year;
 *   - middle: no life dates, and a death year that is the middle of the person's century (1250 with century 13), the
 *     corpus's sorting number. It is emptied; a work's «ق 13 قمری» becomes the life dates where there is one;
 *   - solar: the people of SOLAR, whose «قمری» dates are solar by their biographies: two by outside sources, and nine
 *     by the lives in the site's biographical sources, found by reading every such person's numbers as solar years and
 *     looking for a life of the same name with those Hijri years (step 14.233). The text is corrected and the years
 *     converted (FixCalendarDeathsCommand::columns).
 * People are found by their life dates, names and works, never by id; a run only writes what differs and can be
 * repeated.
 */
class FixLifeYearsCommand extends Command
{
    protected $signature = 'fahares:fix-life-years
        {--file= : Decision file (database/data/fankha_v2_deaths_20261031.json)}
        {--apply : Write the columns; without it the command only counts}
        {--show=8 : How many examples to list per kind}';

    protected $description = 'Empty a death year that is a lone year, a birth year or the middle of a century, and convert the solar dates the catalogue calls Hijri';

    /** Name => the life dates as the catalogue has them and as they should read (the years are solar). */
    public const SOLAR = [
        // born 1866, died 1942
        'عبرت نائینی، محمد علی' => ['1245؟ - 1321 قمری', '1245؟ - 1321 شمسی'],
        // born Tir 1253 or 1254, died 11 Tir 1319
        'ارباب کیخسرو بن شاهرخ' => ['1253 – 1319 ق', '1253 – 1319 شمسی'],
        // By the site's own books of lives (step 14.233): the Hijri years they give are the ones these numbers span
        // as solar years. معجم المؤلفین 8/84, مؤلفین کتب چاپی 4/875, طبقات أعلام الشیعة 17/43: 1285-1360
        'زنجانی، فیاض' => ['1247 - 1319 قمری', '1247 - 1319 شمسی'],
        // أعیان الشیعة 9/401: born 1292, died 1367; طبقات أعلام الشیعة 17/68: 1290-1367
        'شیرازی، محمد کاظم' => ['1253-1326 قمری', '1253-1326 شمسی'],
        // مؤلفین کتب چاپی 4/27: 1296-1356
        'گلشن ایرانپور، عبد الوهاب' => ['1257 - 1315 قمری', '1257 - 1315 شمسی'],
        // مؤلفین کتب چاپی 2/223: 1274-1345
        'شوریده شیرازی، محمد تقی' => ['1236 - 1305 قمری', '1236 - 1305 شمسی'],
        // طبقات أعلام الشیعة 16/1599: 1278-1369
        'نهاوندی، علی اکبر' => ['1240 - 1328 قمری', '1240 - 1328 شمسی'],
        // معجم المؤلفین 13/126, موسوعة طبقات الفقهاء 14/469, أعیان الشیعة 10/232: 1297-1368
        'حائری خراسانی، هادی' => ['1259 - 1327 قمری', '1259 - 1327 شمسی'],
        // مؤلفین کتب چاپی 4/424: died 1346 (1345 by his son's word)
        'لاهیجی حائری، علی بن مهدی' => ['-1306 قمری', '-1306 شمسی'],
        // مؤلفین کتب چاپی 4/5: born 1268, died 1305 solar; طبقات أعلام الشیعة 17/103: died 1345
        'مجدی کردستانی، عبدالمجید' => ['1230 - 1306 قمری', '1230 - 1306 شمسی'],
        // مؤلفین کتب چاپی 6/894: born 1320, living when the book was written
        'شعار، یوسف' => ['1281 - 1351 قمری', '1281 - 1351 شمسی'],
    ];

    /** A work or a copy this long after a lone year shows that year to be the birth. */
    public const BIRTH_GAP = [15, 110];

    private const COLUMNS = ['life_dates_raw', 'birth_year_hijri', 'death_year_hijri', 'century_hijri', 'death_year_gregorian',
        'death_from', 'death_to', 'death_certainty', 'death_hedge_raw', 'death_kind'];

    public function handle(): int
    {
        $path = $this->option('file') ?: database_path('data/fankha_v2_deaths_20261031.json');
        $plan = File::exists($path) ? json_decode(File::get($path), true) : null;
        if (! $plan || ! isset($plan['works'])) {
            $this->error("Decision file missing or invalid: {$path}");

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $texts = FillDeathRangesCommand::personTexts($plan, new FankhaWorkLocator(array_merge($plan['works'], $plan['peers'] ?? [])));
        $counts = array_fill_keys(['text', 'birth', 'alive', 'open', 'middle', 'solar', 'unchanged'], 0);
        $shown = [];
        // only a death year can be wrong here, or the life dates that explain an emptied one
        $people = DB::table('people')->where(fn ($q) => $q->whereNotNull('death_year_hijri')->orWhereNotNull('life_dates_raw'))->orderBy('id')
            ->select(array_merge(['id', 'name'], self::COLUMNS));
        $people->chunkById(5000, function ($rows) use ($apply, $texts, &$counts, &$shown) {
            $updates = [];
            foreach ($rows as $p) {
                $old = FixLifeCenturiesCommand::stored($p, self::COLUMNS);
                [$kind, $new] = $this->change($p, $old, $texts[$p->id] ?? []);
                if ($kind === null) {
                    continue;
                }
                $changed = array_filter($new, fn ($value, $column) => $old[$column] !== $value, ARRAY_FILTER_USE_BOTH);
                if (! $changed) {
                    $counts['unchanged']++;
                    continue;
                }
                $counts[$kind]++;
                $updates[$p->id] = $changed;
                if (($shown[$kind] = ($shown[$kind] ?? 0) + 1) <= (int) $this->option('show')) {
                    $this->line(sprintf('  %-6s #%d «%s» [%s]: %s', $kind, $p->id, $p->name, $p->life_dates_raw, FixLifeCenturiesCommand::describe($old, $changed)));
                }
            }
            if ($apply && $updates) {
                DB::transaction(function () use ($updates) {
                    foreach ($updates as $id => $cols) {
                        DB::table('people')->where('id', $id)->update($cols);
                    }
                });
            }
        });
        $this->info(($apply ? 'Written' : 'Dry run, nothing written') . ": {$counts['text']} fuller texts, {$counts['birth']} birth years, {$counts['alive']} years alive, "
            . "{$counts['open']} open dashes, {$counts['middle']} mid-century years, {$counts['solar']} solar, {$counts['unchanged']} already right.");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  string[]  $texts  the life dates the person's works carry
     * @return array{0: ?string, 1: array<string, mixed>} the kind of the row (null: not one of these) and its columns
     */
    private function change(object $p, array $old, array $texts): array
    {
        $raw = $old['life_dates_raw'];
        $year = $old['death_year_hijri'];
        $hijri = array_values(array_filter($texts, fn ($t) => Person::lifeCalendar($t) === null));

        if (isset(self::SOLAR[$p->name]) && in_array($raw, self::SOLAR[$p->name], true)) {
            $text = self::SOLAR[$p->name][1];
            $new = FixCalendarDeathsCommand::columns($text, ['life_dates_raw' => $text] + $old) ?? FixCalendarDeathsCommand::columns($text, $old);

            return $new === null ? [null, []] : ['solar', $new];
        }
        $life = Person::lifeYear($raw);
        if ($life !== null && ! $life['birth']) {
            // a lone year: unless it is already mended, the death year is that year
            if ($year !== null && $year !== $life['year']) {
                return [null, []];
            }
            if (($fuller = self::fuller($life['year'], $hijri)) !== null) {
                return ['text', self::fromText($fuller, $old)];
            }
            // a year with no «ق» may be a solar one («1295» of someone born in 1295 solar): it is not taken as a Hijri birth
            $marked = preg_match('/ق/u', (string) $raw) === 1;
            $birth = $old['birth_year_hijri'] ?? ($marked && $this->activeAfter((int) $p->id, $life['year']) ? $life['year'] : null);
            $kind = $birth === $life['year'] ? 'birth' : 'alive';

            return [$kind, ['birth_year_hijri' => $birth, 'death_year_hijri' => null, 'century_hijri' => $old['century_hijri'] ?? Person::lifeCentury($raw),
                'death_year_gregorian' => null] + self::range($raw, null) + $old];
        }
        if ($raw === null && $year !== null) {
            foreach ($hijri as $text) {
                $open = Person::lifeYear($text);
                if ($open !== null && $open['birth'] && $open['year'] === $year) {
                    return ['open', self::fromText($text, $old)];
                }
            }
            if ($year % 100 === 50 && $old['century_hijri'] === (int) ceil($year / 100)) {
                $century = array_values(array_filter($hijri, fn ($t) => Person::lifeCentury($t) === $old['century_hijri'] && Person::lifeYear($t) === null));
                $text = $century[0] ?? null;

                return ['middle', ['life_dates_raw' => $text === null ? null : mb_substr($text, 0, 120), 'death_year_hijri' => null, 'death_year_gregorian' => null]
                    + self::range($text, null) + $old];
            }
        }
        // mended rows: the life dates a run of this command wrote
        if ($life !== null && $life['birth'] && $year === null) {
            return ['open', self::fromText($raw, $old)];
        }

        return [null, []];
    }

    /** The text most works give that writes the lone year with a dash, as a birth or a death; null when none does. */
    private static function fuller(int $year, array $texts): ?string
    {
        $found = [];
        foreach ($texts as $text) {
            $life = Person::lifeYear($text);
            if ($life !== null && ! $life['birth']) {
                continue;
            }
            $death = Person::deathColumns($text, null);
            if (($life['year'] ?? null) === $year || Person::yearBeforeDash($text) === $year || ($death['death_from'] === $year && $death['death_to'] === $year)) {
                $found[$text] = ($found[$text] ?? 0) + 1;
            }
        }
        arsort($found);

        return array_key_first($found);
    }

    /** The columns Hijri life dates with a dash give: the birth before it, the death after it. */
    private static function fromText(string $text, array $old): array
    {
        $range = self::range($text, null);
        $death = $range['death_from'] !== null && $range['death_from'] === $range['death_to'] ? $range['death_from'] : null;
        $birth = Person::lifeYear($text)['year'] ?? Person::yearBeforeDash($text);
        $century = match (true) {
            $death !== null => (int) ceil($death / 100),
            $range['death_to'] !== null => (int) ceil($range['death_to'] / 100),
            default => $old['century_hijri'] ?? ($birth === null ? null : (int) ceil($birth / 100)),
        };

        return ['life_dates_raw' => mb_substr($text, 0, 120), 'birth_year_hijri' => $birth ?? $old['birth_year_hijri'], 'death_year_hijri' => $death,
            'century_hijri' => $century, 'death_year_gregorian' => $death === null ? null : $old['death_year_gregorian']] + $range + $old;
    }

    private static function range(?string $text, ?int $year): array
    {
        $range = Person::deathColumns($text, $year);

        return $range + Person::deathKind($range);
    }

    /** Whether the person wrote or copied something dated BIRTH_GAP years after the year, and nothing dated before it. */
    private function activeAfter(int $personId, int $year): bool
    {
        $dates = DB::table('work_people as wp')->join('works as w', 'w.id', '=', 'wp.work_id')->where('wp.person_id', $personId)
            ->whereIn('wp.role', ['author', 'translator'])->whereNull('wp.certainty')->whereNotNull('w.composition_date_from')->whereColumn('w.composition_date_from', 'w.composition_date_to')
            ->pluck('w.composition_date_from')
            ->merge(DB::table('manuscript_people as mp')->join('manuscripts as m', 'm.id', '=', 'mp.manuscript_id')->where('mp.person_id', $personId)->where('mp.role', 'scribe')
                ->whereNotNull('m.copy_date_from')->whereColumn('m.copy_date_from', 'm.copy_date_to')->pluck('m.copy_date_from'))
            ->map(fn ($y) => (int) $y);

        return $dates->isNotEmpty() && $dates->min() >= $year + self::BIRTH_GAP[0] && $dates->max() <= $year + self::BIRTH_GAP[1];
    }
}

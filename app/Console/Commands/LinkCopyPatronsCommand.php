<?php

namespace App\Console\Commands;

use App\Helpers\TextNormalizer;
use App\Models\Person;
use App\Support\Insights;
use App\Support\KnownPatrons;
use App\Support\KnownRulers;
use App\Support\PersonNameMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Links the patrons of copies (manuscript_people, role patron: the one a copy was written for or at the request
 * of) to their person records (step 14.120). Until now none was linked, so the insights page counted each
 * spelling of a name apart and the person pages did not list the copies written for them.
 *
 * A patron links when the copy's own date says who it is:
 * - a ruler of KnownRulers, the spelling decided by the copy's year where it may mean more than one, and never
 *   for a copy written after the ruler's death;
 * - a title of KnownPatrons («ظل السلطان», «آیة الله بروجردی»), the person who held it in the copy's year;
 * - any other name of three words or more besides titles («میرزا», «آقا», «سید», «بن») that is exactly the name or another name of one person only, who was
 *   alive in the copy's year by their death year, and died within 90 years of it.
 * A copy with no year links only on a ruler's or title's unique spelling. The scribe's kin («پدرش …»), «مؤلف»,
 * several people in one name and two-word names («میرزا محمد علی») stay text, and so do rows a reviewer added.
 * The name as the catalogue writes it stays in the row, so the link can be undone.
 */
class LinkCopyPatronsCommand extends Command
{
    protected $signature = 'fahares:link-copy-patrons
        {--dry-run : Count and list what would be linked without writing}
        {--report= : Write every link and every name left as text to this TSV file}
        {--show=15 : How many examples to list per kind}';

    protected $description = 'Link the patrons of copies (manuscript_people role patron) to their person records';

    /** A patron can have died this many years after the copy was written for them, and no more. */
    public const MAX_AFTER = 90;

    /** Words of address and rank that are not counted as words of the name. */
    public const TITLES = ['میرزا', 'اقا', 'ملا', 'مولی', 'مولانا', 'سید', 'میر', 'حاج', 'حاجی', 'شیخ', 'اخوند', 'خان', 'بیک', 'بیگ', 'سرکار', 'جناب', 'مشهدی', 'کربلایی', 'امیر', 'شاهزاده', 'استاد', 'خواجه', 'بن', 'ابن'];

    /** @var array<string, int> */
    private array $counts = [];

    /** @var array<string, array<string, int>> kind => label => rows */
    private array $examples = [];

    /** @var array<string, ?Person> authority name => its one record */
    private array $records = [];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $matcher = new PersonNameMatcher;
        $this->counts = [];
        $this->examples = [];
        $this->records = [];
        $report = [];

        $rows = DB::table('manuscript_people as mp')->join('manuscripts as m', 'm.id', '=', 'mp.manuscript_id')
            ->where('mp.role', 'patron')->whereNull('mp.person_id')->whereNull('mp.suggestion_id')
            ->select(['mp.id', 'mp.name', 'm.copy_date_from as f', 'm.copy_date_to as t'])->orderBy('mp.id')->get();

        $links = [];
        foreach ($rows as $row) {
            $name = trim(preg_replace(['/\([^)]*\)/u', '/\s+/u'], ['', ' '], (string) $row->name));
            $year = self::year($row->f, $row->t);
            [$person, $kind] = $this->person($name, $year, $matcher, $dry);
            $this->counts[$kind] = ($this->counts[$kind] ?? 0) + 1;
            $label = $person ? "{$name} → #" . ($person->id ?? 'new') . " {$person->name}" : $name;
            $this->examples[$kind][$label] = ($this->examples[$kind][$label] ?? 0) + 1;
            $report[] = [$row->id, $kind, $name, $year ?? '', $person?->id ?? '', $person?->name ?? '', $person?->death_year_hijri ?? ''];
            if ($person) {
                $links[$person->id ?? $person->name][] = $row->id;
            }
        }

        if (! $dry) {
            DB::transaction(function () use ($links) {
                foreach ($links as $personId => $ids) {
                    foreach (array_chunk($ids, 500) as $chunk) {
                        DB::table('manuscript_people')->whereIn('id', $chunk)->whereNull('person_id')->update(['person_id' => $personId, 'updated_at' => now()]);
                    }
                }
            });
        }

        if ($path = $this->option('report')) {
            $out = fopen($path, 'w');
            fputcsv($out, ['row', 'kind', 'name', 'copy_year', 'person_id', 'person', 'death'], "\t");
            foreach ($report as $line) {
                fputcsv($out, $line, "\t");
            }
            fclose($out);
        }

        $this->info($dry ? 'Dry run: nothing written.' : 'Written.');
        ksort($this->counts);
        foreach ($this->counts as $kind => $n) {
            $this->line(sprintf('  %-22s %5d', $kind, $n));
        }
        foreach ($this->examples as $kind => $labels) {
            arsort($labels);
            $labels = array_slice($labels, 0, (int) $this->option('show'), true);
            $this->line("\n{$kind}: " . implode(' | ', array_map(fn ($l, $n) => "{$l} ({$n})", array_keys($labels), $labels)));
        }

        return self::SUCCESS;
    }

    /** The year of a copy: the middle of its date when the range is 30 years or less, else none. */
    public static function year($from, $to): ?int
    {
        return $from !== null && $to !== null && (int) $to - (int) $from <= 30 ? intdiv((int) $from + (int) $to, 2) : null;
    }

    /**
     * The person a patron's name is in the copy's year, and how it was found, or null and why not.
     *
     * @return array{0: ?Person, 1: string}
     */
    private function person(string $name, ?int $year, PersonNameMatcher $matcher, bool $dry): array
    {
        $normalized = TextNormalizer::normalize($name);
        if ($normalized === '' || Insights::isKin($normalized)) {
            return [null, 'text:kin'];
        }
        if ($title = KnownPatrons::find($name, $year)) {
            return ($person = $this->record($title['name'], $title['death'] ?? null, $dry)) ? [$person, 'linked:title'] : [null, 'text:title_no_record'];
        }
        if (KnownPatrons::knows($name)) {
            return [null, $year === null ? 'text:title_no_year' : 'text:title_other_holder'];
        }
        if (($ruler = KnownRulers::find(KnownPatrons::core($name), $year)) && ! $ruler['certainty']) {
            if ($year !== null && $year > $ruler['ruler']['death'] + 1) {
                return [null, 'text:after_ruler_death'];
            }

            return ($person = $this->record($ruler['ruler']['name'], null, $dry)) ? [$person, 'linked:ruler'] : [null, 'text:ruler_no_record'];
        }
        // «بهمن میرزا برای محمد شاه قاجار», «حسن و حسین»: more than one person
        if (preg_match('/\s(و|برای|به نام)\s/u', $name) || mb_substr_count($name, '،') >= 2) {
            return [null, 'text:several'];
        }
        // «میرزا محمد علی», «آقا سید محمد»: the titles do not make a common name less common
        $words = count(array_diff(preg_split('/[\s،]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY), self::TITLES));
        if ($words < 3) {
            return [null, $words < 2 ? 'text:one_word' : 'text:two_words'];
        }
        $ids = $matcher->ids($normalized);
        if (! $ids) {
            return [null, 'text:no_record'];
        }
        if (count($ids) > 1) {
            return [null, 'text:ambiguous'];
        }
        if ($year === null) {
            return [null, 'text:no_copy_year'];
        }
        $person = Person::find($ids[0]);
        $death = $person->death_year_hijri ?? $person->death_to;
        if (! $death) {
            return [null, 'text:no_death_year'];
        }
        if ($year > $death + 1 || $death - $year > self::MAX_AFTER) {
            return [null, 'text:dates_disagree'];
        }

        return [$person, 'linked:name'];
    }

    /**
     * The one person record of an authority name, or null when there are several, or none and no death year to
     * make one with. In a dry run a record that would be made is an unsaved one.
     */
    private function record(string $name, ?int $death, bool $dry): ?Person
    {
        if (! array_key_exists($name, $this->records)) {
            $ids = Person::where('normalized_name', TextNormalizer::normalize($name))->orWhere('name', $name)->pluck('id')->unique();
            $this->records[$name] = match (true) {
                $ids->count() === 1 => Person::find($ids->first()),
                $ids->isEmpty() && $death !== null => $this->create($name, $death, $dry),
                default => null,
            };
        }

        return $this->records[$name];
    }

    private function create(string $name, int $death, bool $dry): Person
    {
        $attributes = ['name' => $name, 'normalized_name' => mb_substr(TextNormalizer::normalize($name), 0, 600),
            'death_year_hijri' => $death, 'century_hijri' => PersonNameMatcher::century($death)];
        $this->counts['created'] = ($this->counts['created'] ?? 0) + 1;
        if ($dry) {
            return new Person($attributes);
        }
        $person = Person::create($attributes + ['slug' => 'person-' . Str::random(12)]);
        $person->update(['slug' => (Str::slug($name, '-', null) ?: 'person') . '-' . $person->id]);

        return $person;
    }
}

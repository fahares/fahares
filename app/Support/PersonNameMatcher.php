<?php

namespace App\Support;

use App\Helpers\TextNormalizer;
use App\Models\Person;
use Illuminate\Support\Facades\DB;

/**
 * Finds the person a catalogue's name confidently is, for the work_people backfills: one person whose name or
 * other name is exactly this one, with no disagreeing death century, and with three words or more or an
 * agreeing death century. Anything less keeps the name as text.
 */
class PersonNameMatcher
{
    /** The cataloguer's hedge words and the certainty they express. */
    public const HEDGES = ['؟' => 'doubtful', 'محتملا' => 'probable', 'ظاهرا' => 'probable', 'گویا' => 'probable', 'شاید' => 'doubtful', 'احتمالا' => 'probable'];

    /** @var array<string, int[]>|null normalized name or other name => person ids */
    private ?array $index = null;

    /** «طالبوف ، عبدالرحیم»: the people table writes the comma without the space before it. */
    public static function clean(string $name): string
    {
        return trim(preg_replace(['/\s+/u', '/\s+،/u'], [' ', '،'], $name));
    }

    /** The certainty a hedge word expresses; null when there is none. */
    public static function certainty(?string $hedge): ?string
    {
        $hedge = trim((string) $hedge);
        if ($hedge === '') {
            return null;
        }

        return self::HEDGES[TextNormalizer::normalize($hedge)] ?? self::HEDGES[$hedge] ?? 'probable';
    }

    /** The Hijri century of a death year. */
    public static function century(?int $deathYear): ?int
    {
        return $deathYear ? intdiv($deathYear - 1, 100) + 1 : null;
    }

    /**
     * The person this name confidently is, else null and why not.
     *
     * @return array{0: ?Person, 1: ?string}
     */
    public function match(string $name, ?int $century, bool $requireCentury = false): array
    {
        $normalized = TextNormalizer::normalize($name);
        // «جان طیلر و ابوالقاسم …», «یوسفعلی، ماردروس، میرزا علیقلی…»: several people in one name
        if (preg_match('/\sو\s/u', $name) || mb_substr_count($name, '،') >= 2) {
            return [null, 'several'];
        }
        $words = count(preg_split('/[\s،]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY));
        if ($words < 2) {
            return [null, 'one_word'];
        }
        $ids = $this->ids($normalized);
        if (! $ids) {
            return [null, 'no_match'];
        }
        if (count($ids) > 1) {
            return [null, 'ambiguous'];
        }
        $person = Person::find($ids[0]);
        $personCentury = $person->century_hijri ?? self::century($person->death_year_hijri);
        if ($century && $personCentury && (int) $century !== (int) $personCentury) {
            return [null, 'death_conflict'];
        }
        // A two-word name («میرزا محمد», «منشی، محسن») is common: it links only when the death century agrees.
        // So does any name where the people table is full of namesakes the catalogue does not mean (dedicatees)
        if (($words < 3 || $requireCentury) && ! ($century && $personCentury)) {
            return [null, 'short_unconfirmed'];
        }

        return [$person, null];
    }

    /** @return int[] the people with this name or other name */
    public function ids(string $name): array
    {
        $this->index ??= $this->buildIndex();

        return $this->index[TextNormalizer::normalize($name)] ?? [];
    }

    /** A person created after the index was built (KnownRulers) is found by later lookups too. */
    public function remember(Person $person): void
    {
        $this->index ??= $this->buildIndex();
        $this->index[TextNormalizer::normalize($person->normalized_name)][] = $person->id;
    }

    /** Every person by normalized name and other names, for exact lookups. */
    private function buildIndex(): array
    {
        $index = [];
        DB::table('people')->select(['id', 'normalized_name', 'aliases'])->orderBy('id')->chunk(5000, function ($people) use (&$index) {
            foreach ($people as $p) {
                // normalized_name keeps «آ» and «ئ» as written; lookups go through TextNormalizer, so the index does too
                $names = [TextNormalizer::normalize($p->normalized_name)];
                foreach ((array) json_decode((string) $p->aliases, true) as $alias) {
                    $names[] = TextNormalizer::normalize(is_array($alias) ? ($alias['name'] ?? '') : (string) $alias);
                }
                foreach (array_unique(array_filter($names)) as $n) {
                    $index[$n][] = $p->id;
                }
            }
        });

        return array_map(fn ($ids) => array_values(array_unique($ids)), $index);
    }
}

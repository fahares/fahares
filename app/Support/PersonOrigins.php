<?php

namespace App\Support;

use App\Helpers\TextNormalizer;

/**
 * Where a person was from, was born and died, as far as the records say it (backlog item 137, step 14.276), read each
 * time and stored nowhere, like PersonPlaces:
 *
 *  - from the nisba of the name: «اصفهانی», «الحلی», «هروی» name a city of the map (fromName). A nisba says where a
 *    person or the family was from, not where the person was born or lived, so it is a layer of its own («از نسبت»).
 *  - from the text of the person's entries in the biographical dictionaries (fromBiography): «ولد بأصفهان»,
 *    «توفي في النجف», «من أهل أستراباد», «در شیراز متولد شده». Only a city named right after such a word is taken;
 *    «توفي بها» and a place a few sentences on are not.
 *
 * Both are guesses of a rule, not a reading by a person: the figures built on them say so.
 *
 * A biography may name a country or a region where it names no city («من أهل خوارزم», «ولد بالهند»): fromBiography
 * reads those too when asked (step 14.380), for the proposals of the periods of a life; the map has only cities.
 */
final class PersonOrigins
{
    public const BIRTH = 'birth';
    public const DEATH = 'death';
    public const ORIGIN = 'origin';

    // A word that looks like a nisba of a city and is mostly something else: the title of one who has been on
    // pilgrimage to Mashhad or Karbala («مشهدی حسن», «کربلایی محمد»)
    private const NOT_NISBAS = ['مشهدی', 'کربلایی'];

    // How far into an entry «of the people of …» is still said of its person: some 270 letters
    private const HEAD_BYTES = 500;

    private const KIN = '/(?<![\p{L}])(?:پدر|پدرش|جدش|اجدادش|ابوه|ابیه|ابی|والده|جده|اخوه)(?![\p{L}])/u';

    // Other names of a city that, in the text of a life, are the name of its country
    private const COUNTRIES = ['مصر', 'شام'];

    /** @var array<string, string>|null nisba => city */
    private static ?array $nisbas = null;

    /** @var array{0: string, 1: array<string, string>}|null the cities as a pattern, and each name's city */
    private static ?array $names = null;

    /** @var array{0: string, 1: array<string, string>}|null the countries and regions as a pattern, and each name's region */
    private static ?array $regionNames = null;

    /**
     * The nisbas of the cities of the map: the name of a city or of its other names with «ی» («اصفهانی», «طهرانی»,
     * «حائری»), where that is how the nisba is made, and those of config insights.nisbas, which are made otherwise
     * («هروی», «رازی», «حلی», «غروی»).
     *
     * @return array<string, string>
     */
    public static function nisbas(): array
    {
        if (self::$nisbas !== null) {
            return self::$nisbas;
        }
        $nisbas = [];
        foreach (CopyPlaces::cities() as $city => $info) {
            foreach ([$city, ...($info['aliases'] ?? [])] as $name) {
                $name = TextNormalizer::normalize($name);
                // One word, and not one that ends in a vowel letter: those make their nisba in ways of their own
                if (! str_contains($name, ' ') && ! in_array(mb_substr($name, -1), ['ا', 'ه', 'ی', 'و'], true)) {
                    $nisbas[$name . 'ی'] ??= $city;
                }
            }
        }
        foreach (config('insights.nisbas', []) as $nisba => $city) {
            $nisbas[TextNormalizer::normalize($nisba)] = $city;
        }
        foreach (self::NOT_NISBAS as $word) {
            unset($nisbas[$word]);
        }

        return self::$nisbas = $nisbas;
    }

    /**
     * The cities the nisbas of a name point to, in the order of the name.
     *
     * @return string[]
     */
    public static function fromName(?string $name): array
    {
        $nisbas = self::nisbas();
        $cities = [];
        // What stands in brackets is a note on the name, often another person's
        $name = preg_replace('/[(\[（][^)\]）]*[)\]）]/u', ' ', (string) $name);
        foreach (preg_split('/[^\p{L}]+/u', TextNormalizer::normalize($name), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $city = $nisbas[$word] ?? $nisbas[preg_replace('/^ال(?=\p{L}{3})/u', '', $word)] ?? null;
            if ($city !== null) {
                $cities[$city] = true;
            }
        }

        return array_keys($cities);
    }

    /**
     * The places a biographical entry names for the person's birth, death and origin: the cities of the map, and
     * with $regions the countries and regions too (config insights.copy_regions, step 14.380). A country or region is
     * taken only for a kind the entry names no city for («ولد فی النجف … بالعراق» is Najaf), and its hit says
     * `region` => true; `city` is then the name of the country or region.
     *
     * @return array<int, array{kind: string, city: string, text: string, region?: bool}>
     */
    public static function fromBiography(?string $text, bool $regions = false): array
    {
        $text = TextNormalizer::normalize($text);
        if ($text === '') {
            return [];
        }
        $found = self::read($text, ...self::names());
        if ($regions) {
            $kinds = array_column($found, 'kind', 'kind');
            foreach (self::read($text, ...[...self::regionNames(), true]) as $hit) {
                if (! isset($kinds[$hit['kind']])) {
                    $found[] = $hit + ['region' => true];
                }
            }
        }

        return $found;
    }

    /** A place of a life that is a country by its name, though the same word is another name of a city of the map */
    public static function isCountry(?string $place): bool
    {
        return in_array(preg_replace('/^ال/u', '', trim(TextNormalizer::normalize($place))), self::COUNTRIES, true);
    }

    /**
     * @param  string  $city  the names to look for, as a pattern
     * @param  array<string, string>  $cityOf  each name's place
     * @return array<int, array{kind: string, city: string, text: string}>
     */
    private static function read(string $text, string $city, array $cityOf, bool $oneClause = false): array
    {
        if ($city === '') {
            return [];
        }
        // Up to three words between the verb and the place («ولد سنه 1200 فی», «توفی شهیدا ب»)
        // … but not the birth or death of a kinsman («ولد ابوه فی القطیف»)
        $gap = '(?!\s+(?:ابوه|ابیه|والده|جده|اخوه|ابنه|ولده|حفیده|عمه)(?![\p{L}]))(?:\s+[\p{L}\p{N}]+){0,3}?';
        // For a country or region the words between must be of the same clause: no «و», which begins another
        // («ولد باسفرایین و تنقل فی ایران» is not a birth in Iran), but for «مولده و وفاته بمصر», «ولد و نشا فی
        // خوارزم», «ولد و عاش بخوارزم», where the second verb is of the same place
        if ($oneClause) {
            $gap = '(?!\s+(?:ابوه|ابیه|والده|جده|اخوه|ابنه|ولده|حفیده|عمه)(?![\p{L}]))(?:\s+و\s+(?:وفاته|نشا|عاش)(?:\s+(?!و(?![\p{L}]))[\p{L}\p{N}]+)?|(?:\s+(?!و(?![\p{L}]))[\p{L}\p{N}]+){0,3}?)';
        }
        $in = '\s+(?:فی\s+(?:بلده\s+|مدینه\s+)?(?:ال)?|ب(?:بلده\s+|مدینه\s+)?(?:ال)?)';
        $end = '(?![\p{L}])';
        $patterns = [
            self::BIRTH => [
                "/(?<![\p{L}])(?:ولد|مولده|ولادته|تولد){$gap}{$in}({$city}){$end}/u",
                "/(?<![\p{L}])در\s+({$city})\s+(?:متولد|تولد|به دنیا|بدنیا|زاده|ولادت)/u",
                // «زاده» is left out: it is mostly the end of a name («قاضی زاده همدان»)
                "/(?<![\p{L}])متولد\s+({$city}){$end}/u",
            ],
            self::DEATH => [
                "/(?<![\p{L}])(?:توفی|مات|وفاته){$gap}{$in}({$city}){$end}/u",
                // «قتل الباب … فی تبریز» is the death of another: not when a noun with its article follows the verb
                "/(?<![\p{L}])(?:قتل|استشهد)(?!\s+ال[\p{L}]){$gap}{$in}({$city}){$end}/u",
                "/(?<![\p{L}])در\s+({$city})\s+(?:وفات|درگذشت|در گذشت|فوت|رحلت|شهید|کشته)/u",
                // «در … مدفون» is left out: a burial is not a death (three of the proposals of step 14.291)
            ],
            self::ORIGIN => [
                // «his origin is from …» is said of the person wherever it stands
                "/(?<![\p{L}])اصله من\s+(?:بلده\s+|مدینه\s+)?(?:ال)?({$city}){$end}/u",
            ],
            // «of the people of …» is said of the person at the head of the entry; further on it is mostly of others
            // («رجلا من اهل الموصل», «زنی از اهل کوفه»: 17 of the 227 origins of the run of step 14.291), and «بعضی از
            // اهالی اصفهان», «جمع من اهل بغداد» are others anywhere
            // … unless it is said outright: «کان من اهل شوشتر», «و هو من اهل الکوفه», «اصلا از اهل قزوین بوده»
            self::ORIGIN . '-said' => [
                "/(?<![\p{L}])(?:کان|هو|اصلا)\s*(?:من اهل|من اهالی|از مردم|از اهالی|از اهل)\s+(?:بلده\s+|مدینه\s+)?(?:ال)?({$city}){$end}/u",
            ],
            self::ORIGIN . '-head' => [
                "/(?<![\p{L}])(?<!بعضی )(?<!برخی )(?<!جمعی )(?<!یکی )(?<!گروهی )(?<!بعض )(?<!جمع )(?<!جماعه )(?<!احد )(?:من اهل|من اهالی|از مردم|از اهالی|از اهل)\s+(?:بلده\s+|مدینه\s+)?(?:ال)?({$city}){$end}/u",
            ],
        ];
        $found = [];
        foreach ($patterns as $kind => $list) {
            [$kind, $where] = array_pad(explode('-', $kind, 2), 2, null);
            foreach ($list as $pattern) {
                if (! preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                    continue;
                }
                foreach ($matches as $match) {
                    // Past the head of the entry, or in a clause about the father or a forebear
                    if ($where !== null && (($where === 'head' && $match[0][1] > self::HEAD_BYTES)
                        || preg_match(self::KIN, mb_strcut($text, max(0, $match[0][1] - 80), min(80, $match[0][1]))))) {
                        continue;
                    }
                    $raw = $match[1][0];
                    $name = preg_replace('/\s+/u', ' ', $raw);
                    $after = ltrim(mb_strcut($text, $match[1][1] + strlen($raw), 80));
                    $place = $name === 'مشهد' ? self::shrine($after) : $cityOf[$name];
                    if ($place !== null) {
                        $found[$kind . '|' . $place] ??= ['kind' => $kind, 'city' => $place, 'text' => self::clause($text, $match[0][1], strlen($match[0][0]))];
                    }
                }
            }
        }

        return array_values($found);
    }

    /**
     * «مشهد» in an Arabic text is a shrine: «مشهد الحسین» is Karbala and «المشهد الغروی» Najaf. Taken as the city of
     * Mashhad only where the next word says so or where nothing more is said of it.
     */
    private static function shrine(string $after): ?string
    {
        $next = preg_split('/[^\p{L}]+/u', $after, 3, PREG_SPLIT_NO_EMPTY);
        $word = preg_replace('/^ال/u', '', $next[0] ?? '');

        return match (true) {
            $word === '' || in_array($word, ['و', 'سنه', 'فی', 'سال', 'عام'], true) => 'مشهد',
            (bool) preg_match('/^(?:رضو|رضا|مقدس|طوس|خراسان)/u', $word) => 'مشهد',
            (bool) preg_match('/^(?:حسین|حایر)/u', $word) => 'کربلا',
            (bool) preg_match('/^(?:غرو|غری|علو|امیر)/u', $word) => 'نجف',
            (bool) preg_match('/^کاظم/u', $word) => 'کاظمین',
            default => null,
        };
    }

    /** The words around what was found, up to the marks that end a clause, for a reviewer to judge the reading by */
    private static function clause(string $text, int $at, int $length): string
    {
        $before = mb_strcut($text, max(0, $at - 120), min(120, $at));
        $after = mb_strcut($text, $at + $length, 120);
        $before = preg_replace('/^.*[.؛:!?؟]/us', '', $before);
        $after = preg_replace('/[.؛:!?؟].*$/us', '', $after);

        return trim($before . mb_strcut($text, $at, $length) . $after);
    }

    /** @return array{0: string, 1: array<string, string>} */
    private static function names(): array
    {
        if (self::$names !== null) {
            return self::$names;
        }
        $cityOf = [];
        foreach (CopyPlaces::cities() as $city => $info) {
            foreach ([$city, ...($info['aliases'] ?? [])] as $name) {
                // «مصر» and «الشام» in a life are the country, not Cairo and Damascus
                if (! in_array($name, self::COUNTRIES, true)) {
                    $cityOf[TextNormalizer::normalize($name)] ??= $city;
                }
            }
        }
        // The longest first, so «تربت حیدریه» is not read as a shorter name
        $names = array_keys($cityOf);
        usort($names, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return self::$names = [implode('|', array_map(fn ($n) => str_replace(' ', '\s+', preg_quote($n, '/')), $names)), $cityOf];
    }

    /**
     * The countries and regions by their names, without the names the cities have («بحرین», «کشمیر» are cities of
     * the map as well, and are read as those).
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private static function regionNames(): array
    {
        if (self::$regionNames !== null) {
            return self::$regionNames;
        }
        $cities = self::names()[1];
        $regionOf = [];
        foreach (CopyPlaces::regions() as $region => $info) {
            foreach ([$region, ...($info['aliases'] ?? [])] as $name) {
                $name = TextNormalizer::normalize($name);
                if (! isset($cities[$name])) {
                    $regionOf[$name] ??= $region;
                }
            }
        }
        $names = array_keys($regionOf);
        usort($names, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return self::$regionNames = [implode('|', array_map(fn ($n) => str_replace(' ', '\s+', preg_quote($n, '/')), $names)), $regionOf];
    }

    /** For the tests, which change the config */
    public static function forget(): void
    {
        self::$nisbas = self::$names = self::$regionNames = null;
    }
}

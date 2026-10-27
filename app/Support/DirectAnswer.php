<?php

namespace App\Support;

use App\Helpers\TextNormalizer;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\SourceEntry;
use App\Models\Work;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * A search that names one record rather than a topic (backlog item 83, phase 2): a shelfmark with its library
 * («مجلس ۱۱۸۹»), a bare number («۱۱۸۹», which libraries hold it), or a place in a printed catalogue
 * («مرعشی ج ۳۳ ص ۲۹۴», «فنخا ج ۱۲ ص ۳۴۵»). Answered from the database, not the search engine, so a shelfmark
 * matches only as a shelfmark and not as a year or a page somewhere in a record.
 */
class DirectAnswer
{
    public const SHELFMARK = 'shelfmark';
    public const NUMBER = 'number';
    public const CITATION = 'citation';

    /** Words around a shelfmark or a citation that name nothing */
    private const FILLER = ['کتابخانه', 'کتابخانه ی', 'کتابخانه‌ی', 'ی', 'نسخه', 'نسخه ی', 'شماره', 'شماره ی', 'ش', 'شم', 'فهرست', 'ف', 'در', 'از', 'نسخ', 'خطی', 'مخطوط'];

    /** The union catalogue is cited by these, not by a library */
    private const FANKHA = ['فنخا', 'فهرستگان'];

    private const LIMIT = 200;

    public function __construct(
        public string $kind,
        public string $number,
        public ?Library $library = null,
        public Collection $alternatives = new Collection,
        public Collection $manuscripts = new Collection,
        public Collection $works = new Collection,
        /** For a bare number: library => how many of its shelfmarks are this number */
        public Collection $libraryCounts = new Collection,
        public ?int $volume = null,
        public ?int $page = null,
        public bool $fankha = false,
        /** The printed entries of a source on the page (al-Dhari'a…), and that source */
        public Collection $items = new Collection,
        public ?\App\Models\Source $source = null,
    ) {}

    public static function for(string $query): ?self
    {
        $text = TextNormalizer::normalize($query);
        if ($text === '' || ! preg_match('/\d/', $text)) {
            return null;
        }

        // A catalogue page: «ص ۲۹۴», with an optional «ج ۳۳»
        if (preg_match('/(?<!\p{L})(?:ص|صفحه|صص)\s*\.?\s*(\d+)/u', $text, $page)) {
            $volume = preg_match('/(?<!\p{L})(?:ج|جلد)\s*\.?\s*(\d+)/u', $text, $v) ? (int) $v[1] : null;
            $rest = self::words(preg_replace('/(?<!\p{L})(?:ص|صفحه|صص|ج|جلد)\s*\.?\s*\d+/u', ' ', $text));

            return self::citation($rest, $volume, (int) $page[1]);
        }

        if (! preg_match('~(?<![\p{L}\d])(\d+(?:\s*/\s*\d+)?)(?!\d)~u', $text, $m)) {
            return null;
        }
        $number = preg_replace('/\s+/u', '', $m[1]);
        $rest = self::words(str_replace($m[0], ' ', $text));
        if ($rest === '') {
            return self::bareNumber($number);
        }

        [$library, $alternatives, $leftover] = self::matchLibrary($rest);
        if (! $library) {
            return null;
        }
        $manuscripts = self::byShelfmark($library->id, $number, $leftover);

        return new self(self::SHELFMARK, $number, $library, $alternatives, $manuscripts);
    }

    /** The single manuscript the search names, to go to it directly */
    public function single(): ?Manuscript
    {
        return $this->kind === self::SHELFMARK && $this->manuscripts->count() === 1 ? $this->manuscripts->first() : null;
    }

    public function isEmpty(): bool
    {
        return $this->manuscripts->isEmpty() && $this->works->isEmpty() && $this->libraryCounts->isEmpty() && $this->items->isEmpty();
    }

    /** Manuscripts of a library whose shelfmark is the number, or a part or variant of it: «1189»، «1189/4»، «1189 ط» */
    public static function byShelfmark(int $libraryId, string $number, string $suffix = ''): Collection
    {
        $found = self::shelfmarkQuery($number)->where('library_id', $libraryId)
            ->with(['work.author', 'libraryRecord'])->limit(self::LIMIT)->get();

        // «۱۱۸۹ ط»: the letters after the number pick one collection of the library, when any shelfmark has them
        if ($suffix !== '') {
            $withSuffix = $found->filter(fn (Manuscript $m) => str_contains(TextNormalizer::normalize($m->shelfmark), $suffix));
            $found = $withSuffix->isNotEmpty() ? $withSuffix : $found;
        }

        return $found->sortBy(function (Manuscript $m) use ($number) {
            $key = Manuscript::collectionKey($m->shelfmark);

            // The shelfmark itself, then the parts of its bound volume in order, then other collections' marks
            return [TextNormalizer::normalize($m->shelfmark) === $number ? 0 : ($key ? 1 : 2), (int) ($key['part'] ?? 0), $m->id];
        })->values();
    }

    private static function bareNumber(string $number): ?self
    {
        $counts = self::shelfmarkQuery($number)->whereNotNull('library_id')
            ->selectRaw('library_id, COUNT(*) AS n')->groupBy('library_id')->orderByDesc('n')->pluck('n', 'library_id');
        if ($counts->isEmpty()) {
            return null;
        }
        $libraries = Library::whereIn('id', $counts->keys())->get()->keyBy('id');

        return new self(self::NUMBER, $number, libraryCounts: $counts->mapWithKeys(fn ($n, $id) => [$id => ['library' => $libraries->get($id), 'count' => (int) $n]])
            ->filter(fn ($row) => $row['library'])
            ->sortByDesc(fn ($row) => [$row['count'], $row['library']->manuscripts_count]));
    }

    private static function citation(string $rest, ?int $volume, int $page): ?self
    {
        $fankha = (bool) array_intersect(explode(' ', $rest), self::FANKHA);
        if ($fankha) {
            // The union catalogue's volume and page are the record's own (volume_number, page_start…page_end)
            if (! $volume) {
                return null;
            }
            $onPage = fn ($q) => $q->where('volume_number', $volume)->where('page_start', '<=', $page)
                ->where(fn ($q) => $q->where('page_end', '>=', $page)->orWhere(fn ($q) => $q->whereNull('page_end')->where('page_start', $page)));

            return new self(self::CITATION, (string) $page,
                manuscripts: $onPage(Manuscript::query())->with(['work.author', 'libraryRecord'])->orderBy('page_start')->orderBy('sequence_number')->limit(self::LIMIT)->get(),
                works: $onPage(Work::query())->with('author')->orderBy('page_start')->limit(self::LIMIT)->get(),
                volume: $volume, page: $page, fankha: true);
        }

        // A source whose printed entries the site holds: «ذریعه ج ۳ ص ۴۰۲» (phase 7)
        if ($source = self::matchSource($rest)) {
            $items = \App\Models\SourceItem::with('source')->where('source_id', $source->id)->where('kind', '!=', 'preface')
                ->when($volume, fn ($q) => $q->where('volume_number', $volume))
                ->where('page_start', '<=', $page)->whereRaw('COALESCE(page_end, page_start) >= ?', [$page])
                ->orderBy('volume_number')->orderBy('position')->limit(self::LIMIT)->get();

            return new self(self::CITATION, (string) $page, volume: $volume, page: $page, items: $items, source: $source);
        }

        [$library, $alternatives] = self::matchLibrary($rest);
        if (! $library) {
            return null;
        }

        // The library's own printed catalogues: the entries that begin on the page, or else the one running onto it
        $entries = fn () => SourceEntry::query()
            ->whereHas('source', fn ($q) => $q->where('library_id', $library->id))
            ->where('citable_type', Manuscript::class)
            // A link the reviewers rejected points at the wrong manuscript
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', SourceEntry::STATUS_REJECTED))
            ->when($volume, fn ($q) => $q->where('volume_number', $volume));
        $ids = $entries()->where('page_start', $page)->pluck('citable_id');
        if ($ids->isEmpty()) {
            $ids = $entries()->where('page_start', '<', $page)->where('page_end', '>=', $page)->pluck('citable_id');
        }

        return new self(self::CITATION, (string) $page, $library, $alternatives,
            Manuscript::whereKey($ids->unique()->take(self::LIMIT))->with(['work.author', 'libraryRecord'])->get()->sortBy('shelfmark_key')->values(),
            volume: $volume, page: $page);
    }

    private static function shelfmarkQuery(string $number)
    {
        $forms = array_unique([$number, strtr($number, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹'])]);

        return Manuscript::query()->where(function ($q) use ($forms) {
            foreach ($forms as $form) {
                $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $form);
                // The shelfmark itself, a part of its bound volume («1189/4»), or the number with a collection's
                // mark («1189 ط»، «1189ع»، «3429ـ ف»): anything but another digit after it. The prefix uses the index.
                $q->orWhere(fn ($q) => $q->where('shelfmark_key', 'like', "{$like}%")
                    ->whereRaw('shelfmark_key REGEXP ?', ['^' . preg_quote($form) . '([^0-9۰-۹]|$)']));
            }
        });
    }

    /** The source with printed entries on the site that the words name, by its short name or title, with or without the article */
    private static function matchSource(string $words): ?\App\Models\Source
    {
        $padded = ' ' . $words . ' ';
        // id => the normalized forms of its names; plain arrays, as the cache serializes them
        $sources = Cache::remember('direct-answer.item-sources', 3600, function () {
            $forms = [];
            foreach (\App\Models\Source::whereIn('id', \App\Models\SourceItem::query()->select('source_id')->distinct())->get(['id', 'short_name', 'title']) as $source) {
                foreach (array_filter([$source->short_name, $source->title]) as $name) {
                    $name = self::words(TextNormalizer::normalize($name));
                    // «الذریعه» is also «ذریعه»
                    $forms[$source->id][] = $name;
                    $forms[$source->id][] = preg_replace('/(?<!\p{L})ال(?=\p{L}{3,})/u', '', $name);
                }
                $forms[$source->id] = array_values(array_unique(array_filter($forms[$source->id] ?? [])));
            }

            return $forms;
        });
        foreach ($sources as $id => $forms) {
            foreach ($forms as $form) {
                if (str_contains($padded, " {$form} ")) {
                    return \App\Models\Source::find($id);
                }
            }
        }

        return null;
    }

    /** The text without punctuation and filler words */
    private static function words(string $text): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return implode(' ', array_diff($words, self::FILLER));
    }

    /**
     * The library the words name, by its name, full name, other names or name and city: the longest name found
     * wins, then a library in a city the words also name, then the larger. Returns [library, the others that
     * fit as well, the words left over].
     *
     * @return array{0: ?Library, 1: Collection, 2: string}
     */
    private static function matchLibrary(string $words): array
    {
        $padded = " {$words} ";
        $hits = [];
        foreach (self::libraryNames() as $lib) {
            foreach ($lib['names'] as $name) {
                if (mb_strlen($name) >= 2 && str_contains($padded, " {$name} ")) {
                    $len = mb_strlen($name);
                    if ($len > ($hits[$lib['id']]['len'] ?? 0)) {
                        $hits[$lib['id']] = ['len' => $len, 'name' => $name, 'city' => $lib['city'] !== '' && str_contains($padded, " {$lib['city']} "), 'count' => $lib['count']];
                    }
                }
            }
        }
        if (! $hits) {
            return [null, collect(), $words];
        }

        uasort($hits, fn ($a, $b) => [$b['len'], $b['city'], $b['count']] <=> [$a['len'], $a['city'], $a['count']]);
        $best = reset($hits);
        $id = array_key_first($hits);
        // Others named the same way: «مجلس» is in Tehran and in Qom
        $others = array_keys(array_filter($hits, fn ($h, $hid) => $hid !== $id && $h['len'] === $best['len'] && $h['city'] === $best['city'], ARRAY_FILTER_USE_BOTH));
        $libraries = Library::whereIn('id', [$id, ...$others])->get()->keyBy('id');

        $leftover = trim(preg_replace('/\s+/u', ' ', str_replace(" {$best['name']} ", ' ', $padded)));
        $city = self::libraryNames()[$id]['city'] ?? '';
        if ($best['city']) {
            $leftover = trim(preg_replace('/\s+/u', ' ', str_replace(" {$city} ", ' ', " {$leftover} ")));
        }

        return [$libraries->get($id), collect($others)->map(fn ($o) => $libraries->get($o))->filter()->values(), $leftover];
    }

    private const LIBRARIES_KEY = 'direct-answer.library-names';

    public static function forgetLibraries(): void
    {
        Cache::forget(self::LIBRARIES_KEY);
    }

    /** id => normalized names and city of every library with manuscripts; cached for an hour, dropped when a library changes */
    private static function libraryNames(): array
    {
        return Cache::remember(self::LIBRARIES_KEY, 3600, function () {
            $names = [];
            foreach (Library::where('manuscripts_count', '>', 0)->get(['id', 'name', 'full_name', 'aliases', 'city', 'manuscripts_count']) as $lib) {
                $city = TextNormalizer::normalize($lib->city);
                $forms = [$lib->name, ...(array) ($lib->aliases ?? [])];
                if ($lib->full_name) {
                    // «کتابخانه مجلس (تهران)» is also «مجلس»
                    $full = trim(preg_replace('/\([^)]*\)/u', '', $lib->full_name));
                    $forms[] = $full;
                    $forms[] = preg_replace('/^کتابخانه(?:\s|‌ی\s|ی\s)/u', '', $full);
                }
                $set = [];
                foreach ($forms as $form) {
                    $form = self::words(TextNormalizer::normalize((string) $form));
                    if ($form !== '') {
                        $set[] = $form;
                        if ($city !== '') {
                            $set[] = "{$form} {$city}";
                        }
                    }
                }
                $names[$lib->id] = ['id' => $lib->id, 'names' => array_values(array_unique($set)), 'city' => $city, 'count' => (int) $lib->manuscripts_count];
            }

            return $names;
        });
    }
}

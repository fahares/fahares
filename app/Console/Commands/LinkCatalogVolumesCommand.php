<?php

namespace App\Console\Commands;

use App\Models\Cataloger;
use App\Models\CatalogVolume;
use App\Models\Library;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Links manuscripts without a cataloger to their catalog volume and cataloger (backlog item 49).
 *
 * The citation is read from metadata.catalog_citation, or from the last bracket of raw_text when the
 * corpus parser left it among the editorial notes («[الفبائی: -512]», «[ف مخ: 1]», «[رایانه]» …).
 * Volumes and catalogers come from database/data/catalog_volume_links.json, sourced from MTIF or the
 * catalogue bibliography in Fankha's introduction, and approved by the user. Everything is matched by
 * natural key (library slug, MTIF entry ids, volume title within its library, cataloger slug), never
 * by local id, so the same command runs on production. Safe to run again.
 *
 * It also:
 *  - moves the Malek catalogue volumes that carried the Razavi library id back to Malek, and relinks
 *    the Razavi copies wrongly attached to them to the Razavi volume their citation names;
 *  - marks copies whose only source is the library's database («رایانه») or register («د.ث.»/«د.ت.»)
 *    in metadata.catalog_source, since no printed catalogue and so no cataloger exists for them.
 */
class LinkCatalogVolumesCommand extends Command
{
    protected $signature = 'catalogs:link-volumes
        {--dry-run : Report the changes without saving them}
        {--data= : Path to the links file (default database/data/catalog_volume_links.json)}';

    protected $description = 'Link manuscripts to sourced catalog volumes and catalogers by natural key';

    public const SOURCE_LIBRARY_DATABASE = 'library_database';

    public const SOURCE_REGISTER = 'register';

    protected bool $dry = false;

    public function handle(): int
    {
        ini_set('memory_limit', '1024M');
        DB::disableQueryLog();
        $this->dry = (bool) $this->option('dry-run');

        $path = $this->option('data') ?: database_path('data/catalog_volume_links.json');
        if (! File::exists($path)) {
            $this->error("Links file not found: {$path}");

            return self::FAILURE;
        }
        $data = json_decode(File::get($path), true);

        $before = $this->counts();
        $this->line("Before: {$before['no_cataloger']} without cataloger, {$before['no_volume']} without volume");
        if ($this->dry) {
            $this->warn('Dry run: nothing is saved.');
        }

        $plan = $this->resolveLinks($data['links'] ?? []);
        if ($plan === null) {
            return self::FAILURE;
        }

        $run = function () use ($plan, $data) {
            if (isset($data['malek'])) {
                $this->fixMalek($data['malek']);
            }
            foreach ($plan as $link) {
                $this->applyLink($link);
            }
            $this->markNonCatalogSources();
            if (! $this->dry) {
                $this->recount();
            }
        };
        $this->dry ? $run() : DB::transaction($run);

        if (! $this->dry) {
            $after = $this->counts();
            $this->info("After: {$after['no_cataloger']} without cataloger, {$after['no_volume']} without volume");
        }

        return self::SUCCESS;
    }

    /** @return array{no_cataloger:int, no_volume:int} */
    protected function counts(): array
    {
        return [
            'no_cataloger' => DB::table('manuscripts')->whereNull('cataloger_id')->count(),
            'no_volume' => DB::table('manuscripts')->whereNull('catalog_volume_id')->count(),
        ];
    }

    /**
     * Checks every library exists before anything is written.
     *
     * @return array<int, array<string, mixed>>|null
     */
    protected function resolveLinks(array $links): ?array
    {
        $plan = [];
        foreach ($links as $link) {
            $library = Library::where('slug', $link['library'])->first();
            if (! $library) {
                $this->error("Library not found by slug: {$link['library']}");

                return null;
            }
            // a catalogue shared by two library records (one catalogue, or a duplicate library) keeps its
            // volumes under one of them
            $volumeLibrary = $library;
            if (! empty($link['volume_library'])) {
                $volumeLibrary = Library::where('slug', $link['volume_library'])->first();
                if (! $volumeLibrary) {
                    $this->error("Library not found by slug: {$link['volume_library']}");

                    return null;
                }
            }
            $plan[] = $link + ['library_model' => $library, 'volume_library_model' => $volumeLibrary];
        }

        return $plan;
    }

    protected function applyLink(array $link): void
    {
        /** @var Library $library */
        $library = $link['library_model'];
        $catalogers = $this->catalogers($link);
        $primary = $catalogers->first();

        // volume number => volume id
        $volumes = [];
        foreach ($link['volumes'] as $spec) {
            $volume = $this->volume($link['volume_library_model'], $spec, $link['source']);
            $volumes[(int) $spec['number']] = $volume?->id;
            if ($volume && ! $this->dry) {
                $volume->catalogers()->syncWithoutDetaching(
                    $catalogers->mapWithKeys(fn ($c) => [$c->id => ['role' => 'cataloger']])->all()
                );
            }
        }
        $single = count($volumes) === 1 ? array_key_first($volumes) : null;

        $matched = [];
        $skipped = 0;
        DB::table('manuscripts')->where('library_id', $library->id)->whereNull('cataloger_id')
            ->select(['id', 'metadata', 'raw_text'])
            ->chunkById(2000, function ($chunk) use ($link, $volumes, $single, &$matched, &$skipped) {
                foreach ($chunk as $ms) {
                    $parsed = self::parseCitation(self::citationOf($ms->metadata, $ms->raw_text), $link['series']);
                    if (! $parsed) {
                        continue;
                    }
                    $number = $parsed['volume'] ?? $single;
                    if ($number !== null && array_key_exists($number, $volumes)) {
                        $matched[$number][] = $ms->id;
                    } else {
                        $skipped++;
                    }
                }
            });

        $total = 0;
        foreach ($matched as $number => $ids) {
            $total += count($ids);
            if (! $this->dry) {
                foreach (array_chunk($ids, 1000) as $part) {
                    DB::table('manuscripts')->whereIn('id', $part)->update([
                        'catalog_volume_id' => $volumes[$number],
                        'cataloger_id' => $primary?->id,
                    ]);
                }
            }
        }
        $names = $catalogers->pluck('name')->implode('، ');
        $this->line(sprintf('%-40s [%s] %5d linked%s → %s (%s)', $library->name, $link['series'], $total,
            $skipped ? ", {$skipped} with an unlisted volume" : '', $names, $link['source']));
    }

    /**
     * Catalogers of a link in order, matched by MTIF id, else by slug (for those MTIF lacks, or records
     * created before their MTIF id was known, which then receive it). New ones are created only when
     * marked so.
     */
    protected function catalogers(array $link): Collection
    {
        return collect($link['catalogers'])->map(function ($spec) use ($link) {
            $mtif = $spec['mtif_entry_id'] ?? null;
            $cataloger = $mtif ? Cataloger::where('mtif_entry_id', $mtif)->first() : null;
            if (! $cataloger && ! empty($spec['slug'])) {
                $cataloger = Cataloger::where('slug', $spec['slug'])->first();
                if ($cataloger && $mtif && ! $cataloger->mtif_entry_id && ! $this->dry) {
                    $cataloger->update(['mtif_entry_id' => $mtif]);
                }
            }
            if ($cataloger) {
                return $cataloger;
            }
            $key = $mtif ? "MTIF id {$mtif}" : 'slug ' . ($spec['slug'] ?? '?');
            if (empty($spec['new'])) {
                throw new \RuntimeException("Cataloger with {$key} ({$spec['name']}) not found");
            }
            $this->line("  new cataloger: {$spec['name']} ({$key})");
            if ($this->dry) {
                return new Cataloger(['name' => $spec['name']]);
            }

            return Cataloger::create([
                'name' => $spec['name'],
                'slug' => $spec['slug'] ?? Str::slug($spec['name'], '-', null),
                'mtif_entry_id' => $mtif,
                'metadata' => ['sources' => [($mtif ? 'رکورد MTIF' : 'منبع') . " ({$link['source']}): «{$link['source_text']}»"]],
            ]);
        });
    }

    /**
     * Finds the volume within the library by the given match title, its MTIF id, or its own title (the
     * key of a volume MTIF lacks), or creates it.
     */
    protected function volume(Library $library, array $spec, string $source): ?CatalogVolume
    {
        $volume = null;
        if (! empty($spec['match_title'])) {
            $volume = CatalogVolume::where('library_id', $library->id)->where('title', $spec['match_title'])->first();
        }
        if (! $volume && ! empty($spec['mtif_entry_id'])) {
            $volume = CatalogVolume::where('mtif_entry_id', $spec['mtif_entry_id'])->where('library_id', $library->id)->first();
        }
        $volume ??= CatalogVolume::where('library_id', $library->id)->where('title', $spec['title'])->first();
        if ($volume) {
            return $volume;
        }
        $this->line("  new volume: {$spec['title']}");
        if ($this->dry) {
            return new CatalogVolume(['title' => $spec['title']]);
        }

        return CatalogVolume::create([
            'title' => $spec['title'],
            'volume_number' => $spec['number'],
            'library_id' => $library->id,
            'catalog_id' => 1,
            'publication_year' => $spec['publication_year'] ?? null,
            'pages_count' => $spec['pages_count'] ?? null,
            'mtif_entry_id' => $spec['mtif_entry_id'] ?? null,
            'metadata' => ['source' => $source],
        ]);
    }

    /**
     * The Malek volumes were filed under the Razavi library, so the Razavi rule «ف: N» picked the Malek
     * volume N. Move them to Malek and send the Razavi copies to the Razavi volume their citation names,
     * or to none when it names another series («اهدائی ف»).
     */
    protected function fixMalek(array $spec): void
    {
        $malek = Library::where('slug', $spec['library'])->firstOrFail();
        $razavi = Library::where('slug', $spec['razavi_library'])->firstOrFail();
        $malekVolumes = CatalogVolume::whereIn('mtif_entry_id', $spec['volumes'])->get();
        $razaviVolumes = CatalogVolume::where('library_id', $razavi->id)
            ->whereIn('mtif_entry_id', array_values($spec['razavi_volumes']))->get()->keyBy('mtif_entry_id');

        $moves = [];
        DB::table('manuscripts')->whereIn('catalog_volume_id', $malekVolumes->pluck('id'))
            ->where('library_id', $razavi->id)->select(['id', 'metadata', 'raw_text'])->orderBy('id')
            ->each(function ($ms) use ($spec, $razaviVolumes, &$moves) {
                $parsed = self::parseCitation(self::citationOf($ms->metadata, $ms->raw_text), 'ف');
                $mtif = $parsed && $parsed['volume'] ? ($spec['razavi_volumes'][(string) $parsed['volume']] ?? null) : null;
                $moves[$mtif ? ($razaviVolumes->get($mtif)?->id ?? 0) : 0][] = $ms->id;
            });

        $relinked = 0;
        foreach ($moves as $volumeId => $ids) {
            $volume = $volumeId ? $razaviVolumes->firstWhere('id', $volumeId) : null;
            $catalogerId = $volume?->catalogers()->wherePivot('role', 'cataloger')->orderBy('catalog_volume_cataloger.id')->value('catalogers.id');
            if ($volume) {
                $relinked += count($ids);
            }
            if (! $this->dry) {
                DB::table('manuscripts')->whereIn('id', $ids)->update(['catalog_volume_id' => $volume?->id, 'cataloger_id' => $catalogerId]);
            }
        }
        $total = array_sum(array_map('count', $moves));
        $this->line("Malek volumes moved to «{$malek->name}»: {$malekVolumes->count()}; Razavi copies relinked: {$relinked}, unlinked: " . ($total - $relinked));
        if (! $this->dry) {
            CatalogVolume::whereIn('id', $malekVolumes->pluck('id'))->update(['library_id' => $malek->id]);
        }
    }

    protected function markNonCatalogSources(): void
    {
        $marked = [self::SOURCE_LIBRARY_DATABASE => [], self::SOURCE_REGISTER => []];
        DB::table('manuscripts')->whereNull('cataloger_id')->select(['id', 'metadata', 'raw_text'])
            ->chunkById(5000, function ($chunk) use (&$marked) {
                foreach ($chunk as $ms) {
                    if ($source = self::nonCatalogSource(self::citationOf($ms->metadata, $ms->raw_text))) {
                        $marked[$source][] = $ms->id;
                    }
                }
            });
        foreach ($marked as $source => $ids) {
            $this->line(sprintf('Marked catalog_source=%s: %d', $source, count($ids)));
            if (! $this->dry) {
                foreach (array_chunk($ids, 1000) as $part) {
                    DB::table('manuscripts')->whereIn('id', $part)
                        ->update(['metadata' => DB::raw("JSON_SET(COALESCE(metadata, '{}'), '$.catalog_source', '{$source}')")]);
                }
            }
        }
    }

    /** Same rule as the remediation command: direct links plus the copies of the cataloger's volumes. */
    protected function recount(): void
    {
        $counts = DB::table('manuscripts')->whereNotNull('catalog_volume_id')
            ->groupBy('catalog_volume_id')->selectRaw('catalog_volume_id, count(*) as n')->pluck('n', 'catalog_volume_id');
        CatalogVolume::query()->each(fn ($v) => $v->update(['manuscripts_count' => (int) ($counts[$v->id] ?? 0)]));

        Cataloger::query()->each(function (Cataloger $cataloger) {
            $volumeIds = $cataloger->catalogVolumes()->pluck('catalog_volumes.id');
            $cataloger->update([
                'volumes_count' => $volumeIds->count(),
                'manuscripts_count' => DB::table('manuscripts')
                    ->where(fn ($q) => $q->where('cataloger_id', $cataloger->id)->orWhereIn('catalog_volume_id', $volumeIds))
                    ->count(),
            ]);
        });
        $this->line('Volume and cataloger counts recalculated.');
    }

    /** The catalogue citation: the parsed field, else the last bracket of the raw text. */
    public static function citationOf(?string $metadata, ?string $rawText): string
    {
        $citation = $metadata ? (json_decode($metadata, true)['catalog_citation'] ?? '') : '';
        if (! $citation && $rawText) {
            $raw = preg_replace('/<!--.*?-->/su', '', $rawText);
            if (preg_match_all('/\[([^\[\]]*)\]/u', $raw, $m)) {
                $citation = end($m[1]);
            }
        }

        return self::normalize((string) $citation);
    }

    public static function normalize(string $text): string
    {
        $text = preg_replace('/<!--.*?-->/su', '', $text);
        $text = strtr($text, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            'ي' => 'ی', 'ك' => 'ک', "\u{200C}" => ' ', '–' => '-', 'ـ' => '-',
        ]);

        return trim(preg_replace('/\s+/u', ' ', $text), " []\t\n");
    }

    /**
     * Parses a citation of the given series. «ف» is the library's own catalogue («ف: 2-45», «ف: -50»);
     * any other series is a named catalogue («الفبائی: -512», «کتابخانه‌های قائن: ف: 75», «ف مخ: 1»).
     * Returns null when the citation is of another series, else the volume number (null when not given).
     *
     * @return array{volume: int|null}|null
     */
    public static function parseCitation(string $citation, string $series): ?array
    {
        $series = self::normalize($series);
        if ($series === 'ف') {
            if (! preg_match('/^(?:ف|فهرست)\s*:\s*(.*)$/u', $citation, $m)) {
                return null;
            }
            $rest = $m[1];
        } else {
            if (! str_starts_with($citation, $series)) {
                return null;
            }
            $rest = mb_substr($citation, mb_strlen($series));
            // «ف مخ» must not take «ف مخطوطات…», and a longer series name must not be cut short
            if ($rest !== '' && ! preg_match('/^[\s:\-؛،]/u', $rest)) {
                return null;
            }
            $rest = preg_replace('/^[\s:\-؛،]*(?:ف\s*:)?\s*/u', '', $rest);
        }

        return ['volume' => preg_match('/^(\d+)\s*-\s*\d/u', trim($rest), $v) ? (int) $v[1] : null];
    }

    /** «رایانه» is the library's own database and «د.ث.»/«د.ت.» its register: no printed catalogue. */
    public static function nonCatalogSource(string $citation): ?string
    {
        if (preg_match('/^رایانه/u', $citation)) {
            return self::SOURCE_LIBRARY_DATABASE;
        }
        if (preg_match('/^د\s*\.\s*[ثت]/u', $citation)) {
            return self::SOURCE_REGISTER;
        }

        return null;
    }
}

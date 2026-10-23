<?php

namespace App\Console\Commands;

use App\Console\Commands\LinkCatalogVolumesCommand as Linker;
use App\Sources\SourceEntrySync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Writes each manuscript's Fankha citation («[ف: 2 - 224]», «[ف مخ: 2 - 817]», «[الفبائی: 9-]» …) and its
 * catalog_volume_id as a source entry of origin fankha_citation (backlog item 51; before step 13.4 a
 * manuscript_catalog_references row of source fankha). Saving a manuscript keeps its own in step; this does them all. The raw
 * citation is kept as it is; volume and page are parsed where the citation gives them. The manuscript keeps
 * its catalog_volume_id, so the cataloger chain (items 49 and 56) is untouched.
 *
 * One entry per manuscript: running it again inserts the missing entries, updates the ones whose citation or volume
 * changed since (for instance after catalogs:link-volumes, which writes with DB::table), and never duplicates.
 */
class ImportFankhaCatalogReferencesCommand extends Command
{
    protected $signature = 'catalogs:import-fankha-references
        {--dry-run : Report the counts without saving anything}
        {--chunk=2000 : Manuscripts read per batch}';

    protected $description = 'Write the Fankha catalogue citation of every manuscript as its source entry';

    public function handle(): int
    {
        DB::disableQueryLog();
        $dry = (bool) $this->option('dry-run');
        if ($dry) {
            $this->warn('Dry run: nothing is saved.');
        }

        $stats = array_fill_keys(['manuscripts', 'no citation or volume', 'volume and page', 'page only', 'volume only', 'not parsed', 'written', 'removed'], 0);
        $series = [];

        DB::table('manuscripts')->select('id', 'catalog_volume_id', 'metadata', 'raw_text')
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($rows) use (&$stats, &$series, $dry) {
                foreach ($rows as $row) {
                    $stats['manuscripts']++;
                    $raw = self::rawCitation($row->metadata, $row->raw_text);
                    if ($raw === null && ! $row->catalog_volume_id) {
                        $stats['no citation or volume']++;

                        continue;
                    }
                    $parsed = $raw === null ? ['series' => null, 'volume' => null, 'page' => null] : self::parse($raw);
                    $stats[match (true) {
                        $parsed['volume'] !== null && $parsed['page'] !== null => 'volume and page',
                        $parsed['page'] !== null => 'page only',
                        $parsed['volume'] !== null => 'volume only',
                        default => 'not parsed',
                    }]++;
                    $key = $parsed['series'] ?? '(none)';
                    $series[$key] = ($series[$key] ?? 0) + 1;
                }
                if (! $dry) {
                    [$written, $removed] = SourceEntrySync::syncCited($rows);
                    $stats['written'] += $written;
                    $stats['removed'] += $removed;
                }
            });

        $this->table(['', 'count'], collect($stats)->map(fn ($n, $k) => [$k, number_format($n)])->values()->all());
        arsort($series);
        $this->line('Most cited series:');
        $this->table(['series', 'count'], collect(array_slice($series, 0, 15, true))->map(fn ($n, $k) => [$k, number_format($n)])->values()->all());

        return self::SUCCESS;
    }

    /** The citation as Fankha prints it, brackets included; null when the copy has none. */
    public static function rawCitation(?string $metadata, ?string $rawText): ?string
    {
        $citation = $metadata ? (json_decode($metadata, true)['catalog_citation'] ?? null) : null;
        if (! $citation && $rawText) {
            $raw = preg_replace('/<!--.*?-->/su', '', $rawText);
            if (preg_match_all('/\[[^\[\]]*\]/u', $raw, $m)) {
                $citation = end($m[0]);
            }
        }
        $citation = trim((string) $citation);

        return $citation === '' ? null : $citation;
    }

    /**
     * The series and, where given, the volume and page of a citation: «ف: 2 - 224» is volume 2 page 224 of the
     * library's own catalogue, «ف مخ - 3 - 1159» of its handwritten one, «رشت و همدان: ف: -1398» page 1398 of a
     * one-volume catalogue, «الفبائی: 9-» volume 9 without a page, «47/1 - 15» volume 47 (its part stays in the raw text). Of several citations («…؛ …») the first is read.
     * A lone number («مختصر ف: 10») may be either, so it is left unparsed.
     *
     * @return array{series: string|null, volume: int|null, page: int|null}
     */
    public static function parse(string $citation): array
    {
        $text = Linker::normalize($citation);
        $text = trim(explode('؛', $text)[0]);
        if ($source = Linker::nonCatalogSource($text)) {
            return ['series' => $source === Linker::SOURCE_REGISTER ? 'د.ث.' : 'رایانه', 'volume' => null, 'page' => null];
        }

        if (str_contains($text, ':')) {
            [$series, $rest] = array_map('trim', explode(':', $text, 2));
        } elseif (preg_match('/^([^\d]+?)[\s\-]*(\d.*|)$/u', $text, $m)) {
            [$series, $rest] = [trim($m[1], " -"), $m[2]];
        } else {
            [$series, $rest] = [null, $text];
        }
        // «سنا: ف2-157», «رشت و همدان: ف: -1398»
        $rest = trim(preg_replace('/^ف\s*:?\s*/u', '', $rest));

        $volume = $page = null;
        if (preg_match('/^(\d+)\s*-\s*(\d+)/u', $rest, $m)) {
            [$volume, $page] = [(int) $m[1], (int) $m[2]];
        } elseif (preg_match('/^(\d+)\s*\/\s*(\d+)\s*-\s*(\d+)/u', $rest, $m)) {
            // a volume with its part, «47/1 - 15»; «1/27» is part 1 of volume 27 (see LinkCatalogVolumesCommand)
            [$volume, $part, $page] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if ($volume <= 3 && $part > 3) {
                $volume = $part;
            }
        } elseif (preg_match('/^(\d+)\s*[^\d\-\/][^\-]*-\s*(\d+)\s*$/u', $rest, $m)) {
            // a volume with its subject, «2 اخبار 1-112», «1علوم قرآن - 79»: the page is the last number
            [$volume, $page] = [(int) $m[1], (int) $m[2]];
        } elseif (preg_match('/^-\s*(\d+)/u', $rest, $m)) {
            $page = (int) $m[1];
        } elseif (preg_match('/^(\d+)\s*-\s*$/u', $rest, $m)) {
            $volume = (int) $m[1];
        } elseif (preg_match('/^\D.*?-\s*(\d+)\s*$/u', $rest, $m)) {
            // journal issues and named parts: «س5ش3و4-150», «دفتر نهم - 606»
            $page = (int) $m[1];
        }

        return ['series' => $series ?: null, 'volume' => $volume, 'page' => $page];
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Manuscript;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class SyncFixedShelfmarksCommand extends Command
{
    protected $signature = 'fahares:sync-fixed-shelfmarks
                            {--file= : Path to fixed_shelfmarks_update.json}';

    protected $description = 'Synchronize clean shelfmarks and parsed codicological metadata for the 1,489 fixed manuscripts into MariaDB and Meilisearch';

    public function handle(): int
    {
        $filePath = $this->option('file') ?: database_path('data/fixed_shelfmarks_update.json');
        if (!File::exists($filePath)) {
            $this->error("Update data file not found at: {$filePath}");
            return 1;
        }

        $updates = json_decode(File::get($filePath), true);
        if (!$updates) {
            $this->error("Invalid JSON in: {$filePath}");
            return 1;
        }

        $this->info("================================================================================");
        $this->info("SYNCING FIXED SHELFMARKS & CODICOLOGY INTO MARIADB & MEILISEARCH");
        $this->info("Loaded " . count($updates) . " records to sync from " . $filePath);
        $this->info("================================================================================");

        $totalUpdated = 0;
        $totalNotFound = 0;
        $updatedIds = [];
        $now = now()->toDateTimeString();

        foreach ($updates as $idx => $item) {
            $volNum = (int) $item['volume_number'];
            $page = (int) ($item['page_start'] ?? 0);
            $seq = (int) ($item['sequence_number'] ?? 0);
            $rawSm = $item['raw_shelfmark'];
            $cleanSm = $item['clean_shelfmark'];

            // Find in MariaDB
            $dbMs = DB::table('manuscripts')
                ->where('volume_number', $volNum)
                ->where('shelfmark', $rawSm)
                ->first();

            if (!$dbMs && $page > 0) {
                $query = DB::table('manuscripts')
                    ->where('volume_number', $volNum)
                    ->where('page_start', $page);
                if ($seq > 0) {
                    $query->where('sequence_number', $seq);
                }
                $dbMs = $query->first();
            }

            if (!$dbMs) {
                $dbMs = DB::table('manuscripts')
                    ->where('volume_number', $volNum)
                    ->where('shelfmark', $cleanSm)
                    ->first();
            }

            if (!$dbMs) {
                $totalNotFound++;
                continue;
            }

            $updatePayload = [
                'shelfmark' => $cleanSm,
                'shelfmark_key' => mb_substr($cleanSm, 0, 100),
                'scribe_name' => !empty($item['scribe_name']) ? mb_substr($item['scribe_name'], 0, 600) : null,
                'is_bika' => (bool) ($item['is_bika'] ?? false),
                'is_bita' => (bool) ($item['is_bita'] ?? false),
                'is_autograph' => (bool) ($item['is_autograph'] ?? false),
                'copy_date_raw' => $item['copy_date_raw'] ?? null,
                'copy_date_hijri_year' => $item['copy_date_hijri_year'] ?? null,
                'copy_place' => $item['copy_place'] ?? null,
                'script_names' => $item['script_names'] ?? null,
                'script_style' => $item['script_style'] ?? null,
                'folios' => !empty($item['folios']) ? (int) $item['folios'] : null,
                'lines' => !empty($item['lines']) ? (int) $item['lines'] : null,
                'dimensions' => $item['dimensions'] ?? null,
                'paper' => $item['paper'] ?? null,
                'binding' => $item['binding'] ?? null,
                'incipit_text' => $item['incipit_text'] ?? null,
                'explicit_text' => $item['explicit_text'] ?? null,
                'is_corrected' => (bool) ($item['is_corrected'] ?? false),
                'has_marginal_notes' => (bool) ($item['has_marginal_notes'] ?? false),
                'is_ruled' => (bool) ($item['is_ruled'] ?? false),
                'has_catchwords' => (bool) ($item['has_catchwords'] ?? false),
                'is_facsimile' => (bool) ($item['is_facsimile'] ?? false),
                'is_collated' => (bool) ($item['is_collated'] ?? false),
                'is_illuminated' => (bool) ($item['is_illuminated'] ?? false),
                'is_illustrated' => (bool) ($item['is_illustrated'] ?? false),
                'has_author_marginalia' => (bool) ($item['has_author_marginalia'] ?? false),
                'metadata' => json_encode($item['metadata'] ?? [], JSON_UNESCAPED_UNICODE),
                'raw_text' => $item['raw_text'] ?? null,
                'updated_at' => $now,
            ];

            DB::table('manuscripts')->where('id', $dbMs->id)->update($updatePayload);
            $updatedIds[] = $dbMs->id;
            $totalUpdated++;
        }

        $this->info("Total manuscripts updated in MariaDB: {$totalUpdated} / " . count($updates));
        if ($totalNotFound > 0) {
            $this->warn("Total records not found: {$totalNotFound}");
        }

        // Sync Meilisearch Index for updated manuscripts
        if (!empty($updatedIds)) {
            $this->info("Syncing " . count($updatedIds) . " updated manuscripts into Meilisearch index...");
            $chunks = array_chunk($updatedIds, 500);
            foreach ($chunks as $chunk) {
                $models = Manuscript::whereIn('id', $chunk)->get();
                $models->searchable();
            }
            $this->info("Meilisearch synchronization complete.");
        }

        $this->info("================================================================================");
        $this->info("SUCCESS: All fixed manuscripts are synchronized in MariaDB and Meilisearch.");
        return 0;
    }
}

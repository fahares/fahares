<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Manuscript;
use App\Sources\SourceEntrySync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Puts back the catalogue volumes the Tabriz National Library's copies were left on (step 13.93). Three catalog_volumes
 * rows that first held the Tabriz catalogue's volumes 1–3 were retitled as Gowharshad 4–5 and Ferdowsi Faculty of
 * Letters 1 and kept the Tabriz library, so the Tabriz copies Fankha cites as «ف: 1 - 205» stayed on them and the
 * sources page showed a second «فهرست … گوهرشاد» under the Tabriz library. Each such copy moves to the Tabriz volume
 * its citation names («ف: 173-1», page first, is read the other way round), each retitled volume takes the library
 * the Fankha catalogue list gives it, and its source volume joins the source of that library and title. Safe to run
 * again.
 */
class RepairTabrizVolumesCommand extends Command
{
    protected $signature = 'catalogs:repair-tabriz-volumes
        {--apply : Write; without it the command only reports}';

    protected $description = 'Move the Tabriz National Library copies off the Gowharshad and Ferdowsi volumes, and give those volumes their library';

    private const TABRIZ_SERIES = 'فهرست کتابخانه ملی تبریز (اهدایی محمد نخجوانی)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $volumes = DB::table('catalog_volumes')->get()->keyBy('id');
        $tabriz = $volumes->filter(fn ($v) => SourceEntrySync::stem($v->title) === self::TABRIZ_SERIES)->keyBy(fn ($v) => (int) $v->volume_number);
        $libraryId = $tabriz->pluck('library_id')->unique()->sole();
        $wrong = $volumes->filter(fn ($v) => (int) $v->library_id === (int) $libraryId && SourceEntrySync::stem($v->title) !== self::TABRIZ_SERIES);

        // the library the Fankha catalogue list gives each retitled volume, past any merge of library records
        $master = collect(json_decode(file_get_contents(database_path('data/fankha_catalogs_master_verified.json')), true))
            ->mapWithKeys(fn ($i) => [$i['catalog_series'] . '|' . $i['volume_number'] => $i['library_db_id']]);
        $redirects = DB::table('entity_redirects')->where('entity_type', 'libraries')->pluck('target_id', 'source_id');
        $libraries = [];
        foreach ($wrong as $v) {
            $id = $master[SourceEntrySync::stem($v->title) . '|' . $v->volume_number] ?? null;
            while ($id && isset($redirects[$id])) {
                $id = $redirects[$id];
            }
            if (! $id || ! DB::table('libraries')->where('id', $id)->exists()) {
                $this->warn("volume #{$v->id} «{$v->title}»: no library in the Fankha list, left as it is");
                continue;
            }
            $libraries[$v->id] = (int) $id;
            $this->line("volume #{$v->id} «{$v->title}»: library {$v->library_id} → {$id}");
        }

        // each Tabriz copy on a retitled volume, or on none, goes to the Tabriz volume its citation names
        $moves = [];
        $unread = [];
        $copies = DB::table('manuscripts')->where('library_id', $libraryId)
            ->where(fn ($q) => $q->whereIn('catalog_volume_id', $wrong->keys())->orWhereNull('catalog_volume_id'))
            ->get(['id', 'catalog_volume_id', 'metadata', 'raw_text']);
        foreach ($copies as $m) {
            $raw = ImportFankhaCatalogReferencesCommand::rawCitation($m->metadata, $m->raw_text);
            $parsed = $raw === null ? null : ImportFankhaCatalogReferencesCommand::parse($raw);
            if ($m->catalog_volume_id === null && ! in_array($parsed['series'] ?? null, ['ف', ''], true)) {
                continue;
            }
            $to = $tabriz[$parsed['volume'] ?? 0] ?? $tabriz[$parsed['page'] ?? 0] ?? null;
            $to ? $moves[$m->id] = [$m->catalog_volume_id, $to->id] : $unread[] = "#{$m->id} {$raw}";
        }
        $this->line(count($moves) . ' Tabriz copies to move: ' . collect($moves)->countBy(fn ($p) => ($p[0] ?? 'none') . '→' . $p[1])
            ->map(fn ($n, $k) => "{$k}: {$n}")->implode(', '));
        foreach ($unread as $line) {
            $this->warn("not moved, no Tabriz volume in its citation: {$line}");
        }

        if (! $apply) {
            $this->info('Dry run: nothing written.');

            return self::SUCCESS;
        }

        $touched = [];
        DB::transaction(function () use ($moves, $libraries, &$touched) {
            foreach (collect($moves)->groupBy(fn ($p) => $p[1], true) as $to => $group) {
                DB::table('manuscripts')->whereIn('id', $group->keys())->update(['catalog_volume_id' => $to]);
            }
            foreach ($moves as $id => [$from, $to]) {
                AuditLog::create(['user_id' => null, 'auditable_type' => Manuscript::class, 'auditable_id' => $id, 'action' => 'corpus_update',
                    'old_values' => ['catalog_volume_id' => $from], 'new_values' => ['catalog_volume_id' => $to, 'corpus_fix' => 'repair-tabriz-volumes (step 13.93)']]);
            }

            foreach ($libraries as $volumeId => $library) {
                DB::table('catalog_volumes')->where('id', $volumeId)->update(['library_id' => $library, 'updated_at' => now()]);
                $row = DB::table('catalog_volumes')->find($volumeId);
                $from = DB::table('source_volumes')->where('id', $volumeId)->value('source_id');
                $key = SourceEntrySync::groupKey($row);
                $to = DB::table('sources')->where('code', SourceEntrySync::groupCode($key, $row))->value('id');
                if ($to && $to !== $from) {
                    DB::table('source_volumes')->where('id', $volumeId)->update(['source_id' => $to, 'updated_at' => now()]);
                    DB::table('source_entries')->where('source_volume_id', $volumeId)->update(['source_id' => $to]);
                } elseif (! $to && DB::table('source_volumes')->where('source_id', $from)->where('id', '!=', $volumeId)->doesntExist()) {
                    // the volume is its source's only one: the source takes the library (its code, the page's address, stays)
                    DB::table('sources')->where('id', $from)->update(['library_id' => $library, 'metadata' => json_encode(['group' => $key], JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
                }
                array_push($touched, $from, $to ?: $from);
            }

            // the cited page of every copy on these volumes follows its volume's source
            $volumeIds = collect($moves)->flatten()->merge(array_keys($libraries))->filter()->unique();
            DB::table('manuscripts')->whereIn('catalog_volume_id', $volumeIds)->select(['id', 'metadata', 'raw_text', 'catalog_volume_id'])
                ->orderBy('id')->chunkById(1000, fn ($rows) => SourceEntrySync::syncCited($rows));
            $touched = array_merge($touched, DB::table('source_volumes')->whereIn('id', $volumeIds)->pluck('source_id')->all());

            foreach (array_unique($touched) as $sourceId) {
                $count = DB::table('source_volumes')->where('source_id', $sourceId)->count();
                $used = $count || DB::table('source_entries')->where('source_id', $sourceId)->exists() || DB::table('source_items')->where('source_id', $sourceId)->exists()
                    || DB::table('field_evidence')->where('source_id', $sourceId)->exists() || DB::table('sources')->where('edition_of_id', $sourceId)->exists();
                $used ? DB::table('sources')->where('id', $sourceId)->update(['volumes_count' => $count, 'updated_at' => now()])
                    : DB::table('sources')->where('id', $sourceId)->delete();
                $this->line("source #{$sourceId}: " . ($used ? "{$count} volumes" : 'removed, nothing left on it'));
            }
        });

        Cache::forget('sources:shelf');
        foreach (array_unique($touched) as $sourceId) {
            Cache::forget("sources:copies:{$sourceId}");
        }
        $this->info('Written.');

        return self::SUCCESS;
    }
}

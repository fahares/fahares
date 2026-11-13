<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Manuscript;
use App\Sources\SourceEntrySync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Moves the copies of one volume that Fankha cites in another part of it to that part's own volume (step 14.152).
 * Fankha names the part of a volume printed in parts: «ف: 33/1 - 172», «ف: 1 / 33 - 43», «ف: 2/33-150» (the print
 * sets it either way round). ImportFankhaCatalogReferencesCommand::parse keeps the volume only, so every copy of the
 * Majlis library's vol. 33 went to the one row «جلد ۲/۳۳» it had; once «جلد ۱/۳۳» exists (catalogs:set-facsimiles
 * makes it), the copies whose citation names part 1 move to it.
 *
 * The two volumes are found by library slug and exact title, never by local id. A copy moves when the citation's
 * volume number is --volume and its part --part; copies naming no part, or another, stay. The move writes
 * manuscripts.catalog_volume_id and the copy's manuscript_catalog_references row on the old volume, logs each copy in
 * audit_logs, and writes the cited page again (SourceEntrySync::syncCited) for the copies of both volumes. Without
 * --apply nothing is written. Safe to run again: a copy already moved is no longer on the old volume.
 */
class MoveCitedPartCommand extends Command
{
    protected $signature = 'catalogs:move-cited-part
        {--library= : Slug of the library}
        {--volume= : Volume number the citations give (33)}
        {--part= : Part whose copies move (1)}
        {--from= : Exact title of the volume the copies are on}
        {--to= : Exact title of the part\'s volume}
        {--apply : Write; without it the command only reports}';

    protected $description = 'Move the copies Fankha cites in a part of a volume to that part\'s own volume';

    public function handle(): int
    {
        foreach (['library', 'volume', 'part', 'from', 'to'] as $option) {
            if (blank($this->option($option))) {
                $this->error("--{$option} is required");

                return self::FAILURE;
            }
        }
        $libraryId = DB::table('libraries')->where('slug', $this->option('library'))->value('id');
        $find = fn (string $title) => DB::table('catalog_volumes')->where('library_id', $libraryId)->where('title', $title)->get();
        [$from, $to] = [$find($this->option('from')), $find($this->option('to'))];
        if (! $libraryId || $from->count() !== 1 || $to->count() !== 1) {
            $this->error(sprintf('expected one library and one volume each: library %s, from %d, to %d',
                $libraryId ?: 'none', $from->count(), $to->count()));

            return self::FAILURE;
        }
        [$from, $to] = [$from->first(), $to->first()];
        $volume = (int) $this->option('volume');
        $part = (int) $this->option('part');

        $copies = DB::table('manuscripts')->where('catalog_volume_id', $from->id)->orderBy('id')->get(['id', 'shelfmark', 'metadata', 'raw_text']);
        $moves = [];
        $parts = [];
        foreach ($copies as $m) {
            $raw = ImportFankhaCatalogReferencesCommand::rawCitation($m->metadata, $m->raw_text);
            $cited = $raw === null ? null : self::partOf($raw, $volume);
            $parts[$cited ?? 'none'] = ($parts[$cited ?? 'none'] ?? 0) + 1;
            if ($cited === $part) {
                $moves[] = $m->id;
            }
        }
        ksort($parts);
        $this->line(sprintf('%d copies on #%d «%s»; part named: %s', $copies->count(), $from->id, $from->title,
            collect($parts)->map(fn ($n, $k) => "{$k}: {$n}")->implode(', ')));
        $this->line(sprintf('%d copies to move to #%d «%s»', count($moves), $to->id, $to->title));

        if (! $this->option('apply')) {
            $this->info('Dry run: nothing written.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($moves, $from, $to) {
            foreach (array_chunk($moves, 500) as $ids) {
                DB::table('manuscripts')->whereIn('id', $ids)->update(['catalog_volume_id' => $to->id]);
                DB::table('manuscript_catalog_references')->whereIn('manuscript_id', $ids)->where('catalog_volume_id', $from->id)
                    ->update(['catalog_volume_id' => $to->id]);
            }
            foreach ($moves as $id) {
                AuditLog::create(['user_id' => null, 'auditable_type' => Manuscript::class, 'auditable_id' => $id, 'action' => 'corpus_update',
                    'old_values' => ['catalog_volume_id' => $from->id],
                    'new_values' => ['catalog_volume_id' => $to->id, 'corpus_fix' => 'move-cited-part (step 14.152)']]);
            }
            DB::table('manuscripts')->whereIn('catalog_volume_id', [$from->id, $to->id])->select(['id', 'metadata', 'raw_text', 'catalog_volume_id'])
                ->orderBy('id')->chunkById(1000, fn ($rows) => SourceEntrySync::syncCited($rows));
        });

        Cache::forget('sources:shelf');
        foreach (DB::table('source_volumes')->whereIn('id', [$from->id, $to->id])->pluck('source_id')->unique() as $sourceId) {
            Cache::forget("sources:copies:{$sourceId}");
        }
        $this->info(sprintf('Moved %d copies.', count($moves)));

        return self::SUCCESS;
    }

    /** The part a citation names for $volume («33/1 - 172» and «1 / 33 - 43» are part 1), or null when it names none. */
    public static function partOf(string $citation, int $volume): ?int
    {
        if (! preg_match('/ف\s*:\s*(\d+)\s*\/\s*(\d+)\s*[-–]/u', $citation, $m)) {
            return null;
        }
        [$a, $b] = [(int) $m[1], (int) $m[2]];

        return $a === $volume && $b !== $volume ? $b : ($b === $volume && $a !== $volume ? $a : null);
    }
}

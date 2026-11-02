<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Library;
use App\Models\Manuscript;
use App\Sources\SourceEntrySync;
use App\Suggestions\ManuscriptLocation;
use App\Suggestions\ProtectedColumns;
use App\Support\SenateLibrary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gives the former Senate library its own record (step 13.148): the Majlis copies described only from the Senate's
 * catalogue (Manuscript::citesOnlySenate), and the Senate's catalogues and their volumes (title «… سنا»), move from
 * the Majlis to «مجلس سنا». A copy whose library a reviewed correction set is left where it is. The move is logged
 * as a «split» on the new library with every id it moved, and --undo puts them back from that log and removes the
 * library when nothing is left in it. Safe to run again: copies already moved are not counted. A copy on a Senate
 * volume that cites the Majlis short catalogue («مختصر ف») moves to that catalogue's volume (step 13.158).
 */
class SplitSenateLibraryCommand extends Command
{
    protected $signature = 'fahares:split-senate-library
        {--apply : Write; without it the command only counts}
        {--undo : Put back what the last --apply moved}
        {--show=10 : How many examples to list}';

    protected $description = 'Give the former Senate library its own record, apart from the Majlis';

    public function handle(): int
    {
        $majlis = SenateLibrary::majlis();
        if (! $majlis) {
            $this->error('The Majlis library (تهران، مجلس) is not found.');

            return self::FAILURE;
        }
        if ($this->option('undo')) {
            return $this->undo($majlis);
        }

        $guard = ProtectedColumns::for(Manuscript::class);
        $copies = [];
        $kept = [];
        DB::table('manuscripts')->where('library_id', $majlis->id)->where('raw_text', 'like', '%سنا%')
            ->orderBy('id')->select(['id', 'shelfmark', 'raw_text', 'catalog_volume_id'])
            ->each(function ($row) use ($guard, &$copies, &$kept) {
                if (! Manuscript::citesOnlySenate($row->raw_text)) {
                    return;
                }
                if ($guard->strip($row->id, ['library_id' => 1]) === []) {
                    $kept[] = $row->id;

                    return;
                }
                $copies[$row->id] = $row;
            });
        $volumes = DB::table('catalog_volumes')->where('library_id', $majlis->id)->where('title', 'like', '%سنا%')->pluck('title', 'id');
        $sources = DB::table('sources')->where('library_id', $majlis->id)->where('title', 'like', '%سنا%')->pluck('title', 'id');
        $inVolumes = collect($copies)->whereIn('catalog_volume_id', $volumes->keys())->count();
        // Copies the Senate's volumes hold that cite another catalogue stay with the Majlis (on a run after the split
        // the volumes are the Senate's already). One citing the Majlis short catalogue («[مختصر ف: - 211]», which
        // Fankha cites for the Majlis only) goes to that catalogue's volume; the rest are listed to be checked.
        $senateVolumes = DB::table('catalog_volumes')->where('title', 'like', '%سنا%')
            ->whereIn('library_id', array_filter([$majlis->id, SenateLibrary::find()?->id]))->pluck('id');
        $strays = DB::table('manuscripts')->where('library_id', $majlis->id)->whereIn('catalog_volume_id', $senateVolumes)
            ->whereNotIn('id', array_keys($copies))->get(['id', 'shelfmark', 'catalog_volume_id', 'metadata', 'raw_text']);
        $short = DB::table('catalog_volumes')->where('library_id', $majlis->id)->where('title', 'like', 'فهرست مختصر%مجلس شورای اسلامی%')->orderBy('id')->first(['id', 'title']);
        $retie = $short ? $strays->filter(fn ($s) => preg_match('/\[\s*مختصر\s*-?\s*ف\s*:/u', (string) $s->raw_text)) : collect();
        $strays = $strays->whereNotIn('id', $retie->pluck('id'));

        $this->line(sprintf('  %-34s %8d', 'copies to move', count($copies)));
        $this->line(sprintf('  %-34s %8d', '  of which in the Senate volumes', $inVolumes));
        $this->line(sprintf('  %-34s %8d', 'kept:protected', count($kept)));
        $this->line(sprintf('  %-34s %8d', 'to the Majlis short catalogue', $retie->count()) . ($short ? " (volume #{$short->id})" : ''));
        $this->line(sprintf('  %-34s %8d', 'Senate volumes copies left behind', $strays->count()));
        foreach ($volumes as $id => $title) {
            $this->line("  volume #{$id} {$title}");
        }
        foreach ($sources as $id => $title) {
            $this->line("  source #{$id} {$title}");
        }
        foreach ($strays->take((int) $this->option('show')) as $s) {
            $this->line("   left behind: #{$s->id} «{$s->shelfmark}» (volume #{$s->catalog_volume_id})");
        }

        if (! $this->option('apply')) {
            $this->info('Dry run: nothing written.');

            return self::SUCCESS;
        }

        if ($retie->isNotEmpty()) {
            // The cataloger the short catalogue's own copies carry
            $cataloger = DB::table('manuscripts')->where('catalog_volume_id', $short->id)->whereNotNull('cataloger_id')
                ->selectRaw('cataloger_id, count(*) n')->groupBy('cataloger_id')->orderByDesc('n')->value('cataloger_id');
            DB::transaction(function () use ($retie, $short, $cataloger) {
                foreach ($retie as $s) {
                    DB::table('manuscripts')->where('id', $s->id)->update(['catalog_volume_id' => $short->id, 'cataloger_id' => $cataloger, 'updated_at' => now()]);
                    AuditLog::create(['user_id' => null, 'auditable_type' => Manuscript::class, 'auditable_id' => $s->id, 'action' => 'corpus_update',
                        'old_values' => ['catalog_volume_id' => $s->catalog_volume_id], 'new_values' => ['catalog_volume_id' => $short->id, 'cataloger_id' => $cataloger,
                            'corpus_fix' => 'split-senate-library: the Majlis short catalogue (step 13.158)']]);
                }
                // the page Fankha cites goes with the volume (step 13.4)
                SourceEntrySync::syncCited(DB::table('manuscripts')->whereIn('id', $retie->pluck('id'))->get(['id', 'metadata', 'raw_text', 'catalog_volume_id']));
            });
            LinkCatalogVolumesCommand::recountVolumesAndCatalogers();
            $this->info("Moved {$retie->count()} copies to «{$short->title}».");
        }
        if (! $copies) {
            $this->info('No copy left to move to the Senate library.');

            return self::SUCCESS;
        }

        $senate = DB::transaction(function () use ($majlis, $copies, $volumes, $sources) {
            $senate = SenateLibrary::find();
            if (! $senate) {
                // The slug is made as for the Majlis («thran-mgls-4»): city, name and id
                $senate = Library::create(['city' => SenateLibrary::CITY, 'name' => SenateLibrary::NAME, 'full_name' => SenateLibrary::FULL_NAME,
                    'aliases' => SenateLibrary::ALIASES, 'country' => $majlis->country, 'slug' => 'library-' . Str::random(12)]);
                $senate->update(['slug' => mb_substr(Str::slug(SenateLibrary::CITY . ' ' . SenateLibrary::NAME) ?: 'library', 0, 180) . '-' . $senate->id]);
            }
            foreach (array_chunk(array_keys($copies), 1000) as $chunk) {
                DB::table('manuscripts')->whereIn('id', $chunk)->update(['library_id' => $senate->id, 'library' => $senate->name, 'city' => $senate->city, 'updated_at' => now()]);
            }
            DB::table('catalog_volumes')->whereIn('id', $volumes->keys())->update(['library_id' => $senate->id, 'updated_at' => now()]);
            DB::table('sources')->whereIn('id', $sources->keys())->update(['library_id' => $senate->id, 'updated_at' => now()]);
            AuditLog::create(['user_id' => null, 'auditable_type' => Library::class, 'auditable_id' => $senate->id, 'action' => 'split',
                'old_values' => ['library_id' => $majlis->id, 'library' => $majlis->name],
                'new_values' => ['manuscript_ids' => array_keys($copies), 'catalog_volume_ids' => $volumes->keys()->all(), 'source_ids' => $sources->keys()->all(),
                    'corpus_fix' => 'split-senate-library (step 13.148)']]);

            return $senate;
        });
        SenateLibrary::forget();
        ManuscriptLocation::recountLibraries([$majlis->id, $senate->id]);
        foreach (array_chunk(array_keys($copies), 200) as $chunk) {
            Manuscript::whereIn('id', $chunk)->get()->searchable();
            gc_collect_cycles();
        }
        $this->info("Written: library #{$senate->id} «{$senate->name}».");

        return self::SUCCESS;
    }

    private function undo(Library $majlis): int
    {
        $senate = SenateLibrary::find();
        $log = $senate ? AuditLog::where('auditable_type', Library::class)->where('auditable_id', $senate->id)->where('action', 'split')->latest('id')->first() : null;
        if (! $log) {
            $this->error('No split of the Senate library to undo.');

            return self::FAILURE;
        }
        $moved = (array) ($log->new_values['manuscript_ids'] ?? []);
        DB::transaction(function () use ($log, $senate, $majlis, $moved) {
            foreach (array_chunk($moved, 1000) as $chunk) {
                DB::table('manuscripts')->whereIn('id', $chunk)->where('library_id', $senate->id)->update(['library_id' => $majlis->id, 'library' => $majlis->name, 'city' => $majlis->city, 'updated_at' => now()]);
            }
            DB::table('catalog_volumes')->whereIn('id', (array) ($log->new_values['catalog_volume_ids'] ?? []))->where('library_id', $senate->id)->update(['library_id' => $majlis->id]);
            DB::table('sources')->whereIn('id', (array) ($log->new_values['source_ids'] ?? []))->where('library_id', $senate->id)->update(['library_id' => $majlis->id]);
            AuditLog::create(['user_id' => null, 'auditable_type' => Library::class, 'auditable_id' => $senate->id, 'action' => 'split_undo',
                'old_values' => $log->new_values, 'new_values' => ['library_id' => $majlis->id]]);
        });
        $left = Manuscript::where('library_id', $senate->id)->exists() || DB::table('catalog_volumes')->where('library_id', $senate->id)->exists()
            || DB::table('sources')->where('library_id', $senate->id)->exists();
        if (! $left) {
            $senate->delete();
        }
        SenateLibrary::forget();
        ManuscriptLocation::recountLibraries([$majlis->id, $senate->id]);
        foreach (array_chunk($moved, 200) as $chunk) {
            Manuscript::whereIn('id', $chunk)->get()->searchable();
            gc_collect_cycles();
        }
        $this->info('Undone: ' . count($moved) . ' copies back with the Majlis' . ($left ? '; the Senate library still holds records, so it is kept.' : '; the Senate library is removed.'));

        return self::SUCCESS;
    }
}

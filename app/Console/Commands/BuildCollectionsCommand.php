<?php

namespace App\Console\Commands;

use App\Support\CollectionValues;
use App\Support\Collections;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Makes the «مجموعه» record of every bound volume and links its treatises to it (backlog item 163, step 14.322):
 * one record for each library and collection_key, manuscripts.collection_id for each keyed copy (App\Support\Collections).
 * Nothing else of a copy is written and nothing is reindexed. Safe to run again: a second run changes nothing.
 * It also works out what each volume is said to be from what its treatises say (step 14.323, CollectionValues).
 * Without --apply it only reports what differs.
 */
class BuildCollectionsCommand extends Command
{
    protected $signature = 'fahares:build-collections
        {--apply : Write; without it the command only reports}
        {--summary : Count the values by field and basis, and the treatises that read one they do not state}';

    protected $description = 'Make the record of each bound volume and link its treatises to it';

    public function handle(): int
    {
        if ($this->option('apply')) {
            $counts = Collections::sync();
            $this->info('Written.');
        } else {
            $keyed = fn () => DB::table('manuscripts')->where('manuscripts.collection_pinned', false)->whereNotNull('manuscripts.library_id')->whereNotNull('manuscripts.collection_key')->where('manuscripts.collection_key', '<>', '');
            $link = fn () => $keyed()->leftJoin('collections', fn ($j) => $j->on('collections.library_id', '=', 'manuscripts.library_id')->on('collections.key', '=', 'manuscripts.collection_key'));
            $counts = [
                'made' => DB::query()->fromSub($link()->whereNull('collections.id')->distinct()->select('manuscripts.library_id', 'manuscripts.collection_key'), 'm')->count(),
                'linked' => $link()->where(fn ($q) => $q->whereNull('collections.id')->orWhereNull('manuscripts.collection_id')->orWhereColumn('manuscripts.collection_id', '<>', 'collections.id'))->count(),
                'unlinked' => DB::table('manuscripts')->where('collection_pinned', false)->whereNotNull('collection_id')
                    ->where(fn ($q) => $q->whereNull('library_id')->orWhereNull('collection_key')->orWhere('collection_key', ''))->count(),
            ];
            $values = Collections::derive(null, false);
            $counts['values'] = $values['collections'];
            $this->info('Dry run: nothing written.');
        }
        foreach ($counts as $kind => $n) {
            $this->line(sprintf('  %-12s %8d', $kind, $n));
        }
        $this->line(sprintf('  %-12s %8d', 'collections', DB::table('collections')->count()));
        foreach (DB::table('collections')->selectRaw('kind, COUNT(*) AS n, SUM(copies_count) AS copies, SUM(copies_count = 1) AS single')->groupBy('kind')->orderBy('kind')->get() as $row) {
            $this->line(sprintf('  %-12s %8d  copies %d, with one copy %d', $row->kind, $row->n, $row->copies, $row->single));
        }

        foreach ($values['fields'] ?? [] as $field => $n) {
            $this->line(sprintf('  value of %-14s %8d would change', $field, $n));
        }
        if ($this->option('summary')) {
            $this->summary();
        }

        return self::SUCCESS;
    }

    /** By field: the collections that hold a value, by where it comes from, and the treatises silent on it that read it. */
    private function summary(): void
    {
        $this->line('-- values: field, basis, collections; then the treatises that state nothing and read the volume\'s (photographic copies apart)');
        foreach (CollectionValues::fields() as $field) {
            $column = CollectionValues::columnsOf($field)[0];
            $own = $field === 'scribe' ? 'manuscripts.scribe_id IS NULL AND (manuscripts.scribe_name IS NULL OR manuscripts.scribe_name = \'\')'
                : ($field === 'lines' ? 'manuscripts.`lines` IS NULL' : "(manuscripts.`{$column}` IS NULL OR manuscripts.`{$column}` = '')");
            foreach (DB::table('collections')->whereNotNull("basis->{$field}")->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(basis, '$.\"{$field}\"')) AS b, COUNT(*) AS n")->groupBy('b')->orderBy('b')->get() as $row) {
                $readers = in_array($field, CollectionValues::SHARED, true) ? null : DB::table('manuscripts')->join('collections', 'collections.id', '=', 'manuscripts.collection_id')
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(collections.basis, '$.\"{$field}\"')) = ?", [$row->b])->whereRaw($own)
                    ->selectRaw('COUNT(*) AS n, COALESCE(SUM(manuscripts.is_facsimile), 0) AS photographic')->first();
                $this->line(sprintf('  %-14s %-10s %7d%s', $field, $row->b, $row->n, $readers ? sprintf('   read by %6d treatises (%d photographic)', $readers->n, $readers->photographic) : ''));
            }
        }
    }
}

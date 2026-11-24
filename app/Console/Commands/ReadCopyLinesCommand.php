<?php

namespace App\Console\Commands;

use App\Models\Claim;
use App\Models\Manuscript;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Sources\FirstHand\CopyLineComparer;
use App\Sources\FirstHand\Readers\GolpayeganiReader;
use App\Sources\FirstHand\Readers\MasjidAzamReader;
use App\Sources\FirstHand\Readers\Reader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reads a library catalogue's own lines for its copies against their records (backlog item 147, step 14.250): a
 * field that agrees goes into the entry's confirmed_fields, a field that differs or that the record lacks becomes a
 * candidate claim for review. The records are not changed. Running it again replaces the entry's confirmed fields and
 * its candidate claims of method «rule»; accepted and rejected claims stay.
 */
class ReadCopyLinesCommand extends Command
{
    /** The reader of each catalogue's copy lines. */
    public const READERS = [
        'golpayegani' => GolpayeganiReader::class,
        'masjid-azam' => MasjidAzamReader::class,
    ];

    /** Sources whose reader is known by their code. Golpayegani's code was made at import, so it is named with --reader. */
    public const BY_CODE = [
        'masjid-azam-ostadi' => 'masjid-azam',
    ];

    protected $signature = 'sources:read-copy-lines
        {source : Code of the library catalogue (sources.code)}
        {--reader= : The reader, when the code does not name it (golpayegani, masjid-azam)}
        {--apply : Save confirmed fields and claims (without it, only count)}
        {--limit= : Read at most this many entries}
        {--report= : Write the differences and fills to this CSV file}';

    protected $description = 'Read a library catalogue\'s copy lines against the copies\' records: confirmed fields and claims';

    public function handle(CopyLineComparer $comparer): int
    {
        $source = Source::where('code', $this->argument('source'))->first();
        $key = $this->option('reader') ?: (self::BY_CODE[$this->argument('source')] ?? null);
        if (! $source || ! isset(self::READERS[$key])) {
            $this->error($source ? 'Name the reader with --reader (' . implode(', ', array_keys(self::READERS)) . ')' : 'No source ' . $this->argument('source'));

            return self::FAILURE;
        }
        /** @var Reader $reader */
        $reader = new (self::READERS[$key])();
        $apply = (bool) $this->option('apply');

        $query = SourceEntry::where('source_id', $source->id)->where('citable_type', Manuscript::class)
            ->where('status', '!=', SourceEntry::STATUS_REJECTED)->whereNotNull('copy_text')->where('copy_text', '!=', '')
            ->orderBy('id');
        if ($limit = (int) $this->option('limit')) {
            $query->limit($limit);
        }

        $stats = array_fill_keys(CopyLineComparer::FIELDS, ['confirmed' => 0, 'fills' => 0, 'differs' => 0]);
        $entries = 0;
        $unread = 0;
        $report = $this->option('report') ? fopen($this->option('report'), 'w') : null;
        if ($report) {
            fputcsv($report, ['manuscript_id', 'entry_id', 'field', 'kind', 'catalogue', 'record', 'words']);
        }

        $accepted = 0;
        $query->chunkById(500, function ($chunk) use ($reader, $comparer, $apply, &$stats, &$entries, &$unread, &$accepted, $report) {
            $copies = Manuscript::whereIn('id', $chunk->pluck('citable_id'))->get()->keyBy('id');
            DB::transaction(function () use ($chunk, $copies, $reader, $comparer, $apply, &$stats, &$entries, &$unread, &$accepted, $report) {
                foreach ($chunk as $entry) {
                    $ms = $copies->get($entry->citable_id);
                    if (! $ms) {
                        continue;
                    }
                    $entries++;
                    $line = $reader->read((string) $entry->copy_text);
                    $result = $comparer->compare($line, $ms);
                    if (! $result['confirmed'] && ! $result['claims']) {
                        $unread++;
                    }
                    foreach ($result['confirmed'] as $field) {
                        $stats[$field]['confirmed']++;
                    }
                    foreach ($result['claims'] as $c) {
                        $stats[$c['field']][$c['fills'] ? 'fills' : 'differs']++;
                        if ($report) {
                            fputcsv($report, [$ms->id, $entry->id, $c['field'], $c['fills'] ? 'fills' : 'differs', $c['value'], $c['current'], $c['raw']]);
                        }
                    }
                    if (! $apply) {
                        continue;
                    }
                    $entry->forceFill(['confirmed_fields' => $result['confirmed'] ?: null, 'fields_read_at' => now()])->saveQuietly();
                    Claim::where('source_entry_id', $entry->id)->where('method', Claim::METHOD_RULE)->candidate()->delete();
                    foreach ($result['claims'] as $c) {
                        // the volume's line stands under each of its treatises' entries: one claim for the collection
                        // is enough, whichever treatise's entry brought it and whatever became of it (step 14.331)
                        if (str_starts_with($c['field'], \App\Suggestions\SuggestionRegistry::COLLECTION_PREFIX) && $ms->collection_id
                            && Claim::where('subject_type', Manuscript::class)->where('field', $c['field'])->where('value_text', $c['value'])
                                ->whereIn('subject_id', DB::table('manuscripts')->where('collection_id', $ms->collection_id)->select('id'))->exists()) {
                            continue;
                        }
                        $made = Claim::create([
                            'subject_type' => Manuscript::class, 'subject_id' => $ms->id, 'field' => $c['field'],
                            'value_text' => $c['value'], 'current_text' => $c['current'], 'raw_text' => $c['raw'],
                            'source_entry_id' => $entry->id, 'method' => Claim::METHOD_RULE,
                        ]);
                        if ($this->acceptsVolumeLeaves($c, $ms)) {
                            $this->acceptVolumeLeaves($made, $ms);
                            $accepted++;
                        }
                    }
                }
            });
        });
        if ($report) {
            fclose($report);
        }

        $this->info(($apply ? 'Read' : 'Would read') . " {$entries} copy lines of {$source->code} ({$unread} gave no field)");
        if ($accepted) {
            $this->line("Accepted by themselves: the leaves of {$accepted} whole volumes (collection_folios, where the collection had none)");
        }
        $this->table(['field', 'confirmed', 'fills', 'differs'], collect($stats)->map(fn ($s, $f) => [$f, $s['confirmed'], $s['fills'], $s['differs']])->values());

        return self::SUCCESS;
    }

    /**
     * Whether a claim is accepted with no reviewer (the user's decision, step 14.334): the leaves of a whole volume
     * («212 برگ» of the volume's own line) where the collection has none, so nothing is set aside; and not fewer than
     * the last leaf any of its treatises is said to reach, which a misread number would be.
     */
    private function acceptsVolumeLeaves(array $claim, Manuscript $ms): bool
    {
        if ($claim['field'] !== 'collection_folios' || ! $claim['fills'] || ! ctype_digit((string) $claim['value']) || ! ($volume = $ms->boundCollection())) {
            return false;
        }
        $leaves = (int) $claim['value'];

        return $leaves >= 2 && $leaves <= 5000
            && $leaves >= (int) DB::table('manuscripts')->where('collection_id', $volume->id)->max('range_to_number');
    }

    /** Writes the leaves to the collection as the catalogue's («catalogue»), with an audit entry that undoes it, and settles the claim. */
    private function acceptVolumeLeaves(Claim $claim, Manuscript $ms): void
    {
        $field = \App\Suggestions\SuggestionRegistry::fieldOf($ms, $claim->field);
        $result = app(\App\Suggestions\SuggestionApplier::class)->apply($ms, $field, ['applied_value' => $claim->value_text, 'collection_basis' => 'catalogue']);
        \App\Models\AuditLog::create(['user_id' => null, 'auditable_type' => Manuscript::class, 'auditable_id' => $ms->id, 'action' => 'verify',
            'old_values' => $result['old'], 'new_values' => $result['new'] + ['first_hand' => ['claim_id' => $claim->id, 'source_entry_id' => $claim->source_entry_id]]]);
        $claim->update(['status' => Claim::STATUS_ACCEPTED, 'reviewed_at' => now()]);
    }
}

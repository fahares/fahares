<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\EntityRedirect;
use App\Models\Person;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\SourceItem;
use Illuminate\Console\Command;

/**
 * Saves the judged ties of people to their lives found by name and death year (backlog item 86, step 14.116;
 * candidates from sources:find-person-lives). The decisions file is keyed by person slug, source code and the life's
 * item key, since local and production ids differ. A person who already has a life in that source (from his works,
 * sources:tie-lives-to-people, or by hand) keeps it and gets none here. Each tie is an unverified source_entries row of
 * the person (role entry, origin match) under its own legacy type, so a later run of tie-lives-to-people never
 * removes it; running this again changes nothing. A person merged away since the list was judged is followed to the
 * person he was merged into (by the merge's audit log and the redirects), so his tie is not lost (step 14.134). Without
 * --apply it only reports.
 */
class TiePersonLivesCommand extends Command
{
    public const LEGACY_TYPE = 'person_life_by_name';

    protected $signature = 'sources:tie-person-lives
        {--file=database/data/person_lives_step14_116.json : The judged ties}
        {--apply : Save them}';

    protected $description = 'Tie people to their lives in the books of lives from a judged list';

    public function handle(): int
    {
        $path = base_path($this->option('file'));
        $doc = is_file($path) ? json_decode(file_get_contents($path), true) : null;
        if (! is_array($doc['ties'] ?? null)) {
            $this->error("No ties in {$path}");

            return self::FAILURE;
        }
        $sources = Source::where('kind', Source::KIND_BIOGRAPHICAL)->get()->keyBy('code');
        $counts = ['created' => 0, 'exists' => 0, 'has_life' => 0, 'merged' => 0, 'missing_person' => 0, 'missing_life' => 0];

        foreach ($doc['ties'] as $t) {
            $person = Person::where('slug', $t['person_slug'])->first(['id', 'name']);
            if (! $person && ($person = self::mergedInto($t['person_slug']))) {
                $counts['merged']++;
            }
            $source = $sources[$t['source']] ?? null;
            if (! $person || ! $source) {
                $counts['missing_person']++;

                continue;
            }
            $item = SourceItem::where('source_id', $source->id)->where('item_key', $t['item_key'])
                ->when($t['volume'] ?? null, fn ($q, $v) => $q->where('volume_number', $v))->first();
            if (! $item) {
                $counts['missing_life']++;
                $this->line("  missing life: {$t['source']} {$t['item_key']}");

                continue;
            }
            $key = ['legacy_type' => self::LEGACY_TYPE, 'legacy_id' => $person->id * 1000000 + $source->id, 'legacy_index' => 0];
            if (SourceEntry::where($key)->exists()) {
                $counts['exists']++;

                continue;
            }
            if (SourceEntry::where('citable_type', Person::class)->where('citable_id', $person->id)->where('source_id', $source->id)->exists()) {
                $counts['has_life']++;

                continue;
            }
            $counts['created']++;
            if (! $this->option('apply')) {
                continue;
            }
            SourceEntry::create($key + [
                'citable_type' => Person::class, 'citable_id' => $person->id, 'source_id' => $source->id,
                'source_volume_id' => $item->source_volume_id, 'source_item_id' => $item->id, 'volume_number' => $item->volume_number,
                'page_start' => $item->page_start, 'page_end' => $item->page_end > $item->page_start ? $item->page_end : null,
                'entry_number' => $item->entry_number, 'role' => SourceEntry::ROLE_ENTRY, 'origin' => SourceEntry::ORIGIN_MATCH,
                'match_method' => $t['method'], 'confidence' => (int) $t['confidence'], 'status' => SourceEntry::STATUS_UNVERIFIED,
                'citation_text' => $source->title, 'text_status' => SourceEntry::TEXT_NONE,
            ]);
        }

        $this->table(array_keys($counts), [array_values($counts)]);
        $this->info($this->option('apply') ? 'Saved.' : 'Dry run; pass --apply to save.');

        return self::SUCCESS;
    }

    /** The person a removed person's slug was merged into, following later merges of that person too. */
    private static function mergedInto(string $slug): ?Person
    {
        $log = AuditLog::where('auditable_type', Person::class)->where('action', 'merge')
            ->where('old_values->source_snapshot->slug', $slug)->latest('id')->first(['id', 'auditable_id']);
        if (! $log) {
            return null;
        }
        $id = EntityRedirect::resolveTargetId('people', (int) $log->auditable_id) ?? (int) $log->auditable_id;

        return Person::find($id, ['id', 'name']);
    }
}

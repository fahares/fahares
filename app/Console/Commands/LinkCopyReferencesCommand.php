<?php

namespace App\Console\Commands;

use App\Models\FieldSuggestion;
use App\Models\Manuscript;
use App\Suggestions\MergeComparison;
use App\Suggestions\RecordMerger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fankha's «همان نسخه بالا» (step 14.16): an entry that says it is the copy above, or a photograph of it, gets linked
 * to that copy instead of standing as a copy of its own. «Above» is the work's previous copy in the same Fankha volume
 * (by id, the order of import; sequence_number is not reliable for the unnumbered entries), followed to the copy it
 * stands for when that one was linked or merged already.
 *
 * - A photograph (the entry's «نسخه اصل: همان نسخه بالا», or no «نسخه اصل» but a citation of a photograph or film
 *   catalogue, or the copy marked as one): a facsimile_of relation to the copy above, or to that one's mother when
 *   the copy above is itself a photograph (or to that photograph, when its mother is outside the records). The copy is
 *   marked as a facsimile.
 * - The same copy in two libraries whose transfer Fankha itself records (TRANSFERS): one record. The copy in the
 *   former library is merged into the one in the present library (RecordMerger, which moves its catalogue entry and
 *   fills what the kept copy lacks); its library and shelfmark become a former location, and a field the two give
 *   differently keeps the other catalogue's value as an alternative reading (قول دیگر) citing it. Nothing is lost.
 * - Any other «همان نسخه بالا»: a possibly_same relation, the two kept apart, since the phrase alone does not show
 *   whether the catalogue left out «نسخه اصل».
 *
 * Without --dry-run every change is written to a backup first; --revert undoes the merges, readings, relations and
 * facsimile marks of that backup. Safe to run again: a copy already linked is left as it is.
 */
class LinkCopyReferencesCommand extends Command
{
    protected $signature = 'fahares:link-copy-references
        {--dry-run : Report what would change without saving}
        {--work= : Only the copies of this work (by id)}
        {--report= : Write every decision to this CSV file}
        {--backup= : Where to write the backup (default storage/app/private/backups/)}
        {--revert= : Undo the changes of a backup this command wrote}';

    protected $description = 'Link Fankha entries that say «همان نسخه بالا» to the copy above: photograph, merge, or possibly the same';

    /** Collections Fankha's list of abbreviations says moved: former library => present library (vol. 1, رموز و اختصارات) */
    public const TRANSFERS = [
        'مولوی' => 'الهیات',          // «قسمتی از این مجموعه به کتابخانه دانشکده الهیات دانشگاه فردوسی مشهد منتقل شده است»
        'شیخ علی حیدر' => 'رضوی',     // «این مجموعه به شهر مشهد و به آستان قدس رضوی انتقال یافته است»
        'مجلس سنا' => 'مجلس',         // «مجموعه آن به کتابخانه مجلس شورای اسلامی منتقل شده است»
    ];

    private const SAME = '/همان\s+نسخه(\s+اصل)?(\s+نسخه)?\s+بالا/u';
    // «نسخه اصل: همان نسخه بالا», also written «اصل نسخه:»
    private const PHOTO = '/(نسخه\s+اصل|اصل\s+نسخه)\s*:\s*((احتمالا|ظاهرا)\S*\s+)?همان\s+نسخه/u';
    private const HEDGE = '/(احتمالا|ظاهرا)\S*\s+(عکس\s+)?همان\s+نسخه/u';
    private const PHOTO_CATALOGUE = '/^\[\s*(عکسی|فیلم\s*ها|فیلم‌ها|فیلمها)/u';

    private bool $dry = false;

    /** Ids of copies merged away => the copy kept */
    private array $merged = [];

    /** Links made in this run, by copy and type (a dry run writes none, and later entries build on them) */
    private array $links = [];

    private array $backup = ['relations' => [], 'facsimile' => [], 'merges' => [], 'readings' => []];

    private array $report = [];

    public function handle(): int
    {
        if ($file = $this->option('revert')) {
            return $this->revert($file);
        }
        $this->dry = (bool) $this->option('dry-run');
        // The command object outlives a call (tests, Artisan::call): every run starts afresh
        [$this->merged, $this->links, $this->report] = [[], [], []];
        $this->backup = ['relations' => [], 'facsimile' => [], 'merges' => [], 'readings' => []];
        if ($this->dry) {
            $this->warn('Dry run: nothing is saved.');
        }

        $query = DB::table('manuscripts')->where('raw_text', 'like', '%همان نسخه%بالا%')->orderBy('id');
        if ($work = $this->option('work')) {
            $query->where('work_id', (int) $work);
        }
        $ids = $query->pluck('id')->all();
        $this->line(sprintf('%d entries mention «همان نسخه … بالا»', count($ids)));

        $counts = [];
        $backupPath = $this->dry ? null : $this->backupPath();
        foreach ($ids as $id) {
            $decision = $this->dry ? $this->decide($id) : DB::transaction(fn () => $this->decide($id));
            $counts[$decision] = ($counts[$decision] ?? 0) + 1;
            // Written as it goes, so an interrupted run can still be reverted
            if ($backupPath && $decision !== 'skipped' && ($counts[$decision] % 200 === 1 || $decision === 'merged')) {
                $this->writeBackup($backupPath);
            }
        }
        if ($backupPath) {
            $this->writeBackup($backupPath);
            $this->info("Backup: {$backupPath}");
        }

        ksort($counts);
        foreach ($counts as $decision => $n) {
            $this->line(sprintf('  %-22s %d', $decision, $n));
        }
        if ($path = $this->option('report')) {
            $this->writeReport($path);
            $this->info("Report: {$path}");
        }

        return self::SUCCESS;
    }

    /** Links one entry; returns the decision's name for the counts. */
    private function decide(int $id): string
    {
        $copy = Manuscript::find($id);
        if (! $copy || ! preg_match(self::SAME, (string) $copy->raw_text)) {
            return $this->note($id, null, 'skipped', 'merged earlier or no «همان نسخه بالا»');
        }
        if ($this->linked($id, 'facsimile_of') || $this->linked($id, 'possibly_same')) {
            return $this->note($id, null, 'skipped', 'already linked');
        }
        $above = $this->above($copy);
        if (! $above) {
            return $this->note($id, null, 'no_copy_above', '');
        }

        $text = (string) $copy->raw_text;
        $hedged = (bool) preg_match(self::HEDGE, $text);
        $statesPhoto = (bool) preg_match(self::PHOTO, $text);
        $photoCatalogue = (bool) preg_match(self::PHOTO_CATALOGUE, (string) $copy->catalog_citation);
        if ($statesPhoto || $photoCatalogue || $copy->is_facsimile) {
            $mother = $above->is_facsimile ? $this->motherOf($above) : $above;
            // A copy cited from a catalogue of photographs is a photograph: no note needed for that
            $note = null;
            // The photograph above has a mother outside the records: both are of it, and this one is linked to the
            // photograph above, which names it
            if (! $mother) {
                $mother = $above;
                $note = trim('نسخه‌ی بالا خود عکسی است و هر دو عکسِ یک نسخه‌اند. ' . $note);
            }
            $this->relate($copy, $mother, 'facsimile_of', $hedged ? trim('احتمالی. ' . $note) : ($note ?: null));
            if (! $copy->is_facsimile) {
                $this->backup['facsimile'][] = $copy->id;
                $this->dry || DB::table('manuscripts')->where('id', $copy->id)->update(['is_facsimile' => true]);
            }

            return $this->note($id, $mother->id, $statesPhoto ? 'facsimile' : 'facsimile_inferred', match (true) {
                $statesPhoto => '',
                $photoCatalogue => 'cited from a catalogue of photographs',
                default => 'marked as a facsimile',
            });
        }

        $root = $this->rootOf($above);
        if ($root->is_facsimile) {
            $this->relate($copy, $root, 'possibly_same', 'فنخا: «همان نسخه بالا»؛ نسخه‌ی بالا عکسی است');

            return $this->note($id, $root->id, 'possibly_same', 'the copy above is a photograph');
        }
        if (! $hedged && ($merge = $this->transfer($copy, $root))) {
            [$former, $present] = $merge;
            $readings = $this->merge($former, $present);

            return $this->note($id, $present->id, 'merged', sprintf('%s → %s; %d alternative readings', $former->library, $present->library, $readings));
        }
        // A reader or reviewer has said they are two copies
        $distinct = DB::table('manuscript_relations')->where('type', 'distinct_from')->where(fn ($q) => $q->where(['manuscript_id' => $copy->id, 'related_manuscript_id' => $root->id])
            ->orWhere(fn ($q) => $q->where(['manuscript_id' => $root->id, 'related_manuscript_id' => $copy->id])))->exists();
        if ($distinct) {
            return $this->note($id, $root->id, 'distinct', 'stated to be two copies');
        }
        $this->relate($copy, $root, 'possibly_same', $hedged ? 'فنخا: «احتمالاً همان نسخه بالا»' : 'فنخا: «همان نسخه بالا»');

        return $this->note($id, $root->id, 'possibly_same', $copy->library . ' / ' . $root->library);
    }

    /** The work's previous copy in the same Fankha volume, or the copy it was merged into. */
    private function above(Manuscript $copy): ?Manuscript
    {
        $id = DB::table('manuscripts')->where('work_id', $copy->work_id)->where('id', '<', $copy->id)
            ->where('volume_number', $copy->volume_number)->max('id');
        // The copy above may have been merged into one after it, or before it
        $id = $this->merged[$id] ?? $id;

        return $id ? Manuscript::find($id) : null;
    }

    /** A copy linked as possibly the same as another stands for that one */
    private function rootOf(Manuscript $copy): Manuscript
    {
        for ($i = 0; $i < 5; $i++) {
            $next = $this->linked($copy->id, 'possibly_same');
            if (! $next || ! ($found = Manuscript::find($next))) {
                break;
            }
            $copy = $found;
        }

        return $copy;
    }

    private function motherOf(Manuscript $photo): ?Manuscript
    {
        $id = $this->linked($photo->id, 'facsimile_of');

        return $id ? Manuscript::find($id) : null;
    }

    /** The copy $id is linked to by $type, in this run or before it */
    private function linked(int $id, string $type): ?int
    {
        $to = $this->links[$id][$type] ?? DB::table('manuscript_relations')->where('manuscript_id', $id)->where('type', $type)->value('related_manuscript_id');

        return $to ? (int) ($this->merged[$to] ?? $to) : null;
    }

    /** [former, present] when the two copies are one copy in a collection Fankha records as moved */
    private function transfer(Manuscript $copy, Manuscript $above): ?array
    {
        foreach ([[$copy, $above], [$above, $copy]] as [$former, $present]) {
            if ((self::TRANSFERS[trim((string) $former->library)] ?? null) === trim((string) $present->library)) {
                return [$former, $present];
            }
        }

        return null;
    }

    private function relate(Manuscript $from, Manuscript $to, string $type, ?string $note): void
    {
        $this->links[$from->id][$type] = $to->id;
        if ($this->dry) {
            return;
        }
        $id = DB::table('manuscript_relations')->insertGetId(['manuscript_id' => $from->id, 'related_manuscript_id' => $to->id, 'type' => $type,
            'note' => $note ? mb_substr($note, 0, 500) : null, 'source' => 'fankha', 'created_at' => now(), 'updated_at' => now()]);
        $this->backup['relations'][] = $id;
    }

    /**
     * One copy described in two catalogues: the former library's record goes into the present one's. A value only
     * the former gives fills the kept copy; lists and notes are combined; a value both give differently stays as the
     * kept copy has it, and the other becomes an alternative reading citing its catalogue. Returns the readings made.
     */
    private function merge(Manuscript $former, Manuscript $present): int
    {
        $rows = MergeComparison::rows($former, $present);
        $choices = [];
        $readings = [];
        foreach ($rows as $row) {
            $conflict = $row['default'] === MergeComparison::KEEP;
            if ($conflict && $row['kind'] === 'text') {
                $choices[$row['key']] = MergeComparison::BOTH;
            } elseif ($conflict) {
                $readings[] = $row;
            }
        }
        if ($this->dry) {
            $this->merged[$former->id] = $present->id;

            return count($readings);
        }

        $citation = trim((string) $former->catalog_citation, '[] ');
        $metaBefore = $present->metadata;
        $sourceMetaBefore = $former->metadata;
        $meta = $present->metadata ?? [];
        $meta['former_locations'] = array_values(array_merge((array) ($meta['former_locations'] ?? []), [array_filter([
            'library_id' => $former->library_id,
            'library' => $former->libraryRecord?->name ?? $former->library,
            'city' => $former->city,
            'shelfmark' => $former->shelfmark,
            'catalog' => $citation ?: null,
            'note' => 'فنخا: «همان نسخه بالا»',
        ], fn ($v) => $v !== null && $v !== '')]));
        $present->metadata = $meta;
        $present->save();

        $suggestions = [];
        // The stored value, as a suggestion holds it; none (or «بی‌کاتب», «بی‌تاریخ») is «نامشخص», as the site shows it (step 14.13)
        $stored = fn (Manuscript $m, string $field) => trim((string) $m->{$field}) !== '' ? (string) $m->{$field} : 'نامشخص';
        foreach ($readings as $row) {
            $suggestions[] = FieldSuggestion::create([
                'suggestable_type' => Manuscript::class,
                'suggestable_id' => $present->id,
                'kind' => 'field',
                'field_name' => $row['key'],
                'current_value' => $stored($present, $row['key']),
                'suggested_value' => $stored($former, $row['key']),
                'rationale_citation' => $citation ?: null,
                // The reading's holder is the other catalogue
                'credit_name' => mb_substr('فهرست ' . ($citation ?: $former->library), 0, 120),
                'status' => 'approved',
                'standing' => 'alternative',
                'public_note' => sprintf('قول فهرست دیگرِ همین نسخه (%s، شماره‌ی %s%s)، در یکی شدن دو معرفی آن.',
                    $former->library, $former->shelfmark ?? '—', $citation ? '؛ ' . $citation : ''),
                'resolution' => ['applied' => false, 'origin' => 'copy_merge', 'merged_manuscript_id' => $former->id],
                'reviewed_at' => now(),
            ])->id;
        }

        // The «همان نسخه بالا» note means nothing once the two are one record, and the other catalogue's citation
        // is kept with the former location (and its entry, which the merge moves)
        $sourceMeta = $former->metadata ?? [];
        unset($sourceMeta['catalog_citation']);
        if (isset($sourceMeta['editorial_notes'])) {
            $sourceMeta = self::withoutSameNote($sourceMeta);
        }
        $former->metadata = $sourceMeta ?: null;
        $former->saveQuietly();

        $result = app(RecordMerger::class)->mergeManuscript($former, $present, $choices);
        $this->merged[$former->id] = $present->id;
        $this->backup['merges'][] = ['source_id' => $former->id, 'target_id' => $present->id, 'target_metadata' => $metaBefore,
            'source_metadata' => $sourceMetaBefore, 'result' => $result];
        array_push($this->backup['readings'], ...$suggestions);

        return count($readings);
    }

    /** The metadata without the editorial note «همان نسخه بالا» (and its type) */
    public static function withoutSameNote(array $meta): array
    {
        $notes = $meta['editorial_notes'];
        if (is_string($notes)) {
            if (preg_match('/^\s*(احتمالا\S*\s+)?همان\s+نسخه(\s+اصل)?\s+بالا\.?\s*$/u', $notes)) {
                unset($meta['editorial_notes'], $meta['editorial_note_types']);
            }

            return $meta;
        }
        $types = (array) ($meta['editorial_note_types'] ?? []);
        foreach ((array) $notes as $i => $note) {
            $text = is_array($note) ? ($note['text'] ?? '') : (string) $note;
            if (preg_match('/^\s*(احتمالا\S*\s+)?همان\s+نسخه(\s+اصل)?\s+بالا\.?\s*$/u', $text)) {
                unset($notes[$i], $types[$i]);
            }
        }
        $meta['editorial_notes'] = array_values($notes) ?: null;
        $meta['editorial_note_types'] = array_values($types) ?: null;

        return array_filter($meta, fn ($v) => $v !== null);
    }

    private function note(int $id, ?int $target, string $decision, string $detail): string
    {
        $this->report[] = [$id, $target, $decision, $detail];

        return $decision;
    }

    private function writeReport(string $path): void
    {
        $out = fopen($path, 'w');
        fputcsv($out, ['manuscript_id', 'linked_to', 'decision', 'detail']);
        foreach ($this->report as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
    }

    private function backupPath(): string
    {
        $path = $this->option('backup') ?: storage_path('app/private/backups/link-copy-references-' . now()->format('Ymd_His') . '.json');
        @mkdir(dirname($path), 0775, true);

        return $path;
    }

    private function writeBackup(string $path): void
    {
        file_put_contents($path, json_encode(['command' => $this->getName()] + $this->backup, JSON_UNESCAPED_UNICODE));
    }

    /** Undoes a run: merges last first, then the readings, relations and facsimile marks it made. */
    private function revert(string $file): int
    {
        $backup = is_file($file) ? json_decode(file_get_contents($file), true) : null;
        if (($backup['command'] ?? null) !== $this->getName()) {
            $this->error("{$file}: not a backup of {$this->getName()}.");

            return self::FAILURE;
        }
        $merger = app(RecordMerger::class);
        foreach (array_reverse($backup['merges']) as $merge) {
            DB::transaction(function () use ($merge, $merger) {
                $target = Manuscript::findOrFail($merge['target_id']);
                $source = $merger->undoManuscriptMerge($target, $merge['result']);
                $source->forceFill(['metadata' => $merge['source_metadata']])->saveQuietly();
                $target->refresh()->forceFill(['metadata' => $merge['target_metadata']])->save();
            });
        }
        FieldSuggestion::whereIn('id', $backup['readings'])->delete();
        DB::table('manuscript_relations')->whereIn('id', $backup['relations'])->where('source', 'fankha')->delete();
        DB::table('manuscripts')->whereIn('id', $backup['facsimile'])->update(['is_facsimile' => false]);
        $this->info(sprintf('Reverted %d merges, %d readings, %d relations, %d facsimile marks.',
            count($backup['merges']), count($backup['readings']), count($backup['relations']), count($backup['facsimile'])));

        return self::SUCCESS;
    }
}

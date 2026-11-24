<?php

namespace App\Console\Commands;

use App\Helpers\TextNormalizer;
use App\Models\AuditLog;
use App\Models\Manuscript;
use App\Models\ManuscriptPerson;
use App\Models\Person;
use App\Suggestions\RecordRemover;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cleans the names of scribes that hold more than a name (rest of backlog item 145, step 14.356), from a decisions
 * file keyed by the name as it stands, since local and production ids differ: {"items": [{"name", "action",
 * "new_name"?, "note"?}]}. A person is touched only when he is a scribe and nothing else (no work, no other role).
 *
 *  - rename: «مرتضی غروی (فرزند شیخ شعبان گیلانی)», «گویا میرزا احمد نیریزی», «کاتب: محمد صادق ابن حاجی محمد»,
 *    «حسین بن محمد حسینی، تا رجب 1119ق». The person and his copies' scribe_name take the name alone; the remark, the
 *    doubt or the date goes to the note of his scribe rows, which the copy's page shows beside the name. Where one
 *    other person has exactly that name and is plainly the same scribe (sameScribe), the two are merged.
 *  - text: «به خطهای مختلف», «نونویس: غلامرضا اسدی», «گویا به خط ابوتراب»: no person. The copy keeps the text as
 *    its scribe's name with no person, and the person is removed (RecordRemover, logged as "remove"; his address leads
 *    to the work of a copy he was on).
 *  - autograph: «= مؤلف», «به خط مؤلف»: the copy is the author's hand (is_autograph); its scribe name and row go, and
 *    the person is removed. fahares:link-autograph-scribes then ties the author as before.
 *
 * Every change is logged as corpus_update. Safe to run again.
 */
class CleanScribeNamesCommand extends Command
{
    protected $signature = 'fahares:clean-scribe-names
        {--file= : The decisions file (database/data/scribe_names_step*.json)}
        {--apply : Write; without it the command only counts}
        {--show=6 : How many examples to list per action}';

    protected $description = 'Clean the names of scribes that hold a remark, a hedge, a label or a date, or are no name';

    public function handle(): int
    {
        $data = json_decode((string) @file_get_contents((string) $this->option('file')), true);
        if (! isset($data['items'])) {
            $this->error('File not found or without items: ' . $this->option('file'));

            return 1;
        }
        $apply = (bool) $this->option('apply');
        $counts = ['rename' => 0, 'text' => 0, 'autograph' => 0, 'no such person' => 0, 'left: not a scribe only' => 0, 'copies' => 0];
        $examples = [];
        $recount = $reindex = [];

        foreach ($data['items'] as $item) {
            $people = Person::where('name', $item['name'])->get();
            if ($people->isEmpty()) {
                $counts['no such person']++;

                continue;
            }
            foreach ($people as $person) {
                $rows = DB::table('manuscript_people')->where('person_id', $person->id)->get();
                if ($rows->contains(fn ($r) => $r->role !== ManuscriptPerson::SCRIBE) || DB::table('work_people')->where('person_id', $person->id)->exists()
                    || DB::table('works')->where('author_id', $person->id)->exists() || DB::table('catalogers')->where('person_id', $person->id)->exists()) {
                    $counts['left: not a scribe only']++;

                    continue;
                }
                $copies = $rows->pluck('manuscript_id')->merge(DB::table('manuscripts')->where('scribe_id', $person->id)->pluck('id'))->unique()->values();
                $counts[$item['action']]++;
                $counts['copies'] += $copies->count();
                if (count($examples[$item['action']] ?? []) < (int) $this->option('show')) {
                    $examples[$item['action']][] = "#{$person->id} «{$item['name']}»" . (isset($item['new_name']) ? " → «{$item['new_name']}»" . (isset($item['note']) ? " ({$item['note']})" : '') : '');
                }
                if (! $apply) {
                    continue;
                }
                DB::transaction(fn () => match ($item['action']) {
                    'rename' => $this->rename($person, $item, $rows, $copies),
                    'text' => $this->text($person, $item, $copies),
                    'autograph' => $this->autograph($person, $item, $copies),
                });
                $recount[] = $person->id;
                array_push($reindex, ...$copies->all());
            }
        }

        $this->info($apply ? 'Written.' : 'Dry run: nothing written.');
        foreach ($counts + ($apply ? ['merged into the scribe of the same name' => $this->merged] : []) as $k => $n) {
            $this->line(sprintf('  %-24s %6d', $k, $n));
        }
        foreach ($examples as $action => $list) {
            $this->line("-- {$action}");
            foreach ($list as $e) {
                $this->line("   {$e}");
            }
        }
        if ($apply && $reindex) {
            $ids = Person::whereIn('id', array_unique($recount))->pluck('id')->all();
            Person::withoutSyncingToSearch(fn () => Person::recountMany($ids));
            Person::whereIn('id', $ids)->get()->searchable();
            foreach (array_chunk(array_values(array_unique($reindex)), 500) as $chunk) {
                Manuscript::whereIn('id', $chunk)->get()->searchable();
            }
        }

        return 0;
    }

    /** The person and his copies take the name alone; what was with it goes to the note of his scribe rows */
    private function rename(Person $person, array $item, $rows, $copies): void
    {
        $name = trim($item['new_name']);
        $note = isset($item['note']) ? trim($item['note']) : null;
        foreach ($rows as $row) {
            $held = (string) $row->note;
            if ($note !== null && ! str_contains($held, $note)) {
                DB::table('manuscript_people')->where('id', $row->id)->update(['note' => mb_substr(trim($held . ($held === '' ? '' : '، ') . $note), 0, 500), 'updated_at' => now()]);
            }
        }
        DB::table('manuscripts')->whereIn('id', $copies)->where('scribe_name', $item['name'])->update(['scribe_name' => mb_substr($name, 0, 600), 'updated_at' => now()]);
        DB::table('people')->where('id', $person->id)->update(['name' => $name, 'normalized_name' => mb_substr(TextNormalizer::normalize($name), 0, 600), 'updated_at' => now()]);
        AuditLog::create(['user_id' => null, 'auditable_type' => Person::class, 'auditable_id' => $person->id, 'action' => 'corpus_update',
            'old_values' => ['name' => $item['name']],
            'new_values' => ['name' => $name, 'note' => $note, 'copies' => $copies->all(), 'corpus_fix' => $this->option('file'), 'category' => 'scribe_names']]);
        if ($target = $this->sameScribe($person->id, $name, $copies)) {
            app(\App\Services\PersonMerger::class)->merge($person->fresh(), $target, null, null, ['corpus_fix' => $this->option('file'), 'category' => 'scribe_names'], true);
            $this->merged++;
        }
    }

    private int $merged = 0;

    /**
     * The one other person of exactly this name, where he is plainly the same scribe: a name of three words or more,
     * his own copies within a century, and these copies' dates no more than sixty years outside them. Else the two
     * stay apart, as two people of one name do elsewhere.
     */
    private function sameScribe(int $personId, string $name, $copies): ?Person
    {
        if (count(preg_split('/\s+/u', trim($name))) < 3) {
            return null;
        }
        $others = Person::where('name', $name)->where('id', '!=', $personId)->limit(2)->get();
        if ($others->count() !== 1) {
            return null;
        }
        $range = DB::table('manuscript_people as mp')->join('manuscripts as m', 'm.id', '=', 'mp.manuscript_id')
            ->where('mp.person_id', $others[0]->id)->where('mp.role', ManuscriptPerson::SCRIBE)
            ->selectRaw('MIN(m.copy_date_from) AS f, MAX(m.copy_date_to) AS t')->first();
        $mine = DB::table('manuscripts')->whereIn('id', $copies)->selectRaw('MIN(copy_date_from) AS f, MAX(copy_date_to) AS t')->first();
        if ($range->f === null || $mine->f === null || $range->t - $range->f > 100 || $mine->f < $range->f - 60 || $mine->t > $range->t + 60) {
            return null;
        }

        return $others[0];
    }

    /** No person: the copy keeps the text as its scribe's name, and the person is removed */
    private function text(Person $person, array $item, $copies): void
    {
        DB::table('manuscript_people')->where('person_id', $person->id)->update(['person_id' => null, 'name' => mb_substr($item['name'], 0, 600), 'updated_at' => now()]);
        DB::table('manuscripts')->where('scribe_id', $person->id)->update(['scribe_id' => null, 'updated_at' => now()]);
        $this->remove($person, $copies, 'a scribe\'s «name» that is no person; the copy keeps the text');
    }

    /** «= مؤلف», «به خط مؤلف»: the copy is the author's hand */
    private function autograph(Person $person, array $item, $copies): void
    {
        DB::table('manuscript_people')->where('person_id', $person->id)->delete();
        foreach (DB::table('manuscripts')->whereIn('id', $copies)->get(['id', 'scribe_name', 'is_autograph']) as $copy) {
            DB::table('manuscripts')->where('id', $copy->id)->update(['is_autograph' => 1, 'scribe_id' => null, 'is_bika' => 0,
                'scribe_name' => $copy->scribe_name === $item['name'] ? null : $copy->scribe_name, 'updated_at' => now()]);
            AuditLog::create(['user_id' => null, 'auditable_type' => Manuscript::class, 'auditable_id' => $copy->id, 'action' => 'corpus_update',
                'old_values' => ['scribe_name' => $copy->scribe_name, 'is_autograph' => (int) $copy->is_autograph],
                'new_values' => ['scribe_name' => $copy->scribe_name === $item['name'] ? null : $copy->scribe_name, 'is_autograph' => 1, 'corpus_fix' => $this->option('file'), 'category' => 'scribe_names']]);
        }
        $this->remove($person, $copies, 'a scribe\'s «name» that says the author wrote the copy');
    }

    private function remove(Person $person, $copies, string $reason): void
    {
        $id = $person->id;
        $work = $copies->isEmpty() ? null : DB::table('manuscripts')->where('id', $copies->first())->value('work_id');
        $result = app(RecordRemover::class)->remove($person);
        AuditLog::create(['user_id' => null, 'auditable_type' => Person::class, 'auditable_id' => $id, 'action' => 'remove',
            'old_values' => $result, 'new_values' => ['redirect_work_id' => $work, 'reason' => $reason, 'copies' => $copies->all(), 'corpus_fix' => $this->option('file')]]);
    }
}

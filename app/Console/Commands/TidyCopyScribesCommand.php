<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Manuscript;
use App\Models\ManuscriptPerson;
use App\Models\Person;
use App\Suggestions\RecordRemover;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Tidies what fahares:add-copy-scribes left behind (backlog item 87, step 14.297), for the copies of its file:
 *
 *  - The person a copy's whole list of scribes was taken for («درویش در 1182ق، محمدهاشم بن …، محمد شفیع تبریزی در
 *    1230ق») has no copy once the list is one row a scribe. It is removed (RecordRemover, logged as "remove", so the
 *    audit log can bring it back); its address leads to the work of the copy it named.
 *  - A scribe that command linked to a person who was there already is unlinked, and stays a name, where that person
 *    is not plainly the same man: his other copies span more than a century (a name many scribes share: «سید محمد
 *    حسینی», 997 to 1323), or this hand's year («در 1014ق», else the copy's date) is more than sixty years outside
 *    them. A reviewer can link the name again.
 *
 * Only rows that command wrote are judged: those of a copy with its audit entry, written at or after it. Safe to
 * run again.
 */
class TidyCopyScribesCommand extends Command
{
    protected $signature = 'fahares:tidy-copy-scribes
        {--file= : The JSON file fahares:add-copy-scribes was run with}
        {--apply : Write; without it the command only lists}';

    protected $description = 'Remove the people named by a whole list of scribes, and unlink doubtful scribe links';

    private const SPAN = 100;
    private const MARGIN = 60;

    public function handle(): int
    {
        $data = json_decode((string) @file_get_contents((string) $this->option('file')), true);
        if (! isset($data['records'])) {
            $this->error('File not found or without records: ' . $this->option('file'));

            return 1;
        }
        $apply = (bool) $this->option('apply');
        $ids = array_column($data['records'], 'id');
        $logs = AuditLog::where('auditable_type', Manuscript::class)->whereIn('auditable_id', $ids)->where('action', 'corpus_update')
            ->where('new_values', 'like', '%"category":"scribes"%')->orderBy('id')->get()->keyBy('auditable_id');
        $unlinked = $removed = 0;
        $people = $copies = [];

        foreach ($logs as $copyId => $log) {
            $copy = DB::table('manuscripts')->where('id', $copyId)->first(['id', 'work_id', 'copy_date_from', 'copy_date_to']);
            $rows = DB::table('manuscript_people')->where('manuscript_id', $copyId)->where('role', ManuscriptPerson::SCRIBE)->whereNotNull('person_id')
                ->where(fn ($q) => $q->where('created_at', '>=', $log->created_at->copy()->subSeconds(5))->orWhere('updated_at', '>=', $log->created_at->copy()->subSeconds(5)))->get();
            foreach ($rows as $row) {
                $person = DB::table('people')->where('id', $row->person_id)->first(['id', 'name', 'created_at']);
                // a person made for this hand is his own
                if (! $person || ($person->created_at && $person->created_at >= $log->created_at->copy()->subSeconds(5))) {
                    continue;
                }
                $why = $this->doubt($row, $copy);
                if (! $why) {
                    continue;
                }
                $this->line("#{$copyId} «{$person->name}» (#{$person->id}): {$why}");
                $unlinked++;
                if ($apply) {
                    DB::table('manuscript_people')->where('id', $row->id)->update(['person_id' => null, 'name' => mb_substr($person->name, 0, 600), 'updated_at' => now()]);
                    if ((int) $row->position === 0) {
                        DB::table('manuscripts')->where('id', $copyId)->where('scribe_id', $person->id)->update(['scribe_id' => null, 'updated_at' => now()]);
                    }
                    AuditLog::create([
                        'user_id' => null, 'auditable_type' => Manuscript::class, 'auditable_id' => $copyId, 'action' => 'corpus_update',
                        'old_values' => ['scribe_person' => $person->id], 'new_values' => ['scribe_person' => null, 'scribe' => $person->name, 'reason' => $why, 'category' => 'scribe_unlinked'],
                    ]);
                    $people[] = $person->id;
                    $copies[] = $copyId;
                }
            }

            // the person named by the whole text of the copy's scribes
            $old = (string) ($log->old_values['scribe_name'] ?? '');
            $new = (string) ($log->new_values['scribe_name'] ?? '');
            if ($old === '' || $old === $new) {
                continue;
            }
            foreach (Person::where('name', $old)->get() as $person) {
                if (app(RecordRemover::class)->blockers($person)) {
                    $this->line("«{$old}» (#{$person->id}) still has copies or works: kept");

                    continue;
                }
                $this->line("remove «" . mb_substr($old, 0, 90) . "» (#{$person->id})");
                $removed++;
                if ($apply) {
                    DB::transaction(function () use ($person, $copy) {
                        $id = $person->id;
                        $result = app(RecordRemover::class)->remove($person);
                        AuditLog::create([
                            'user_id' => null, 'auditable_type' => Person::class, 'auditable_id' => $id, 'action' => 'remove',
                            'old_values' => $result, 'new_values' => ['redirect_work_id' => $copy->work_id, 'reason' => 'the whole list of a copy\'s scribes, taken for one person', 'copy' => $copy->id],
                        ]);
                    });
                }
            }
        }
        $this->info(($apply ? 'Unlinked ' : 'Would unlink ') . "{$unlinked} scribes and " . ($apply ? 'removed ' : 'remove ') . "{$removed} people, of {$logs->count()} copies.");
        if ($apply && $people) {
            $people = array_values(array_unique($people));
            Person::withoutSyncingToSearch(fn () => Person::recountMany($people));
            Person::whereIn('id', $people)->get()->searchable();
            Manuscript::whereIn('id', array_unique($copies))->get()->searchable();
        }

        return 0;
    }

    /** Why the row's person may be another man than this hand, or null */
    private function doubt(object $row, object $copy): ?string
    {
        $range = DB::table('manuscript_people as mp')->join('manuscripts as m', 'm.id', '=', 'mp.manuscript_id')
            ->where('mp.person_id', $row->person_id)->where('mp.role', ManuscriptPerson::SCRIBE)->where('mp.manuscript_id', '!=', $copy->id)
            ->selectRaw('MIN(m.copy_date_from) AS f, MAX(m.copy_date_to) AS t, COUNT(*) AS n')->first();
        if (! $range->n || $range->f === null) {
            return null;
        }
        if ($range->t - $range->f > self::SPAN) {
            return "his other {$range->n} copies span {$range->f} to {$range->t}";
        }
        $year = preg_match('/(?<!\d)(\d{3,4})(?!\d)/u', (string) $row->note, $m) ? (int) $m[1] : ($copy->copy_date_from ?: null);
        if ($year && ($year < $range->f - self::MARGIN || $year > $range->t + self::MARGIN)) {
            return "this hand is of {$year}, his other copies of {$range->f} to {$range->t}";
        }

        return null;
    }
}

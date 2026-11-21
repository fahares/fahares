<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\PersonPeriod;
use App\Support\DateSpan;
use Illuminate\Console\Command;

/**
 * Applies a file of decisions on the waiting proposals of the periods of people's lives (steps 14.298 and 14.311,
 * backlog item 137): the proposals of fahares:propose-life-periods were read one by one against the person and the
 * entry of the life, and each got a verdict. A decision is
 *
 *     {"id": 1671, "verdict": "approve", "place": "أصبهان", "year": 1118}
 *     {"id": 644, "verdict": "approve", "place": "النجف", "kind": "residence", "year": 1290, "year_is": "from"}
 *     {"id": 2172, "verdict": "reject", "reason": "the entry is the life of another person"}
 *
 * «approve» makes the proposal a period of the person's page, with the place as the source writes it, the kind when
 * the reading corrects it, and the year when one is given («in» that year, or «from» it on); «reject» keeps the row as
 * rejected, so that it is not proposed again. A decision on a row that is no longer a waiting proposal is skipped: a
 * reviewer's word is never overwritten. Every change is
 * written to the audit log under the person, like a reviewer's own. Without --apply the command only counts.
 */
class ReviewLifePeriodsCommand extends Command
{
    protected $signature = 'fahares:review-life-periods
        {file : JSON array of decisions: id, verdict (approve|reject), place, kind, year, year_is (in|from), reason}
        {--apply : Write the decisions; without it the command only counts}';

    protected $description = 'Apply reviewed decisions on the proposed periods of people\'s lives';

    public function handle(): int
    {
        $decisions = json_decode((string) @file_get_contents($this->argument('file')), true);
        if (! is_array($decisions)) {
            $this->error('The file is not a JSON array of decisions.');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $counts = ['approve' => 0, 'reject' => 0, 'skipped' => 0, 'with_year' => 0, 'bad' => 0];
        $kinds = [];
        $noDate = array_fill_keys(array_keys((new DateSpan(null, null))->columns('period')), null);
        foreach ($decisions as $decision) {
            $verdict = $decision['verdict'] ?? null;
            if (! is_array($decision) || ! isset($decision['id']) || ! in_array($verdict, ['approve', 'reject'], true)) {
                $counts['bad']++;
                continue;
            }
            $period = PersonPeriod::find((int) $decision['id']);
            if ($period === null || $period->status !== PersonPeriod::PROPOSED) {
                $counts['skipped']++;
                continue;
            }
            $old = $period->toArray();
            if ($verdict === 'reject') {
                $new = ['status' => PersonPeriod::REJECTED];
            } else {
                $year = isset($decision['year']) && (int) $decision['year'] >= 1 && (int) $decision['year'] <= 1500 ? (int) $decision['year'] : null;
                // «in» a year, or «from» it on (a residence that began then)
                $date = $year === null ? $noDate : PersonPeriod::dateColumns(($decision['year_is'] ?? 'in') === 'from'
                    ? ['mode' => 'span', 'start' => $year] : ['mode' => 'point', 'start' => $year]);
                $place = trim((string) ($decision['place'] ?? ''));
                $kind = array_key_exists($decision['kind'] ?? '', PersonPeriod::KINDS) ? $decision['kind'] : $period->kind;
                $new = ['status' => PersonPeriod::APPROVED, 'kind' => $kind] + $date + ($place !== '' ? ['place' => mb_substr($place, 0, 150)] : []);
                $counts['with_year'] += $year === null ? 0 : 1;
            }
            $counts[$verdict]++;
            $kinds["{$period->kind} {$verdict}"] = ($kinds["{$period->kind} {$verdict}"] ?? 0) + 1;
            if (! $apply) {
                continue;
            }
            $city = $period->city;
            $period->update($new);
            // The place as the source writes it must still name the city the proposal was read for
            if ($verdict === 'approve' && $period->city !== $city) {
                $period->forceFill(['city' => $city])->saveQuietly();
            }
            AuditLog::create([
                'user_id' => null,
                'auditable_type' => Person::class,
                'auditable_id' => $period->person_id,
                'action' => 'update',
                'old_values' => ['person_period' => $old],
                'new_values' => ['person_period' => $period->fresh()->toArray(), 'person_period_change' => $verdict,
                    'by' => 'fahares:review-life-periods', 'reason' => mb_substr((string) ($decision['reason'] ?? ''), 0, 300)],
            ]);
        }

        $this->info($apply ? 'Decisions written.' : 'Dry run: nothing written.');
        foreach ($counts as $what => $n) {
            $this->line(sprintf('  %-10s %6d', $what, $n));
        }
        ksort($kinds);
        foreach ($kinds as $what => $n) {
            $this->line(sprintf('  %-16s %6d', $what, $n));
        }

        return self::SUCCESS;
    }
}

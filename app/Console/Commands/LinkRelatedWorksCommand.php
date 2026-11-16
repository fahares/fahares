<?php

namespace App\Console\Commands;

use App\Models\Work;
use App\Support\WorkRelationLinker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Links the related works Fankha records as text («وابسته به», «شروح و حواشی») to their works in work_relations
 * (backlog item 6, step 14.104, WorkRelationLinker), with source «auto» and a confidence: 90 when the type is read
 * from the title, 70 for «related». A pair an editor already related (a suggestion, either way round) is left; the auto
 * rows are made again on every run, the ones no longer given removed. The works made on another are indexed again
 * afterwards, as their base titles feed the search (Work::baseTitles).
 */
class LinkRelatedWorksCommand extends Command
{
    public const SOURCE = 'auto';

    protected $signature = 'works:link-related
        {--dry-run : Match and count without saving}
        {--sample= : Write a random sample of each type, with the texts that gave it, to this file}
        {--per-kind=50 : Pairs of each type in the sample}
        {--no-index : Do not index the changed works again}
        {--unmatched= : Write the entries not linked, with how far they matched, to this TSV file}
        {--by-rule= : Write the entries a rule other than title and author linked, with the work found, to this TSV file}';

    protected $description = 'Link the related works recorded as text (related_work, commentaries_and_glosses) to their works';

    public function handle(): int
    {
        $this->info('Reading the works…');
        $unmatched = [];
        $byRule = [];
        $each = $this->option('unmatched') || $this->option('by-rule') ? function (int $id, string $side, string $how, array $entry, ?array $pair) use (&$unmatched, &$byRule) {
            $row = [$id, $side, $how, implode(' = ', $entry['titles']), (string) $entry['author']];
            if (! $pair) {
                $unmatched[] = implode("\t", $row);
            } elseif ($how !== 'title+author') {
                $byRule[] = [...$row, $side === 'related' ? $pair['parent'] : $pair['child'], $pair['type']];
            }
        } : null;
        ['pairs' => $pairs, 'count' => $count] = (new WorkRelationLinker)->pairs($each);
        if ($this->option('unmatched')) {
            file_put_contents($this->option('unmatched'), "work_id\tside\thow\ttitles\tauthor\n" . implode("\n", $unmatched) . "\n");
            $this->info(count($unmatched) . ' entries not linked written to ' . $this->option('unmatched'));
        }
        if ($this->option('by-rule')) {
            $found = DB::table('works')->whereIn('id', array_column($byRule, 5))->get(['id', 'clean_title', 'author_name'])->keyBy('id');
            file_put_contents($this->option('by-rule'), "work_id\tside\thow\ttitles\tauthor\tfound_id\ttype\tfound_title\tfound_author\n" . implode("\n", array_map(
                fn ($r) => implode("\t", [...$r, $found[$r[5]]->clean_title ?? '', $found[$r[5]]->author_name ?? '']), $byRule)) . "\n");
            $this->info(count($byRule) . ' entries linked by a rule written to ' . $this->option('by-rule'));
        }
        ksort($count);
        $this->table(['entries', 'count'], array_map(fn ($k, $n) => [$k, $n], array_keys($count), $count));

        // a pair an editor related already, of any type and either way round, is the editor's
        $kept = 0;
        $edited = DB::table('work_relations')->where('source', '<>', self::SOURCE)->get(['work_id', 'related_work_id'])
            ->flatMap(fn ($r) => ["{$r->work_id}>{$r->related_work_id}" => true, "{$r->related_work_id}>{$r->work_id}" => true]);
        foreach (array_keys($pairs) as $k) {
            if (isset($edited[$k])) {
                unset($pairs[$k]);
                $kept++;
            }
        }
        $byType = collect($pairs)->countBy('type')->sortDesc();
        $this->table(['type', 'pairs'], $byType->map(fn ($n, $t) => [$t, $n])->values()->all());
        $this->line("Left to the editors' relations: {$kept}. Pairs from both texts: " . collect($pairs)->filter(fn ($p) => count($p['sides']) === 2)->count());

        if ($this->option('sample')) {
            $this->sample($pairs);
        }

        $want = collect($pairs)->keyBy(fn ($p) => "{$p['child']}>{$p['parent']}>{$p['type']}");
        $have = DB::table('work_relations')->where('source', self::SOURCE)->get(['id', 'work_id', 'related_work_id', 'type', 'confidence'])
            ->keyBy(fn ($r) => "{$r->work_id}>{$r->related_work_id}>{$r->type}");
        $add = $want->diffKeys($have);
        $remove = $have->diffKeys($want);
        $this->info(sprintf('%d relations: %d to add, %d to remove, %d kept', $want->count(), $add->count(), $remove->count(), $want->count() - $add->count()));
        if ($this->option('dry-run')) {
            $this->warn('Dry run: nothing is saved.');

            return self::SUCCESS;
        }

        $now = now();
        DB::transaction(function () use ($add, $remove, $now) {
            foreach ($remove->pluck('id')->chunk(1000) as $ids) {
                DB::table('work_relations')->whereIn('id', $ids)->delete();
            }
            foreach ($add->chunk(1000) as $chunk) {
                DB::table('work_relations')->insertOrIgnore($chunk->map(fn ($p) => [
                    'work_id' => $p['child'], 'related_work_id' => $p['parent'], 'type' => $p['type'], 'note' => null,
                    'source' => self::SOURCE, 'confidence' => $p['confidence'], 'created_at' => $now, 'updated_at' => $now,
                ])->values()->all());
            }
        });
        $this->info("Saved: {$add->count()} added, {$remove->count()} removed.");

        $changed = $add->pluck('child')->merge($remove->pluck('work_id'))->unique()->values();
        if (! $this->option('no-index') && $changed->isNotEmpty()) {
            foreach ($changed->chunk(500) as $ids) {
                Work::whereIn('id', $ids)->searchable();
            }
            $this->info("Indexed {$changed->count()} works again.");
        }

        return self::SUCCESS;
    }

    private function sample(array $pairs): void
    {
        $titles = DB::table('works')->whereIn('id', collect($pairs)->flatMap(fn ($p) => [$p['child'], $p['parent']])->unique())->pluck('clean_title', 'id');
        $out = [];
        foreach (collect($pairs)->groupBy('type') as $type => $group) {
            $per = (int) $this->option('per-kind');
            $out[] = "===== {$type} ({$group->count()} pairs) =====";
            foreach (($group->count() <= $per ? $group : $group->shuffle(14104)->take($per))->values() as $n => $p) {
                $out[] = sprintf("%s-%d #%d «%s»  →  #%d «%s»\n    from %s: «%s»", $type, $n + 1, $p['child'], $titles[$p['child']] ?? '', $p['parent'],
                    $titles[$p['parent']] ?? '', implode('+', $p['sides']), $p['text']);
            }
        }
        file_put_contents($this->option('sample'), implode("\n", $out) . "\n");
        $this->info('Sample written to ' . $this->option('sample'));
    }
}

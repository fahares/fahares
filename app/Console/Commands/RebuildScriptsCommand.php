<?php

namespace App\Console\Commands;

use App\Models\Manuscript;
use App\Models\Script;
use App\Support\ScriptNameCleaner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Recomputes every copy's scripts from the «خط:» line of its raw text with ScriptNameCleaner and relinks
 * the copies whose set of scripts differs (backlog items 53 and 55). It replaced the one-off
 * fahares:rebuild-shekasteh-scripts. A changed copy's «نوع خط» becomes the catalogue's wording. Copies
 * without a «خط:» line, whose line yields no script, or whose script fields were changed through an
 * approved suggestion are left alone. The dry run groups the changes by «before → after» for review.
 * Safe to run again.
 */
class RebuildScriptsCommand extends Command
{
    protected $signature = 'fahares:rebuild-scripts
        {--dry-run : Report the changes without saving them}
        {--sync-search : Re-index the changed manuscripts in Meilisearch}
        {--examples=2 : Example manuscripts shown for each change}';

    protected $description = 'Recompute the scripts of every copy from its raw «خط:» line and relink the ones that differ';

    /** The «خط:» value: up to the next field, the scribe, the date, the place or a codicological note. */
    // Same stops as SCRIPT_STOP in the corpus parser
    public const LINE = '/خط:\s*(.*?)(?=[؛\n\[]|،\s*(?:کا:|کاتب:|محرر:|تا:|جا:|کاغذ:|جلد:|قطع:|ابعاد|اندازه|مصحح|مجدول|مذهب|مصور|رکابه‌دار|\d|مختلف[‌\s]السطو?ر)|$)/u';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $examples = (int) $this->option('examples');

        $names = Script::pluck('name', 'id');
        $edited = DB::table('field_suggestions')
            ->where('suggestable_type', (new Manuscript)->getMorphClass())
            ->whereIn('field_name', ['script_names', 'script_style'])->where('status', 'approved')
            ->pluck('suggestable_id')->flip();

        $plans = [];
        $transitions = [];
        $emptied = 0;
        DB::table('manuscripts')->where('raw_text', 'like', '%خط:%')->select(['id', 'raw_text'])
            ->chunkById(5000, function ($rows) use ($names, $edited, &$plans, &$transitions, &$emptied, $examples) {
                $current = DB::table('manuscript_script')->whereIn('manuscript_id', $rows->pluck('id'))
                    ->orderByDesc('is_primary')->orderBy('script_id')->get()->groupBy('manuscript_id');
                foreach ($rows as $m) {
                    if (isset($edited[$m->id]) || ! preg_match(self::LINE, ScriptNameCleaner::normalize((string) $m->raw_text), $line)) {
                        continue;
                    }
                    $clean = ScriptNameCleaner::clean($line[1]);
                    $old = ($current[$m->id] ?? collect())->map(fn ($p) => $names[$p->script_id])->all();
                    if (! $clean['scripts']) {
                        $emptied += $old ? 1 : 0;
                        continue;
                    }
                    $new = $clean['scripts'];
                    $a = $old;
                    $b = $new;
                    sort($a);
                    sort($b);
                    if ($a === $b) {
                        continue;
                    }
                    $plans[$m->id] = ['scripts' => $new, 'wording' => $clean['wording']];
                    $key = (implode(' + ', $old) ?: '—') . ' → ' . implode(' + ', $new);
                    $transitions[$key] ??= ['n' => 0, 'ids' => []];
                    $transitions[$key]['n']++;
                    if (count($transitions[$key]['ids']) < $examples) {
                        $transitions[$key]['ids'][] = "{$m->id} «{$clean['wording']}»";
                    }
                }
            });

        uasort($transitions, fn ($x, $y) => $y['n'] <=> $x['n']);
        $this->line('Step 1: ' . count($plans) . ' copies whose scripts differ from their «خط:» line; before → after');
        $this->table(['change', 'copies', 'examples'], collect($transitions)->map(fn ($t, $k) => [$k, $t['n'], implode('، ', $t['ids'])])->values()->all());
        $this->line("Step 2: {$emptied} copies whose «خط:» line yields no script keep their links; " . count($edited) . ' copies edited through suggestions are skipped');

        if ($dry || ! $plans) {
            $this->info($plans ? 'Dry run: nothing saved.' : 'Nothing to change.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($plans) {
            $ids = [];
            foreach (array_unique(array_merge(...array_column($plans, 'scripts'))) as $name) {
                $ids[$name] = $this->scriptId($name);
            }
            foreach ($plans as $id => $plan) {
                DB::table('manuscript_script')->where('manuscript_id', $id)->delete();
                DB::table('manuscript_script')->insert(array_map(fn ($name, $i) => [
                    'manuscript_id' => $id, 'script_id' => $ids[$name], 'is_primary' => $i === 0,
                ], $plan['scripts'], array_keys($plan['scripts'])));
                DB::table('manuscripts')->where('id', $id)->update(['script_names' => $plan['wording'], 'updated_at' => now()]);
            }
            DB::statement('UPDATE scripts SET manuscripts_count = (SELECT COUNT(*) FROM manuscript_script WHERE manuscript_script.script_id = scripts.id)');
            DB::table('scripts')->where('manuscripts_count', 0)->delete();
        });
        $this->info('Saved. Scripts now: ' . Script::count());

        if ($this->option('sync-search')) {
            foreach (array_chunk(array_keys($plans), 200) as $chunk) {
                (new Manuscript)->makeAllSearchableUsing(Manuscript::whereIn('id', $chunk))->get()->searchable();
            }
            $this->info('Re-indexed ' . count($plans) . ' manuscripts.');
        }

        return self::SUCCESS;
    }

    protected function scriptId(string $name): int
    {
        $script = Script::where('name', $name)->first();
        if (! $script) {
            $script = Script::create(['name' => $name, 'slug' => Str::uuid()->toString()]);
            $script->update(['slug' => (Str::slug($name) ?: 'script') . '-' . $script->id]);
            $this->line("  created script {$script->id} «{$name}»");
        }

        return $script->id;
    }
}

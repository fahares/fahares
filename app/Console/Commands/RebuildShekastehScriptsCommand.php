<?php

namespace App\Console\Commands;

use App\Models\Manuscript;
use App\Models\Script;
use App\Support\ScriptNameCleaner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rebuilds the scripts of every copy linked to «شکسته» from the «خط:» line of its raw text (backlog
 * item 53). The parser had read «نستعلیق شکسته» as two scripts and «شکسته تعلیق» as «شکسته» plus
 * «تعلیق», and kept «شکسته» apart from «شکسته نستعلیق». Under the user's decisions of 28 Mehr 1405:
 * «شکسته» alone is شکسته نستعلیق, «نستعلیق شکسته» is شکسته نستعلیق only, «نستعلیق و شکسته» and
 * «نستعلیق مخلوط به شکسته» are both scripts, and «شکسته تعلیق» is a script of its own. The copy's
 * «نوع خط» becomes the catalogue's wording; «تحریری» in that wording goes to «کیفیت خط». Copies dated
 * before the 11th century, when شکسته نستعلیق was not yet in use, are listed for review against the
 * printed catalogue. Safe to run again.
 */
class RebuildShekastehScriptsCommand extends Command
{
    protected $signature = 'fahares:rebuild-shekasteh-scripts
        {--dry-run : Report the changes without saving them}
        {--sync-search : Re-index the changed manuscripts in Meilisearch}';

    protected $description = 'Rebuild the scripts of the copies linked to «شکسته» from their raw «خط:» line';

    /** The «خط:» value: up to the next field, the scribe, the date or the place. */
    private const LINE = '/خط:\s*(.*?)(?=[؛\n\[]|،\s*(?:کا:|کاتب:|محرر:|تا:|جا:|کاغذ:|جلد:|\d)|$)/u';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $shekasteh = Script::where('name', 'شکسته')->first();
        if (! $shekasteh) {
            $this->info('No «شکسته» record left.');

            return self::SUCCESS;
        }

        $names = Script::pluck('name', 'id');
        $pivots = DB::table('manuscript_script')->get(['manuscript_id', 'script_id', 'is_primary'])
            ->whereIn('manuscript_id', DB::table('manuscript_script')->where('script_id', $shekasteh->id)->pluck('manuscript_id'))
            ->groupBy('manuscript_id');
        $manuscripts = DB::table('manuscripts')->whereIn('id', $pivots->keys())
            ->get(['id', 'raw_text', 'script_names', 'script_style', 'copy_date_hijri_year', 'library', 'shelfmark'])->keyBy('id');

        $plans = [];
        $unread = [];
        $transitions = [];
        $review = [];
        foreach ($manuscripts as $m) {
            if (! preg_match(self::LINE, ScriptNameCleaner::normalize((string) $m->raw_text), $line)) {
                $unread[] = $m->id;
                continue;
            }
            $clean = ScriptNameCleaner::clean($line[1]);
            if (! $clean['scripts']) {
                $unread[] = $m->id;
                continue;
            }
            $styles = array_values(array_filter(array_map('trim', explode('،', (string) $m->script_style))));
            if (preg_match('/(?<!\p{L})تحریری(?!\p{L})/u', $clean['wording']) && ! in_array('تحریری', $styles, true)) {
                $styles[] = 'تحریری';
            }
            $plans[$m->id] = ['scripts' => $clean['scripts'], 'wording' => $clean['wording'], 'style' => $styles ? implode('، ', $styles) : null];

            $old = $pivots[$m->id]->sortByDesc('is_primary')->map(fn ($p) => $names[$p->script_id])->implode(' + ');
            $transitions[$old . ' → ' . implode(' + ', $clean['scripts'])] = ($transitions[$old . ' → ' . implode(' + ', $clean['scripts'])] ?? 0) + 1;

            if ($m->copy_date_hijri_year && $m->copy_date_hijri_year <= 1000 && in_array('شکسته نستعلیق', $clean['scripts'], true)
                && ! str_contains($clean['wording'], 'شکسته نستعلیق')) {
                $review[] = [$m->id, $m->copy_date_hijri_year, $m->library, $m->shelfmark, $clean['wording']];
            }
        }

        arsort($transitions);
        $this->line('Step 1: ' . count($plans) . ' copies to rebuild; scripts before → after');
        $this->table(['change', 'copies'], collect($transitions)->map(fn ($n, $k) => [$k, $n])->values()->take(25)->all());
        if (count($transitions) > 25) {
            $this->line('  … and ' . (count($transitions) - 25) . ' rarer changes, ' . array_sum(array_slice($transitions, 25)) . ' copies');
        }
        if ($unread) {
            // The parser took these from «به خط شکسته …» in the description: «شکسته» becomes شکسته نستعلیق
            $this->line('Step 2: ' . count($unread) . ' copies without a «خط:» line: «شکسته» → شکسته نستعلیق, «نوع خط» kept: ' . implode(', ', $unread));
        }
        $this->line('Step 3: «شکسته» copies dated before the 11th century, to check against the printed catalogue');
        $this->table(['manuscript', 'year', 'library', 'shelfmark', 'wording'], $review);

        if ($dry) {
            $this->info('Dry run: nothing saved.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($plans, $unread, $shekasteh) {
            $ids = [];
            foreach (array_unique(['شکسته نستعلیق', ...array_merge(...array_column($plans, 'scripts'))]) as $name) {
                $ids[$name] = $this->scriptId($name);
            }
            foreach ($unread as $id) {
                DB::table('manuscript_script')->insertOrIgnore(['manuscript_id' => $id, 'script_id' => $ids['شکسته نستعلیق'], 'is_primary' => false]);
                $wasPrimary = DB::table('manuscript_script')->where(['manuscript_id' => $id, 'script_id' => $shekasteh->id])->value('is_primary');
                DB::table('manuscript_script')->where(['manuscript_id' => $id, 'script_id' => $shekasteh->id])->delete();
                if ($wasPrimary) {
                    DB::table('manuscript_script')->where(['manuscript_id' => $id, 'script_id' => $ids['شکسته نستعلیق']])->update(['is_primary' => true]);
                }
            }
            foreach ($plans as $id => $plan) {
                DB::table('manuscript_script')->where('manuscript_id', $id)->delete();
                DB::table('manuscript_script')->insert(array_map(fn ($name, $i) => [
                    'manuscript_id' => $id, 'script_id' => $ids[$name], 'is_primary' => $i === 0,
                ], $plan['scripts'], array_keys($plan['scripts'])));
                DB::table('manuscripts')->where('id', $id)->update([
                    'script_names' => $plan['wording'], 'script_style' => $plan['style'], 'updated_at' => now(),
                ]);
            }
            $shekasteh->delete();
            DB::statement('UPDATE scripts SET manuscripts_count = (SELECT COUNT(*) FROM manuscript_script WHERE manuscript_script.script_id = scripts.id)');
        });
        $this->info('Saved. Scripts now: ' . Script::count());

        if ($this->option('sync-search')) {
            foreach (array_chunk([...array_keys($plans), ...$unread], 200) as $chunk) {
                (new Manuscript)->makeAllSearchableUsing(Manuscript::whereIn('id', $chunk))->get()->searchable();
            }
            $this->info('Re-indexed ' . (count($plans) + count($unread)) . ' manuscripts.');
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

<?php

namespace App\Console\Commands;

use App\Models\Manuscript;
use App\Models\Script;
use App\Support\ScriptNameCleaner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cleans the script records that hold a codicological note or a scribe's name (backlog item 50):
 * «تحریری، بی‌کا» → «تحریری» plus the «بی‌کا» flag, «نستعليق، كا: …» → «نستعلیق» plus the scribe's
 * name, a bare scribe name → no script; then a quality leaves the script name for «کیفیت خط», variants
 * merge and print or layouts leave the list (ScriptNameCleaner::UNIFY). Each manuscript moves to the clean
 * script records, its empty «بی‌کا»، «بی‌تا» and «کاتب = مؤلف» flags and its empty scribe name are filled,
 * the qualities are added to its «کیفیت خط», its «نوع خط» text keeps the names without the notes
 * («تحریری متوسط»، «چاپی»), and the emptied records are deleted. Records are matched by name, so the command runs the
 * same on local and production. Safe to run again.
 */
class CleanScriptNamesCommand extends Command
{
    protected $signature = 'fahares:clean-script-names
        {--dry-run : Report the changes without saving them}
        {--sync-search : Re-index the changed manuscripts in Meilisearch}';

    protected $description = 'Split codicological notes and scribe names out of the script records';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $plans = Script::orderBy('id')->get()
            // «شکسته» needs each copy's raw «خط:» line: fahares:rebuild-scripts
            ->reject(fn (Script $s) => ScriptNameCleaner::isClean($s->name) || $s->name === 'شکسته')
            ->map(fn (Script $s) => ['script' => $s, 'clean' => ScriptNameCleaner::clean($s->name)]);

        if ($plans->isEmpty()) {
            $this->info('All script records are clean.');

            return self::SUCCESS;
        }

        $pivots = DB::table('manuscript_script')->whereIn('script_id', $plans->pluck('script.id'))->get()->groupBy('script_id');
        $manuscripts = DB::table('manuscripts')
            ->whereIn('id', $pivots->flatten()->pluck('manuscript_id')->unique())
            ->get(['id', 'scribe_id', 'scribe_name', 'is_bika', 'is_bita', 'is_autograph', 'copy_date_hijri_year', 'script_style'])
            ->keyBy('id');

        $this->line('Step 1: script records to clean');
        $this->table(['id', 'name', 'manuscripts', 'scripts', 'notes'], $plans->map(fn ($p) => [
            $p['script']->id,
            $p['script']->name,
            count($pivots[$p['script']->id] ?? []),
            implode('، ', $p['clean']['scripts']) ?: '—',
            $this->describeNotes($p['clean']),
        ])->all());

        // Step 2: what each manuscript gains; a note that contradicts the record is reported, not applied
        $fills = ['is_bika' => [], 'is_bita' => [], 'is_autograph' => [], 'scribe_name' => [], 'script_style' => []];
        $already = ['is_bika' => 0, 'is_bita' => 0, 'is_autograph' => 0];
        $conflicts = [];
        foreach ($plans as $p) {
            $clean = $p['clean'];
            foreach ($pivots[$p['script']->id] ?? [] as $pivot) {
                $m = $manuscripts[$pivot->manuscript_id];
                $hasScribe = $m->scribe_id || $m->scribe_name;
                foreach (['bika' => 'is_bika', 'bita' => 'is_bita', 'autograph' => 'is_autograph'] as $key => $column) {
                    if (! $clean[$key]) {
                        continue;
                    }
                    if ($m->{$column}) {
                        $already[$column]++;
                    } elseif ($column === 'is_bita' && $m->copy_date_hijri_year) {
                        $conflicts[] = "manuscript {$m->id}: «بی‌تا» but copy year {$m->copy_date_hijri_year}";
                    } elseif ($column !== 'is_bita' && $hasScribe) {
                        $conflicts[] = "manuscript {$m->id}: {$column} note but scribe «" . ($m->scribe_name ?? "#{$m->scribe_id}") . '»';
                    } else {
                        $fills[$column][$m->id] = true;
                    }
                }
                if ($clean['styles']) {
                    $styles = $fills['script_style'][$m->id] ?? array_values(array_filter(array_map('trim', explode('،', (string) $m->script_style))));
                    $added = array_diff($clean['styles'], $styles);
                    if ($added) {
                        $fills['script_style'][$m->id] = [...$styles, ...$added];
                    }
                }
                if ($clean['scribe'] !== null) {
                    if ($m->scribe_id || ScriptNameCleaner::normalize((string) $m->scribe_name) === $clean['scribe']) {
                        // the scribe is already recorded
                    } elseif ($hasScribe || $m->is_bika || $m->is_autograph) {
                        $conflicts[] = "manuscript {$m->id}: scribe «{$clean['scribe']}» not set, record has " . ($m->scribe_name ?? ($m->scribe_id ? "#{$m->scribe_id}" : 'a flag'));
                    } else {
                        $fills['scribe_name'][$m->id] = $clean['scribe'];
                    }
                }
            }
        }

        $this->line('Step 2: manuscript flags and scribe names');
        $this->table(['field', 'already set', 'to fill'], [
            ['is_bika', $already['is_bika'], count($fills['is_bika'])],
            ['is_bita', $already['is_bita'], count($fills['is_bita'])],
            ['is_autograph', $already['is_autograph'], count($fills['is_autograph'])],
            ['scribe_name', '—', count($fills['scribe_name'])],
            ['script_style', '—', count($fills['script_style'])],
        ]);
        foreach ($conflicts as $conflict) {
            $this->warn("  skipped: {$conflict}");
        }

        $affectedIds = $manuscripts->keys()->all();

        // «نوع خط» text: the names of the manuscript's scripts, primary first, with each cleaned record's labels
        $labels = $plans->mapWithKeys(fn ($p) => [$p['script']->id => $p['clean']['labels']]);
        $scriptNames = DB::table('manuscript_script')
            ->join('scripts', 'scripts.id', '=', 'manuscript_script.script_id')
            ->whereIn('manuscript_id', $affectedIds)
            ->orderByDesc('is_primary')->orderBy('scripts.id')
            ->get(['manuscript_id', 'scripts.id', 'scripts.name'])
            ->groupBy('manuscript_id')
            ->map(fn ($rows) => $rows->flatMap(fn ($r) => $labels[$r->id] ?? [$r->name])->unique()->implode('، ') ?: null);
        $this->line('Step 3: ' . $plans->count() . ' records to delete, ' . count($affectedIds) . ' manuscripts to relink');

        if ($dry) {
            $this->info('Dry run: nothing saved.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($plans, $pivots, $fills, $affectedIds, $scriptNames) {
            foreach ($plans as $p) {
                $targetIds = array_map(fn ($name) => $this->scriptId($name), $p['clean']['scripts']);
                foreach ($pivots[$p['script']->id] ?? [] as $pivot) {
                    foreach ($targetIds as $i => $targetId) {
                        $isPrimary = $pivot->is_primary && $i === 0;
                        DB::table('manuscript_script')->insertOrIgnore([
                            'manuscript_id' => $pivot->manuscript_id, 'script_id' => $targetId, 'is_primary' => $isPrimary,
                        ]);
                        if ($isPrimary) {
                            DB::table('manuscript_script')->where(['manuscript_id' => $pivot->manuscript_id, 'script_id' => $targetId])->update(['is_primary' => true]);
                        }
                    }
                }
                DB::table('manuscript_script')->where('script_id', $p['script']->id)->delete();
                $p['script']->delete();
            }

            foreach (['is_bika', 'is_bita', 'is_autograph'] as $column) {
                foreach (array_chunk(array_keys($fills[$column]), 500) as $ids) {
                    DB::table('manuscripts')->whereIn('id', $ids)->update([$column => true]);
                }
            }
            foreach ($fills['scribe_name'] as $id => $name) {
                DB::table('manuscripts')->where('id', $id)->update(['scribe_name' => $name]);
            }

            foreach ($fills['script_style'] as $id => $styles) {
                DB::table('manuscripts')->where('id', $id)->update(['script_style' => implode('، ', $styles)]);
            }
            foreach ($affectedIds as $id) {
                DB::table('manuscripts')->where('id', $id)->update(['script_names' => $scriptNames[$id] ?? null, 'updated_at' => now()]);
            }

            DB::statement('UPDATE scripts SET manuscripts_count = (SELECT COUNT(*) FROM manuscript_script WHERE manuscript_script.script_id = scripts.id)');
        });

        $this->info('Saved. Scripts now: ' . Script::count());

        if ($this->option('sync-search')) {
            $this->line('Re-indexing ' . count($affectedIds) . ' manuscripts...');
            foreach (array_chunk($affectedIds, 200) as $ids) {
                (new Manuscript)->makeAllSearchableUsing(Manuscript::whereIn('id', $ids))->get()->searchable();
            }
            $this->info('Re-indexed.');
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

    protected function describeNotes(array $clean): string
    {
        return implode('، ', array_filter([
            $clean['bika'] ? 'بی‌کا' : null,
            $clean['bita'] ? 'بی‌تا' : null,
            $clean['autograph'] ? 'کاتب = مؤلف' : null,
            $clean['scribe'] !== null ? "کاتب: {$clean['scribe']}" : null,
            $clean['styles'] ? 'کیفیت: ' . implode('، ', $clean['styles']) : null,
            $clean['dropped'] ? 'حذف: ' . implode(' / ', $clean['dropped']) : null,
        ]));
    }
}

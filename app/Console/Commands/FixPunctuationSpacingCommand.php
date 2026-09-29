<?php

namespace App\Console\Commands;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Work;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixPunctuationSpacingCommand extends Command
{
    protected $signature = 'fahares:fix-punctuation-spacing {--sync-search : Sync updated records to Meilisearch}';

    protected $description = 'Fix punctuation spacing anomalies (e.g. space before comma) in people, libraries, works, and manuscripts, and merge duplicate entities safely.';

    public function handle(): int
    {
        $this->info('Starting punctuation spacing remediation...');

        $syncSearch = (bool) $this->option('sync-search');

        DB::beginTransaction();

        try {
            // ==========================================
            // 1. DEDUPLICATE & CLEAN PEOPLE
            // ==========================================
            $this->info('--- Auditing and merging People records ---');
            $allPeopleWithSpace = Person::where('name', 'LIKE', '% ،%')
                ->orWhere('name', 'LIKE', '% ,%')
                ->get();

            $mergedPeopleCount = 0;
            $orphansCleanedCount = 0;
            $affectedWorkIds = [];
            $affectedManuscriptIds = [];
            $peopleToReindex = [];
            $peopleUnsearchable = [];

            foreach ($allPeopleWithSpace as $dupPerson) {
                $cleanedName = preg_replace('/\s+([،,])/u', '$1', $dupPerson->name);
                $cleanedName = trim(preg_replace('/\s+/u', ' ', $cleanedName));

                // Find candidate target person
                $targetPerson = Person::where('name', $cleanedName)
                    ->where('id', '!=', $dupPerson->id)
                    ->first();

                if ($targetPerson) {
                    $this->line("Merging Person ID {$dupPerson->id} [{$dupPerson->name}] -> ID {$targetPerson->id} [{$targetPerson->name}]");

                    // 1. Reassign works
                    $workIds = DB::table('works')->where('author_id', $dupPerson->id)->pluck('id')->all();
                    if (!empty($workIds)) {
                        DB::table('works')->whereIn('id', $workIds)->update([
                            'author_id' => $targetPerson->id,
                            'author_name' => $targetPerson->name,
                        ]);
                        $affectedWorkIds = array_merge($affectedWorkIds, $workIds);
                    }

                    // 2. Reassign manuscripts (scribe)
                    $msIds = DB::table('manuscripts')->where('scribe_id', $dupPerson->id)->pluck('id')->all();
                    if (!empty($msIds)) {
                        DB::table('manuscripts')->whereIn('id', $msIds)->update([
                            'scribe_id' => $targetPerson->id,
                            'scribe_name' => $targetPerson->name,
                        ]);
                        $affectedManuscriptIds = array_merge($affectedManuscriptIds, $msIds);
                    }

                    // 3. Recalculate target person stats
                    $targetWorksCount = DB::table('works')->where('author_id', $targetPerson->id)->count();
                    $targetMssCount = DB::table('manuscripts')->where('scribe_id', $targetPerson->id)->count();

                    $targetPerson->works_count = $targetWorksCount;
                    $targetPerson->manuscripts_count = $targetMssCount;
                    $targetPerson->is_author = $targetWorksCount > 0;
                    $targetPerson->is_scribe = $targetMssCount > 0;
                    $targetPerson->save();

                    $peopleToReindex[$targetPerson->id] = $targetPerson;
                    $peopleUnsearchable[] = $dupPerson;

                    // Delete duplicate person
                    $dupPerson->delete();
                    $mergedPeopleCount++;
                } else {
                    // Orphan record: just clean the name
                    $this->line("Cleaning orphan Person ID {$dupPerson->id} [{$dupPerson->name}] -> [{$cleanedName}]");

                    $cleanedNorm = preg_replace('/\s+([،,])/u', '$1', $dupPerson->normalized_name);
                    $cleanedNorm = trim(preg_replace('/\s+/u', ' ', $cleanedNorm));

                    $dupPerson->name = $cleanedName;
                    $dupPerson->normalized_name = $cleanedNorm;
                    $dupPerson->save();

                    // Update works & manuscripts text
                    $workIds = DB::table('works')->where('author_id', $dupPerson->id)->pluck('id')->all();
                    if (!empty($workIds)) {
                        DB::table('works')->whereIn('id', $workIds)->update(['author_name' => $cleanedName]);
                        $affectedWorkIds = array_merge($affectedWorkIds, $workIds);
                    }

                    $msIds = DB::table('manuscripts')->where('scribe_id', $dupPerson->id)->pluck('id')->all();
                    if (!empty($msIds)) {
                        DB::table('manuscripts')->whereIn('id', $msIds)->update(['scribe_name' => $cleanedName]);
                        $affectedManuscriptIds = array_merge($affectedManuscriptIds, $msIds);
                    }

                    $peopleToReindex[$dupPerson->id] = $dupPerson;
                    $orphansCleanedCount++;
                }
            }

            $this->info("People Merged: {$mergedPeopleCount} duplicate records removed.");
            $this->info("People Cleaned: {$orphansCleanedCount} orphan records updated.");

            // ==========================================
            // 2. DEDUPLICATE & CLEAN LIBRARIES
            // ==========================================
            $this->info('--- Auditing and merging Library records ---');
            $allLibsWithSpace = Library::where('name', 'LIKE', '% ،%')
                ->orWhere('name', 'LIKE', '% ,%')
                ->get();

            $mergedLibsCount = 0;
            $orphansLibsCount = 0;

            foreach ($allLibsWithSpace as $dupLib) {
                $cleanedName = preg_replace('/\s+([،,])/u', '$1', $dupLib->name);
                $cleanedName = trim(preg_replace('/\s+/u', ' ', $cleanedName));

                $targetLib = Library::where('city', $dupLib->city)
                    ->where('name', $cleanedName)
                    ->where('id', '!=', $dupLib->id)
                    ->first();

                if ($targetLib) {
                    $this->line("Merging Library ID {$dupLib->id} [{$dupLib->city}: {$dupLib->name}] -> ID {$targetLib->id} [{$targetLib->city}: {$targetLib->name}]");

                    // Reassign manuscripts
                    $msIds = DB::table('manuscripts')->where('library_id', $dupLib->id)->pluck('id')->all();
                    if (!empty($msIds)) {
                        DB::table('manuscripts')->whereIn('id', $msIds)->update([
                            'library_id' => $targetLib->id,
                            'library' => $targetLib->name,
                            'city' => $targetLib->city,
                        ]);
                        $affectedManuscriptIds = array_merge($affectedManuscriptIds, $msIds);
                    }

                    // Recalculate target library manuscripts_count
                    $targetLib->manuscripts_count = DB::table('manuscripts')->where('library_id', $targetLib->id)->count();
                    $targetLib->save();

                    // Delete duplicate library
                    $dupLib->delete();
                    $mergedLibsCount++;
                } else {
                    $this->line("Cleaning orphan Library ID {$dupLib->id} [{$dupLib->name}] -> [{$cleanedName}]");

                    $dupLib->name = $cleanedName;
                    $dupLib->full_name = "کتابخانه {$cleanedName} ({$dupLib->city})";
                    $dupLib->save();

                    $msIds = DB::table('manuscripts')->where('library_id', $dupLib->id)->pluck('id')->all();
                    if (!empty($msIds)) {
                        DB::table('manuscripts')->whereIn('id', $msIds)->update(['library' => $cleanedName]);
                        $affectedManuscriptIds = array_merge($affectedManuscriptIds, $msIds);
                    }

                    $orphansLibsCount++;
                }
            }

            $this->info("Libraries Merged: {$mergedLibsCount} duplicate records removed.");
            $this->info("Libraries Cleaned: {$orphansLibsCount} orphan records updated.");

            // ==========================================
            // 3. CLEAN REMAINING WORKS & MANUSCRIPTS
            // ==========================================
            $this->info('--- Sweeping remaining works.author_name and manuscripts.scribe_name ---');
            $remainingWorks = DB::table('works')
                ->where('author_name', 'LIKE', '% ،%')
                ->orWhere('author_name', 'LIKE', '% ,%')
                ->pluck('id')
                ->all();

            if (!empty($remainingWorks)) {
                DB::statement("UPDATE works SET author_name = REGEXP_REPLACE(author_name, '[[:space:]]+[،,]', '،') WHERE id IN (" . implode(',', $remainingWorks) . ")");
                $affectedWorkIds = array_merge($affectedWorkIds, $remainingWorks);
                $this->info("Updated " . count($remainingWorks) . " additional works with space before comma.");
            }

            $remainingMss = DB::table('manuscripts')
                ->where('scribe_name', 'LIKE', '% ،%')
                ->orWhere('scribe_name', 'LIKE', '% ,%')
                ->pluck('id')
                ->all();

            if (!empty($remainingMss)) {
                DB::statement("UPDATE manuscripts SET scribe_name = REGEXP_REPLACE(scribe_name, '[[:space:]]+[،,]', '،') WHERE id IN (" . implode(',', $remainingMss) . ")");
                $affectedManuscriptIds = array_merge($affectedManuscriptIds, $remainingMss);
                $this->info("Updated " . count($remainingMss) . " additional manuscripts with space before comma.");
            }

            DB::commit();
            $this->info('Database transaction committed successfully!');

            // ==========================================
            // 4. SYNC MEILISEARCH INDEXES
            // ==========================================
            if ($syncSearch) {
                $this->info('--- Syncing changes to Meilisearch ---');

                // 1. Unsearchable deleted people
                foreach ($peopleUnsearchable as $unPerson) {
                    $unPerson->unsearchable();
                }
                $this->info("Unindexed " . count($peopleUnsearchable) . " deleted people from Meilisearch.");

                // 2. Reindex target & updated people
                if (!empty($peopleToReindex)) {
                    Person::whereIn('id', array_keys($peopleToReindex))->searchable();
                    $this->info("Reindexed " . count($peopleToReindex) . " people in Meilisearch.");
                }

                // 3. Reindex affected Works
                $affectedWorkIds = array_unique($affectedWorkIds);
                if (!empty($affectedWorkIds)) {
                    $this->info("Reindexing " . count($affectedWorkIds) . " affected works in Meilisearch...");
                    foreach (array_chunk($affectedWorkIds, 200) as $chunk) {
                        Work::whereIn('id', $chunk)->searchable();
                    }
                }

                // 4. Reindex affected Manuscripts
                $affectedManuscriptIds = array_unique($affectedManuscriptIds);
                if (!empty($affectedManuscriptIds)) {
                    $this->info("Reindexing " . count($affectedManuscriptIds) . " affected manuscripts in Meilisearch...");
                    foreach (array_chunk($affectedManuscriptIds, 500) as $chunk) {
                        Manuscript::whereIn('id', $chunk)->searchable();
                    }
                }
            } else {
                $this->comment('Search index sync skipped. Run with --sync-search to update Meilisearch.');
            }

            $this->info('Punctuation spacing remediation completed successfully!');
            return 0;

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Failed: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        }
    }
}

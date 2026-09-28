<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\Library;
use App\Models\Manuscript;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $cleanCity = function ($city, $libName = '') {
            $c = $city ?? '';
            if (str_contains($c, 'دعیه')) return 'قائن';
            if ($c === 'آباد' && str_contains($libName, 'حججی')) return 'نجف آباد';
            if ($c === 'ان') return 'تهران';
            if ($c === 'هران' || $c === 'تتهران' || $c === 'تهرانتهران' || str_contains($c, 'It is')) return 'تهران';
            if ($c === 'شهد') return 'مشهد';
            if (str_contains($c, 'دا5غان') || str_contains($c, 'د15امغان') || str_contains($c, '15دامغان')) return 'دامغان';
            
            // Regex strip leading numbering and punctuation
            $c = preg_replace('/^[0-9\x{06F0}-\x{06F9}\.\-\s•\`\(\)\[\]\/\:\،\,\؟\«\»\x{060C}\x{061B}]+/u', '', $c);
            $c = preg_replace('/[0-9\x{06F0}-\x{06F9}\.\-\s•\`\(\)\[\]\/\:\،\,\؟\«\»\x{060C}\x{061B}]+$/u', '', $c);
            $c = str_replace(['ي', 'ك'], ['ی', 'ک'], $c);
            return trim($c);
        };

        $cleanLibName = function ($name) {
            $n = str_replace(['ي', 'ك'], ['ی', 'ک'], $name ?? '');
            return trim($n);
        };

        $resolveCountry = function ($city) {
            return match ($city) {
                'اسکندریه' => 'مصر',
                'فرانکفورت' => 'آلمان',
                'سلیمانیه' => 'عراق',
                'کابل' => 'افغانستان',
                default => 'ایران',
            };
        };

        $allLibraries = Library::all();
        $groups = [];

        foreach ($allLibraries as $lib) {
            $cCity = $cleanCity($lib->city, $lib->name);
            $cName = $cleanLibName($lib->name);
            $key = $cCity . '||' . $cName;
            $groups[$key][] = $lib;
        }

        $allUpdatedManuscriptIds = [];

        DB::beginTransaction();

        try {
            foreach ($groups as $key => $members) {
                // Find canonical record: the one with lowest id among those with highest manuscripts_count
                usort($members, function ($a, $b) {
                    if ($a->manuscripts_count !== $b->manuscripts_count) {
                        return $b->manuscripts_count <=> $a->manuscripts_count;
                    }
                    return $a->id <=> $b->id;
                });

                $canonical = $members[0];
                [$cCity, $cName] = explode('||', $key, 2);

                // Update canonical record
                DB::table('libraries')->where('id', $canonical->id)->update([
                    'city' => $cCity,
                    'name' => $cName,
                    'full_name' => "کتابخانه {$cName} ({$cCity})",
                    'country' => $resolveCountry($cCity),
                    'updated_at' => now(),
                ]);

                // Merge duplicates if any
                $duplicateIds = [];
                for ($i = 1; $i < count($members); $i++) {
                    $duplicateIds[] = $members[$i]->id;
                }

                if (!empty($duplicateIds)) {
                    // Find manuscripts attached to duplicates
                    $msIds = DB::table('manuscripts')
                        ->whereIn('library_id', $duplicateIds)
                        ->pluck('id')
                        ->toArray();

                    if (!empty($msIds)) {
                        DB::table('manuscripts')->whereIn('id', $msIds)->update([
                            'library_id' => $canonical->id,
                            'city' => $cCity,
                            'library' => $cName,
                        ]);
                        $allUpdatedManuscriptIds = array_merge($allUpdatedManuscriptIds, $msIds);
                    }

                    // Delete duplicate library records
                    DB::table('libraries')->whereIn('id', $duplicateIds)->delete();
                }
            }

            // Also clean up any manuscripts with dirty city directly
            $dirtyManuscripts = DB::table('manuscripts')
                ->where('city', 'REGEXP', '^[0-9\.\-\s•\`\(\)\[\]\/\:\،\,\؟]')
                ->orWhereIn('city', ['هران', 'شهد', 'آباد', 'ان', 'دا5غان', 'د15امغان', '15دامغان', 'تهرانتهران'])
                ->orWhere('city', 'like', '%دعیه%')
                ->orWhere('city', 'like', '%It is%')
                ->get(['id', 'city', 'library', 'library_id']);

            foreach ($dirtyManuscripts as $dm) {
                $cCity = $cleanCity($dm->city, $dm->library);
                $cLib = $cleanLibName($dm->library);
                DB::table('manuscripts')->where('id', $dm->id)->update([
                    'city' => $cCity,
                    'library' => $cLib,
                ]);
                $allUpdatedManuscriptIds[] = $dm->id;
            }

            // Recalculate manuscripts_count on all remaining libraries
            DB::statement('
                UPDATE libraries l 
                SET manuscripts_count = (SELECT COUNT(*) FROM manuscripts m WHERE m.library_id = l.id)
            ');

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        // Sync updated manuscripts with Meilisearch in chunks
        $uniqueUpdatedIds = array_unique($allUpdatedManuscriptIds);
        if (!empty($uniqueUpdatedIds)) {
            foreach (array_chunk($uniqueUpdatedIds, 500) as $chunk) {
                Manuscript::whereIn('id', $chunk)->searchable();
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Consolidation is irreversible without re-seeding from raw corpus.
    }
};

<?php

namespace Tests\Feature;

use App\Models\Cataloger;
use App\Models\Manuscript;
use App\Models\SourceEntry;
use App\Models\Work;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Step 14.403 (backlog item 170): a copy's cataloger goes onto its citation entry after the deploy, once. */
class FillCatalogueEntriesTest extends TestCase
{
    public function test_the_copies_catalogers_fill_their_citation_entries_and_a_second_run_writes_nothing()
    {
        $work = Work::create(['primary_title' => 'اثر آزمون', 'clean_title' => 'اثر آزمون', 'slug' => 'fill-cat-' . uniqid()]);
        $copy = Manuscript::create(['work_id' => $work->id, 'shelfmark' => '7', 'sequence_number' => 1]);
        $cataloger = Cataloger::create(['name' => 'فهرست‌نگار آزمون', 'slug' => 'fill-cat-' . uniqid()]);
        $entry = SourceEntry::create(['citable_type' => Manuscript::class, 'citable_id' => $copy->id, 'origin' => SourceEntry::ORIGIN_FANKHA_CITATION,
            'citation_text' => '[ف: 2-414]', 'legacy_type' => 'fankha_citation', 'legacy_id' => $copy->id]);
        // as the first deploy left production: the copy has its cataloger, the entry's column is empty
        DB::table('manuscripts')->where('id', $copy->id)->update(['cataloger_id' => $cataloger->id]);

        $this->artisan('sources:fill-catalogue-entries', ['--dry-run' => true])->expectsOutputToContain('differ from their copy: 1')->assertSuccessful();
        $this->assertNull($entry->fresh()->cataloger_id);
        $this->artisan('sources:fill-catalogue-entries')->expectsOutputToContain('still differing: 0')->assertSuccessful();
        $this->assertSame($cataloger->id, $entry->fresh()->cataloger_id);
        $this->artisan('sources:fill-catalogue-entries')->expectsOutputToContain('Written: 0')->assertSuccessful();
    }
}

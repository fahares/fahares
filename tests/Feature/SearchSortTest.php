<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Work;
use Tests\TestCase;

/** The order and page size of search results (backlog item 83, phase 3) */
class SearchSortTest extends TestCase
{
    public function test_browsing_is_sorted_by_the_chosen_order_with_undated_last(): void
    {
        $library = Library::create(['name' => 'کتابخانه‌ی ترتیب', 'city' => 'قم', 'slug' => 'sort-lib-' . uniqid()]);
        $work = Work::create(['primary_title' => 'اثر ترتیب', 'clean_title' => 'اثر ترتیب', 'slug' => 'sort-work-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        foreach (['S-900' => 900, 'S-none' => null, 'S-500' => 500] as $shelfmark => $year) {
            Manuscript::create(['work_id' => $work->id, 'library_id' => $library->id, 'shelfmark' => $shelfmark, 'copy_date_hijri_year' => $year,
                'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1, 'raw_text' => 'نسخه']);
        }
        $url = fn ($sort) => route('search', ['type' => 'manuscripts', 'library_id' => $library->id, 'sort' => $sort]);

        $this->get($url('oldest'))->assertOk()->assertSeeInOrder(['S-500', 'S-900', 'S-none'])->assertSee('<option value="oldest" selected', false);
        $this->get($url('newest'))->assertOk()->assertSeeInOrder(['S-900', 'S-500', 'S-none']);
        // An unknown order is ignored
        $this->get($url('bogus'))->assertOk()->assertSee('S-900');
    }
}

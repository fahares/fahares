<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Script;
use App\Models\Work;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The filters of the list of copies on a work page (backlog item 79, step 14.99) */
class WorkCopyFiltersTest extends TestCase
{
    use DatabaseTransactions;

    private Work $work;

    private Library $majlis;

    private Library $malek;

    private Script $naskh;

    protected function setUp(): void
    {
        parent::setUp();

        $this->work = Work::create(['primary_title' => 'اثر آزمایشی فیلتر نسخه‌ها', 'clean_title' => 'اثر آزمایشی فیلتر نسخه‌ها',
            'slug' => 'copy-filters-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $this->majlis = Library::create(['name' => 'مجلس آزمایشی', 'city' => 'تهران', 'slug' => 'majlis-' . uniqid()]);
        $this->malek = Library::create(['name' => 'ملک آزمایشی', 'city' => 'تهران', 'slug' => 'malek-' . uniqid()]);
        $razavi = Library::create(['name' => 'رضوی آزمایشی', 'city' => 'مشهد', 'slug' => 'razavi-' . uniqid()]);
        $this->naskh = Script::create(['name' => 'نسخ آزمایشی', 'slug' => 'naskh-' . uniqid()]);

        // 8 in مجلس (5 of them dated 1050, 2 with a scribe, 1 autograph), 4 in ملک (dated «قرن 12», 2 photographs),
        // 2 in رضوی (undated); the script on the first 3
        $rows = [
            ...array_fill(0, 5, [$this->majlis, '1050ق', null, false, false]),
            [$this->majlis, null, 'کاتب یکم', false, false],
            [$this->majlis, null, 'کاتب دوم', false, false],
            [$this->majlis, null, null, true, false],
            [$this->malek, 'قرن 12', null, false, true],
            [$this->malek, 'قرن 12', null, false, true],
            [$this->malek, 'قرن 12', null, false, false],
            [$this->malek, 'قرن 12', null, false, false],
            [$razavi, null, null, false, false],
            [$razavi, null, null, false, false],
        ];
        foreach ($rows as $i => [$library, $date, $scribe, $autograph, $facsimile]) {
            $ms = Manuscript::create([
                'work_id' => $this->work->id, 'sequence_number' => $i + 1, 'volume_number' => 1, 'page_start' => 1,
                'library_id' => $library->id, 'library' => $library->name, 'city' => $library->city,
                'shelfmark' => 'ش' . \App\Support\Fa::digits($i + 1) . 'ش', 'copy_date_raw' => $date, 'scribe_name' => $scribe,
                'is_autograph' => $autograph, 'is_facsimile' => $facsimile,
            ]);
            if ($i < 3) {
                $ms->scripts()->attach($this->naskh->id);
            }
        }
    }

    private function page(array $query = [])
    {
        return $this->get(route('works.show', ['id' => $this->work->getRouteKey(), ...$query]));
    }

    public function test_the_filters_offer_only_this_works_values_with_their_counts()
    {
        $this->page()->assertOk()
            ->assertSee('data-copy-filters', false)
            ->assertSee('مجلس آزمایشی (۸)')
            ->assertSee('ملک آزمایشی (۴)')
            ->assertSee('رضوی آزمایشی (۲)')
            ->assertSee('تهران (۱۲)')
            ->assertSee('سده‌ی ۱۱ (۵)')
            ->assertSee('سده‌ی ۱۲ (۴)')
            ->assertSee('نسخ آزمایشی (۳)')
            ->assertSee('فقط دستخط مؤلف (۱)')
            ->assertSee('تاریخ‌دار (۹)')
            ->assertSee('بی‌تاریخ (۵)')
            ->assertSee('کاتب‌دار (۳)')
            ->assertSee('عکسی یا میکروفیلم (۲)')
            ->assertDontSee('حذف فیلترها');
    }

    public function test_a_library_filter_narrows_the_list_and_the_other_counts()
    {
        $this->page(['library' => $this->malek->id])->assertOk()
            ->assertSee('ش۹ش')->assertDontSee('ش۵ش')->assertDontSee('ش۱۳ش')
            ->assertSee('<span class="font-bold text-ink">۴</span> از ۱۴ نسخه', false)
            // the library's own options still count under the other filters only
            ->assertSee('مجلس آزمایشی (۸)')
            ->assertSee('عکسی یا میکروفیلم (۲)')
            ->assertSee('اصل (۲)')
            ->assertSee('حذف فیلترها');
    }

    public function test_filters_combine_with_each_other_and_the_sort()
    {
        $response = $this->page(['century' => 11, 'script' => $this->naskh->id, 'sort' => 'shelfmark', 'direction' => 'desc'])->assertOk()
            ->assertSee('<span class="font-bold text-ink">۳</span> از ۱۴ نسخه', false)
            ->assertSeeInOrder(['ش۳ش', 'ش۲ش', 'ش۱ش'])
            ->assertSee('name="sort" value="shelfmark"', false);

        $this->page(['dated' => '0', 'scribe' => '1'])->assertOk()
            ->assertSee('<span class="font-bold text-ink">۳</span> از ۱۴ نسخه', false)
            ->assertSee('ش۶ش')->assertSee('ش۷ش')->assertSee('ش۸ش')->assertDontSee('ش۱۳ش');

        $this->page(['facsimile' => '0', 'city' => 'تهران'])->assertOk()
            ->assertSee('<span class="font-bold text-ink">۱۰</span> از ۱۴ نسخه', false);

        $this->page(['autograph' => '1'])->assertOk()->assertSee('ش۸ش')->assertDontSee('ش۷ش');
    }

    public function test_a_filter_with_no_copies_says_so_and_bad_values_are_ignored()
    {
        $this->page(['library' => $this->malek->id, 'century' => 11])->assertOk()
            ->assertSee('هیچ نسخه‌ای با این فیلترها نیست.')
            ->assertSee('سده‌ی ۱۱ (۰)');

        $this->page(['library' => 'x', 'century' => '99', 'dated' => 'maybe'])->assertOk()
            ->assertDontSee('حذف فیلترها')
            ->assertSee('ش۱۴ش');
    }

    public function test_pagination_keeps_the_filters()
    {
        foreach (range(15, 45) as $i) {
            Manuscript::create(['work_id' => $this->work->id, 'sequence_number' => $i, 'volume_number' => 1, 'page_start' => 1,
                'library_id' => $this->majlis->id, 'library' => $this->majlis->name, 'city' => 'تهران', 'shelfmark' => 'ش' . \App\Support\Fa::digits($i) . 'ش']);
        }

        $this->page(['library' => $this->majlis->id])->assertOk()
            ->assertSee('library=' . $this->majlis->id . '&amp;page=2', false);
    }

    public function test_a_work_with_few_copies_shows_no_filters_and_linked_photographs_are_not_counted()
    {
        $small = Work::create(['primary_title' => 'اثر کم‌نسخه', 'clean_title' => 'اثر کم‌نسخه', 'slug' => 'few-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        Manuscript::create(['work_id' => $small->id, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1, 'shelfmark' => 'تک']);
        $this->get(route('works.show', $small))->assertOk()->assertDontSee('data-copy-filters', false);

        // a photograph shown under its copy (step 14.16) stays out of the list and its counts
        $copies = Manuscript::where('work_id', $this->work->id)->orderBy('sequence_number')->pluck('id');
        DB::table('manuscript_relations')->insert(['manuscript_id' => $copies[8], 'related_manuscript_id' => $copies[0], 'type' => 'facsimile_of',
            'created_at' => now(), 'updated_at' => now()]);
        $this->page(['library' => $this->malek->id])->assertOk()
            ->assertSee('<span class="font-bold text-ink">۳</span> از ۱۳ نسخه', false)
            ->assertSee('ملک آزمایشی (۳)');
    }
}

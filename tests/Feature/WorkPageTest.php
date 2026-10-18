<?php

namespace Tests\Feature;

use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Work;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class WorkPageTest extends TestCase
{
    use DatabaseTransactions;

    public function test_life_span_prefers_exact_years_then_century()
    {
        $this->assertSame('۸۱۷–۸۹۸ ق', (new Person(['birth_year_hijri' => 817, 'death_year_hijri' => 898]))->life_span);
        $this->assertSame('د. ۸۹۸ ق', (new Person(['death_year_hijri' => 898]))->life_span);
        $this->assertSame('سده‌ی ۱۰ ق', (new Person(['century_hijri' => 10]))->life_span);
        $this->assertNull((new Person())->life_span);
    }

    public function test_work_page_shows_author_dates_and_the_earliest_plausible_copy()
    {
        $author = Person::create(['name' => 'مؤلف آزمایشی صفحه‌ی اثر', 'normalized_name' => 'مولف ازمایشی صفحه اثر', 'slug' => 'test-' . uniqid(), 'birth_year_hijri' => 817, 'death_year_hijri' => 898]);
        $work = Work::create([
            'primary_title' => 'اثر آزمایشی صفحه‌ی اثر',
            'clean_title' => 'اثر آزمایشی صفحه‌ی اثر',
            'slug' => 'work-page-' . uniqid(),
            'author_id' => $author->id,
            'composition_year_hijri' => 897,
            'volume_number' => 24,
            'page_start' => 439,
        ]);
        foreach ([[1, 'شماره‌ی تاریخ نادرست', 450], [2, 'شماره‌ی کهن‌ترین', 899], [3, 'شماره‌ی متأخر', 1100]] as [$seq, $shelf, $year]) {
            Manuscript::create([
                'work_id' => $work->id, 'sequence_number' => $seq, 'volume_number' => 24, 'page_start' => 439,
                'shelfmark' => $shelf, 'copy_date_hijri_year' => $year, 'copy_date_raw' => "{$year}ق",
            ]);
        }

        $this->assertSame('شماره‌ی کهن‌ترین', $work->earliestDatedManuscript()->shelfmark);

        $this->get(route('works.show', $work))
            ->assertOk()
            ->assertSee('(۸۱۷–۸۹۸ ق)', false)
            ->assertSeeInOrder(['کهن‌ترین نسخه‌ی تاریخ‌دار', 'شماره‌ی کهن‌ترین', '۸۹۹ق'])
            ->assertSee('ج ۲۴، ص ۴۳۹');
    }
}

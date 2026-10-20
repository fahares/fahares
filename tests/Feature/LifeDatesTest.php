<?php

namespace Tests\Feature;

use App\Models\Manuscript;
use App\Models\ManuscriptPerson;
use App\Models\Person;
use App\Models\Work;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Backlog item 9: a person's life dates appear after the name wherever the name is shown,
 * and a copy's own date is never passed off as its scribe's dates.
 */
class LifeDatesTest extends TestCase
{
    use DatabaseTransactions;

    protected function person(string $name, array $dates = []): Person
    {
        return Person::create(['name' => $name, 'normalized_name' => $name, 'slug' => 'life-' . uniqid()] + $dates);
    }

    protected function copy(Work $work, ?Person $scribe, int $copyYear, ?string $scribeName = null): Manuscript
    {
        return Manuscript::create([
            'work_id' => $work->id, 'sequence_number' => 1, 'volume_number' => 3, 'page_start' => 12,
            'shelfmark' => 'ش-' . uniqid(),
            'scribe_id' => $scribe?->id, 'scribe_name' => $scribeName ?? $scribe?->name,
            'copy_date_hijri_year' => $copyYear, 'copy_date_raw' => "{$copyYear}ق",
        ]);
    }

    protected function work(?Person $author, string $title = 'اثر آزمایشی تاریخ حیات'): Work
    {
        return Work::create([
            'primary_title' => $title, 'clean_title' => $title, 'slug' => 'life-work-' . uniqid(),
            'author_id' => $author?->id, 'author_name' => $author?->name, 'volume_number' => 3, 'page_start' => 12,
        ]);
    }

    public function test_life_span_formats_and_shared_callers_agree()
    {
        $this->assertSame('ز. ۸۱۷ ق', (new Person(['birth_year_hijri' => 817]))->life_span);
        $this->assertSame('سده‌ی ۷ ق', (new Person(['century_hijri' => 7]))->life_span);
        $this->assertSame('د. ۶۷۲ ق', (new Person(['death_year_hijri' => 672, 'century_hijri' => 7]))->life_span);

        $p = new Person(['birth_year_hijri' => 597, 'death_year_hijri' => 672]);
        $this->assertSame($p->life_span, \App\Http\Controllers\SuggestionController::lifeYears($p));
    }

    public function test_manuscript_page_shows_scribe_dates_but_not_the_copy_date_as_dates()
    {
        $author = $this->person('مؤلف بی‌تاریخ آزمایشی');
        $dated = $this->person('کاتب تاریخ‌دار آزمایشی', ['death_year_hijri' => 1050]);
        $undated = $this->person('کاتب بی‌تاریخ آزمایشی');
        $work = $this->work($author);

        $this->get(route('manuscripts.show', $this->copy($work, $dated, 1040)))
            ->assertOk()
            ->assertSeeInOrder(['کاتب تاریخ‌دار آزمایشی', '(د. ۱۰۵۰ ق)'], false);

        $this->get(route('manuscripts.show', $this->copy($work, $undated, 1040)))
            ->assertOk()
            ->assertSee('کاتب بی‌تاریخ آزمایشی')
            ->assertDontSee('(۱۰۴۰', false)
            ->assertDontSee('(د. ۱۰۴۰ ق)', false);
    }

    public function test_related_people_of_a_copy_show_their_dates()
    {
        $owner = $this->person('مالک آزمایشی', ['death_year_hijri' => 1200]);
        $ms = $this->copy($this->work(null), null, 1100, 'کاتبی بی‌رکورد');
        ManuscriptPerson::create(['manuscript_id' => $ms->id, 'person_id' => $owner->id, 'role' => array_key_first(ManuscriptPerson::ROLES)]);

        $this->get(route('manuscripts.show', $ms))
            ->assertOk()
            ->assertSee('کاتبی بی‌رکورد')
            ->assertSeeInOrder(['مالک آزمایشی', '(د. ۱۲۰۰ ق)'], false);
    }

    public function test_work_copies_list_person_page_and_search_show_dates()
    {
        $author = $this->person('مؤلف تاریخ‌دار آزمایشی', ['birth_year_hijri' => 597, 'death_year_hijri' => 672]);
        $scribe = $this->person('کاتب سده‌دار آزمایشی', ['century_hijri' => 11]);
        $work = $this->work($author, 'عنوان یکتای آزمون تاریخ حیات');
        $this->copy($work, $scribe, 1060);

        $this->get(route('works.show', $work))
            ->assertOk()
            ->assertSeeInOrder(['کاتب سده‌دار آزمایشی', '(سده‌ی ۱۱ ق)'], false);

        $this->get(route('people.show', $scribe))
            ->assertOk()
            ->assertSeeInOrder(['مؤلف تاریخ‌دار آزمایشی', '(۵۹۷–۶۷۲ ق)'], false);

        $this->get(route('search', ['type' => 'manuscripts', 'century' => 11]))
            ->assertOk()
            ->assertSeeInOrder(['مؤلف تاریخ‌دار آزمایشی', '(۵۹۷–۶۷۲ ق)', 'کاتب سده‌دار آزمایشی', '(سده‌ی ۱۱ ق)'], false);

        $this->getJson(route('search.api', ['type' => 'people', 'q' => 'مؤلف تاریخ‌دار آزمایشی']))
            ->assertOk()
            ->assertJsonFragment(['death_hijri' => '۵۹۷–۶۷۲ ق']);
    }
}

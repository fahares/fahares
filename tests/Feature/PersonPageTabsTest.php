<?php

namespace Tests\Feature;

use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Work;
use Tests\TestCase;

class PersonPageTabsTest extends TestCase
{
    protected array $workIds = [];
    protected array $peopleIds = [];

    protected function tearDown(): void
    {
        Manuscript::whereIn('work_id', $this->workIds)->delete();
        Work::whereIn('id', $this->workIds)->delete();
        Person::whereIn('id', $this->peopleIds)->delete();

        parent::tearDown();
    }

    protected function person(string $name): Person
    {
        $p = Person::create(['name' => $name, 'normalized_name' => $name, 'slug' => 'test-tabs-' . uniqid()]);
        $this->peopleIds[] = $p->id;

        return $p;
    }

    protected function work(?Person $author = null): Work
    {
        $work = Work::create([
            'primary_title' => 'اثر آزمایشی تب‌ها ' . uniqid(),
            'clean_title' => 'اثر آزمایشی تب‌ها',
            'slug' => 'test-tabs-work-' . uniqid(),
            'author_id' => $author?->id,
            'author_name' => $author?->name,
            'volume_number' => 1,
            'page_start' => 1,
        ]);
        $this->workIds[] = $work->id;

        return $work;
    }

    protected function scribe(Person $scribe): Manuscript
    {
        return Manuscript::create([
            'work_id' => $this->work()->id,
            'sequence_number' => 1,
            'volume_number' => 1,
            'page_start' => 1,
            'scribe_id' => $scribe->id,
            'scribe_name' => $scribe->name,
            'raw_text' => 'نسخه‌ی آزمایشی',
        ]);
    }

    public function test_scribe_only_person_opens_on_scribed_without_tab_bar()
    {
        $p = $this->person('کاتب آزمایشی تب‌ها');
        $this->scribe($p);

        $this->get(route('people.show', $p))
            ->assertOk()
            ->assertSee("activeTab: 'scribed'", false)
            ->assertDontSee('فهرست آثار و تألیفات')
            ->assertDontSee('@click="activeTab = \'authored\'"', false)
            ->assertSee('نسخه‌های خطی کتابت‌شده توسط این شخص');
    }

    public function test_author_and_scribe_shows_both_tabs()
    {
        $p = $this->person('مؤلف و کاتب آزمایشی تب‌ها');
        $this->work($p);
        $this->scribe($p);

        $this->get(route('people.show', $p))
            ->assertOk()
            ->assertSee("activeTab: 'authored'", false)
            ->assertSee('@click="activeTab = \'scribed\'"', false)
            ->assertSee('فهرست آثار و تألیفات');

        $this->get(route('people.show', $p) . '?manuscripts_page=1')
            ->assertSee("activeTab: 'scribed'", false);
    }

    public function test_person_without_works_or_manuscripts_gets_a_message()
    {
        $p = $this->person('شخص بی‌اثر آزمایشی تب‌ها');

        $this->get(route('people.show', $p))
            ->assertOk()
            ->assertSee('اثر یا نسخه‌ی کتابت‌شده‌ای برای این شخص ثبت نشده است.')
            ->assertDontSee('فهرست آثار و تألیفات')
            ->assertDontSee('نسخه‌های خطی کتابت‌شده توسط این شخص');
    }
}

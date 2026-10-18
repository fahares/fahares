<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Work;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LibraryNamesTest extends TestCase
{
    use DatabaseTransactions;

    private function library(?string $fullName = 'کتابخانه‌ی آزمایشی نام کامل'): Library
    {
        return Library::create(['name' => 'آزمایشی' . uniqid(), 'full_name' => $fullName, 'city' => 'شهر آزمایشی', 'slug' => 'lib-names-' . uniqid()]);
    }

    public function test_display_name_falls_back_to_the_short_name()
    {
        $library = $this->library();
        $this->assertSame('کتابخانه‌ی آزمایشی نام کامل', $library->display_name);
        $this->assertSame($library->name, $library->short_name);

        $bare = $this->library(null);
        $this->assertSame($bare->name, $bare->display_name);
    }

    public function test_introductions_use_the_full_name_and_compact_lists_the_short_name()
    {
        $library = $this->library();
        $work = Work::create(['primary_title' => 'اثر آزمایشی نام کتابخانه', 'clean_title' => 'اثر آزمایشی نام کتابخانه', 'slug' => 'work-lib-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $manuscript = Manuscript::create(['work_id' => $work->id, 'library_id' => $library->id, 'library' => $library->name, 'city' => $library->city, 'shelfmark' => '۱۲۳', 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1]);

        $this->get(route('libraries.show', $library))->assertOk()
            ->assertSee('<title>کتابخانه‌ی آزمایشی نام کامل', false)
            ->assertSeeInOrder(['<h1', 'کتابخانه‌ی آزمایشی نام کامل', '</h1>', $library->name], false);

        $this->get(route('manuscripts.show', $manuscript))->assertOk()
            ->assertSee('"name": "کتابخانه‌ی آزمایشی نام کامل"', false)
            ->assertSee('نسخه خطی کتابخانه‌ی آزمایشی نام کامل، شماره بازیابی', false);

        $this->get(route('works.show', $work))->assertOk()
            ->assertSee('title="کتابخانه‌ی آزمایشی نام کامل"', false)
            ->assertSee('>' . $library->name . '</a>', false);
    }

    public function test_manuscripts_index_carries_the_full_name()
    {
        $library = $this->library();
        $work = Work::create(['primary_title' => 'اثر آزمایشی نمایه', 'clean_title' => 'اثر آزمایشی نمایه', 'slug' => 'work-idx-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $manuscript = Manuscript::create(['work_id' => $work->id, 'library_id' => $library->id, 'library' => $library->name, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1]);

        $this->assertSame('کتابخانه‌ی آزمایشی نام کامل', $manuscript->toSearchableArray()['library_full_name']);
    }

    public function test_command_matches_by_slug_then_by_name_and_city()
    {
        $bySlug = $this->library('نام کهنه');
        $byName = $this->library('نام کهنه');
        $file = 'storage/framework/testing-library-names.json';
        file_put_contents(base_path($file), json_encode([
            ['slug' => $bySlug->slug, 'name' => 'دیگر', 'city' => 'دیگر', 'full_name' => 'نام تازه‌ی یکم', 'source' => 'fankha'],
            ['slug' => 'no-such-slug', 'name' => $byName->name, 'city' => $byName->city, 'full_name' => 'نام تازه‌ی دوم', 'source' => 'auto'],
        ], JSON_UNESCAPED_UNICODE));

        try {
            $this->artisan('fahares:apply-library-full-names', ['--file' => $file, '--dry-run' => true])->assertSuccessful();
            $this->assertSame('نام کهنه', $bySlug->fresh()->full_name);

            $this->artisan('fahares:apply-library-full-names', ['--file' => $file, '--no-reindex' => true])->assertSuccessful();
            $this->assertSame('نام تازه‌ی یکم', $bySlug->fresh()->full_name);
            $this->assertSame('نام تازه‌ی دوم', $byName->fresh()->full_name);
        } finally {
            @unlink(base_path($file));
        }
    }
}

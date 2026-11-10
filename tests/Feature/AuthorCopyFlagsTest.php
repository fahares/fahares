<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Work;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** Step 14.130: copies written from, collated with, or corrected by the author's own copy */
class AuthorCopyFlagsTest extends TestCase
{
    use DatabaseTransactions;

    private Work $work;

    /** @var Manuscript[] */
    private array $copies = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = Work::create(['primary_title' => 'اثر آزمایشی نسخه‌ی مؤلف', 'clean_title' => 'اثر آزمایشی نسخه‌ی مؤلف',
            'slug' => 'author-copy-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $library = Library::create(['name' => 'کتابخانه‌ی آزمایشی', 'city' => 'قم', 'slug' => 'lib-' . uniqid()]);
        // eleven copies, more than WorkCopyFilters::MIN_COPIES, so the work page offers its filters
        foreach (range(1, 11) as $i) {
            $this->copies[] = Manuscript::create(['work_id' => $this->work->id, 'sequence_number' => $i, 'volume_number' => 1,
                'page_start' => 1, 'library_id' => $library->id, 'library' => $library->name, 'city' => $library->city,
                'shelfmark' => 'ن' . \App\Support\Fa::digits($i) . 'ن', 'raw_text' => "{$i}. قم؛ کتابخانه‌ی آزمایشی؛ شماره نسخه: {$i}\nخط: نسخ، بی‌کا، بی‌تا؛ از روی نسخه مؤلف"]);
        }
    }

    public function test_rebuild_copies_writes_the_flags_and_the_pages_show_them()
    {
        $file = tempnam(sys_get_temp_dir(), 'author-copy');
        $records = [];
        foreach ([[0, 'copied_from_author_copy'], [1, 'collated_with_author_copy'], [2, 'corrected_by_author']] as [$i, $flag]) {
            $records[] = ['table' => 'manuscripts', 'id' => $this->copies[$i]->id, 'shelfmark' => $this->copies[$i]->shelfmark,
                'category' => 'author_copy', 'v2' => null, 'changes' => [$flag => ['from' => 0, 'to' => 1]]];
        }
        file_put_contents($file, json_encode(['records' => $records], JSON_UNESCAPED_UNICODE));

        $this->artisan('fahares:rebuild-copies', ['--file' => $file, '--apply' => true])
            ->expectsOutputToContain('Rebuilding 3 copies')->assertSuccessful();
        $this->artisan('fahares:rebuild-copies', ['--file' => $file])->expectsOutputToContain('Would rebuild 0 copies')->assertSuccessful();
        @unlink($file);

        $this->assertTrue($this->copies[0]->fresh()->copied_from_author_copy);
        $this->assertTrue($this->copies[1]->fresh()->collated_with_author_copy);
        $this->assertTrue($this->copies[2]->fresh()->corrected_by_author);
        $this->assertFalse($this->copies[3]->fresh()->copied_from_author_copy);

        $this->get(route('manuscripts.show', $this->copies[2]))->assertOk()->assertSee('مقابله یا تصحیح به دست مؤلف');
        $this->get(route('manuscripts.show', $this->copies[0]))->assertOk()->assertSee('کتابت از روی نسخه‌ی مؤلف');

        // the work page filter keeps the three copies near the author's own
        $route = fn (array $q = []) => route('works.show', ['id' => $this->work->getRouteKey(), ...$q]);
        $this->get($route())->assertOk()->assertSee('از روی نسخه‌ی مؤلف یا مقابله با آن (۳)');
        $this->get($route(['author_copy' => '1']))->assertOk()
            ->assertSee('ن۱ن')->assertSee('ن۳ن')->assertDontSee('ن۵ن');
    }

    public function test_the_flags_are_in_the_search_document()
    {
        $this->copies[1]->update(['collated_with_author_copy' => true]);
        $doc = $this->copies[1]->fresh()->toSearchableArray();
        $this->assertTrue($doc['collated_with_author_copy']);
        $this->assertFalse($doc['corrected_by_author']);
        $this->assertArrayHasKey('collated_with_author_copy', \App\Support\SearchFilters::FLAGS);
    }
}

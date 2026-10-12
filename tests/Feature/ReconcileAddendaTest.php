<?php

namespace Tests\Feature;

use App\Models\Catalog;
use App\Models\EntityRedirect;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\SourceCitation;
use App\Models\Work;
use Tests\TestCase;

class ReconcileAddendaTest extends TestCase
{
    protected array $works = [];
    protected array $people = [];
    protected string $file;

    protected function setUp(): void
    {
        parent::setUp();

        Catalog::firstOrCreate(['code' => 'fankha'], ['name' => 'فهرستگان نسخه‌های خطی ایران', 'short_name' => 'فنخا']);
        $this->file = tempnam(sys_get_temp_dir(), 'addenda') . '.json';
    }

    protected function tearDown(): void
    {
        $ids = array_map(fn($w) => $w->id, $this->works);
        SourceCitation::where('citable_type', Work::class)->whereIn('citable_id', $ids)->delete();
        $msIds = Manuscript::whereIn('work_id', $ids)->pluck('id');
        SourceCitation::where('citable_type', Manuscript::class)->whereIn('citable_id', $msIds)->delete();
        EntityRedirect::whereIn('entity_type', ['works', 'manuscripts'])->whereIn('target_id', array_merge($ids, $msIds->all()))->delete();
        Work::whereIn('id', $ids)->delete();
        Person::whereIn('id', array_map(fn($p) => $p->id, $this->people))->delete();
        @unlink($this->file);

        parent::tearDown();
    }

    protected function work(string $title, int $volume, int $page, ?Person $author = null, string $raw = ''): Work
    {
        return $this->works[] = Work::create([
            'catalog_id' => Catalog::where('code', 'fankha')->value('id'),
            'primary_title' => $title,
            'clean_title' => $title,
            'slug' => 'test-addenda-' . uniqid(),
            'author_id' => $author?->id,
            'author_name' => $author?->name,
            'volume_number' => $volume,
            'page_start' => $page,
            'raw_text' => $raw ?: "● {$title}",
        ]);
    }

    protected function manuscript(Work $work, string $shelfmark, int $seq = 1): Manuscript
    {
        return Manuscript::create([
            'work_id' => $work->id,
            'catalog_id' => $work->catalog_id,
            'sequence_number' => $seq,
            'volume_number' => $work->volume_number,
            'page_start' => $work->page_start,
            'city' => 'تهران',
            'library' => 'مجلس',
            'shelfmark' => $shelfmark,
            'raw_text' => "تهران؛ مجلس؛ شماره نسخه: {$shelfmark}",
        ]);
    }

    protected function ref(Work $w): array
    {
        return ['work_id' => $w->id, 'title' => $w->primary_title];
    }

    public function test_addenda_are_merged_with_citations_redirects_and_errata()
    {
        $oldAuthor = $this->people[] = Person::create(['name' => 'مؤلف نادرست آزمایشی', 'normalized_name' => 'مولف نادرست', 'slug' => 'test-old-' . uniqid()]);
        $newAuthor = $this->people[] = Person::create(['name' => 'مؤلف درست آزمایشی', 'normalized_name' => 'مولف درست', 'slug' => 'test-new-' . uniqid()]);

        $target = $this->work('حاشیه آزمایشی استدراک', 11, 816, null, '● حاشیه آزمایشی استدراک — غیر همانند:');
        $kept = $this->manuscript($target, '100/1');
        $source = $this->work('حاشیه آزمایشی استدراک', 34, 979);
        $moved = $this->manuscript($source, '200/2');
        $duplicate = $this->manuscript($source, '100/1', 2);

        $erratumTarget = $this->work('رساله آزمایشی اصلاحیه', 2, 278, $oldAuthor);
        $erratum = $this->work('رساله آزمایشی اصلاحیه', 34, 972, $newAuthor, 'تصحیح نام مؤلف (مربوط به جلد 2: ص 286)');

        file_put_contents($this->file, json_encode([
            'catalog' => 'fankha',
            'merges' => [['source' => $this->ref($source), 'target' => $this->ref($target), 'distinct_work' => true]],
            'errata' => [['source' => $this->ref($erratum), 'target' => $this->ref($erratumTarget), 'field' => 'author', 'new_value' => $newAuthor->name]],
        ], JSON_UNESCAPED_UNICODE));

        $this->artisan('fahares:reconcile-addenda', ['--file' => $this->file])->assertSuccessful();

        // The addendum work is gone and its URL redirects to the main entry
        $this->assertNull(Work::find($source->id));
        $this->assertEquals($target->id, EntityRedirect::resolveTargetId('works', $source->id));
        $this->get("/works/{$source->id}")->assertStatus(301);

        // The new copy moved under the main entry and is flagged as a distinct text
        $moved->refresh();
        $this->assertEquals($target->id, $moved->work_id);
        $this->assertTrue($moved->is_distinct_work);
        $this->assertEquals(34, $moved->volume_number);
        $this->assertEquals(2, $target->fresh()->manuscripts_count);

        // The copy already listed under the main entry stays single; its addendum description becomes a citation
        $this->assertNull(Manuscript::find($duplicate->id));
        $this->assertEquals($kept->id, EntityRedirect::resolveTargetId('manuscripts', $duplicate->id));
        $this->assertEquals(['addendum'], $kept->sourceCitations()->pluck('role')->all());

        // The addendum text is preserved as a citation of the main entry
        $citation = $target->sourceCitations()->first();
        $this->assertEquals('addendum', $citation->role);
        $this->assertEquals([34, 979], [$citation->volume_number, $citation->page_start]);
        $this->assertStringContainsString('حاشیه آزمایشی استدراک', $citation->raw_text);

        // Erratum: author corrected, correction kept as a citation, pseudo-entry removed
        $erratumTarget->refresh();
        $this->assertEquals($newAuthor->id, $erratumTarget->author_id);
        $this->assertEquals('erratum', $erratumTarget->sourceCitations()->value('role'));
        $this->assertNull(Work::find($erratum->id));
        $this->assertEquals(0, $oldAuthor->fresh()->works_count);
        $this->assertEquals(1, $newAuthor->fresh()->works_count);

        // Re-running changes nothing
        $this->artisan('fahares:reconcile-addenda', ['--file' => $this->file])
            ->expectsOutputToContain('Merged 0 entries')
            ->assertSuccessful();
        $this->assertEquals(1, $target->sourceCitations()->count());
    }

    public function test_dry_run_writes_nothing()
    {
        $target = $this->work('اثر آزمایشی پیش‌اجرا', 5, 10);
        $source = $this->work('اثر آزمایشی پیش‌اجرا', 34, 980);
        $this->manuscript($source, '300');

        file_put_contents($this->file, json_encode([
            'catalog' => 'fankha',
            'merges' => [['source' => $this->ref($source), 'target' => $this->ref($target), 'distinct_work' => false]],
        ], JSON_UNESCAPED_UNICODE));

        $this->artisan('fahares:reconcile-addenda', ['--file' => $this->file, '--dry-run' => true])->assertSuccessful();

        $this->assertNotNull(Work::find($source->id));
        $this->assertEquals(0, $target->sourceCitations()->count());
    }

    public function test_title_mismatch_is_skipped()
    {
        $target = $this->work('اثر مقصد آزمایشی', 5, 10);
        $source = $this->work('اثر مبدأ آزمایشی', 34, 980);

        file_put_contents($this->file, json_encode([
            'catalog' => 'fankha',
            'merges' => [['source' => ['work_id' => $source->id, 'title' => 'عنوانی دیگر'], 'target' => $this->ref($target), 'distinct_work' => false]],
        ], JSON_UNESCAPED_UNICODE));

        $this->artisan('fahares:reconcile-addenda', ['--file' => $this->file])->assertSuccessful();

        $this->assertNotNull(Work::find($source->id));
    }
}

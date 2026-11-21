<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FieldSuggestion;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Subject;
use App\Models\User;
use App\Models\Work;
use App\Suggestions\RecordRelations;
use App\Support\WorkParts;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The whole and its part (step 14.308): a work recorded as a part of another reads the author, subject and language
 * it lacks from it, keeps what it has, and loses what it read when the relation goes; a chain is followed, a loop is
 * refused; the search document and the person's page follow.
 */
class WorkPartsTest extends TestCase
{
    private Person $simi;

    private Work $jawhariyya;

    protected function setUp(): void
    {
        parent::setUp();
        $this->simi = Person::create(['name' => 'سیمی نیشابوری', 'normalized_name' => 'سیمی نیشابوری', 'slug' => 'wp-' . uniqid(), 'death_year_hijri' => 868]);
        $this->jawhariyya = $this->work('جوهریه سیمی', ['author_id' => $this->simi->id, 'author_name' => 'سیمی نیشابوری', 'language_summary' => 'فارسی',
            'subject_summary' => 'کتاب‌آرایی', 'composition_date_raw' => '837 ق']);
        $this->jawhariyya->subjects()->attach(Subject::create(['name' => 'کتاب‌آرایی', 'slug' => 'wp-' . uniqid()])->id);
    }

    private function work(string $title, array $more = []): Work
    {
        return Work::create($more + ['primary_title' => $title, 'clean_title' => $title, 'slug' => 'wp-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
    }

    private function partOf(Work $part, Work $whole): array
    {
        return app(RecordRelations::class)->add($part, $whole, 'part', null, null);
    }

    /** The ids the search index was sent, in place of the engine */
    private function spyOnIndex(): object
    {
        $engine = new class extends \Laravel\Scout\Engines\NullEngine
        {
            public array $ids = [];

            public function update($models)
            {
                foreach ($models as $model) {
                    if ($model instanceof Work) {
                        $this->ids[] = $model->id;
                    }
                }
            }
        };
        app(\Laravel\Scout\EngineManager::class)->extend('spy', fn () => $engine);
        config(['scout.driver' => 'spy']);

        return $engine;
    }

    public function test_a_part_reads_what_it_lacks_from_its_whole_and_keeps_what_it_has()
    {
        $paper = $this->work('کاغذشناسی');
        $this->assertFalse($paper->inherits('author'));
        $this->assertNull($paper->author_label);

        $this->partOf($paper, $this->jawhariyya);
        $paper = Work::find($paper->id);
        $this->assertTrue($this->jawhariyya->is($paper->whole()));
        foreach (WorkParts::TRAITS as $trait) {
            $this->assertTrue($paper->inherits($trait), $trait);
            $this->assertTrue($this->jawhariyya->is($paper->traitSource($trait)));
        }
        $this->assertSame('سیمی نیشابوری', $paper->author_label);
        $this->assertTrue($this->simi->is($paper->effective_author));
        // nothing is written into the part's own columns
        $this->assertSame([null, null, null, null], [$paper->author_id, $paper->author_name, $paper->subject_summary, $paper->language_summary]);
        $this->assertSame(0, DB::table('work_people')->where('work_id', $paper->id)->count());

        // a part with an author of its own keeps it, and still reads the subject and the language
        $majnun = Person::create(['name' => 'مجنون رفیقی', 'normalized_name' => 'مجنون رفیقی', 'slug' => 'wp-' . uniqid()]);
        $ink = $this->work('مرکب‌سازی', ['author_id' => $majnun->id, 'author_name' => 'مجنون رفیقی', 'language_summary' => 'عربی']);
        $this->partOf($ink, $this->jawhariyya);
        $ink = Work::find($ink->id);
        $this->assertFalse($ink->inherits('author'));
        $this->assertSame('مجنون رفیقی', $ink->author_label);
        $this->assertFalse($ink->inherits('language'));
        $this->assertTrue($ink->inherits('subject'));

        // a part of two works reads from neither
        $other = $this->work('مجموعه‌ای دیگر', ['author_name' => 'کسی دیگر']);
        $this->partOf($paper, $other);
        $paper = Work::find($paper->id);
        $this->assertNull($paper->whole());
        $this->assertFalse($paper->inherits('author'));
        $this->assertNull($paper->author_label);
    }

    /** Each trait by itself: a part may have a subject or a language of its own and still read the rest */
    public function test_a_part_keeps_a_subject_or_a_language_of_its_own()
    {
        $own = Subject::create(['name' => 'شیمی', 'slug' => 'wp-' . uniqid()]);

        // a language of its own: the author and the subject are read, the language is not
        $arabic = $this->work('رسالة فی الحبر', ['language_summary' => 'عربی']);
        $this->partOf($arabic, $this->jawhariyya);
        $arabic = Work::find($arabic->id);
        $this->assertSame([true, true, false], [$arabic->inherits('author'), $arabic->inherits('subject'), $arabic->inherits('language')]);
        $doc = $arabic->toSearchableArray();
        $this->assertSame(['عربی', 'کتاب‌آرایی', ['کتاب‌آرایی'], $this->simi->id], [$doc['language_summary'], $doc['subject_summary'], $doc['subjects'], $doc['author_id']]);
        $this->followingRedirects()->get(route('works.show', $arabic))->assertOk()
            ->assertSeeInOrder(['زبان', 'عربی', 'رده‌های موضوعی', 'کتاب‌آرایی', 'data-inherited'], false)
            ->assertDontSee('فارسی');

        // a language recorded only as a link counts as the part's own
        $linked = $this->work('رسالۀ ترکی');
        $linked->languages()->attach(\App\Models\Language::create(['name' => 'ترکی', 'slug' => 'wp-' . uniqid()])->id);
        $this->partOf($linked, $this->jawhariyya);
        // (the document names a work's own languages when they are loaded, as an import loads them)
        $linked = Work::with('languages')->find($linked->id);
        $this->assertFalse($linked->inherits('language'));
        $this->assertSame([null, ['ترکی']], [$linked->toSearchableArray()['language_summary'], $linked->toSearchableArray()['languages']]);

        // a subject of its own, as text or as a link: the subject is not read, the author and the language are
        $bySummary = $this->work('در ساختن رنگ', ['subject_summary' => 'شیمی']);
        $byLink = $this->work('در ساختن حل');
        $byLink->subjects()->attach($own->id);
        foreach ([$bySummary, $byLink] as $part) {
            $this->partOf($part, $this->jawhariyya);
            $part = Work::find($part->id);
            $this->assertSame([true, false, true], [$part->inherits('author'), $part->inherits('subject'), $part->inherits('language')], $part->primary_title);
            $doc = $part->toSearchableArray();
            $this->assertNotContains('کتاب‌آرایی', $doc['subjects']);
            $this->assertNotSame('کتاب‌آرایی', $doc['subject_summary']);
            $this->assertSame('فارسی', $doc['language_summary']);
        }
        $this->assertSame(['شیمی'], Work::with('subjects')->find($byLink->id)->toSearchableArray()['subjects']);
        $this->followingRedirects()->get(route('works.show', $byLink))->assertOk()
            ->assertSeeInOrder(['رده‌های موضوعی', 'شیمی'], false)->assertDontSee('کتاب‌آرایی');

        // the part's own value given later takes the place of the read one, and taken away gives it back
        $arabic->update(['subject_summary' => 'خوشنویسی']);
        $this->assertFalse(Work::find($arabic->id)->inherits('subject'));
        $arabic->update(['subject_summary' => null, 'language_summary' => null]);
        $this->assertSame([true, true], [Work::find($arabic->id)->inherits('subject'), Work::find($arabic->id)->inherits('language')]);
    }

    /** The whole is itself a part: each trait from the nearest whole above that has it, named in the mark */
    public function test_each_trait_comes_from_the_nearest_whole_that_has_it()
    {
        // «جوهریه» ← a chapter with a language of its own ← a section with nothing
        $chapter = $this->work('باب اول جوهریه', ['language_summary' => 'عربی']);
        $section = $this->work('فصل مرکب');
        $this->partOf($chapter, $this->jawhariyya);
        $this->partOf($section, $chapter);
        $section = Work::find($section->id);
        $this->assertTrue($this->jawhariyya->is($section->traitSource('author')));
        $this->assertTrue($this->jawhariyya->is($section->traitSource('subject')));
        $this->assertTrue($chapter->is($section->traitSource('language')));

        // the page: «بخشی از» names the whole next above only; each mark names the work the value is read from
        $html = $this->followingRedirects()->get(route('works.show', $section))->assertOk()->getContent();
        preg_match('/<p[^>]*data-part-of.*?<\/p>/su', $html, $partOf);
        $this->assertStringContainsString('باب اول جوهریه', $partOf[0]);
        $this->assertStringNotContainsString('جوهریه سیمی', $partOf[0]);
        preg_match_all('/data-inherited>\((.*?)<\/span>/su', $html, $marks);
        $named = array_map(fn ($m) => trim(strip_tags($m)), $marks[1]);
        // the author, the whole's date, the language, the subject, in the order of the page
        $this->assertSame(['از اثر اصل: جوهریه سیمی)', 'اثر اصل: جوهریه سیمی)', 'از اثر اصل: باب اول جوهریه)', 'از اثر اصل: جوهریه سیمی)'], $named);

        // a change at any whole above sends every work below it to the index again
        $index = $this->spyOnIndex();
        $this->jawhariyya->update(['subject_summary' => 'هنرهای کتاب']);
        $this->assertEqualsCanonicalizing([$chapter->id, $section->id], array_values(array_unique(array_diff($index->ids, [$this->jawhariyya->id]))));
        $this->assertSame('هنرهای کتاب', Work::find($section->id)->toSearchableArray()['subject_summary']);
        $index->ids = [];
        $chapter->update(['language_summary' => 'عربی، فارسی']);
        $this->assertContains($section->id, $index->ids);
        $this->assertSame('عربی، فارسی', Work::find($section->id)->toSearchableArray()['language_summary']);

        // the middle whole taken off its own whole: the section reads what is left above it
        DB::table('work_relations')->where(['work_id' => $chapter->id, 'related_work_id' => $this->jawhariyya->id])->delete();
        $index->ids = [];
        RecordRelations::partsChanged([$chapter->id]);
        $this->assertEqualsCanonicalizing([$chapter->id, $section->id], array_values(array_unique($index->ids)));
        $section = Work::find($section->id);
        $this->assertSame([false, true], [$section->inherits('author'), $section->inherits('language')]);
        $this->assertNull($section->author_label);
    }

    public function test_the_pages_of_the_part_and_of_the_whole()
    {
        $paper = $this->work('کاغذشناسی');
        Manuscript::create(['work_id' => $paper->id, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1]);
        $paper->update(['manuscripts_count' => 1]);
        $this->partOf($paper, $this->jawhariyya);
        $wholeUrl = route('works.show', $this->jawhariyya);

        $page = $this->followingRedirects()->get(route('works.show', $paper))->assertOk();
        $page->assertSeeInOrder(['data-part-of', 'بخشی از:', $wholeUrl, 'جوهریه سیمی'], false)
            // the author, marked as read from the whole
            ->assertSeeInOrder([route('people.show', $this->simi), 'سیمی نیشابوری', 'data-inherited', 'از اثر اصل', $wholeUrl, 'جوهریه سیمی'], false)
            ->assertSeeInOrder(['تاریخ تألیف اصل', '۸۳۷ ق'], false)
            ->assertSeeInOrder(['زبان', 'فارسی', 'data-inherited'], false)
            ->assertSeeInOrder(['رده‌های موضوعی', 'کتاب‌آرایی', 'data-inherited'], false)
            ->assertSee('بخش، گزیده یا جلدی است از:')
            ->assertDontSee('پدیدآور ناشناخته');

        // the whole: its parts with the count of their copies, and nothing marked as inherited
        $this->followingRedirects()->get($wholeUrl)->assertOk()
            ->assertSeeInOrder(['data-part-count', '۱ بخش جدا'], false)
            ->assertSeeInOrder(['id="relations"', 'بخش‌ها، گزیده‌ها و مجلدها:', route('works.show', $paper), 'کاغذشناسی', '۱ نسخه'], false)
            ->assertDontSee('data-inherited', false)->assertDontSee('data-part-of', false);

        // a copy of the part names the whole's author
        $copy = Manuscript::where('work_id', $paper->id)->first();
        $this->followingRedirects()->get(route('manuscripts.show', $copy))->assertOk()
            ->assertSeeInOrder([route('people.show', $this->simi), 'سیمی نیشابوری', 'data-inherited'], false);

        // the wording of the relation, both ways, in the suggestion form
        $options = RecordRelations::options('work');
        $this->assertSame('این اثر بخش، گزیده یا جلدی است از اثر انتخاب‌شده', $options['part']);
        $this->assertSame('«کاغذشناسی» بخش، گزیده یا جلدی است از «جوهریه سیمی»', RecordRelations::sentence($paper, $this->jawhariyya, 'part'));
        $this->assertSame('«جوهریه سیمی» بخش، گزیده یا جلدی است از «کاغذشناسی»', RecordRelations::sentence($paper, $this->jawhariyya, '~part'));
    }

    public function test_a_chain_is_followed_and_a_loop_is_refused()
    {
        $chapter = $this->work('باب اول جوهریه');
        $section = $this->work('فصل مرکب');
        $this->partOf($chapter, $this->jawhariyya);
        $this->partOf($section, $chapter);

        // the part of a part reads through the part that has nothing of its own
        $section = Work::find($section->id);
        $this->assertSame([$chapter->id, $this->jawhariyya->id], $section->wholes()->pluck('id')->all());
        $this->assertTrue($this->jawhariyya->is($section->traitSource('author')));
        $this->assertSame('سیمی نیشابوری', $section->author_label);
        // ...and from the nearest whole that has it
        $chapter->update(['author_name' => 'گردآورندۀ باب']);
        $this->assertSame('گردآورندۀ باب', Work::find($section->id)->author_label);
        $this->assertSame('فارسی', Work::find($section->id)->traitSource('language')->language_summary);

        // a work is no part of itself, of its part, or of a part of its part
        foreach ([[$this->jawhariyya, $this->jawhariyya], [$this->jawhariyya, $chapter], [$this->jawhariyya, $section], [$chapter, $section]] as [$part, $whole]) {
            try {
                $this->partOf($part, $whole);
                $this->fail('A loop was accepted');
            } catch (InvalidArgumentException $e) {
                $this->assertTrue(true);
            }
        }
        // the other way round of the same pair is offered as «~part», and refused alike
        $this->expectException(InvalidArgumentException::class);
        app(RecordRelations::class)->add($section, $this->jawhariyya, '~part', null, null);
    }

    public function test_a_loop_written_past_the_check_ends()
    {
        $a = $this->work('الف');
        $b = $this->work('ب');
        $c = $this->work('ج', ['author_name' => 'نویسندۀ ج']);
        foreach ([[$a, $b], [$b, $a], [$c, $a]] as [$part, $whole]) {
            DB::table('work_relations')->insert(['work_id' => $part->id, 'related_work_id' => $whole->id, 'type' => 'part', 'source' => 'suggestion', 'created_at' => now(), 'updated_at' => now()]);
        }
        WorkParts::flush();
        $this->assertSame([$b->id], WorkParts::chainIds($a->id));
        $this->assertNull(Work::find($a->id)->author_label);
        $this->assertEqualsCanonicalizing([$b->id, $c->id], WorkParts::descendantIds($a->id));

        // a chain longer than the depth is cut
        $works = collect(range(1, WorkParts::MAX_DEPTH + 3))->map(fn ($n) => $this->work("حلقۀ {$n}"));
        foreach ($works->sliding(2) as $pair) {
            DB::table('work_relations')->insert(['work_id' => $pair->first()->id, 'related_work_id' => $pair->last()->id, 'type' => 'part', 'source' => 'suggestion', 'created_at' => now(), 'updated_at' => now()]);
        }
        WorkParts::flush();
        $this->assertCount(WorkParts::MAX_DEPTH, WorkParts::chainIds($works->first()->id));
    }

    public function test_the_search_document_of_a_part_and_its_reindexing()
    {
        $paper = $this->work('کاغذشناسی');
        $own = $paper->toSearchableArray();
        $this->assertSame([null, [], null, null, []], [$own['author_id'], $own['author_ids'], $own['subject_summary'], $own['language_summary'], $own['subjects']]);

        $index = $this->spyOnIndex();
        $made = $this->partOf($paper, $this->jawhariyya);
        $this->assertContains($paper->id, $index->ids);

        $doc = Work::find($paper->id)->toSearchableArray();
        $this->assertSame($this->simi->id, $doc['author_id']);
        $this->assertSame([$this->simi->id], $doc['author_ids']);
        $this->assertStringContainsString(\App\Helpers\TextNormalizer::normalize('سیمی نیشابوری'), $doc['author_name']);
        $this->assertContains(\App\Helpers\TextNormalizer::normalize('سیمی نیشابوری'), $doc['titles_names']);
        $this->assertSame(['کتاب‌آرایی', 'فارسی', ['کتاب‌آرایی']], [$doc['subject_summary'], $doc['language_summary'], $doc['subjects']]);
        // the era is the author's; the composition stays the part's own
        $this->assertSame([868, 868, null], [$doc['author_death_year'], $doc['era_year'], $doc['composition_year_hijri']]);

        // a change of the whole's author, subject or language sends its parts again; another change does not
        $index->ids = [];
        $this->jawhariyya->update(['description' => 'در هنرهای کتاب']);
        $this->assertNotContains($paper->id, $index->ids);
        $this->jawhariyya->update(['language_summary' => 'فارسی، عربی']);
        $this->assertContains($paper->id, $index->ids);
        $this->assertSame('فارسی، عربی', Work::find($paper->id)->toSearchableArray()['language_summary']);
        // ...as does a new name of the whole's author
        $index->ids = [];
        $this->simi->update(['name' => 'سیمی نیشابوری، علی']);
        $this->assertContains($paper->id, $index->ids);
        $this->assertSame('سیمی نیشابوری، علی', Work::find($paper->id)->author_label);

        // the relation taken away: the part is sent again, with nothing of the whole left in it
        $index->ids = [];
        DB::table('work_relations')->where('id', $made['new']['added_record_relation']['id'])->delete();
        RecordRelations::partsChanged([$paper->id]);
        $this->assertContains($paper->id, $index->ids);
        $after = Work::find($paper->id);
        $this->assertNull($after->whole());
        $this->assertNull($after->author_label);
        $this->assertSame($own, $after->toSearchableArray());
    }

    public function test_the_relation_approved_from_a_suggestion_and_rolled_back()
    {
        \Illuminate\Support\Facades\Notification::fake();
        $admin = User::firstOrCreate(['email' => 'test-admin@fahares.net'], ['name' => 'Admin Tester', 'password' => 'password123', 'role' => 'admin']);
        $admin->forceFill(['role' => 'admin', 'email_verified_at' => now()])->save();
        $paper = $this->work('کاغذشناسی');
        $index = $this->spyOnIndex();

        // from the whole's page: «the chosen work is a part of this work»
        $this->suggestJson(['type' => 'work', 'id' => $this->jawhariyya->id, 'kind' => 'relation', 'field_name' => 'work_relation', 'target_id' => $paper->id,
            'suggested_value' => '', 'rationale_citation' => 'باب اول', 'role' => '~part'])->assertOk();
        $s = FieldSuggestion::where('suggestable_id', $this->jawhariyya->id)->where('status', 'pending')->latest('id')->firstOrFail();
        $this->actingAs($admin)->get("/admin/suggestions/{$s->id}")->assertOk()->assertSee('«کاغذشناسی» بخش، گزیده یا جلدی است از «جوهریه سیمی»', false);
        $this->actingAs($admin)->post("/admin/suggestions/{$s->id}/approve", ['applied_value' => '', 'revision_category' => 'scholarly_correction', 'role' => '~part'])
            ->assertRedirect('/admin/suggestions');
        $this->assertSame('سیمی نیشابوری', Work::find($paper->id)->author_label);
        $this->assertContains($paper->id, $index->ids);

        // the same pair the other way round would make a loop
        $this->suggestJson(['type' => 'work', 'id' => $this->jawhariyya->id, 'kind' => 'relation', 'field_name' => 'work_relation', 'target_id' => $paper->id,
            'suggested_value' => '', 'rationale_citation' => 'وارونه', 'role' => 'part'])->assertOk();
        $loop = FieldSuggestion::where('suggestable_id', $this->jawhariyya->id)->where('status', 'pending')->latest('id')->firstOrFail();
        $this->actingAs($admin)->post("/admin/suggestions/{$loop->id}/approve", ['applied_value' => '', 'revision_category' => 'scholarly_correction', 'role' => 'part'])
            ->assertSessionHasErrors('applied_value');
        $this->assertSame(1, DB::table('work_relations')->count());

        // rolled back: the relation goes, and with it everything the part read
        $index->ids = [];
        $log = AuditLog::where('auditable_type', Work::class)->where('auditable_id', $this->jawhariyya->id)->where('action', 'verify')->latest('id')->first();
        $this->actingAs($admin)->post("/admin/audit-logs/{$log->id}/rollback")->assertSessionHas('success');
        $this->assertSame(0, DB::table('work_relations')->count());
        $this->assertNull(Work::find($paper->id)->author_label);
        $this->assertContains($paper->id, $index->ids);
    }

    public function test_a_merge_and_a_removal_of_the_whole()
    {
        $paper = $this->work('کاغذشناسی');
        $this->partOf($paper, $this->jawhariyya);
        $index = $this->spyOnIndex();

        // the whole folded into another record: its parts are that record's
        $kept = $this->work('جوهریه', ['author_name' => 'سیمی']);
        $log = RecordRelations::moveOnMerge($this->jawhariyya, $kept);
        $this->assertContains($paper->id, $index->ids);
        $this->assertSame('سیمی', Work::find($paper->id)->author_label);
        $index->ids = [];
        RecordRelations::undoMove($log, $this->jawhariyya->id);
        $this->assertContains($paper->id, $index->ids);
        $this->assertSame('سیمی نیشابوری', Work::find($paper->id)->author_label);

        // the whole removed as redundant: the part stands alone, and reads again when the removal is undone
        $index->ids = [];
        $old = app(\App\Suggestions\RecordRemover::class)->remove($this->jawhariyya);
        $this->assertContains($paper->id, $index->ids);
        $this->assertNull(Work::find($paper->id)->author_label);
        app(\App\Suggestions\RecordRemover::class)->undo(Work::class, $old);
        $this->assertSame('سیمی نیشابوری', Work::find($paper->id)->author_label);
    }

    public function test_the_person_page_lists_the_parts_under_their_whole()
    {
        $paper = $this->work('کاغذشناسی');
        $ink = $this->work('مرکب و کاغذ');
        $this->partOf($paper, $this->jawhariyya);
        $this->partOf($ink, $this->jawhariyya);
        $this->simi->recountContributions();

        // the parts are no works of the person's own: not in the count, not in the list of works
        $this->assertSame(1, (int) $this->simi->fresh()->works_count);
        $this->assertSame([$this->jawhariyya->id], $this->simi->works()->pluck('works.id')->all());
        $page = $this->followingRedirects()->get(route('people.show', $this->simi))->assertOk();
        $page->assertSeeInOrder(['جوهریه سیمی', 'data-inherited-part', 'بخشی از این اثر:', 'کاغذشناسی', 'data-inherited-part', 'مرکب و کاغذ'], false);
        $this->assertSame(2, substr_count($page->getContent(), 'data-inherited-part'));

        // a part given an author of its own leaves the list
        $ink->update(['author_name' => 'مجنون رفیقی']);
        $page = $this->followingRedirects()->get(route('people.show', $this->simi))->assertOk();
        $this->assertSame(1, substr_count($page->getContent(), 'data-inherited-part'));
        $page->assertDontSee('مرکب و کاغذ');
    }
}

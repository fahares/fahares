<?php

namespace Tests\Feature;

use App\Auth\Permissions;
use App\Models\AuditLog;
use App\Models\FieldSuggestion;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\User;
use App\Models\Work;
use App\Suggestions\Contributions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** The correction history on each record page and the credit beside relations (backlog item 39). */
class RecordHistoryTest extends TestCase
{
    protected User $admin;

    protected User $researcher;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Permissions::flush();
        $this->admin = User::create(['name' => 'مدیر کل', 'email' => uniqid('rh-admin-') . '@example.test', 'password' => 'password123', 'role' => 'admin']);
        $this->researcher = User::create(['name' => 'نام حساب پژوهشگر', 'email' => uniqid('rh-user-') . '@example.test', 'password' => 'password123', 'role' => 'user']);
    }

    protected function library(string $name): Library
    {
        return Library::create(['name' => $name, 'city' => 'قم', 'slug' => 'rh-lib-' . uniqid()]);
    }

    protected function work(string $title): Work
    {
        return Work::create(['primary_title' => $title, 'clean_title' => $title, 'slug' => 'rh-work-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
    }

    protected function manuscript(Work $work, array $attributes = []): Manuscript
    {
        return Manuscript::create($attributes + ['work_id' => $work->id, 'volume_number' => 1, 'page_start' => 1, 'sequence_number' => 1]);
    }

    protected function person(string $name): Person
    {
        return Person::create(['name' => $name, 'normalized_name' => $name, 'slug' => 'rh-p-' . uniqid()]);
    }

    protected function apply($record, array $data)
    {
        $type = \App\Suggestions\SuggestionRegistry::typeKey($record::class);

        return $this->actingAs($this->admin)->post(route('admin.structure.apply', [$type, $record->id]), $data)->assertSessionHasNoErrors();
    }

    /** The structural tool applied it as the editors' work; credit it to a researcher, as an approved suggestion of theirs. */
    protected function credit(?string $name = 'احمد پژوهشگر'): FieldSuggestion
    {
        $s = FieldSuggestion::where('kind', 'relation')->latest('id')->firstOrFail();
        $s->update(['user_id' => $this->researcher->id, 'credit_name' => $name]);

        return $s;
    }

    protected function page($record)
    {
        auth()->logout();
        $route = match (true) {
            $record instanceof Work => 'works.show',
            $record instanceof Manuscript => 'manuscripts.show',
            $record instanceof Person => 'people.show',
            $record instanceof Library => 'libraries.show',
        };

        return $this->followingRedirects()->get(route($route, $record))->assertOk();
    }

    protected function text($response): string
    {
        return preg_replace('/\s+/u', ' ', strip_tags($response->getContent()));
    }

    public function test_a_moved_copy_reads_on_its_own_page_and_on_both_works()
    {
        $from = $this->work('اثر نخستین');
        $to = $this->work('خلق الاعمال');
        $ms = $this->manuscript($from, ['shelfmark' => '23961']);

        $this->apply($ms, ['relation' => 'move_to_work', 'target' => (string) $to->id]);
        $s = $this->credit();
        $this->assertSame($from->id, $s->resolution['from_work_id']);

        $this->assertStringContainsString('به پیشنهاد احمد پژوهشگر، این نسخه از اثر «اثر نخستین» به اثر «خلق الاعمال» منتقل شد.', $this->text($this->page($ms)));
        $target = $this->text($this->page($to));
        $this->assertStringContainsString('تاریخچه‌ی اصلاحات', $target);
        $this->assertMatchesRegularExpression('/به پیشنهاد احمد پژوهشگر، نسخه‌ی «[^»]+» از اثر «اثر نخستین» به این اثر منتقل شد\./u', $target);
        $this->assertMatchesRegularExpression('/به پیشنهاد احمد پژوهشگر، نسخه‌ی «[^»]+» از این اثر به اثر «خلق الاعمال» منتقل شد\./u', $this->text($this->page($from)));
        $this->page($to)->assertSee(route('contributors.show', $this->researcher), false)->assertDontSee('نام حساب پژوهشگر');
    }

    public function test_without_a_permitted_name_the_line_stays_anonymous_and_unapproved_ones_are_not_shown()
    {
        $work = $this->work('اثر بی‌نام');
        $ms = $this->manuscript($this->work('اثر پیشین'));
        $this->apply($ms, ['relation' => 'move_to_work', 'target' => (string) $work->id]);
        $this->credit(null);
        FieldSuggestion::create(['suggestable_type' => Work::class, 'suggestable_id' => $work->id, 'kind' => 'field', 'field_name' => 'description',
            'suggested_value' => 'متن در انتظار داوری', 'status' => 'pending', 'user_id' => $this->researcher->id, 'credit_name' => 'نام در انتظار']);

        $text = $this->text($this->page($work));
        $this->assertStringContainsString('به پیشنهاد ' . Contributions::ANONYMOUS_LABEL . '، نسخه‌ی', $text);
        $this->assertStringNotContainsString('نام حساب پژوهشگر', $text);
        $this->assertStringNotContainsString('نام در انتظار', $text);
        $this->assertStringNotContainsString('متن در انتظار داوری', $text);
    }

    public function test_the_editors_own_change_reads_as_theirs_and_a_record_without_corrections_has_no_section()
    {
        $kept = $this->library('کتابخانه‌ی ماندنی');
        $duplicate = $this->library('کتابخانه‌ی تکراری');
        $this->apply($duplicate, ['relation' => 'duplicate', 'target' => (string) $kept->id, 'keep' => 'target']);

        $text = $this->text($this->page($kept->fresh()));
        $this->assertStringContainsString('به دست ' . Contributions::STAFF_LABEL . '، رکورد تکراری «کتابخانه‌ی تکراری» در این رکورد ادغام شد.', $text);
        $this->page($this->library('کتابخانه‌ی بی‌اصلاح'))->assertDontSee('تاریخچه‌ی اصلاحات');
    }

    public function test_a_relocated_copy_keeps_its_earlier_place_and_shows_on_both_libraries()
    {
        // a name starting with «د», whose first byte is that of «،» (production suggestion 46, «دیانی»)
        $old = $this->library('دیانی پیشین');
        $new = $this->library('کتابخانه‌ی تازه');
        $ms = $this->manuscript($this->work('اثر جابه‌جا'), ['library_id' => $old->id, 'library' => $old->name, 'shelfmark' => '12']);

        $this->apply($ms, ['relation' => 'relocated', 'target' => (string) $new->id, 'shelfmark' => '99', 'library_choice' => 'target']);
        $s = $this->credit();
        $this->assertSame([$old->id, '12', $new->id, '99'], [$s->resolution['from_library_id'], $s->resolution['from_shelfmark'], $s->resolution['library_id'], $s->resolution['shelfmark']]);

        foreach ([$old, $new] as $library) {
            $this->assertMatchesRegularExpression('/به پیشنهاد احمد پژوهشگر، نسخه‌ی «[^»]+» از «دیانی پیشین، ش 12» به «کتابخانه‌ی تازه، ش 99» منتقل شد\./u', $this->text($this->page($library)));
        }
        $this->assertStringContainsString('این نسخه از «دیانی پیشین، ش 12»', $this->text($this->page($ms)));
    }

    public function test_a_relation_reads_from_both_sides_with_its_credit_and_a_person_shows_the_work_given_to_them()
    {
        $part = $this->work('جلد دوم');
        $whole = $this->work('کتاب کامل');
        $this->apply($part, ['relation' => 'work_relation', 'target' => (string) $whole->id, 'role' => 'part']);
        $s = $this->credit();
        $this->assertSame($s->id, DB::table('work_relations')->value('suggestion_id'));

        foreach ([$part, $whole] as $work) {
            $page = $this->page($work);
            $text = $this->text($page);
            $this->assertStringContainsString('به پیشنهاد احمد پژوهشگر، رابطه ثبت شد:', $text);
            // the relation itself carries the name too
            $this->assertStringContainsString('به پیشنهاد احمد پژوهشگر', strip_tags(explode('تاریخچه‌ی اصلاحات', $page->getContent())[0]));
        }

        $author = $this->person('مؤلف درست');
        $this->apply($part, ['relation' => 'author_is', 'target' => (string) $author->id]);
        $this->credit();
        $text = $this->text($this->page($author));
        $this->assertStringContainsString('به پیشنهاد احمد پژوهشگر، اثر «جلد دوم»، تعیین پدیدآور: «مؤلف درست»', $text);
    }

    public function test_the_backfill_reads_the_earlier_work_from_the_audit_log_once()
    {
        $from = $this->work('اثر مبدأ قدیمی');
        $to = $this->work('اثر مقصد قدیمی');
        $ms = $this->manuscript($to);
        // applied before the source work was kept in the resolution (production suggestions 12 and 13)
        $s = FieldSuggestion::create(['suggestable_type' => Manuscript::class, 'suggestable_id' => $ms->id, 'kind' => 'relation', 'field_name' => 'move_to_work', 'suggested_value' => '',
            'payload' => ['target_id' => $to->id], 'status' => 'approved', 'reviewed_at' => now(), 'user_id' => $this->researcher->id,
            'credit_name' => 'احمد پژوهشگر', 'resolution' => ['applied' => true, 'work_id' => $to->id]]);
        AuditLog::create(['action' => 'verify', 'auditable_type' => Manuscript::class, 'auditable_id' => $ms->id,
            'old_values' => ['work_id' => $from->id], 'new_values' => ['work_id' => $to->id, 'suggestion_id' => $s->id]]);

        $this->page($from)->assertDontSee('تاریخچه‌ی اصلاحات');
        $this->artisan('fahares:backfill-suggestion-resolution')->expectsOutputToContain('Would write: 1 of 1')->assertSuccessful();
        $this->assertArrayNotHasKey('from_work_id', $s->fresh()->resolution);

        $this->artisan('fahares:backfill-suggestion-resolution', ['--apply' => true])->expectsOutputToContain('Written: 1 of 1')->assertSuccessful();
        $this->assertSame($from->id, $s->fresh()->resolution['from_work_id']);
        $this->artisan('fahares:backfill-suggestion-resolution')->expectsOutputToContain('Would write: 0 of 0')->assertSuccessful();

        $this->assertMatchesRegularExpression('/به پیشنهاد احمد پژوهشگر، نسخه‌ی «[^»]+» از این اثر به اثر «اثر مقصد قدیمی» منتقل شد\./u', $this->text($this->page($from)));
    }

    public function test_a_reading_a_merge_kept_names_the_catalogue_not_a_submitter_and_is_not_a_researchers_correction()
    {
        $ms = $this->manuscript($this->work('اثر دو معرفی'), ['folios' => '37']);
        $library = $this->library('کتابخانه‌ی قمر');
        $reading = fn ($record, string $field, string $value, array $resolution, string $credit) => FieldSuggestion::create([
            'suggestable_type' => $record::class, 'suggestable_id' => $record->id, 'kind' => 'field', 'field_name' => $field,
            'current_value' => 'مقدار ماندنی', 'suggested_value' => $value, 'credit_name' => $credit, 'status' => 'approved',
            'standing' => 'alternative', 'public_note' => 'قول فهرست دیگرِ همین نسخه.', 'resolution' => ['applied' => false] + $resolution, 'reviewed_at' => now()]);
        // as fahares:link-copy-references and MergeComparison write them (production suggestions 80–105)
        $copy = $reading($ms, 'folios', '34', ['origin' => 'copy_merge', 'merged_manuscript_id' => 999999], 'فهرست مؤید: 1-375');
        $merge = $reading($library, 'full_name', 'کتابخانه قمر بنی هاشم (ع)', ['origin' => 'merge', 'merged_id' => 999998], 'قمر بنی هاشم (ع)');
        // a guest's correction without a resolution stays a researcher's
        $guest = FieldSuggestion::create(['suggestable_type' => Manuscript::class, 'suggestable_id' => $ms->id, 'kind' => 'field', 'field_name' => 'copy_place',
            'current_value' => 'اصفهان', 'suggested_value' => 'هرات', 'status' => 'approved', 'standing' => 'adopted', 'reviewed_at' => now()]);

        $text = $this->text($this->page($ms));
        $this->assertStringContainsString('در یکی شدن دو معرفی این نسخه، قول فهرست مؤید: 1-375 برای «تعداد برگ» نگه داشته شد: «34»', $text);
        $this->assertStringNotContainsString('به پیشنهاد فهرست مؤید', $text);
        $this->assertStringContainsString('در ادغام رکورد تکراری در این کتابخانه، قول قمر بنی هاشم (ع) برای', $this->text($this->page($library)));

        $contributions = $this->get('/contributions')->assertOk();
        foreach ([$copy, $merge] as $s) {
            $contributions->assertDontSee('data-contribution="' . $s->id . '"', false);
        }
        $contributions->assertSee('data-contribution="' . $guest->id . '"', false);

        $panel = $this->actingAs($this->admin)->get('/admin/suggestions?status=approved')->assertOk();
        $panel->assertSee(FieldSuggestion::MACHINE_ORIGINS['copy_merge'])->assertSee(FieldSuggestion::MACHINE_ORIGINS['merge']);
        $this->actingAs($this->admin)->get("/admin/suggestions/{$copy->id}")->assertOk()
            ->assertSee(FieldSuggestion::MACHINE_ORIGINS['copy_merge'])->assertSee('data-machine-reading', false)->assertDontSee('پژوهشگر مهمان');
        $this->assertNull($guest->machineOrigin());
    }

    public function test_a_copy_merge_reads_as_one_line_with_the_readings_it_kept_under_it()
    {
        // as fahares:link-copy-references leaves it (step 14.16): a former location, and the other catalogue's readings
        $ms = $this->manuscript($this->work('مصباح الشريعة'), ['library' => 'رضوی', 'shelfmark' => '36188', 'folios' => '37', 'metadata' => ['former_locations' => [
            ['library_id' => 205, 'library' => 'شیخ علی حیدر', 'city' => 'مشهد', 'shelfmark' => '465/4', 'catalog' => 'مؤید: 1-375', 'note' => 'فنخا: «همان نسخه بالا»'],
        ]]]);
        $reading = FieldSuggestion::create(['suggestable_type' => Manuscript::class, 'suggestable_id' => $ms->id, 'kind' => 'field', 'field_name' => 'folios',
            'current_value' => '37', 'suggested_value' => '34', 'credit_name' => 'فهرست مؤید: 1-375', 'status' => 'approved', 'standing' => 'alternative',
            'resolution' => ['applied' => false, 'origin' => 'copy_merge', 'merged_manuscript_id' => 999999], 'reviewed_at' => now()]);
        // a copy merged with no reading still shows its merge
        $quiet = $this->manuscript($this->work('اثر بی‌قول'), ['metadata' => ['former_locations' => [
            ['library' => 'مولوی', 'city' => 'مشهد', 'shelfmark' => '653', 'catalog' => 'نشریه: 5-103', 'note' => 'فنخا: «همان نسخه بالا»'],
        ]]]);

        $page = $this->page($ms);
        $text = $this->text($page);
        $this->assertStringContainsString('در یکی شدن دو معرفی این نسخه، معرفی «شیخ علی حیدر، ش 465/4» (مؤید: 1-375) در این رکورد ادغام شد.', $text);
        $this->assertStringContainsString('قول فهرست مؤید: 1-375 برای «تعداد برگ»: «34»', $text);
        // the reading is under the merge, not a line of its own
        $page->assertDontSee('data-history="' . $reading->id . '"', false)->assertSee('data-history-readings', false);
        $this->assertStringNotContainsString('نگه داشته شد: «34»', $text);
        $this->assertStringContainsString('معرفی «مولوی، ش 653» (نشریه: 5-103) در این رکورد ادغام شد.', $this->text($this->page($quiet)));
    }

    public function test_the_former_leaves_of_a_merged_copy_go_to_its_former_location_and_the_leaf_reading_goes()
    {
        $ms = $this->manuscript($this->work('مصباح الشريعة'), ['library' => 'رضوی', 'shelfmark' => '36188', 'folios' => '37', 'metadata' => ['former_locations' => [
            ['library_id' => 205, 'library' => 'شیخ علی حیدر', 'city' => 'مشهد', 'shelfmark' => '465/4', 'catalog' => 'مؤید: 1-375', 'note' => 'فنخا: «همان نسخه بالا»'],
        ]]]);
        $removedId = 999990;
        $reading = fn (string $field, string $value) => FieldSuggestion::create(['suggestable_type' => Manuscript::class, 'suggestable_id' => $ms->id, 'kind' => 'field',
            'field_name' => $field, 'current_value' => 'رضوی', 'suggested_value' => $value, 'credit_name' => 'فهرست مؤید: 1-375', 'status' => 'approved',
            'standing' => 'alternative', 'resolution' => ['applied' => false, 'origin' => 'copy_merge', 'merged_manuscript_id' => $removedId], 'reviewed_at' => now()]);
        $folios = $reading('folios', '34');
        $script = $reading('script_names', 'نسخ');
        // the run's backup, as fahares:link-copy-references wrote it: the removed record's leaves
        $run = storage_path('app/test-fold-' . uniqid() . '.json');
        file_put_contents($run, json_encode(['command' => 'fahares:link-copy-references', 'relations' => [], 'facsimile' => [], 'readings' => [], 'merges' => [[
            'source_id' => $removedId, 'target_id' => $ms->id, 'result' => ['source_snapshot' => ['attributes' => [
                'library' => 'شیخ علی حیدر', 'shelfmark' => '465/4', 'folios' => 34, 'range_from' => '213پ', 'range_to' => '246پ']]],
        ]]], JSON_UNESCAPED_UNICODE));
        $backup = storage_path('app/test-fold-backup-' . uniqid() . '.json');

        $this->artisan('fahares:fold-copy-merge-extents', ['run' => $run])->expectsOutputToContain('Would write: 1 former locations get their leaves, 1 leaf-count readings removed')->assertSuccessful();
        $this->assertNotNull($folios->fresh());
        $this->artisan('fahares:fold-copy-merge-extents', ['run' => $run, '--apply' => true, '--backup' => $backup])->expectsOutputToContain('Written: 1')->assertSuccessful();
        $this->artisan('fahares:fold-copy-merge-extents', ['run' => $run])->expectsOutputToContain('Would write: 0 former locations get their leaves, 0')->assertSuccessful();

        $former = $ms->fresh()->metadata['former_locations'][0];
        $this->assertSame([34, '213پ', '246پ'], [$former['folios'], $former['range_from'], $former['range_to']]);
        $this->assertNull($folios->fresh());
        $this->assertNotNull($script->fresh(), 'a reading of another field stays');
        $this->assertSame($folios->id, json_decode(file_get_contents($backup), true)['copies'][0]['readings'][0]['id']);

        $text = $this->text($this->page($ms));
        $this->assertStringContainsString('نشانی پیشین: شیخ علی حیدر، مشهد، شماره‌ی ۴۶۵/۴، برگ ۲۱۳پ تا ۲۴۶پ (۳۴ برگ) (در مؤید: ۱-۳۷۵)', $text);
        $this->assertStringContainsString('معرفی «شیخ علی حیدر، ش 465/4»، برگ ۲۱۳پ تا ۲۴۶پ (۳۴ برگ) (مؤید: 1-375) در این رکورد ادغام شد.', $text);
        @unlink($run);
        @unlink($backup);
    }
}

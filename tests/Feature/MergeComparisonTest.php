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
use App\Suggestions\MergeComparison;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The comparison step of a merge (backlog items 46 and 90): the removed record's own data is filled, combined, kept
 * or replaced as the admin chooses, the same from the direct tool, an approved suggestion and the people merge tool;
 * the choices are logged and an undo puts both records back. A misspelt person name becomes a hidden search form.
 */
class MergeComparisonTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Permissions::flush();
        $this->admin = User::create(['name' => 'مدیر کل', 'email' => uniqid('mc-admin-') . '@example.test', 'password' => 'password123', 'role' => 'admin']);
    }

    protected function work(array $attributes = []): Work
    {
        return Work::create($attributes + ['primary_title' => 'اثر ' . uniqid(), 'clean_title' => 'اثر', 'slug' => 'mc-work-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
    }

    protected function manuscript(array $attributes = []): Manuscript
    {
        return Manuscript::create($attributes + ['work_id' => $attributes['work_id'] ?? $this->work()->id, 'volume_number' => 1, 'page_start' => 1, 'sequence_number' => 1]);
    }

    protected function person(string $name, array $attributes = []): Person
    {
        return Person::create($attributes + ['name' => $name, 'normalized_name' => $name, 'slug' => 'mc-p-' . uniqid()]);
    }

    protected function duplicate($record, $other): FieldSuggestion
    {
        return FieldSuggestion::create([
            'suggestable_type' => $record::class, 'suggestable_id' => $record->id, 'kind' => 'relation', 'field_name' => 'duplicate',
            'suggested_value' => 'تکراری', 'payload' => ['target_id' => $other->id], 'guest_name' => 'پژوهشگر', 'guest_email' => 'r@example.test', 'status' => 'pending',
        ]);
    }

    protected function rows($source, $target): array
    {
        return collect(MergeComparison::rows($source, $target))->keyBy('key')->all();
    }

    public function test_the_comparison_offers_each_differing_field_with_defaults_that_lose_nothing_but_conflicts()
    {
        $work = $this->work();
        $source = $this->manuscript(['work_id' => $work->id, 'paper' => 'ترمه', 'binding' => 'تیماج', 'is_illuminated' => true, 'folios' => 100,
            'metadata' => ['ownership_and_seals' => ['مهر الف'], 'residual_notes' => 'یادداشت']]);
        $target = $this->manuscript(['work_id' => $work->id, 'paper' => 'فرنگی', 'folios' => 100, 'metadata' => ['ownership_and_seals' => ['مهر ب']]]);
        $rows = $this->rows($source, $target);

        // Same value (folios) and «no» flags are not asked
        $this->assertSame(['paper', 'binding', 'is_illuminated', 'metadata'], array_keys($rows));
        $this->assertSame([MergeComparison::KEEP, MergeComparison::TAKE, MergeComparison::TAKE, MergeComparison::BOTH], array_column($rows, 'default'));
        $this->assertArrayHasKey(MergeComparison::BOTH, $rows['paper']['options'], 'a note may keep both');
        $this->assertSame(['فرنگی'], $rows['paper']['target']);
        $this->assertSame(['ترمه'], $rows['paper']['source']);

        // A submitted choice the row does not offer falls back to the default
        $this->assertSame(MergeComparison::KEEP, MergeComparison::choices([$rows['paper']], ['paper' => 'wrong'])['paper']);
    }

    public function test_a_manuscript_merge_approved_from_a_suggestion_follows_the_choices_and_its_undo_restores_both()
    {
        $work = $this->work();
        $dup = $this->manuscript(['work_id' => $work->id, 'paper' => 'ترمه', 'binding' => 'تیماج', 'dimensions' => '۲۰×۱۵', 'is_illuminated' => true,
            'metadata' => ['ownership_and_seals' => ['مهر الف']]]);
        $kept = $this->manuscript(['work_id' => $work->id, 'paper' => 'فرنگی', 'dimensions' => '۲۱×۱۴', 'metadata' => ['ownership_and_seals' => ['مهر ب']]]);
        $s = $this->duplicate($dup, $kept);

        // The reviewer sees the comparison for either record kept
        $this->actingAs($this->admin)->get("/admin/suggestions/{$s->id}")->assertOk()
            ->assertSee('مقایسه‌ی فیلدها')->assertSee('fields[target][paper]', false)->assertSee('fields[source][paper]', false);

        $this->actingAs($this->admin)->post("/admin/suggestions/{$s->id}/approve", [
            'keep' => 'target',
            'fields' => ['target' => ['paper' => 'source', 'binding' => 'target'], 'source' => ['paper' => 'both']],
        ])->assertSessionHasNoErrors();

        $kept->refresh();
        $this->assertNull(Manuscript::find($dup->id));
        $this->assertSame('ترمه', $kept->paper, 'replaced as chosen');
        $this->assertNull($kept->binding, 'left empty as chosen');
        $this->assertSame('۲۱×۱۴', $kept->dimensions, 'a conflict keeps the kept value by default');
        $this->assertTrue($kept->is_illuminated, 'a «yes» flag reaches the kept copy');
        $this->assertSame(['مهر ب', 'مهر الف'], $kept->metadata['ownership_and_seals'], 'lists are combined');

        $log = AuditLog::activeForSuggestion($s->id);
        $this->assertSame('source', $log->old_values['fields']['choices']['paper']);
        $this->assertSame('target', $log->old_values['fields']['choices']['binding']);
        $this->assertSame('فرنگی', $log->old_values['fields']['before']['paper']);

        $this->actingAs($this->admin)->post("/admin/suggestions/{$s->id}/reopen")->assertSessionHas('success');
        $kept->refresh();
        $restored = Manuscript::findOrFail($dup->id);
        $this->assertSame(['فرنگی', null, false, ['مهر ب']], [$kept->paper, $kept->binding, $kept->is_illuminated, $kept->metadata['ownership_and_seals']]);
        $this->assertSame(['ترمه', 'تیماج', true], [$restored->paper, $restored->binding, $restored->is_illuminated]);
    }

    public function test_a_work_merge_from_the_direct_tool_keeps_both_notes_and_combines_lists_and_undoes()
    {
        $dup = $this->work(['primary_title' => 'اثر تکراری', 'description' => 'معرفی دوم', 'transliteration' => 'Kitab', 'bibliography' => ['الذریعة ۱/۲', 'ریحانة ۳/۴']]);
        $kept = $this->work(['primary_title' => 'اثر ماندنی', 'description' => 'معرفی نخست', 'bibliography' => ['الذریعة ۱/۲']]);

        $this->actingAs($this->admin)->get(route('admin.structure.show', ['work', $dup->id]) . '?' . http_build_query(['relation' => 'duplicate', 'target' => (string) $kept->id]))
            ->assertOk()->assertSee('مقایسه‌ی فیلدها')->assertSee('معرفی دوم')->assertSee('fields[target][description]', false);

        $this->actingAs($this->admin)->post(route('admin.structure.apply', ['work', $dup->id]), [
            'relation' => 'duplicate', 'target' => (string) $kept->id, 'keep' => 'target',
            'fields' => ['target' => ['description' => 'both']],
        ])->assertSessionHasNoErrors();

        $kept->refresh();
        $this->assertSame("معرفی نخست\n\nمعرفی دوم", $kept->description);
        $this->assertSame('Kitab', $kept->transliteration, 'an empty field is filled by default');
        $this->assertSame(['الذریعة ۱/۲', 'ریحانة ۳/۴'], $kept->bibliography);
        $this->assertContains('اثر تکراری', array_column($kept->alternative_titles, 'title'));

        $log = AuditLog::where('auditable_type', Work::class)->where('auditable_id', $kept->id)->where('action', 'merge')->latest('id')->firstOrFail();
        $this->actingAs($this->admin)->post("/admin/audit-logs/{$log->id}/rollback")->assertSessionHas('success');
        $kept->refresh();
        $this->assertSame(['معرفی نخست', null, ['الذریعة ۱/۲'], null], [$kept->description, $kept->transliteration, $kept->bibliography, $kept->alternative_titles]);
        $this->assertSame('معرفی دوم', Work::findOrFail($dup->id)->description);
    }

    public function test_a_library_merge_takes_the_chosen_description_and_its_undo_restores_it()
    {
        $dup = Library::create(['name' => 'کتابخانه‌ی دوم', 'city' => 'قم', 'slug' => 'mc-lib-' . uniqid(), 'description' => 'معرفی دقیق‌تر', 'full_name' => 'کتابخانه‌ی دوم شهر قم']);
        $kept = Library::create(['name' => 'کتابخانه‌ی اصلی', 'city' => 'قم', 'slug' => 'mc-lib-' . uniqid(), 'description' => 'معرفی کوتاه']);
        $s = $this->duplicate($dup, $kept);

        $this->actingAs($this->admin)->post("/admin/suggestions/{$s->id}/approve", ['keep' => 'target', 'fields' => ['target' => ['description' => 'source']]])
            ->assertSessionHasNoErrors();
        $kept->refresh();
        $this->assertSame(['معرفی دقیق‌تر', 'کتابخانه‌ی دوم شهر قم'], [$kept->description, $kept->full_name]);
        $this->assertContains('کتابخانه‌ی دوم', $kept->aliases);

        $this->actingAs($this->admin)->post("/admin/suggestions/{$s->id}/reopen")->assertSessionHas('success');
        $kept->refresh();
        $this->assertSame(['معرفی کوتاه', null], [$kept->description, $kept->full_name]);
        $this->assertSame('معرفی دقیق‌تر', Library::findOrFail($dup->id)->description);
    }

    public function test_a_misspelt_person_name_becomes_a_hidden_search_form_and_the_undo_takes_it_back()
    {
        $wrong = $this->person('محمدباقر مجلسى غلط', ['bio_notes' => 'زندگی‌نامه از رکورد تکراری', 'death_year_hijri' => 1110, 'is_donor' => true]);
        $right = $this->person('محمدباقر مجلسی', ['aliases' => ['علامه مجلسی'], 'bio_notes' => 'زندگی‌نامه‌ی اصلی']);
        $work = $this->work(['author_id' => $wrong->id, 'author_name' => $wrong->name]);

        // The tool, opened for the pair, shows the comparison with the name first
        $this->actingAs($this->admin)->get(route('admin.people.merge', ['source_id' => $wrong->id, 'target_id' => $right->id]))->assertOk()
            ->assertSee('مقایسه‌ی فیلدها')->assertSee('نام رکورد حذف‌شونده نادرست است', false)->assertSee('fields[name]', false);

        $this->actingAs($this->admin)->post(route('admin.people.merge'), [
            'source_id' => $wrong->id, 'target_id' => $right->id, 'compared' => "{$wrong->id}-{$right->id}",
            'fields' => ['name' => 'wrong', 'bio_notes' => 'source'],
        ])->assertSessionHasNoErrors();

        $right->refresh();
        $this->assertSame(['علامه مجلسی'], $right->aliases, 'the wrong name is not another name');
        $this->assertSame(['محمدباقر مجلسى غلط'], $right->wrong_names);
        $this->assertSame(['زندگی‌نامه از رکورد تکراری', 1110, true], [$right->bio_notes, $right->death_year_hijri, $right->is_donor]);
        $this->assertSame($right->id, $work->fresh()->author_id);
        $this->assertStringContainsString('غلط', implode(' ', $right->toSearchableArray()['aliases']), 'searchable by the wrong form');
        $this->assertContains(\App\Helpers\TextNormalizer::normalize('محمدباقر مجلسى غلط'), $work->fresh()->toSearchableArray()['author_aliases']);

        // Shown once, faintly, not among the other names
        $this->get(route('people.show', $right))->assertOk()
            ->assertSee('در برخی منابع به صورت «محمدباقر مجلسى غلط» آمده است.')
            ->assertDontSee('<x-ui.chip>محمدباقر مجلسى غلط', false);

        $log = AuditLog::where('auditable_type', Person::class)->where('action', 'merge')->latest('id')->firstOrFail();
        $this->assertSame(['wrong', ['محمدباقر مجلسى غلط']], [$log->new_values['name_choice'], $log->new_values['added_wrong_names']]);
        $this->actingAs($this->admin)->post("/admin/audit-logs/{$log->id}/rollback")->assertSessionHas('success');
        $right->refresh();
        $this->assertNull($right->wrong_names);
        $this->assertSame(['زندگی‌نامه‌ی اصلی', null, false], [$right->bio_notes, $right->death_year_hijri, $right->is_donor]);
        $this->assertSame($wrong->id, $work->fresh()->author_id);
        $this->assertSame('زندگی‌نامه از رکورد تکراری', Person::findOrFail($wrong->id)->bio_notes);
    }

    public function test_the_removed_name_may_become_the_kept_persons_name_and_an_unchecked_merge_takes_the_defaults()
    {
        $a = $this->person('ابن فهد حلی');
        $b = $this->person('ابن فهد', ['bio_notes' => 'یادداشت']);
        $this->actingAs($this->admin)->post(route('admin.people.merge'), [
            'source_id' => $a->id, 'target_id' => $b->id, 'compared' => "{$a->id}-{$b->id}", 'fields' => ['name' => 'source'],
        ])->assertSessionHasNoErrors();
        $b->refresh();
        $this->assertSame(['ابن فهد حلی', ['ابن فهد']], [$b->name, $b->aliases]);

        $log = AuditLog::where('auditable_type', Person::class)->where('action', 'merge')->latest('id')->firstOrFail();
        $this->actingAs($this->admin)->post("/admin/audit-logs/{$log->id}/rollback")->assertSessionHas('success');
        $b->refresh();
        $this->assertSame(['ابن فهد', []], [$b->name, $b->aliases]);

        // Choices posted for another pair are ignored: the defaults apply, the removed name becomes another name
        $c = $this->person('ابن فهد الحلی', ['bio_notes' => 'یادداشت دیگر']);
        $this->actingAs($this->admin)->post(route('admin.people.merge'), [
            'source_id' => $c->id, 'target_id' => $b->id, 'compared' => "{$a->id}-{$b->id}", 'fields' => ['name' => 'wrong', 'bio_notes' => 'source'],
        ])->assertSessionHasNoErrors();
        $b->refresh();
        $this->assertSame(['ابن فهد', ['ابن فهد الحلی'], 'یادداشت', null], [$b->name, $b->aliases, $b->bio_notes, $b->wrong_names]);
    }

    public function test_the_admin_moves_a_name_between_other_names_and_misspelt_forms()
    {
        $p = $this->person('شیخ طوسی', ['aliases' => ['شیخ الطائفه', 'شیخ طوسى غلط']]);
        $this->actingAs($this->admin)->put(route('admin.people.update', $p), [
            'name' => 'شیخ طوسی', 'aliases' => "شیخ الطائفه\n", 'wrong_names' => "شیخ طوسى غلط\n\n",
        ])->assertSessionHasNoErrors();
        $p->refresh();
        $this->assertSame([['شیخ الطائفه'], ['شیخ طوسى غلط']], [$p->aliases, $p->wrong_names]);
        $this->actingAs($this->admin)->get(route('admin.people.edit', $p))->assertOk()->assertSee('صورت‌های نادرست');
    }
}

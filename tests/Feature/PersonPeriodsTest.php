<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\PersonPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The periods of a person's life, entered by a reviewer on the person's page (item 139, step 14.218)
 */
class PersonPeriodsTest extends TestCase
{
    private User $admin;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'Admin Tester', 'email' => 'period-admin-' . uniqid() . '@fahares.net', 'password' => 'password123', 'role' => 'admin']);
        $this->member = User::create(['name' => 'Member Tester', 'email' => 'period-member-' . uniqid() . '@fahares.net', 'password' => 'password123', 'role' => 'user']);
    }

    private function person(string $name): Person
    {
        return Person::create(['name' => $name, 'normalized_name' => $name, 'slug' => 'period-' . md5($name . uniqid())]);
    }

    public function test_a_reviewer_adds_edits_and_removes_a_period_and_the_page_shows_it()
    {
        $person = $this->person('شخص آزمایشی دوره');
        $form = ['kind' => 'residence', 'place' => 'دارالسلطنه اصفهان', 'mode' => 'span', 'start' => 1000, 'start_to' => 1010, 'end' => 1020, 'end_to' => 1030,
            'note' => 'در محلۀ عباس‌آباد', 'source_text' => 'ریاض العلماء 3: 120', 'period_form' => 'new'];

        // only a reviewer may write; a member and a guest see no form
        $this->post(route('admin.people.periods.store', $person->id), $form)->assertRedirect(route('login'));
        $this->actingAs($this->member)->post(route('admin.people.periods.store', $person->id), $form)->assertForbidden();
        // a member sees no form, only the invitation to suggest a period (step 14.219)
        $this->actingAs($this->member)->get(route('people.show', $person))->assertOk()->assertDontSee('افزودن دوره')->assertDontSee('دوره‌ای ثبت نشده است');

        $this->actingAs($this->admin)->post(route('admin.people.periods.store', $person->id), $form)->assertRedirect();
        $period = PersonPeriod::where('person_id', $person->id)->sole();
        $this->assertSame(['residence', 'دارالسلطنه اصفهان', 'اصفهان', null, 1000, 1010, 1020, 1030, 'span', $this->admin->id],
            [$period->kind, $period->place, $period->city, $period->region, $period->period_from, $period->period_start_to, $period->period_end_from, $period->period_to, $period->period_kind, $period->created_by]);
        $log = AuditLog::where('auditable_type', Person::class)->where('auditable_id', $person->id)->latest('id')->first();
        $this->assertSame('create', $log->new_values['person_period_change']);

        // everyone reads it, with the city and the catalogue's own words
        $this->actingAs($this->member)->get(route('people.show', $person))->assertOk()->assertSee('دوره‌های زندگی')->assertSee('اقامت')
            ->assertSee('از میان ۱۰۰۰ و ۱۰۱۰ تا میان ۱۰۲۰ و ۱۰۳۰ ق')->assertSee('«دارالسلطنه اصفهان»')->assertSee('ریاض العلماء 3: 120')->assertDontSee('افزودن دوره');
        $this->actingAs($this->admin)->get(route('people.show', $person))->assertOk()->assertSee('افزودن دوره');

        // an edit: another kind, a country with no city, one event at an unknown time
        $this->actingAs($this->admin)->put(route('admin.people.periods.update', [$person->id, $period->id]),
            ['kind' => 'travel', 'place' => 'هندوستان', 'mode' => 'between', 'start' => 1040, 'end' => 1050, 'certainty' => 'probable'])->assertRedirect();
        $period->refresh();
        $this->assertSame(['travel', null, 'هند', 1040, null, null, 1050, 'between', 'probable', null],
            [$period->kind, $period->city, $period->region, $period->period_from, $period->period_start_to, $period->period_end_from, $period->period_to, $period->period_kind, $period->period_certainty, $period->note]);
        $this->get(route('people.show', $person))->assertSee('میان ۱۰۴۰ و ۱۰۵۰ ق')->assertSee('(محتمل)');

        // an open end reads «از … ق به بعد»
        $open = PersonPeriod::create(['person_id' => $person->id, 'kind' => 'office', 'note' => 'امام جمعه'] + PersonPeriod::dateColumns(['mode' => 'span', 'start' => 1252]));
        $this->get(route('people.show', $person))->assertSee('از ۱۲۵۲ ق به بعد')->assertSee('امام جمعه');
        $open->delete();

        // years out of order, and a period that says neither a place nor a note
        $this->actingAs($this->admin)->post(route('admin.people.periods.store', $person->id), ['kind' => 'study', 'place' => 'قم', 'mode' => 'span', 'start' => 1050, 'end' => 1040])->assertSessionHasErrors('start');
        $this->actingAs($this->admin)->post(route('admin.people.periods.store', $person->id), ['kind' => 'office', 'mode' => 'point', 'start' => 1050])->assertSessionHasErrors('place');
        // another person's period is not edited through this one
        $other = $this->person('شخص آزمایشی دیگر دوره');
        $this->actingAs($this->admin)->delete(route('admin.people.periods.destroy', [$other->id, $period->id]))->assertNotFound();

        $this->actingAs($this->admin)->delete(route('admin.people.periods.destroy', [$person->id, $period->id]))->assertRedirect();
        $this->assertSame(0, PersonPeriod::where('person_id', $person->id)->count());
    }

    public function test_a_member_suggests_a_period_and_a_reviewer_enters_it_on_approval()
    {
        $person = $this->person('شخص آزمایشی پیشنهاد دوره');
        $text = 'از حدود 1000 تا 1030 در اصفهان می‌زیست (ریاض العلماء 3: 120).';
        $send = fn (array $data) => $this->actingAs($this->member)->postJson('/suggestions', $data + ['type' => 'person', 'id' => $person->id, 'kind' => 'relation', 'field_name' => 'period', 'rationale_citation' => 'ریاض العلماء 3: 120']);

        // the kind of period and the text are both needed
        $send(['suggested_value' => $text])->assertStatus(422)->assertJsonValidationErrors('role');
        $send(['role' => 'residence', 'suggested_value' => ''])->assertStatus(422);
        $send(['role' => 'residence', 'suggested_value' => $text])->assertSuccessful();
        $suggestion = \App\Models\FieldSuggestion::where('suggestable_type', Person::class)->where('suggestable_id', $person->id)->sole();
        $this->assertSame(['relation', 'period', 'residence', 'pending'], [$suggestion->kind, $suggestion->field_name, $suggestion->payload['role'], $suggestion->status]);
        // the page invites a suggestion while there is no period, and a reviewer's direct tools do not list it
        $this->actingAs($this->member)->get(route('people.show', $person))->assertOk()->assertSee('افزودن اقامت، تحصیل، تدریس، سفر یا منصب');
        $this->assertNotContains('period', array_column(\App\Http\Controllers\Admin\StructureController::choices($this->admin, 'person'), 'key'));

        // the reviewer sees the period's form with the kind chosen and the text as the source, and enters place and years
        $this->actingAs($this->admin)->get(route('admin.suggestions.show', $suggestion))->assertOk()->assertSee('ثبت دورۀ زندگی')->assertSee('ریاض العلماء 3: 120');
        $this->actingAs($this->member)->post(route('admin.suggestions.approve', $suggestion), ['kind' => 'residence', 'place' => 'اصفهان', 'mode' => 'span', 'start' => 1000, 'end' => 1030])->assertForbidden();
        $this->actingAs($this->admin)->post(route('admin.suggestions.approve', $suggestion), ['kind' => 'residence', 'place' => 'اصفهان', 'mode' => 'span', 'start' => 1030, 'end' => 1000])->assertSessionHasErrors('start');
        $this->assertSame('pending', $suggestion->fresh()->status);
        $this->actingAs($this->admin)->post(route('admin.suggestions.approve', $suggestion),
            ['kind' => 'residence', 'place' => 'اصفهان', 'mode' => 'span', 'start' => 1000, 'start_to' => 1005, 'end' => 1030, 'source_text' => 'ریاض العلماء 3: 120', 'public_note' => 'سپاس'])->assertRedirect();

        $period = PersonPeriod::where('person_id', $person->id)->sole();
        $this->assertSame(['residence', 'اصفهان', 1000, 1005, 1030, 'span'], [$period->kind, $period->city, $period->period_from, $period->period_start_to, $period->period_to, $period->period_kind]);
        $suggestion->refresh();
        $this->assertSame(['approved', $period->id, 'سپاس'], [$suggestion->status, $suggestion->resolution['person_period_id'], $suggestion->public_note]);
        $log = AuditLog::where('auditable_type', Person::class)->where('auditable_id', $person->id)->where('action', 'verify')->latest('id')->first();
        $this->assertSame([$suggestion->id, 'create'], [$log->new_values['suggestion_id'], $log->new_values['person_period_change']]);
        $this->actingAs($this->member)->get(route('people.show', $person))->assertOk()->assertSee('از میان ۱۰۰۰ و ۱۰۰۵ تا ۱۰۳۰ ق')->assertSee('پیشنهاد دوره');
    }

    public function test_periods_read_from_a_biography_wait_for_a_reviewer_who_approves_or_rejects_them()
    {
        $person = $this->person('شخص آزمایشی زندگی‌نامه دوره');
        $code = 'period-test-' . uniqid();
        $source = \App\Models\Source::create(['code' => $code, 'kind' => \App\Models\Source::KIND_BIOGRAPHICAL, 'rank' => 30, 'title' => 'معجم آزمایشی دوره', 'short_name' => 'معجم آزمایشی دوره']);
        $volume = \App\Models\SourceVolume::create(['source_id' => $source->id, 'volume_number' => 2, 'facsimile_code' => $code]);
        $item = \App\Models\SourceItem::create(['source_id' => $source->id, 'source_volume_id' => $volume->id, 'volume_number' => 2, 'item_key' => '77#1', 'position' => 77, 'kind' => 'entry',
            'page_start' => 77, 'page_end' => 77, 'title' => $person->name, 'title_normalized' => $person->name, 'text_status' => 'auto',
            'text' => 'فقيه اصولي. ثم انتقل الى طهران سنة 1250، و بقى فيها. و تولى القضاء بحلب.'])->id;
        $entry = DB::table('source_entries')->insertGetId(['citable_type' => Person::class, 'citable_id' => $person->id, 'source_id' => $source->id, 'source_item_id' => $item,
            'volume_number' => 2, 'page_start' => 77, 'role' => 'entry', 'origin' => 'match', 'status' => 'unverified', 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('fahares:propose-life-periods', ['--person' => $person->id])->assertSuccessful();
        $this->assertSame(0, PersonPeriod::where('person_id', $person->id)->count());
        $this->artisan('fahares:propose-life-periods', ['--person' => $person->id, '--apply' => true])->assertSuccessful();
        $proposals = PersonPeriod::where('person_id', $person->id)->orderBy('id')->get();
        $this->assertSame([['residence', 'تهران', 1250, 1250, 'proposed', 'machine'], ['office', 'حلب', null, null, 'proposed', 'machine']],
            $proposals->map(fn ($p) => [$p->kind, $p->city, $p->period_from, $p->period_to, $p->status, $p->origin])->all());
        $this->assertSame([$entry, 'معجم آزمایشی دوره 2: 77'], [$proposals[0]->source_entry_id, $proposals[0]->source_text]);
        // a second run proposes nothing again
        $this->artisan('fahares:propose-life-periods', ['--person' => $person->id, '--apply' => true])->assertSuccessful();
        $this->assertSame(2, PersonPeriod::where('person_id', $person->id)->count());

        // readers see nothing of it; a reviewer sees it on the page and in the panel
        $this->actingAs($this->member)->get(route('people.show', $person))->assertOk()->assertDontSee('انتقل الى طهران')->assertDontSee('پیشنهادهای هوشواره');
        $this->actingAs($this->member)->get(route('admin.people.periods.proposals'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('people.show', $person))->assertOk()->assertSee('پیشنهادهای هوشواره')->assertSee('انتقل الى طهران')->assertSee('تأیید و ثبت دوره');
        $this->actingAs($this->admin)->get(route('admin.people.periods.proposals'))->assertOk()->assertSee($person->name)->assertSee('تولى القضاء بحلب');

        // saving a proposal approves it, with the reviewer's own years; rejecting keeps the row so that it does not come back
        $this->actingAs($this->admin)->put(route('admin.people.periods.update', [$person->id, $proposals[0]->id]),
            ['kind' => 'residence', 'place' => 'تهران', 'mode' => 'span', 'start' => 1250, 'end' => 1260, 'source_text' => 'معجم آزمایشی دوره 2: 77'])->assertRedirect();
        $this->actingAs($this->admin)->post(route('admin.people.periods.reject', [$person->id, $proposals[1]->id]))->assertRedirect();
        $this->assertSame([['approved', 1250, 1260, 'span'], ['rejected', null, null, null]],
            PersonPeriod::where('person_id', $person->id)->orderBy('id')->get()->map(fn ($p) => [$p->status, $p->period_from, $p->period_to, $p->period_kind])->all());
        $this->actingAs($this->member)->get(route('people.show', $person))->assertOk()->assertSee('از ۱۲۵۰ تا ۱۲۶۰ ق')->assertDontSee('حلب');
        $this->artisan('fahares:propose-life-periods', ['--person' => $person->id, '--apply' => true])->assertSuccessful();
        $this->assertSame(2, PersonPeriod::where('person_id', $person->id)->count());
        // --prune takes back a waiting proposal the reader no longer gives, and reads again one it still gives (step 14.227);
        // what a reviewer approved or rejected stays
        $stale = PersonPeriod::create(['person_id' => $person->id, 'kind' => 'study', 'status' => 'proposed', 'origin' => 'machine', 'place' => 'نجف', 'note' => 'خوانش پیشین']);
        $byHand = PersonPeriod::create(['person_id' => $person->id, 'kind' => 'travel', 'status' => 'proposed', 'place' => 'مکه']);
        \App\Models\SourceItem::whereKey($item)->update(['text' => 'فقيه اصولي. ثم انتقل الى طهران، و بقى فيها. و سكن اصفهان سنة 1270.']);
        $this->artisan('fahares:propose-life-periods', ['--person' => $person->id, '--prune' => true])->assertSuccessful();
        $this->assertNotNull($stale->fresh());
        $this->artisan('fahares:propose-life-periods', ['--person' => $person->id, '--prune' => true, '--apply' => true])->assertSuccessful();
        $this->assertNull($stale->fresh());
        $this->assertSame([['residence', 'تهران', 'approved', 1250], ['office', 'حلب', 'rejected', null], ['travel', 'مکه', 'proposed', null], ['residence', 'اصفهان', 'proposed', 1270]],
            PersonPeriod::where('person_id', $person->id)->orderBy('id')->get()->map(fn ($p) => [$p->kind, $p->city, $p->status, $p->period_from])->all());
        $byHand->delete();
        PersonPeriod::where('person_id', $person->id)->where('city', 'اصفهان')->delete();
        // an approved period is not rejected through the proposals' door
        $this->actingAs($this->admin)->post(route('admin.people.periods.reject', [$person->id, $proposals[0]->id]))->assertNotFound();
    }

    public function test_periods_follow_a_merged_person_and_come_back_with_a_rollback()
    {
        $source = $this->person('شخص آزمایشی ادغام دوره الف');
        $target = $this->person('شخص آزمایشی ادغام دوره ب');
        $period = PersonPeriod::create(['person_id' => $source->id, 'kind' => 'teaching', 'place' => 'نجف اشرف'] + PersonPeriod::dateColumns(['mode' => 'span', 'start' => 1200, 'end' => 1210]));
        $this->assertSame('نجف', $period->city);

        $this->actingAs($this->admin)->post(route('admin.people.merge'), ['source_id' => $source->id, 'target_id' => $target->id])->assertRedirect();
        $this->assertNull(Person::find($source->id));
        $this->assertSame($target->id, $period->fresh()->person_id);

        $log = AuditLog::where('action', 'merge')->where('auditable_type', Person::class)->latest('id')->first();
        $this->assertSame([$period->id], $log->old_values['transferred_period_ids'] ?? $log->new_values['transferred_period_ids'] ?? null);
    }

    public function test_the_cities_of_birth_death_and_origin_of_a_biography_are_proposed_too()
    {
        $person = $this->person('شخص آزمایشی زادگاه و وفات');
        $code = 'period-test-' . uniqid();
        $source = \App\Models\Source::create(['code' => $code, 'kind' => \App\Models\Source::KIND_BIOGRAPHICAL, 'rank' => 30, 'title' => 'معجم آزمایشی زادگاه', 'short_name' => 'معجم آزمایشی زادگاه']);
        $volume = \App\Models\SourceVolume::create(['source_id' => $source->id, 'volume_number' => 1, 'facsimile_code' => $code]);
        $item = \App\Models\SourceItem::create(['source_id' => $source->id, 'source_volume_id' => $volume->id, 'volume_number' => 1, 'item_key' => '5#1', 'position' => 5, 'kind' => 'entry',
            'page_start' => 5, 'page_end' => 5, 'title' => $person->name, 'title_normalized' => $person->name, 'text_status' => 'auto',
            'text' => 'فقيه، من أهل أستراباد. ولد ببغداد سنة 1200. و توفي بدمشق.'])->id;
        DB::table('source_entries')->insert(['citable_type' => Person::class, 'citable_id' => $person->id, 'source_id' => $source->id, 'source_item_id' => $item,
            'volume_number' => 1, 'page_start' => 5, 'role' => 'entry', 'origin' => 'match', 'status' => 'unverified', 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('fahares:propose-life-periods', ['--person' => $person->id, '--apply' => true])->assertSuccessful();
        $proposals = PersonPeriod::where('person_id', $person->id)->orderBy('kind')->get();

        $this->assertSame([['birth', 'بغداد', 'تولد'], ['death', 'دمشق', 'وفات'], ['origin', 'استرآباد', 'خاستگاه']],
            $proposals->map(fn ($p) => [$p->kind, $p->city, $p->kind_label])->all());
        $this->assertSame(['proposed', 'machine', 'معجم آزمایشی زادگاه 1: 5'], [$proposals[0]->status, $proposals[0]->origin, $proposals[0]->source_text]);
        // the clause the reading stands in, for the reviewer
        $this->assertStringContainsString('ولد ببغداد', $proposals[0]->note);
        // readers see none of it until a reviewer saves it; then it is a line of the person's page
        $this->actingAs($this->member)->get(route('people.show', $person))->assertOk()->assertDontSee('بغداد');
        $this->actingAs($this->admin)->put(route('admin.people.periods.update', [$person->id, $proposals[0]->id]),
            ['kind' => 'birth', 'place' => 'بغداد', 'mode' => 'point', 'start' => 1200, 'source_text' => 'معجم آزمایشی زادگاه 1: 5'])->assertRedirect();
        $this->actingAs($this->member)->get(route('people.show', $person))->assertOk()->assertSee('تولد')->assertSee('بغداد')->assertDontSee('دمشق');
    }

    public function test_reviewed_decisions_on_the_places_of_a_life_are_applied_by_a_command()
    {
        $person = $this->person('شخص آزمایشی داوری محل‌ها');
        $proposal = fn (string $kind, string $place, string $status = PersonPeriod::PROPOSED) => PersonPeriod::create(
            ['person_id' => $person->id, 'kind' => $kind, 'place' => $place, 'status' => $status, 'origin' => 'machine', 'note' => 'جملۀ مأخذ']);
        [$birth, $death, $origin, $wrong, $residence, $done] = [$proposal('birth', 'اصفهان'), $proposal('death', 'نجف'), $proposal('origin', 'مشهد'),
            $proposal('origin', 'نیشابور'), $proposal('residence', 'تهران'), $proposal('death', 'قم', PersonPeriod::REJECTED)];
        $file = storage_path('framework/testing-period-decisions.json');
        file_put_contents($file, json_encode([
            ['id' => $birth->id, 'verdict' => 'approve', 'place' => 'أصبهان', 'year' => 1118],
            ['id' => $death->id, 'verdict' => 'approve', 'place' => 'النجف', 'year' => null],
            ['id' => $origin->id, 'verdict' => 'approve', 'place' => 'طوس'],
            ['id' => $wrong->id, 'verdict' => 'reject', 'reason' => 'the entry is the life of another person'],
            // a period of another kind, with its kind corrected and the year it began (step 14.311)
            ['id' => $residence->id, 'verdict' => 'approve', 'place' => 'طهران', 'kind' => 'study', 'year' => 1290, 'year_is' => 'from'],
            // a reviewer's own word, an unknown row, a verdict that is none: all left alone
            ['id' => $done->id, 'verdict' => 'approve'],
            ['id' => 999999999, 'verdict' => 'reject'],
            ['id' => $birth->id, 'verdict' => 'doubt'],
        ], JSON_UNESCAPED_UNICODE));

        $this->artisan('fahares:review-life-periods', ['file' => $file])->assertSuccessful();
        $this->assertSame(PersonPeriod::PROPOSED, $birth->fresh()->status);
        $this->artisan('fahares:review-life-periods', ['file' => $file, '--apply' => true])->assertSuccessful();
        unlink($file);

        $this->assertSame(['approved', 'أصبهان', 'اصفهان', 1118, 1118], array_values($birth->fresh()->only(['status', 'place', 'city', 'period_from', 'period_to'])));
        $this->assertSame(['approved', 'النجف', 'نجف', null], array_values($death->fresh()->only(['status', 'place', 'city', 'period_from'])));
        $this->assertSame(['approved', 'طوس', 'مشهد'], array_values($origin->fresh()->only(['status', 'place', 'city'])));
        $this->assertSame('rejected', $wrong->fresh()->status);
        $this->assertSame(['approved', 'study', 'طهران', 'تهران', 1290, null], array_values($residence->fresh()->only(['status', 'kind', 'place', 'city', 'period_from', 'period_to'])));
        $this->assertSame('rejected', $done->fresh()->status);
        $log = \App\Models\AuditLog::where('auditable_type', Person::class)->where('auditable_id', $person->id)->orderBy('id')->get();
        $this->assertSame(['approve', 'approve', 'approve', 'reject', 'approve'], $log->map(fn ($l) => $l->new_values['person_period_change'])->all());
        $this->assertSame('the entry is the life of another person', $log[3]->new_values['reason']);
        // readers see the approved places, one of them without a year
        $this->actingAs($this->member)->get(route('people.show', $person))->assertOk()->assertSee('تولد')->assertSee('۱۱۱۸ ق')->assertSee('وفات')->assertSee('خاستگاه')->assertDontSee('نیشابور');
        // and a reviewer may save a period with no year by the form, of any kind (step 14.311)
        $this->actingAs($this->admin)->post(route('admin.people.periods.store', $person->id), ['kind' => 'origin', 'place' => 'کاشان', 'mode' => 'point'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('admin.people.periods.store', $person->id), ['kind' => 'residence', 'place' => 'کاشان', 'mode' => 'point'])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_a_decision_made_for_another_person_or_city_is_left_alone()
    {
        $person = $this->person('شخص آزمایشی تطبیق تصمیم');
        $proposal = fn (string $kind, string $place) => PersonPeriod::create(
            ['person_id' => $person->id, 'kind' => $kind, 'place' => $place, 'status' => PersonPeriod::PROPOSED, 'origin' => 'machine']);
        [$right, $otherPerson, $otherCity] = [$proposal('residence', 'نجف'), $proposal('study', 'قم'), $proposal('death', 'تهران')];
        $file = storage_path('framework/testing-period-decisions-match.json');
        file_put_contents($file, json_encode([
            ['id' => $right->id, 'person_id' => $person->id, 'city' => 'نجف', 'verdict' => 'approve', 'place' => 'النجف'],
            ['id' => $otherPerson->id, 'person_id' => $person->id + 1, 'city' => 'قم', 'verdict' => 'approve', 'place' => 'قم'],
            ['id' => $otherCity->id, 'person_id' => $person->id, 'city' => 'اصفهان', 'verdict' => 'reject'],
        ], JSON_UNESCAPED_UNICODE));

        $this->artisan('fahares:review-life-periods', ['file' => $file, '--apply' => true])->expectsOutputToContain('mismatch')->assertSuccessful();
        unlink($file);

        $this->assertSame(['approved', 'النجف'], array_values($right->fresh()->only(['status', 'place'])));
        $this->assertSame(PersonPeriod::PROPOSED, $otherPerson->fresh()->status);
        $this->assertSame(PersonPeriod::PROPOSED, $otherCity->fresh()->status);
    }

    public function test_birth_and_death_years_of_approved_rows_are_carried_to_a_record_that_has_none()
    {
        $row = fn (Person $person, string $kind, int $year, string $status = PersonPeriod::APPROVED) => PersonPeriod::create(
            ['person_id' => $person->id, 'kind' => $kind, 'place' => 'نجف', 'status' => $status, 'origin' => 'machine']
            + PersonPeriod::dateColumns(['mode' => 'point', 'start' => $year]));
        // a record of a century only: both years are carried, and the centuries follow
        $bare = $this->person('شخص بی سال');
        $bare->update(['life_dates_raw' => 'ق13 قمری']);
        $row($bare, 'birth', 1236);
        $row($bare, 'death', 1307);
        // a death alone moves the century on, and the century the record named stays the first of the life
        $turn = $this->person('وفات در آغاز سدۀ بعد');
        $turn->update(['life_dates_raw' => 'ق13 قمری']);
        $row($turn, 'death', 1307);
        // a record with a death: the birth that fits is carried, the death stays
        $dated = $this->person('شخص با وفات');
        $dated->update(['life_dates_raw' => '- 1272 قمری', 'death_year_hijri' => 1272]);
        $row($dated, 'birth', 1206);
        $row($dated, 'death', 1270);
        // not carried: a birth after the death, another birth in the life dates, rows that differ, a waiting proposal,
        // a death of another century, a residence
        $late = $this->person('تولد پس از وفات');
        $late->update(['death_year_hijri' => 1100]);
        $row($late, 'birth', 1150);
        $hedged = $this->person('تولد دیگر در متن');
        $hedged->update(['life_dates_raw' => '131؟ - 235 قمری', 'death_year_hijri' => 235]);
        $row($hedged, 'birth', 135);
        $two = $this->person('دو سال');
        $row($two, 'death', 1300);
        $row($two, 'death', 1305);
        $waiting = $this->person('پیشنهاد منتظر');
        $row($waiting, 'death', 1300, PersonPeriod::PROPOSED);
        $far = $this->person('سدۀ دیگر');
        $far->update(['life_dates_raw' => 'ق 10 قمری']);
        $row($far, 'death', 1300);
        $stay = $this->person('اقامت');
        $row($stay, 'residence', 1200);

        $this->artisan('fahares:carry-life-years')->assertSuccessful();
        $this->assertNull($bare->fresh()->death_year_hijri);
        $this->artisan('fahares:carry-life-years', ['--apply' => true])->assertSuccessful();

        $bare->refresh();
        $this->assertSame([1236, 1307, 14, 13], [$bare->birth_year_hijri, $bare->death_year_hijri, $bare->century_hijri, $bare->century_from]);
        $turn->refresh();
        $this->assertSame([null, 1307, 14, 13], [$turn->birth_year_hijri, $turn->death_year_hijri, $turn->century_hijri, $turn->century_from]);
        $this->assertSame([1206, 1272], [$dated->fresh()->birth_year_hijri, $dated->fresh()->death_year_hijri]);
        foreach ([$late, $hedged, $waiting, $stay] as $person) {
            $this->assertNull($person->fresh()->birth_year_hijri, $person->name);
        }
        foreach ([$two, $waiting, $far, $stay] as $person) {
            $this->assertNull($person->fresh()->death_year_hijri, $person->name);
        }
        $log = \App\Models\AuditLog::where('auditable_type', Person::class)->where('auditable_id', $bare->id)->latest('id')->first();
        $this->assertSame('fahares:carry-life-years', $log->new_values['by']);
        $this->assertNull($log->old_values['death_year_hijri']);
        // a second run finds nothing
        $logs = \App\Models\AuditLog::count();
        $this->artisan('fahares:carry-life-years', ['--apply' => true])->assertSuccessful();
        $this->assertSame($logs, \App\Models\AuditLog::count());
    }
}

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
}

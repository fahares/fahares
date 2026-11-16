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
            'note' => 'در محله‌ی عباس‌آباد', 'source_text' => 'ریاض العلماء 3: 120', 'period_form' => 'new'];

        // only a reviewer may write; a member and a guest see no form
        $this->post(route('admin.people.periods.store', $person->id), $form)->assertRedirect(route('login'));
        $this->actingAs($this->member)->post(route('admin.people.periods.store', $person->id), $form)->assertForbidden();
        $this->actingAs($this->member)->get(route('people.show', $person))->assertOk()->assertDontSee('دوره‌های زندگی');

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
}

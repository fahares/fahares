<?php

namespace Tests\Feature;

use App\Models\DateConflict;
use App\Models\FieldSuggestion;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\User;
use App\Models\Work;
use App\Review\CollectiveReview;
use App\Support\DateEstimates\Loader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * «تناقض تاریخ» in «تصحیح جمعی» (step 14.212): which conflicts enter the queue, what an answer files, when an item
 * leaves the public queue, and how a reviewer's decision settles the opinions.
 */
class DateConflictQueueTest extends TestCase
{
    private Person $author;

    private Work $work;

    private Manuscript $copy;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->author = Person::create(['name' => 'مؤلف آزمایشی صف', 'normalized_name' => 'مؤلف آزمایشی صف', 'slug' => 'dq-' . uniqid(), 'death_year_hijri' => 1280]);
        DB::table('people')->where('id', $this->author->id)->update(['death_from' => 1280, 'death_to' => 1280]);
        $this->work = Work::create(['primary_title' => 'اثر آزمایشی صف تاریخ', 'clean_title' => 'اثر آزمایشی صف تاریخ', 'slug' => 'dq-' . uniqid(),
            'volume_number' => 1, 'page_start' => 1, 'composition_date_raw' => 'تألیف 1300ق', 'composition_year_hijri' => 1300]);
        DB::table('works')->where('id', $this->work->id)->update(['composition_date_from' => 1300, 'composition_date_to' => 1300]);
        DB::table('work_people')->insert(['work_id' => $this->work->id, 'person_id' => $this->author->id, 'role' => 'author', 'position' => 0]);
        $library = Library::create(['name' => 'کتابخانه‌ی آزمایشی صف', 'city' => 'قم', 'slug' => 'dq-lib-' . uniqid()]);
        $this->copy = Manuscript::create(['work_id' => $this->work->id, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1,
            'library_id' => $library->id, 'library' => $library->name, 'city' => 'قم', 'shelfmark' => 'صف ۱', 'raw_text' => '1. قم', 'copy_date_raw' => '1299ق']);
        // a copy one year before the work: a conflict, but within the rounding between calendars
        DB::table('manuscripts')->where('id', $this->copy->id)->update(['copy_date_from' => 1299, 'copy_date_to' => 1299]);

        $this->artisan('fahares:estimate-dates', ['--apply' => true])->assertSuccessful();
    }

    /** The author died in 1280 and the work is of 1300: «a» is the composition, «b» the death. */
    private function conflict(): DateConflict
    {
        return DateConflict::where('rule', Loader::RULE_AUTHOR)->sole();
    }

    private function user(string $name, bool $scholar = false): User
    {
        $user = User::create(['name' => $name, 'email' => uniqid('dq-') . '@example.test', 'password' => 'password123', 'role' => 'user', 'is_verified_scholar' => $scholar]);
        $user->markEmailAsVerified();

        return $user;
    }

    private function admin(): User
    {
        return User::firstOrCreate(['email' => 'dq-admin@fahares.net'], ['name' => 'داور', 'password' => 'password123', 'role' => 'admin'])->forceFill(['role' => 'admin']);
    }

    private function answer(User $user, string $answer, array $extra = [])
    {
        return $this->actingAs($user)->post(route('contribute.answer', ['date', $this->conflict()->id]), ['answer' => $answer] + $extra);
    }

    private function waiting(): array
    {
        return CollectiveReview::waiting(CollectiveReview::queue('date'))->pluck('date_conflicts.id')->all();
    }

    public function test_only_open_direct_conflicts_wider_than_the_rounding_enter_the_queue()
    {
        $conflict = $this->conflict();
        $narrow = DateConflict::where('rule', Loader::RULE_COPY)->sole();
        $this->assertSame(1, $narrow->gap);
        $indirect = DateConflict::create(['conflict_key' => 'dq-indirect', 'rule' => Loader::RULE_AUTHOR, 'direct' => false, 'gap' => 50,
            'a_type' => 'work', 'a_id' => $this->work->id, 'a_field' => 'composition', 'a_from' => 1300,
            'b_type' => 'person', 'b_id' => $this->author->id, 'b_field' => 'death', 'b_to' => 1250,
            'via_type' => 'manuscript', 'via_id' => $this->copy->id, 'via_field' => 'copy', 'first_seen_at' => now(), 'last_seen_at' => now()]);

        $this->assertSame([$conflict->id], $this->waiting());
        $this->assertNull(CollectiveReview::queue('date')->find($indirect->id));

        // the section of «تصحیح جمعی» offers the queue, and it opens on the conflict
        $this->get(route('contributions.index'))->assertOk()->assertSee('data-review-queue="date"', false)->assertSee('تناقض تاریخ');
        $this->get(route('contribute.next', 'date'))->assertRedirect(route('contribute.show', ['date', $conflict->id]));
        $this->get(route('contribute.show', ['date', $narrow->id]))->assertOk()->assertSee('دیگر در صف نیست');

        // a reviewer's «خطا نیست» takes it out
        $conflict->update(['dismissed_at' => now()]);
        $this->assertSame([], $this->waiting());
    }

    public function test_the_card_shows_both_records_and_the_answers_of_the_note()
    {
        $conflict = $this->conflict();
        $this->get(route('contribute.show', ['date', $conflict->id]))->assertOk()
            ->assertSee('کدام نادرست است؟')->assertSee('تألیف پس از وفات مؤلف')
            ->assertSee('اثر آزمایشی صف تاریخ')->assertSee('مؤلف آزمایشی صف')
            ->assertSee(route('works.show', $this->work->id))->assertSee(route('people.show', $this->author->id))
            ->assertSee('متن تاریخ در فهرست')
            ->assertSee('ورود و پاسخ');

        $this->actingAs($this->user('پژوهشگر'))->get(route('contribute.show', ['date', $conflict->id]))->assertOk()
            ->assertSee('تألیف «اثر آزمایشی صف تاریخ»')->assertSee('وفات «مؤلف آزمایشی صف»')
            ->assertSee('این اثر از این شخص نیست')->assertSee('هیچ‌کدام؛ هر دو درست‌اند');
    }

    public function test_a_guest_cannot_answer_and_an_unknown_answer_is_refused()
    {
        $conflict = $this->conflict();
        $this->post(route('contribute.answer', ['date', $conflict->id]), ['answer' => 'a'])->assertRedirect(route('login'));
        $this->answer($this->user('پژوهشگر'), 'maybe')->assertSessionHasErrors('answer');
        $this->assertSame(0, FieldSuggestion::count());
    }

    public function test_an_answer_is_a_pending_suggestion_on_the_record_it_blames_and_changes_nothing()
    {
        $conflict = $this->conflict();
        $user = $this->user('پژوهشگر');

        $this->answer($user, 'b', ['note' => 'وفات او 1320 است.'])->assertRedirect(route('contribute.next', 'date'));
        $opinion = FieldSuggestion::sole();
        $this->assertSame(['date', $conflict->id, 'b', $conflict->id, 'pending', 'general'],
            [$opinion->review_queue, (int) $opinion->review_item_id, $opinion->review_answer, (int) $opinion->date_conflict_id, $opinion->status, $opinion->kind]);
        $this->assertSame([Person::class, $this->author->id], [$opinion->suggestable_type, (int) $opinion->suggestable_id]);
        $this->assertStringContainsString('وفات «مؤلف آزمایشی صف» نادرست است', $opinion->suggested_value);
        $this->assertSame('وفات او 1320 است.', $opinion->rationale_citation);
        $this->assertSame(1280, (int) $this->author->fresh()->death_year_hijri);

        // a new answer replaces the earlier one; the tie is the work's to mend
        $this->answer($user, 'tie');
        $opinion = FieldSuggestion::sole();
        $this->assertSame(['tie', Work::class, $this->work->id], [$opinion->review_answer, $opinion->suggestable_type, (int) $opinion->suggestable_id]);
        $this->assertStringContainsString('این اثر از این شخص نیست', $opinion->suggested_value);

        // the panel's list of conflicts counts the answer
        $this->actingAs($this->admin())->get(route('admin.date-conflicts.index'))->assertOk()
            ->assertSee('data-collective-tally', false)->assertSee('این اثر از این شخص نیست.');
    }

    public function test_enough_agreeing_weight_takes_the_conflict_out_of_the_public_queue()
    {
        $conflict = $this->conflict();
        // two different answers of weight 1 and 2: no agreement of 3 yet
        $this->answer($this->user('یکم'), 'a');
        $this->answer($this->user('دوم', scholar: true), 'b');
        $this->assertSame([$conflict->id], $this->waiting());

        // a second voice for «b»: 2 + 1 = ENOUGH
        $this->answer($this->user('سوم'), 'b');
        $this->assertSame([], $this->waiting());
        $this->assertSame(CollectiveReview::ENOUGH, (int) CollectiveReview::agreement('date')->first()->agreement);
        $this->actingAs($this->user('چهارم'))->get(route('contribute.next', 'date'))->assertOk()->assertViewIs('contribute.done');

        // nothing was decided: the opinions wait for the reviewer and the dates stand
        $this->assertSame(3, FieldSuggestion::where('status', 'pending')->count());
        $this->assertSame(1280, (int) $this->author->fresh()->death_year_hijri);
        $this->assertNull($conflict->fresh()->dismissed_at);
    }

    /** Past answers of «تصحیح جمعی» that reviewers decided: $agreed approved, the rest rejected. */
    private function record(User $user, int $settled, int $agreed): void
    {
        for ($i = 0; $i < $settled; $i++) {
            FieldSuggestion::create(['suggestable_type' => Work::class, 'suggestable_id' => $this->work->id, 'kind' => 'general', 'suggested_value' => 'پاسخ پیشین',
                'review_queue' => 'catalog', 'review_item_id' => 900000 + $i, 'review_answer' => 'yes', 'user_id' => $user->id,
                'guest_name' => $user->name, 'guest_email' => $user->email, 'status' => $i < $agreed ? 'approved' : 'rejected']);
        }
    }

    public function test_the_weight_follows_the_track_record_over_every_queue()
    {
        $this->assertSame([1, 2], [CollectiveReview::weightOf(false), CollectiveReview::weightOf(true)]);
        // too few decided answers say nothing; then 60% earns 2 and 80% earns 3
        $this->assertSame(1, CollectiveReview::weightOf(false, 9, 9));
        $this->assertSame([1, 2, 3], [CollectiveReview::weightOf(false, 10, 5), CollectiveReview::weightOf(false, 10, 6), CollectiveReview::weightOf(false, 10, 8)]);
        // a scholar keeps the scholar's weight whatever the record, and a good record raises it
        $this->assertSame([2, 3], [CollectiveReview::weightOf(true, 20, 2), CollectiveReview::weightOf(true, 20, 16)]);

        $conflict = $this->conflict();
        $trusted = $this->user('کارآزموده');
        $this->record($trusted, 10, 8);
        $fair = $this->user('میانه');
        $this->record($fair, 10, 6);
        $new = $this->user('تازه‌وارد');
        $this->record($new, 9, 9);
        $this->assertSame([3, 2, 1], [CollectiveReview::weight($trusted), CollectiveReview::weight($fair), CollectiveReview::weight($new)]);

        // 2 + 1 for «a»: the SQL count agrees with the PHP one, and the item leaves the public queue
        $this->answer($fair, 'a');
        $this->assertSame([$conflict->id], $this->waiting());
        $this->answer($new, 'a');
        $this->assertSame(3, (int) CollectiveReview::agreement('date')->first()->agreement);
        $this->assertSame(3, CollectiveReview::opinionsOn([['date', $conflict->id]])["date:{$conflict->id}"]['weight']);
        $this->assertSame([], $this->waiting());

        // the desk shows whose opinion weighs more, and the researcher sees their own weight
        $this->actingAs($this->admin())->get(route('admin.suggestions.show', FieldSuggestion::where('user_id', $fair->id)->where('status', 'pending')->sole()))
            ->assertOk()->assertSee('data-opinion-weight', false)->assertSee('وزن ۲', false);
        $this->actingAs($trusted)->get(route('contributions.index'))->assertOk()->assertSee('وزن نظر شما');
    }

    public function test_one_trusted_researcher_alone_reaches_enough()
    {
        $conflict = $this->conflict();
        $trusted = $this->user('کارآزموده');
        $this->record($trusted, 12, 11);

        $this->answer($trusted, 'b');
        $this->assertSame([], $this->waiting());
        // still only an opinion: the reviewer decides
        $this->assertSame('pending', FieldSuggestion::where('review_queue', 'date')->sole()->status);
        $this->assertNull($conflict->fresh()->dismissed_at);
    }

    public function test_approving_an_answer_on_the_desk_settles_the_rest_and_keeps_the_conflict_out()
    {
        $conflict = $this->conflict();
        $this->answer($this->user('یکم'), 'b');
        $this->answer($this->user('دوم'), 'b');
        $this->answer($this->user('سوم'), 'none');
        $first = FieldSuggestion::orderBy('id')->first();

        $this->actingAs($this->admin())->get(route('admin.suggestions.show', $first))->assertOk()
            ->assertSee('data-collective-opinions', false)->assertSee('پاسخ به یک ناسازگاری تاریخ');
        $this->actingAs($this->admin())->post(route('admin.suggestions.approve', $first))->assertSessionHasNoErrors();

        $this->assertSame(['approved', 'approved', 'rejected'], FieldSuggestion::orderBy('id')->pluck('status')->all());
        // the nightly run still finds the conflict until the death is mended, but the queue does not ask again
        $this->assertNull($conflict->fresh()->dismissed_at);
        $this->assertSame([], $this->waiting());
        $this->assertNull(CollectiveReview::queue('date')->find($conflict->id));
    }

    public function test_dismissing_the_conflict_in_the_panel_settles_the_opinions_as_both_right()
    {
        $conflict = $this->conflict();
        $this->answer($this->user('یکم'), 'a');
        $this->answer($this->user('دوم'), 'none');

        $this->actingAs($this->admin())->post(route('admin.date-conflicts.dismiss', $conflict), ['note' => 'کاتبِ متأخر'])->assertSessionHasNoErrors();

        $this->assertNotNull($conflict->fresh()->dismissed_at);
        $this->assertSame(['rejected', 'approved'], FieldSuggestion::orderBy('id')->pluck('status')->all());
        $this->assertSame([], $this->waiting());
    }
}

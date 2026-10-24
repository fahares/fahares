<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FieldSuggestion;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\SourceVolume;
use App\Models\User;
use App\Models\Work;
use App\Review\CatalogMatchQueue;
use App\Review\CollectiveReview;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * «تصحیح جمعی» (backlog item 80): researchers answer the panel's review queues; an answer is a pending suggestion that
 * changes nothing until a reviewer decides, and the decision settles every opinion on the item.
 */
class CollectiveReviewTest extends TestCase
{
    protected SourceVolume $edition;

    protected Work $work;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Artisan::call('sources:backfill', ['--only' => ['registry']]);
        $library = Library::create(['name' => 'مسجد اعظم', 'city' => 'قم', 'slug' => 'masjid-azam-' . uniqid()]);
        $this->work = Work::create(['primary_title' => 'آتشکده آذر', 'clean_title' => 'آتشکده آذر', 'slug' => 'work-cr-' . uniqid(), 'volume_number' => 1, 'page_start' => 10]);
        $source = Source::create(['code' => 'cr-edition-' . uniqid(), 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'title' => 'فهرست نسخه‌های خطی کتابخانه مسجد اعظم قم', 'library_id' => $library->id]);
        $this->edition = SourceVolume::create(['source_id' => $source->id, 'volume_number' => 2, 'facsimile_code' => 'masjid-azam-ostadi', 'edition_label' => '۱۳۸۸']);
    }

    protected function match(string $shelfmark = '4039', int $confidence = 90): SourceEntry
    {
        $ms = Manuscript::create(['work_id' => $this->work->id, 'shelfmark' => $shelfmark, 'volume_number' => 15, 'page_start' => 121, 'raw_text' => "شماره نسخه: {$shelfmark}"]);

        return SourceEntry::create(['citable_type' => Manuscript::class, 'citable_id' => $ms->id, 'source_id' => $this->edition->source_id,
            'source_volume_id' => $this->edition->id, 'volume_number' => 2, 'page_start' => 5, 'origin' => SourceEntry::ORIGIN_MATCH, 'confidence' => $confidence]);
    }

    protected function user(string $name, bool $scholar = false): User
    {
        return User::create(['name' => $name, 'email' => uniqid('cr-') . '@example.test', 'password' => 'password123', 'role' => 'user', 'is_verified_scholar' => $scholar]);
    }

    protected function admin(): User
    {
        return User::firstOrCreate(['email' => 'cr-admin@fahares.net'], ['name' => 'داور', 'password' => 'password123', 'role' => 'admin'])->forceFill(['role' => 'admin']);
    }

    protected function answer(User $user, SourceEntry $entry, string $answer, array $extra = [])
    {
        return $this->actingAs($user)->post(route('contribute.answer', ['catalog', $entry->id]), ['answer' => $answer] + $extra);
    }

    public function test_the_page_is_renamed_and_offers_the_section()
    {
        $this->match();
        $this->get(route('contributions.index'))->assertOk()
            ->assertSee('اصلاحات و تعلیقات')->assertDontSee('اصلاحات پژوهشگران')
            ->assertSee('id="review"', false)->assertSee(config('contributions.review_title'))
            ->assertSee(route('contribute.next', 'catalog'));
        $this->get('/contribute')->assertRedirect(route('contributions.index') . '#review');
        $this->assertStringNotContainsString('تصحیح', route('contribute.next', 'catalog'));
    }

    public function test_a_guest_sees_the_card_but_cannot_answer()
    {
        $entry = $this->match();
        $this->get(route('contribute.next', 'catalog'))->assertRedirect(route('contribute.show', ['catalog', $entry->id]));
        $this->get(route('contribute.show', ['catalog', $entry->id]))->assertOk()
            ->assertSee('آیا این نسخه همان است')->assertSee('ورود و پاسخ')
            ->assertDontSee('ai_match')->assertDontSee('shelfmark_title');

        $this->post(route('contribute.answer', ['catalog', $entry->id]), ['answer' => 'yes'])->assertRedirect(route('login'));
        $this->assertSame(0, FieldSuggestion::count());
    }

    public function test_the_card_shows_the_opening_and_closing_words_and_credits_by_default()
    {
        $entry = $this->match();
        $entry->citable->update(['incipit_text' => 'بسم الله آغاز تذکره', 'explicit_text' => 'تمام شد انجام تذکره', 'script_names' => 'نستعلیق']);
        $user = $this->user('پژوهشگر');
        $this->actingAs($user)->get(route('contribute.show', ['catalog', $entry->id]))->assertOk()
            ->assertSee('بسم الله آغاز تذکره')->assertSee('تمام شد انجام تذکره')->assertSee('نستعلیق')
            ->assertSee('name="credit" value="1" checked', false);

        // Unticked once, the box stays unticked for the session's next cards
        $this->answer($user, $entry, 'yes', ['credit' => 0]);
        $this->assertNull(FieldSuggestion::sole()->credit_name);
        $other = $this->match('200');
        $this->actingAs($user)->get(route('contribute.show', ['catalog', $other->id]))->assertDontSee('name="credit" value="1" checked', false);
    }

    public function test_an_answer_is_a_pending_suggestion_that_changes_nothing()
    {
        $entry = $this->match();
        $logs = AuditLog::count();
        $user = $this->user('پژوهشگر');

        $this->answer($user, $entry, 'no', ['note' => 'شماره در برگه نیست', 'credit' => 1])->assertRedirect(route('contribute.next', 'catalog'));

        $s = FieldSuggestion::sole();
        $this->assertSame(['field', 'source_entry_' . $entry->id, 'catalog', $entry->id, 'no', 'نیست', 'ج ۲، ص ۵', 'pending', 'شماره در برگه نیست', 'پژوهشگر'],
            [$s->kind, $s->field_name, $s->review_queue, (int) $s->review_item_id, $s->review_answer, $s->suggested_value, $s->current_value, $s->status, $s->rationale_citation, $s->credit_name]);
        $entry->refresh();
        $this->assertSame([SourceEntry::STATUS_UNVERIFIED, SourceEntry::ORIGIN_MATCH, 5], [$entry->status, $entry->origin, $entry->page_start]);
        $this->assertSame($logs, AuditLog::count(), 'an answer writes no audit log: nothing was changed');

        // One opinion per user and item: a new answer replaces it
        $this->answer($user, $entry, 'page', ['page' => '۴۵']);
        $s = FieldSuggestion::sole();
        $this->assertSame(['page:ج ۲، ص ۴۵', 'ج ۲، ص ۴۵'], [$s->review_answer, $s->suggested_value]);
        $this->assertSame(5, $entry->fresh()->page_start);

        $this->answer($user, $entry, 'page', ['page' => 'ص ۵'])->assertSessionHasErrors('page');
        $this->answer($user, $entry, 'maybe')->assertSessionHasErrors('answer');
    }

    public function test_the_queue_skips_what_the_user_answered_and_what_has_enough_opinions()
    {
        $first = $this->match('100', 90);
        $second = $this->match('200', 80);
        $alice = $this->user('آلیس');
        $this->answer($alice, $first, 'yes');
        $this->get(route('contribute.next', 'catalog'))->assertRedirect(route('contribute.show', ['catalog', $second->id]));

        // A verified scholar (2) and another researcher (1) agree: the item has enough and leaves the public queue
        $bob = $this->user('باب');
        $this->actingAs($bob)->get(route('contribute.next', 'catalog'))->assertRedirect(route('contribute.show', ['catalog', $first->id]));
        $this->answer($this->user('استاد', scholar: true), $first, 'yes');
        $this->assertSame(1, CollectiveReview::waiting(app(CatalogMatchQueue::class))->count());
        $this->actingAs($bob)->get(route('contribute.next', 'catalog'))->assertRedirect(route('contribute.show', ['catalog', $second->id]));

        // A skipped item does not come back in the session
        $this->actingAs($bob)->post(route('contribute.skip', ['catalog', $second->id]))->assertRedirect(route('contribute.next', 'catalog'));
        $this->actingAs($bob)->get(route('contribute.next', 'catalog'))->assertOk()->assertSee('موردی برای شما نمانده');
    }

    public function test_agreeing_opinions_lead_the_desk_and_stand_side_by_side()
    {
        $lone = $this->match('100');
        $agreed = $this->match('200');
        $this->answer($this->user('یک'), $agreed, 'yes');
        $this->answer($this->user('دو', scholar: true), $agreed, 'yes');
        $this->answer($this->user('سه'), $agreed, 'no');
        $this->answer($this->user('چهار'), $lone, 'no');

        $admin = $this->admin();
        $admin->save();
        $ids = $this->actingAs($admin)->get(route('admin.suggestions.index'))->assertOk()->viewData('suggestions')->pluck('review_item_id')->map(fn ($id) => (int) $id)->all();
        $this->assertSame([$agreed->id, $agreed->id, $agreed->id, $lone->id], $ids, 'weight 3 for «yes» puts that item first');

        $opinion = FieldSuggestion::where('review_item_id', $agreed->id)->where('review_answer', 'no')->sole();
        $this->actingAs($admin)->get(route('admin.suggestions.show', $opinion))->assertOk()
            ->assertSee('نظرهای پژوهشگران درباره‌ی همین مورد')->assertSee('همین نسخه است')->assertSee('وزن ۳', false)->assertSee('پژوهشگر تأییدشده');
    }

    public function test_a_reviewer_decision_in_the_queue_settles_the_opinions()
    {
        $entry = $this->match();
        $yes = $this->user('موافق');
        $no = $this->user('مخالف');
        $this->answer($yes, $entry, 'yes', ['credit' => 1]);
        $this->answer($no, $entry, 'no');

        $admin = $this->admin();
        $admin->save();
        $this->actingAs($admin)->post(route('admin.catalog-references.verify', $entry))->assertSessionHas('success');

        $this->assertSame('approved', FieldSuggestion::where('user_id', $yes->id)->sole()->status);
        $this->assertSame('rejected', FieldSuggestion::where('user_id', $no->id)->sole()->status);
        $this->assertSame(SourceEntry::ORIGIN_MATCH, $entry->fresh()->origin);
        $this->get(route('contributions.index'))->assertSee('موافق')->assertSee('همین نسخه است');
        $this->get(route('contribute.show', ['catalog', $entry->id]))->assertSee('داوری این مورد پایان یافته');
    }

    public function test_approving_an_opinion_on_the_desk_verifies_the_match_and_settles_the_rest()
    {
        $entry = $this->match();
        $this->answer($this->user('یک'), $entry, 'yes');
        $this->answer($this->user('دو'), $entry, 'yes');
        $this->answer($this->user('سه'), $entry, 'no');
        $first = FieldSuggestion::where('review_answer', 'yes')->orderBy('id')->first();

        $admin = $this->admin();
        $admin->save();
        $this->actingAs($admin)->post(route('admin.suggestions.approve', $first), ['applied_value' => $first->suggested_value, 'outcome' => 'adopt'])->assertSessionHasNoErrors();

        $entry->refresh();
        $this->assertSame([SourceEntry::STATUS_VERIFIED, SourceEntry::ORIGIN_MATCH, 5], [$entry->status, $entry->origin, $entry->page_start]);
        $this->assertSame(['approved', 'approved', 'rejected'], FieldSuggestion::orderBy('id')->pluck('status')->all());
    }

    public function test_only_reviewers_decide()
    {
        $entry = $this->match();
        $user = $this->user('پژوهشگر', scholar: true);
        $this->answer($user, $entry, 'yes');
        $opinion = FieldSuggestion::sole();

        $this->actingAs($user)->post(route('admin.catalog-references.verify', $entry))->assertForbidden();
        $this->actingAs($user)->post(route('admin.suggestions.approve', $opinion), ['outcome' => 'adopt'])->assertForbidden();
        $this->assertSame([SourceEntry::STATUS_UNVERIFIED, 'pending'], [$entry->fresh()->status, $opinion->fresh()->status]);
    }

    public function test_answers_are_rate_limited()
    {
        $user = $this->user('شتاب‌زده');
        $entries = collect(range(1, 41))->map(fn ($i) => $this->match((string) (1000 + $i)));
        foreach ($entries->take(40) as $entry) {
            $this->answer($user, $entry, 'yes')->assertRedirect();
        }
        $this->answer($user, $entries->last(), 'yes')->assertStatus(429);
        $this->assertSame(40, FieldSuggestion::count());
    }
}

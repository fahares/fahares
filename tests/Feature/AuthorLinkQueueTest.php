<?php

namespace Tests\Feature;

use App\Models\FieldSuggestion;
use App\Models\Person;
use App\Models\PersonReview;
use App\Models\User;
use App\Models\Work;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Step 14.17: works whose author is a name only, with the one person they may be, go to «تصحیح جمعی» and to the panel's
 * «پیوند پدیدآور»; only the reviewer's decision links the person.
 */
class AuthorLinkQueueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['scout.driver' => 'collection']);
    }

    /** A work with a text-only author and the review item the queue holds for it (as the one-off queueing wrote it) */
    private function candidate(): array
    {
        $person = Person::create(['name' => 'بزرگمهر', 'normalized_name' => 'بزرگمهر', 'slug' => 'bozorgmehr-' . uniqid(), 'death_year_hijri' => 550]);
        $work = Work::create(['primary_title' => 'پندنامه', 'clean_title' => 'پندنامه', 'slug' => 'pand-' . uniqid(), 'volume_number' => 99, 'page_start' => 1, 'author_name' => 'بزرگمهر']);
        $review = PersonReview::create(['type' => 'author_link', 'key' => 'author_link:work:' . $work->id, 'person_ids' => [$person->id], 'status' => 'open',
            'reason' => 'نام و آوانویسی یکی است', 'suggestion' => ['work_id' => $work->id, 'translit' => 'bozorgmehr']]);

        return [$work, $person, $review];
    }

    public function test_a_candidate_is_queued_answered_and_linked_only_by_the_reviewer()
    {
        [$work, $person, $review] = $this->candidate();

        // the public card, then an answer that changes nothing
        $this->get(route('contribute.show', ['author', $review->id]))->assertOk()->assertSee('پندنامه')->assertSee('bozorgmehr');
        $user = User::create(['name' => 'پژوهشگر', 'email' => uniqid('al-') . '@example.test', 'password' => 'password123', 'role' => 'user']);
        $user->markEmailAsVerified();
        $this->actingAs($user)->post(route('contribute.answer', ['author', $review->id]), ['answer' => 'yes'])->assertRedirect();
        $opinion = FieldSuggestion::sole();
        $this->assertSame(['relation', 'author_is', $person->id, 'pending'], [$opinion->kind, $opinion->field_name, $opinion->payload['target_id'], $opinion->status]);
        $this->assertNull($work->fresh()->author_id);

        // the reviewer links, which settles the opinion
        $admin = User::create(['name' => 'داور', 'email' => uniqid('al-admin-') . '@example.test', 'password' => 'password123', 'role' => 'admin']);
        $this->actingAs($admin)->get(route('admin.author-links.index'))->assertOk()->assertSee('پندنامه');
        $this->actingAs($admin)->post(route('admin.author-links.link', $review))->assertRedirect();
        $this->assertSame($person->id, (int) $work->fresh()->author_id);
        $this->assertSame('linked', $review->fresh()->status);
        $this->assertNotSame('pending', $opinion->fresh()->status);
    }

    public function test_the_reviewer_may_keep_the_name()
    {
        [$work, , $review] = $this->candidate();
        $admin = User::create(['name' => 'داور', 'email' => uniqid('al-admin-') . '@example.test', 'password' => 'password123', 'role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.author-links.reject', $review))->assertRedirect();
        $this->assertSame(['not_linked', null, 'بزرگمهر'], [$review->fresh()->status, $work->fresh()->author_id, $work->fresh()->author_name]);
    }
}

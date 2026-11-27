<?php

namespace Tests\Feature;

use App\Models\Manuscript;
use App\Models\Person;
use App\Models\PersonReview;
use App\Models\Work;
use App\Review\MixedRecordQueue;
use App\Services\PersonReviewQueue;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Backlog item 125 (step 14.430): a record of several namesake scribes is told by the years its copies surely lie apart
 * (people.scribed_gap), not by its first and last year, which a copy dated by its century alone stretches.
 */
class MixedScribesTest extends TestCase
{
    private array $people = [];

    private array $works = [];

    protected function tearDown(): void
    {
        PersonReview::whereIn('key', array_map(fn ($id) => "person:{$id}", $this->people))->delete();
        Manuscript::whereIn('work_id', $this->works)->delete();
        Work::whereIn('id', $this->works)->delete();
        Person::whereIn('id', $this->people)->delete();

        parent::tearDown();
    }

    private function person(string $name): Person
    {
        $p = Person::create(['name' => "آزمونک قاطی {$name}", 'normalized_name' => "آزمونک قاطی {$name}", 'slug' => 'test-mixed-' . uniqid(), 'is_scribe' => true]);
        $this->people[] = $p->id;

        return $p;
    }

    /** A copy by the scribe dated from one year to another (a century: 1001 to 1100) */
    private function copy(Person $scribe, int $from, ?int $to = null): Manuscript
    {
        $to ??= $from;
        $work = Work::create(['primary_title' => 'اثر آزمایشی قاطی ' . uniqid(), 'clean_title' => 'اثر آزمایشی قاطی',
            'slug' => 'test-mixed-work-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $this->works[] = $work->id;
        $copy = Manuscript::create(['work_id' => $work->id, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1,
            'scribe_id' => $scribe->id, 'scribe_name' => $scribe->name, 'raw_text' => 'نسخۀ آزمایشی']);
        DB::table('manuscripts')->where('id', $copy->id)->update(['copy_date_from' => $from, 'copy_date_to' => $to,
            'copy_date_hijri_year' => $from === $to ? $from : null]);
        Person::recountMany([$scribe->id]);

        return $copy;
    }

    public function test_the_gap_is_what_the_copies_surely_lie_apart()
    {
        $century = $this->person('سده');
        $this->copy($century, 1001, 1100);
        $century->refresh();
        $this->assertSame([1001, 1100, 0], [$century->scribed_from, $century->scribed_to, $century->scribed_gap]);
        $this->assertFalse($century->isMixedScribe(), 'one copy of «قرن ۱۱» is no namesake');
        $this->assertSame('کتابت سدۀ ۱۱ ق', $century->scribed_span);

        $near = $this->person('نزدیک');
        $this->copy($near, 1001, 1100);
        $this->copy($near, 1150);
        $this->assertSame(50, $near->fresh()->scribed_gap);
        $this->assertFalse($near->fresh()->isMixedScribe());

        $far = $this->person('دور');
        $this->copy($far, 301, 400);
        $this->copy($far, 1219);
        $this->assertSame(819, $far->fresh()->scribed_gap);
        $this->assertTrue($far->fresh()->isMixedScribe());

        // the nightly sync finds a gap out of step, and mends it
        $ids = [$century->id, $near->id, $far->id];
        $this->assertSame([], Person::roleDrift()->whereIn('id', $ids)->pluck('id')->all());
        DB::table('people')->whereIn('id', $ids)->update(['scribed_gap' => null]);
        $this->assertSame($ids, Person::roleDrift()->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all());
        Person::recountMany($ids);
        $this->assertSame([], Person::roleDrift()->whereIn('id', $ids)->pluck('id')->all());
    }

    public function test_the_queue_takes_century_dated_copies_and_puts_the_widest_gap_first()
    {
        $single = $this->person('تک');
        $this->copy($single, 1001, 1100);
        $wide = $this->person('پهن');
        $this->copy($wide, 301, 400);
        $this->copy($wide, 1219);
        $narrow = $this->person('تنگ');
        $this->copy($narrow, 1050);
        $this->copy($narrow, 1201, 1300);

        app(PersonReviewQueue::class)->refresh();
        $this->assertNull(PersonReview::where('type', 'mixed')->where('key', "person:{$single->id}")->first());
        $wideReview = PersonReview::where('type', 'mixed')->where('key', "person:{$wide->id}")->firstOrFail();
        $narrowReview = PersonReview::where('type', 'mixed')->where('key', "person:{$narrow->id}")->firstOrFail();
        $this->assertSame('copies 400–1219 (2)', $wideReview->reason);

        $queue = new MixedRecordQueue;
        $order = $queue->order($queue->open()->whereIn('person_reviews.id', [$wideReview->id, $narrowReview->id]))->pluck('person_reviews.id')->all();
        $this->assertSame([$wideReview->id, $narrowReview->id], $order);
    }

    public function test_the_fill_command_writes_the_gaps_of_the_people_already_there()
    {
        $p = $this->person('پرکردن');
        $this->copy($p, 1050);
        $this->copy($p, 1201, 1300);
        DB::table('people')->where('id', $p->id)->update(['scribed_gap' => null]);

        $this->artisan('fahares:fill-scribed-gaps')->assertSuccessful();
        $this->assertNull($p->fresh()->scribed_gap, 'a dry run writes nothing');
        $this->artisan('fahares:fill-scribed-gaps', ['--apply' => true])->assertSuccessful();
        $this->assertSame(151, $p->fresh()->scribed_gap);
        $this->assertSame('open', PersonReview::where('type', 'mixed')->where('key', "person:{$p->id}")->value('status'));
    }

    public function test_the_machine_suggests_the_smaller_side_of_the_widest_gap()
    {
        $rows = fn (array $dates) => collect($dates)->map(fn ($d, $id) => (object) ['id' => $id, 'date_from' => $d[0], 'date_to' => $d[1] ?? $d[0]]);

        $this->assertSame([3], MixedRecordQueue::suggestedSplit($rows([1 => [1050], 2 => [1060], 3 => [1250]])));
        $this->assertSame([1], MixedRecordQueue::suggestedSplit($rows([1 => [1050], 2 => [1300], 3 => [1310]])));
        // as large on both sides: the later ones
        $this->assertSame([3, 4], MixedRecordQueue::suggestedSplit($rows([1 => [900], 2 => [910], 3 => [1200], 4 => [1210]])));
        // a century bridges no gap it does not lie in: «قرن ۱۲» ends at 1200, 100 years before 1300
        $this->assertSame([3], MixedRecordQueue::suggestedSplit($rows([1 => [1050], 2 => [1101, 1200], 3 => [1300]])));
        // no gap over the span, and copies without a date
        $this->assertSame([], MixedRecordQueue::suggestedSplit($rows([1 => [1050], 2 => [1001, 1100], 3 => [1110]])));
        $this->assertSame([], MixedRecordQueue::suggestedSplit($rows([1 => [1050], 2 => [null]])));
    }

    public function test_the_card_marks_the_suggestion_and_the_person_page_links_to_it()
    {
        $p = $this->person('برگه');
        $this->copy($p, 1050);
        $this->copy($p, 1060);
        $late = $this->copy($p, 1201, 1300);
        app(PersonReviewQueue::class)->refresh();
        $review = PersonReview::where('type', 'mixed')->where('key', "person:{$p->id}")->firstOrFail();

        $html = $this->get(route('contribute.show', ['mixed', $review->id]))->assertOk()
            ->assertSee('پیشنهاد ماشین')->assertSee('سدۀ ۱۳')->getContent();
        $this->assertMatchesRegularExpression('/value="' . $late->id . '"[^>]*checked/', $html);
        $this->assertSame(1, preg_match_all('/name="copies\[\]"[^>]*checked/', $html));

        $this->get(route('people.show', $p))->assertOk()->assertSee('چند کاتب هم‌نام؟')->assertSee('۱۵۱ سال')
            ->assertSee(route('contribute.show', ['mixed', $review->id]), false);
    }
}

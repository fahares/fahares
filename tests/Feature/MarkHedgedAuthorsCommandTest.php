<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Work;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Step 14.38: the catalogue's «؟» on a work's author is kept as the author row's certainty and shown as «(؟)». */
class MarkHedgedAuthorsCommandTest extends TestCase
{
    public function test_a_hedged_author_line_marks_the_linked_author_and_the_page_shows_it()
    {
        config(['scout.driver' => 'collection']);
        $person = Person::create(['name' => 'آخوند خراسانی، محمد کاظم بن حسین', 'normalized_name' => 'آخوند خراسانی، محمد کاظم بن حسین', 'slug' => 'akhund-' . uniqid()]);
        $hedged = Work::create(['primary_title' => 'الاجارة', 'clean_title' => 'الاجارة', 'slug' => 'ijara-' . uniqid(), 'volume_number' => 99, 'page_start' => 1,
            'author_id' => $person->id, 'author_name' => $person->name,
            'raw_text' => "● الاجارة / فقه / عربی\nal-ijāra\n\n؟ آخوند خراسانی، محمد کاظم بن حسین، 1255 - 1329 قمری\nāxūnd-e xorāsānī"]);
        $sure = Work::create(['primary_title' => 'کفایة الاصول', 'clean_title' => 'کفایة الاصول', 'slug' => 'kifaya-' . uniqid(), 'volume_number' => 99, 'page_start' => 2,
            'author_id' => $person->id, 'author_name' => $person->name,
            'raw_text' => "● کفایة الاصول / اصول / عربی\nkifāyat-ul uṣūl\n\nآخوند خراسانی، محمد کاظم بن حسین\nāxūnd"]);
        $bare = Work::create(['primary_title' => 'الکیمیا', 'clean_title' => 'الکیمیا', 'slug' => 'kimiya-' . uniqid(), 'volume_number' => 99, 'page_start' => 3,
            'description' => "؟ مصری، عبدالمجید\nmesrī, ‘abd-ol-majīd\n\nاین رساله مختصری است"]);
        $file = tempnam(sys_get_temp_dir(), 'hedged');
        file_put_contents($file, json_encode(['items' => [['work_slug' => $bare->slug, 'name' => 'مصری، عبدالمجید', 'hedge' => '؟',
            'description_from' => $bare->description, 'description_to' => 'این رساله مختصری است']]], JSON_UNESCAPED_UNICODE));

        $this->artisan('fahares:mark-hedged-authors', ['--file' => $file])->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->artisan('fahares:mark-hedged-authors', ['--file' => $file, '--apply' => true])->assertSuccessful();

        $row = fn ($work) => DB::table('work_people')->where('work_id', $work->id)->where('role', 'author')->first();
        $this->assertSame(['doubtful', '؟'], [$row($hedged)->certainty, $row($hedged)->hedge_raw]);
        $this->assertNull($row($sure)->certainty);
        $this->assertSame(['مصری، عبدالمجید', null, 'doubtful'], [$row($bare)->name, $row($bare)->person_id, $row($bare)->certainty]);
        $this->assertSame(['مصری، عبدالمجید', 'این رساله مختصری است'], [$bare->fresh()->author_name, $bare->fresh()->description]);
        $this->followingRedirects()->get("/works/{$hedged->id}")->assertOk()->assertSee('(؟)', false);
        $this->followingRedirects()->get("/works/{$sure->id}")->assertOk()->assertDontSee('(؟)', false);

        // a second run finds nothing left
        $this->artisan('fahares:mark-hedged-authors', ['--file' => $file])->expectsOutputToContain('marked                0')->assertSuccessful();
        @unlink($file);
    }

    /** Step 14.65: «؟علی بن موسی» puts the question mark on the name; a word that only begins like a hedge is none. */
    public function test_a_question_mark_touching_the_name_is_a_hedge_but_a_longer_word_is_not()
    {
        $raw = fn (string $line) => "● الجفر / جفر / عربی\nal-jafr\n\n{$line}\n‘alī ebn-e mūsā";
        $this->assertSame('؟', \App\Console\Commands\MarkHedgedAuthorsCommand::hedgeOf($raw('؟علی بن موسی (ع)، امام هشتم، 153 - 203 قمری')));
        $this->assertSame('؟', \App\Console\Commands\MarkHedgedAuthorsCommand::hedgeOf($raw('؟ منسوب به علی بن ابی طالب (ع)')));
        $this->assertSame('شاید', \App\Console\Commands\MarkHedgedAuthorsCommand::hedgeOf($raw('شاید مجلسی، محمد باقر')));
        $this->assertNull(\App\Console\Commands\MarkHedgedAuthorsCommand::hedgeOf($raw('شایسته، علی')));
        $this->assertNull(\App\Console\Commands\MarkHedgedAuthorsCommand::hedgeOf($raw('علی بن موسی (ع)، امام هشتم')));
    }

    /** Step 14.65: a description that was only the hedged author line and its transliteration is emptied. */
    public function test_a_description_that_was_only_the_author_line_is_emptied()
    {
        config(['scout.driver' => 'collection']);
        $work = Work::create(['primary_title' => 'احوال قلب', 'clean_title' => 'احوال قلب', 'slug' => 'qalb-' . uniqid(), 'volume_number' => 99, 'page_start' => 4,
            'description' => "؟ طالب اسیری\ntāleb-e asīrī"]);
        $file = tempnam(sys_get_temp_dir(), 'hedged');
        file_put_contents($file, json_encode(['items' => [['work_slug' => $work->slug, 'name' => 'طالب اسیری', 'hedge' => '؟',
            'description_from' => $work->description, 'description_to' => null]]], JSON_UNESCAPED_UNICODE));

        $this->artisan('fahares:mark-hedged-authors', ['--file' => $file, '--apply' => true])->assertSuccessful();

        $this->assertSame('طالب اسیری', $work->fresh()->author_name);
        $this->assertNull($work->fresh()->description);
        @unlink($file);
    }
}

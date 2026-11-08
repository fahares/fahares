<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\Work;
use App\Models\WorkPerson;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Step 14.111: works joined to a single-word author name they do not belong to lose the link, not the name. */
class UnlinkSingleNameAuthorsTest extends TestCase
{
    private function work(string $title, Person $person): Work
    {
        $work = Work::create(['primary_title' => $title, 'clean_title' => $title, 'slug' => 'single-' . uniqid(), 'volume_number' => 1,
            'page_start' => 1, 'author_name' => $person->name, 'author_id' => $person->id]);
        // the work keeps its author row in work_people itself
        $this->assertTrue(DB::table('work_people')->where('work_id', $work->id)->where('person_id', $person->id)->where('role', WorkPerson::AUTHOR)->exists());

        return $work;
    }

    public function test_the_judged_works_lose_the_person_link_and_keep_the_name(): void
    {
        $ahmad = Person::create(['name' => 'احمد', 'normalized_name' => 'احمد', 'slug' => 'ahmad-' . uniqid(), 'works_count' => 3]);
        $kept = $this->work('اکمل المراسلات', $ahmad);
        $other = $this->work('نتيجة الفتاوى', $ahmad);
        $verified = $this->work('عکاسی', $ahmad);
        AuditLog::create(['auditable_type' => Work::class, 'auditable_id' => $verified->id, 'action' => 'verify', 'old_values' => [], 'new_values' => ['author_id' => $ahmad->id]]);
        $moved = $this->work('دیوان احمد', Person::create(['name' => 'احمد شیرازی', 'normalized_name' => 'احمد شیرازی', 'slug' => 'as-' . uniqid()]));

        $file = 'storage/framework/testing-single-names.json';
        file_put_contents(base_path($file), json_encode(['step' => '14.111', 'unlinks' => [
            ['work_slug' => $other->slug, 'person_name' => 'احمد', 'judged_by' => 'claude', 'reason' => 'ترکی، اهدا به سلطان سلیم'],
            ['work_slug' => $verified->slug, 'person_name' => 'احمد'],
            ['work_slug' => $moved->slug, 'person_name' => 'احمد'],
            ['work_slug' => 'no-such-work', 'person_name' => 'احمد'],
        ]], JSON_UNESCAPED_UNICODE));

        $this->artisan('fahares:unlink-single-name-authors', ['--file' => $file])->assertSuccessful();
        $this->assertSame($ahmad->id, $other->fresh()->author_id);

        $this->artisan('fahares:unlink-single-name-authors', ['--file' => $file, '--apply' => true])
            ->expectsOutputToContain('1 works unlinked from 1 people; 0 left empty')->assertSuccessful();

        $other->refresh();
        $this->assertNull($other->author_id);
        $this->assertSame('احمد', $other->author_name);
        $this->assertFalse(DB::table('work_people')->where('work_id', $other->id)->exists());
        $this->assertSame($ahmad->id, $kept->fresh()->author_id);
        $this->assertSame($ahmad->id, $verified->fresh()->author_id);
        $this->assertNotNull($moved->fresh()->author_id);
        $this->assertSame(2, $ahmad->fresh()->works_count);

        $log = AuditLog::where('auditable_id', $other->id)->where('action', 'corpus_update')->sole();
        $this->assertSame($ahmad->id, $log->old_values['author_id']);
        $this->assertSame('single_name_author', $log->new_values['corpus_fix']);

        // a second run finds nothing left to do
        $this->artisan('fahares:unlink-single-name-authors', ['--file' => $file, '--apply' => true])
            ->expectsOutputToContain('0 works unlinked')->assertSuccessful();
        @unlink(base_path($file));
    }
}

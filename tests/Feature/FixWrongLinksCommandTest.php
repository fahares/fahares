<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Work;
use App\Suggestions\ProtectedColumns;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Author and scribe links the catalogue has wrong are mended by a decision file, through the site's own correction
 * code (step 14.341)
 */
class FixWrongLinksCommandTest extends TestCase
{
    private Library $library;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->library = Library::create(['name' => 'کتابخانۀ آزمایشی پیوند', 'city' => 'قم', 'slug' => 'lib-' . uniqid()]);
    }

    private function person(string $name): Person
    {
        return Person::create(['name' => $name, 'normalized_name' => $name, 'slug' => 'links-' . md5($name)]);
    }

    private function work(string $title, ?Person $author): Work
    {
        return Work::create(['primary_title' => $title, 'clean_title' => $title, 'slug' => 'links-' . uniqid(), 'volume_number' => 1, 'page_start' => 1,
            'author_id' => $author?->id, 'author_name' => $author?->name]);
    }

    private function copy(Work $work, string $shelfmark, ?Person $scribe, bool $autograph, ?string $scribeName = null): Manuscript
    {
        $n = ++$this->sequence;

        return Manuscript::create(['work_id' => $work->id, 'sequence_number' => $n, 'volume_number' => 1, 'page_start' => 1, 'library_id' => $this->library->id,
            'library' => $this->library->name, 'city' => 'قم', 'shelfmark' => $shelfmark, 'raw_text' => "{$n}. قم", 'scribe_id' => $scribe?->id,
            'scribe_name' => $scribeName, 'is_autograph' => $autograph]);
    }

    private function scribes(Manuscript $copy): array
    {
        return DB::table('manuscript_people')->where('manuscript_id', $copy->id)->where('role', 'scribe')->pluck('person_id')->all();
    }

    public function test_the_links_are_mended_only_with_apply_logged_as_corrections_and_a_run_can_be_repeated()
    {
        $father = $this->person('کلباسی آزمایشی، ابوالمعالی');
        $son = $this->person('کلباسی آزمایشی، ابوالهدی');
        $sama = $this->work('سماء المقال آزمایشی', $father);
        $samaAutograph = $this->copy($sama, 'پ-1', $father, true);
        $samaOther = $this->copy($sama, 'پ-2', null, false);

        $kalhor = $this->person('کلهر آزمایشی');
        $notebook = $this->work('خوشنویسی آزمایشی', $kalhor);
        $notebookCopy = $this->copy($notebook, 'پ-3', $kalhor, true);
        // another work of the same title with no author is not taken for the mended one
        $this->work('خوشنویسی آزمایشی', null);

        $merchant = $this->person('تاجر آزمایشی، محمد تقی');
        $ghazals = $this->work('غزلیات عاشق آزمایشی', $merchant);
        $ghazalsCopy = $this->copy($ghazals, 'پ-4', $merchant, true);

        $qazvini = $this->person('قزوینی آزمایشی، محمد مهدی');
        $nuzha = $this->work('نزهة الالباب آزمایشی', $qazvini);
        $late = $this->copy($nuzha, 'پ-5', $qazvini, true);
        $real = $this->copy($nuzha, 'پ-6', $qazvini, true);

        $author = $this->person('خوانساری آزمایشی، احمد');
        $own = $this->copy($this->work('بهار و خزان آزمایشی', $author), 'پ-7', $author, true);
        $scribed = [$this->copy($sama, 'پ-8', $author, false, 'احمد خوانساری آزمایشی'), $this->copy($nuzha, 'پ-9', $author, false, 'احمد خوانساری آزمایشی')];

        $file = tempnam(sys_get_temp_dir(), 'links');
        file_put_contents($file, json_encode([
            'works' => [
                ['title' => 'سماء المقال آزمایشی', 'author' => $father->name, 'to' => $son->name, 'why' => 'الذریعة'],
                ['title' => 'خوشنویسی آزمایشی', 'author' => $kalhor->name, 'to' => null, 'why' => 'شرح فهرست'],
                ['title' => 'غزلیات عاشق آزمایشی', 'author' => $merchant->name, 'create' => 'عاشق آزمایشی، محمد تقی', 'why' => 'ترقیمه'],
                ['title' => 'اثر ناموجود آزمایشی', 'author' => $father->name, 'to' => $son->name, 'why' => '-'],
            ],
            'not_autograph' => [['title' => 'نزهة الالباب آزمایشی', 'library' => $this->library->name, 'shelfmark' => 'پ-5', 'why' => 'وفات مؤلف']],
            'scribes' => [['person' => $author->name, 'scribe_name' => 'احمد خوانساری آزمایشی', 'new_name' => 'خوانساری آزمایشی، احمد (کاتب)', 'why' => 'هم‌نام']],
        ], JSON_UNESCAPED_UNICODE));

        $this->artisan('fahares:fix-wrong-links', ['--file' => $file])
            ->expectsOutputToContain('Dry run, nothing written: 5 changed, 0 already right, 1 not as the file says')->assertSuccessful();
        $this->assertSame($father->id, $sama->fresh()->author_id);
        $this->assertSame(0, AuditLog::where('action', 'verify')->count());

        $this->artisan('fahares:fix-wrong-links', ['--file' => $file, '--apply' => true])->assertSuccessful();

        // another person of the site: the work and its autograph copy follow, the other copy does not
        $this->assertSame([$son->id, $son->name], [$sama->fresh()->author_id, $sama->fresh()->author_name]);
        $this->assertSame([$son->id, true, [$son->id]], [$samaAutograph->fresh()->scribe_id, $samaAutograph->fresh()->is_autograph, $this->scribes($samaAutograph)]);
        $this->assertNull($samaOther->fresh()->scribe_id);
        // no known person: the copy stays «کاتب = مؤلف» with no one linked
        $this->assertSame([null, null], [$notebook->fresh()->author_id, $notebook->fresh()->author_name]);
        $this->assertSame([null, true, []], [$notebookCopy->fresh()->scribe_id, $notebookCopy->fresh()->is_autograph, $this->scribes($notebookCopy)]);
        // a new person
        $poet = Person::where('name', 'عاشق آزمایشی، محمد تقی')->sole();
        $this->assertSame([$poet->id, $poet->id], [$ghazals->fresh()->author_id, $ghazalsCopy->fresh()->scribe_id]);
        // a copy written after the author's death is no autograph; the author's other copy is untouched
        $this->assertSame([null, false, []], [$late->fresh()->scribe_id, $late->fresh()->is_autograph, $this->scribes($late)]);
        $this->assertSame([$qazvini->id, true], [$real->fresh()->scribe_id, $real->fresh()->is_autograph]);
        // the namesake scribe is a person of his own; the author keeps his autograph
        $scribe = Person::where('name', 'خوانساری آزمایشی، احمد (کاتب)')->sole();
        $this->assertSame([$scribe->id, $scribe->id], [$scribed[0]->fresh()->scribe_id, $scribed[1]->fresh()->scribe_id]);
        $this->assertSame($author->id, $own->fresh()->scribe_id);
        $this->assertSame(2, (int) $scribe->fresh()->manuscripts_count);

        // logged as corrections with the step and the evidence, so a sync from the corpus leaves the columns alone
        $log = AuditLog::where(['auditable_type' => Work::class, 'auditable_id' => $sama->id, 'action' => 'verify'])->sole();
        $this->assertSame(['14.341', 'الذریعة', $son->id], [$log->new_values['step'], $log->new_values['why'], $log->new_values['author_id']]);
        $this->assertSame([], ProtectedColumns::for(Work::class)->strip($sama->id, ['author_id' => $father->id, 'author_name' => $father->name]));
        $this->assertSame([], ProtectedColumns::for(Manuscript::class)->strip($late->id, ['is_autograph' => true]));
        $this->assertSame(1, AuditLog::where(['auditable_type' => Person::class, 'auditable_id' => $author->id, 'action' => 'split'])->count());

        $this->artisan('fahares:fix-wrong-links', ['--file' => $file, '--apply' => true])
            ->expectsOutputToContain('Written: 0 changed, 5 already right, 1 not as the file says')->assertSuccessful();
        $this->assertSame(1, AuditLog::where(['auditable_type' => Work::class, 'auditable_id' => $sama->id, 'action' => 'verify'])->count());
    }
}

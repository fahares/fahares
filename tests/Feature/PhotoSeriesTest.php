<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Manuscript;
use App\Support\PhotoSeries;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The photocopy number series of Ehya and Marashi is a volume of its own (backlog item 174): «645» of Ehya's
 * photocopy catalogue is not one of the treatises of the manuscript volume 645.
 */
class PhotoSeriesTest extends TestCase
{
    /** @var int[] */
    private array $libraries = [];

    /** @var int[] */
    private array $works = [];

    /** @var int[] */
    private array $manuscripts = [];

    protected function tearDown(): void
    {
        AuditLog::where('auditable_type', Manuscript::class)->whereIn('auditable_id', $this->manuscripts)->delete();
        DB::table('manuscripts')->whereIn('id', $this->manuscripts)->orWhereIn('work_id', $this->works)->delete();
        DB::table('works')->whereIn('id', $this->works)->delete();
        DB::table('libraries')->whereIn('id', $this->libraries)->delete();
        parent::tearDown();
    }

    private function text(string $shelfmark, string $citation, string $library = 'مرکز احیاء'): string
    {
        return "3. قم؛ {$library}؛ شماره نسخه: {$shelfmark}\nخط: نستعلیق، 11گ، اندازه: 11×17سم {$citation}";
    }

    /** A copy as the corpus gives it: the stored key is the one the rule before this item gave. */
    private function copy(int $library, string $shelfmark, string $citation, array $columns = []): int
    {
        $work = $this->works[] = \Tests\Support\InsertRecords::work(['primary_title' => 'رسالۀ آزمایشی عکسی ' . uniqid(), 'clean_title' => 'رسالۀ آزمایشی عکسی',
            'slug' => 'test-photo-' . uniqid(), 'volume_number' => 99, 'page_start' => 1]);

        return $this->manuscripts[] = \Tests\Support\InsertRecords::copy($columns + [
            'work_id' => $work, 'sequence_number' => 1, 'volume_number' => 99, 'page_start' => 1, 'page_end' => 1, 'city' => 'قم',
            'library' => 'مرکز احیاء', 'library_id' => $library, 'shelfmark' => $shelfmark, 'shelfmark_key' => $shelfmark,
            'collection_key' => Manuscript::collectionKeyOf($shelfmark),
            'raw_text' => $this->text($shelfmark, $citation),
        ]);
    }

    private function key(int $copy): ?string
    {
        return DB::table('manuscripts')->where('id', $copy)->value('collection_key');
    }

    public function test_the_catalogue_an_entry_cites_first_tells_the_series()
    {
        $this->assertSame([true, true, true, true, true, true], array_map(fn ($c) => PhotoSeries::photo($this->text('645', $c)),
            ['[عکسی ف: 2 - 279]', '[عکسی - ف: 5-407]', '[عکسی مرکز احیاء: ف: 6-1]', '[عکاسی ف: 4 - 470]', '[عكسى مركز احياء: ف: 6 - 273]', '[عکسی ف: 4 - 155 و ف: 4 - 522]']));
        // the manuscript catalogue, Mohaddes Armavi's, both with the manuscript one first, none
        $this->assertSame([false, false, false, false], array_map(fn ($c) => PhotoSeries::photo($this->text('645', $c)),
            ['[ف: 2-306]', '[محدث ارموی مخ: 3-1393]', '[ف: 1-77 و عکسی ف: 5-83]', '']));
        // Marashi, and another library
        $this->assertTrue(PhotoSeries::photo($this->text('1994/3', '[عکسی - ف: 5-407]', 'مرعشی')));
        $this->assertFalse(PhotoSeries::photo($this->text('1994/3', '[عکسی ف: 5-407]', 'مجلس')));
        // a second number with the mark: the keyed one is the manuscript's
        $this->assertFalse(PhotoSeries::photo($this->text('2007/5 و 2191/5 عکسی', '[عکسی ف: 6-239]'), '2007/5 و 2191/5 عکسی'));
    }

    public function test_a_copy_of_the_photocopy_series_has_its_series_in_its_volume_key()
    {
        $photo = $this->text('645/2', '[عکسی ف: 2 - 279]');
        $manuscript = $this->text('645/3', '[ف: 2-306]');

        $this->assertSame(['645-عکسی', '645'], [Manuscript::collectionKeyOf('645/2', $photo), Manuscript::collectionKeyOf('645/3', $manuscript)]);
        $this->assertSame(['645-عکسی', '645'], [Manuscript::plainVolumeKeyOf('645', $photo), Manuscript::plainVolumeKeyOf('645', $manuscript)]);
        // a shelfmark written with the mark has the same key, with or without a part, in Ehya as in Marashi
        $this->assertSame(['1004-عکسی', '1004-عکسی'], [Manuscript::collectionKeyOf('1004/7 عکسی', $manuscript), Manuscript::plainVolumeKeyOf('1004عکسی', $manuscript)]);
        $this->assertSame('1244-عکسی', Manuscript::plainVolumeKeyOf('1244عکسی', $this->text('1244عکسی', '[عکسی - ف: 3-544]', 'مرعشی')));
        // a manuscript and its photocopy under two numbers: the manuscript's volume
        $this->assertSame('2007', Manuscript::collectionKeyOf('2007/5 و 2191/5 عکسی', $this->text('2007/5 و 2191/5 عکسی', '[محدث ارموی مخ: 3-1393] و [عکسی ف: 6-239]')));
        // the key the old rule stored for a plain photocopy number no longer fits it
        $this->assertNull(Manuscript::resolvedCollectionKey('645', $photo, '645'));
        $this->assertSame('645-عکسی', Manuscript::resolvedCollectionKey('645', $photo, '645-عکسی'));
        $this->assertSame('645', Manuscript::resolvedCollectionKey('645', $manuscript, '645'));
    }

    public function test_the_commands_part_the_two_series_and_leave_a_reviewed_key()
    {
        $library = $this->libraries[] = DB::table('libraries')->insertGetId(['city' => 'قم', 'name' => 'احیاء آزمایشی ' . uniqid(), 'slug' => 'test-ehya-' . uniqid()]);
        // a manuscript volume of two treatises, and the photocopy of its number the old rule linked to it
        $m1 = $this->copy($library, '9645/1', '[ف: 2-305]', ['range_from_number' => 1, 'range_to_number' => 4]);
        $m2 = $this->copy($library, '9645/2', '[ف: 2-305]', ['range_from_number' => 7, 'range_to_number' => 29]);
        $book = $this->copy($library, '9645', '[عکسی ف: 2 - 279]', ['collection_key' => '9645']);
        // a photocopied volume of the same number as another manuscript volume, and a treatise with the mark
        $q1 = $this->copy($library, '9646/1', '[محدث ارموی مخ: 1-36]', ['range_from_number' => 1, 'range_to_number' => 9]);
        $q2 = $this->copy($library, '9646/2', '[محدث ارموی مخ: 1-37]', ['range_from_number' => 10, 'range_to_number' => 30]);
        $p1 = $this->copy($library, '9646/1', '[عکسی ف: 6-16]', ['range_from_number' => 1, 'range_to_number' => 40]);
        $p2 = $this->copy($library, '9646/2 عکسی', '[عکسی ف: 6-17]', ['range_from_number' => 41, 'range_to_number' => 90]);
        // a manuscript and its photocopy under two numbers: one copy of the manuscript volume
        $both = $this->copy($library, '9646/3 و 9700/3 عکسی', '[محدث ارموی مخ: 1-38] و [عکسی ف: 6-18]', ['range_from_number' => 31, 'range_to_number' => 40]);
        // a reviewer set this photocopied treatise's volume by hand
        $kept = $this->copy($library, '9647/1', '[عکسی ف: 7-5]');
        AuditLog::create(['user_id' => null, 'auditable_type' => Manuscript::class, 'auditable_id' => $kept, 'action' => 'verify', 'old_values' => [], 'new_values' => ['collection_key' => '9647', 'direct_edit' => true]]);
        foreach ([$p1, $p2] as $id) {
            DB::table('manuscripts')->where('id', $id)->update(['collection_key' => '9646']);
        }
        DB::table('manuscripts')->where('id', $kept)->update(['collection_key' => '9647']);
        \App\Support\Collections::sync($this->manuscripts);

        $this->artisan('fahares:fill-collection-keys')->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->assertSame('9646', $this->key($p1));

        $this->artisan('fahares:fill-collection-keys', ['--apply' => true])->assertSuccessful();
        $this->artisan('fahares:link-plain-volumes', ['--apply' => true])->assertSuccessful();

        // the manuscript volume keeps its treatises and loses the photocopy
        $this->assertSame(['9645', '9645', null], [$this->key($m1), $this->key($m2), $this->key($book)]);
        $this->assertNull(DB::table('manuscripts')->where('id', $book)->value('collection_id'));
        // the two volumes 9646 are two records
        $this->assertSame(['9646', '9646', '9646', '9646-عکسی', '9646-عکسی'], array_map(fn ($id) => $this->key($id), [$q1, $q2, $both, $p1, $p2]));
        $collection = fn (int $id) => DB::table('manuscripts')->where('id', $id)->value('collection_id');
        $this->assertNotNull($collection($p1));
        $this->assertSame($collection($p1), $collection($p2));
        $this->assertNotSame($collection($q1), $collection($p1));
        // the reviewed key is left
        $this->assertSame('9647', $this->key($kept));

        // again: nothing to do
        $before = DB::table('manuscripts')->whereIn('id', $this->manuscripts)->pluck('collection_key', 'id')->all();
        $this->artisan('fahares:fill-collection-keys', ['--apply' => true])->assertSuccessful();
        $this->artisan('fahares:link-plain-volumes', ['--apply' => true])->assertSuccessful();
        $this->assertSame($before, DB::table('manuscripts')->whereIn('id', $this->manuscripts)->pluck('collection_key', 'id')->all());

        // the photocopy shows its series beside a number written without it; a number with the mark does not repeat it
        $this->get(route('manuscripts.show', Manuscript::find($book)))->assertOk()->assertSee('data-series-mark', false);
        $this->assertNull(Manuscript::find($p2)->seriesMark());
        $this->get(route('manuscripts.show', Manuscript::find($p2)))->assertOk()->assertSee('رساله‌های این مجموعه');
        $this->get(route('manuscripts.show', Manuscript::find($m1)))->assertOk()->assertDontSee('data-series-mark', false);
    }
}

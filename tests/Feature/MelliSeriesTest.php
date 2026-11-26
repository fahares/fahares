<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Manuscript;
use App\Models\ManuscriptCollection;
use App\Support\MelliSeries;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The National Library's Arabic number series is a volume of its own (step 14.416): «2897» of the Arabic series is
 * not one of the treatises of the Persian volume 2897.
 */
class MelliSeriesTest extends TestCase
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
        // the collections go with their library
        DB::table('libraries')->whereIn('id', $this->libraries)->delete();
        parent::tearDown();
    }

    private function library(): int
    {
        return $this->libraries[] = DB::table('libraries')->insertGetId(['city' => 'تهران', 'name' => 'ملی آزمایشی ' . uniqid(), 'slug' => 'test-melli-' . uniqid()]);
    }

    /** A copy as the corpus gives it: the stored key is the one the rule before this step gave, the plain number. */
    private function copy(int $library, string $shelfmark, string $citation, array $columns = []): int
    {
        $work = $this->works[] = \Tests\Support\InsertRecords::work(['primary_title' => 'رسالۀ آزمایشی سری ' . uniqid(), 'clean_title' => 'رسالۀ آزمایشی سری',
            'slug' => 'test-melli-' . uniqid(), 'volume_number' => 99, 'page_start' => 1]);

        return $this->manuscripts[] = \Tests\Support\InsertRecords::copy($columns + [
            'work_id' => $work, 'sequence_number' => 1, 'volume_number' => 99, 'page_start' => 1, 'page_end' => 1, 'city' => 'تهران',
            'library' => 'ملی', 'library_id' => $library, 'shelfmark' => $shelfmark, 'shelfmark_key' => $shelfmark,
            'collection_key' => Manuscript::collectionKeyOf($shelfmark),
            'raw_text' => "12. تهران؛ ملی؛ شماره نسخه: {$shelfmark}\nخط: نسخ، بی‌کا، اندازه: 12×19سم {$citation}",
        ]);
    }

    private function key(int $copy): ?string
    {
        return DB::table('manuscripts')->where('id', $copy)->value('collection_key');
    }

    public function test_the_catalogue_volume_an_entry_cites_tells_the_series()
    {
        $text = fn (string $citation, string $head = 'تهران؛ ملی') => "259. {$head}؛ شماره نسخه: 2897\nخط: نستعلیق، 137گ {$citation}";

        $this->assertSame([13, 13, 13, 16, 7], array_map(fn ($c) => MelliSeries::catalogueVolume($text($c)),
            ['[ف: 13-418]', '[ف: 13 – 418]', '[ملی ایران: ف: 13 - 418]', '[ف: ۱۶ - ۴۴۳]', '[ف: <!-- page: 169 --> 7-4]']));
        // the page printed before the volume
        $this->assertSame([6, 11], [MelliSeries::catalogueVolume($text('[ف: 596-6]')), MelliSeries::catalogueVolume($text('[ف: 376-11]'))]);
        // the library's database, a journal, no citation
        $this->assertSame([null, null, null], array_map(fn ($c) => MelliSeries::catalogueVolume($text($c)), ['[رایانه]', '[نشریه: 13-418]', '']));
        // another city's National Library, another library, and «تهران؛ ملی ایران»
        $this->assertNull(MelliSeries::catalogueVolume($text('[ف: 13-418]', 'تبریز؛ ملی')));
        $this->assertNull(MelliSeries::catalogueVolume($text('[ف: 13-418]', 'تهران؛ ملک')));
        $this->assertSame(13, MelliSeries::catalogueVolume($text('[ف: 13-418]', 'تهران؛ ملی ایران')));

        // volumes 7 to 19 describe the Arabic series
        $this->assertSame([false, true, true, false, false], array_map(fn ($c) => MelliSeries::arabic($text($c)),
            ['[ف: 6-601]', '[ف: 7-1]', '[ف: 19-30]', '[ف: 22-5]', '[رایانه]']));
    }

    public function test_a_copy_of_the_arabic_series_has_its_series_in_its_volume_key()
    {
        $arabic = "تهران؛ ملی؛ شماره نسخه: 2897\nخط: نستعلیق [ف: 13-418]";
        $persian = "تهران؛ ملی؛ شماره نسخه: 2897/2\nخط: نستعلیق [ف: 6-601]";

        $this->assertSame(['2897-ع', '2897'], [Manuscript::collectionKeyOf('2897/1', $arabic), Manuscript::collectionKeyOf('2897/2', $persian)]);
        $this->assertSame(['2897-ع', '2897'], [Manuscript::plainVolumeKeyOf('2897', $arabic), Manuscript::plainVolumeKeyOf('2897', $persian)]);
        // a mark the shelfmark has stays, and a copy written with the series' mark has the same key
        $this->assertSame('137-عکسی', Manuscript::collectionKeyOf('137/2-عکسی', $arabic));
        $this->assertSame('2897-ع', Manuscript::collectionKeyOf('2897/5-ع'));
        // the key the old rule stored for a plain Arabic number no longer fits it
        $this->assertNull(Manuscript::resolvedCollectionKey('2897', $arabic, '2897'));
        $this->assertSame('2897-ع', Manuscript::resolvedCollectionKey('2897', $arabic, '2897-ع'));
        $this->assertSame('2897', Manuscript::resolvedCollectionKey('2897', $persian, '2897'));
    }

    public function test_the_commands_part_the_two_series_and_leave_a_reviewed_key()
    {
        $library = $this->library();
        // a Persian volume of two treatises, and the Arabic book of its number the old rule linked to it
        $p1 = $this->copy($library, '9301/1', '[ف: 6-601]', ['range_from_number' => 1, 'range_to_number' => 20]);
        $p2 = $this->copy($library, '9301/2', '[ف: 6-602]', ['range_from_number' => 21, 'range_to_number' => 52]);
        $book = $this->copy($library, '9301', '[ف: 13-418]', ['collection_key' => '9301']);
        // two volumes of one number, each with its treatises, and the Arabic one's own entry
        $q1 = $this->copy($library, '9302/1', '[ف: 5-10]', ['range_from_number' => 1, 'range_to_number' => 9]);
        $q2 = $this->copy($library, '9302/2', '[ف: 5-11]', ['range_from_number' => 10, 'range_to_number' => 30]);
        $a1 = $this->copy($library, '9302/1', '[ف: 12 - 30]', ['range_from_number' => 1, 'range_to_number' => 40]);
        $a2 = $this->copy($library, '9302/2', '[ف: 12 - 31]', ['range_from_number' => 41, 'range_to_number' => 90]);
        $whole = $this->copy($library, '9302', '[ف: 12 - 29]', ['collection_key' => '9302', 'range_from_number' => 1, 'range_to_number' => 90]);
        // a reviewer set this Arabic treatise's volume by hand
        $kept = $this->copy($library, '9303/1', '[ف: 9-5]');
        AuditLog::create(['user_id' => null, 'auditable_type' => Manuscript::class, 'auditable_id' => $kept, 'action' => 'verify', 'old_values' => [], 'new_values' => ['collection_key' => '9303', 'direct_edit' => true]]);
        foreach ([$a1, $a2] as $id) {
            DB::table('manuscripts')->where('id', $id)->update(['collection_key' => '9302']);
        }
        DB::table('manuscripts')->where('id', $kept)->update(['collection_key' => '9303']);
        \App\Support\Collections::sync($this->manuscripts);
        $this->assertSame(1, ManuscriptCollection::where('library_id', $library)->where('key', '9302')->count());

        $this->artisan('fahares:fill-collection-keys')->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->assertSame('9302', $this->key($a1));

        $this->artisan('fahares:fill-collection-keys', ['--apply' => true])->assertSuccessful();
        $this->artisan('fahares:link-plain-volumes', ['--apply' => true])->assertSuccessful();

        // the Persian volume keeps its treatises and loses the Arabic book
        $this->assertSame(['9301', '9301', null], [$this->key($p1), $this->key($p2), $this->key($book)]);
        $this->assertNull(DB::table('manuscripts')->where('id', $book)->value('collection_id'));
        // the two volumes 9302 are two records, and the Arabic one's own entry is with its treatises
        $this->assertSame(['9302', '9302', '9302-ع', '9302-ع', '9302-ع'], array_map(fn ($id) => $this->key($id), [$q1, $q2, $a1, $a2, $whole]));
        $collection = fn (int $id) => DB::table('manuscripts')->where('id', $id)->value('collection_id');
        $this->assertNotNull($collection($a1));
        $this->assertSame([$collection($a1), $collection($a1)], [$collection($a2), $collection($whole)]);
        $this->assertNotSame($collection($q1), $collection($a1));
        $this->assertSame([2, 3], [ManuscriptCollection::find($collection($q1))->copies_count, ManuscriptCollection::find($collection($a1))->copies_count]);
        // the reviewed key is left
        $this->assertSame('9303', $this->key($kept));

        // again: nothing to do
        $before = DB::table('manuscripts')->whereIn('id', $this->manuscripts)->pluck('collection_key', 'id')->all();
        $this->artisan('fahares:fill-collection-keys', ['--apply' => true])->assertSuccessful();
        $this->artisan('fahares:link-plain-volumes', ['--apply' => true])->assertSuccessful();
        $this->assertSame($before, DB::table('manuscripts')->whereIn('id', $this->manuscripts)->pluck('collection_key', 'id')->all());

        // the Persian treatise's page lists its volume without the Arabic book; the Arabic copies show their series
        $page = $this->get(route('manuscripts.show', Manuscript::find($p1)))->assertOk()->assertSee('رساله‌های این مجموعه');
        $this->assertStringNotContainsString(route('manuscripts.show', Manuscript::find($book)), $page->getContent());
        $page->assertDontSee('data-series-mark', false);
        $this->get(route('manuscripts.show', Manuscript::find($book)))->assertOk()->assertSee('data-series-mark', false)->assertDontSee('رساله‌های این مجموعه');
        $this->get(route('manuscripts.show', Manuscript::find($a1)))->assertOk()->assertSee('data-series-mark', false)->assertSee('رساله‌های این مجموعه');
    }
}

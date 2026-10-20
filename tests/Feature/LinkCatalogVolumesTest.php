<?php

namespace Tests\Feature;

use App\Console\Commands\LinkCatalogVolumesCommand as Linker;
use App\Models\Cataloger;
use App\Models\CatalogVolume;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Work;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LinkCatalogVolumesTest extends TestCase
{
    use DatabaseTransactions;

    private Work $work;

    private string $dataPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = Work::create(['primary_title' => 'اثر آزمایشی', 'clean_title' => 'اثر آزمایشی', 'slug' => 'work-link-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $this->dataPath = tempnam(sys_get_temp_dir(), 'links') . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->dataPath);
        parent::tearDown();
    }

    public function test_citations_are_parsed_by_series()
    {
        $this->assertSame(['volume' => 2, 'part' => null, 'page' => 45], Linker::parseCitation(Linker::normalize('[ف: ۲-۴۵]'), 'ف'));
        $this->assertSame(['volume' => null, 'part' => null, 'page' => 50], Linker::parseCitation('ف: -50', 'ف'));
        $this->assertNull(Linker::parseCitation('ف مخ: 1', 'ف'));
        $this->assertNull(Linker::parseCitation('فهرست عراقی: 1-136', 'ف'));
        $this->assertNull(Linker::parseCitation('الفبائی: -512', 'الفبائی')['volume']);
        $this->assertNull(Linker::parseCitation(Linker::normalize('کتابخانه‌های قائن - ف: - 170'), 'کتابخانه‌های قائن')['volume']);
        $this->assertNull(Linker::parseCitation('ف مخ: 1', 'ف مخ')['volume']);
        $this->assertNull(Linker::parseCitation('ف مخطوطات: 1', 'ف مخ'));
        $this->assertSame(Linker::SOURCE_LIBRARY_DATABASE, Linker::nonCatalogSource('رایانه'));
        $this->assertSame(Linker::SOURCE_REGISTER, Linker::nonCatalogSource('د.ث. مجلس'));
        $this->assertNull(Linker::nonCatalogSource('ف: 1-2'));
    }

    public function test_parts_are_read_only_when_the_link_asks_for_them()
    {
        $this->assertNull(Linker::parseCitation('ف: 47/1 - 15', 'ف')['volume']);
        $this->assertSame(['volume' => 47, 'part' => 1, 'page' => 15], Linker::parseCitation('ف: 47/1 - 15', 'ف', true));
        $this->assertSame(['volume' => 27, 'part' => 1, 'page' => 9], Linker::parseCitation('ف: 1/27 - 9', 'ف', true));
        $this->assertSame(['volume' => 47, 'part' => null, 'page' => null], Linker::parseCitation('ف: 47 -', 'ف', true));
        $this->assertSame(['volume' => 47, 'part' => 1, 'page' => null], Linker::parseCitation('ف: 47/1 -', 'ف', true));
        $this->assertSame(18, Linker::parseCitation('ف: 18 -؛ فهرست رایانه ای ص 730', 'ف', true)['volume']);
        $this->assertNull(Linker::parseCitation('ف: 47 -', 'ف')['volume']);
        $this->assertSame(['volume' => 2, 'part' => 1, 'page' => 112], Linker::parseCitation('اهدائی رهبر: 2 اخبار 1-112', 'اهدائی رهبر', true));
        $this->assertSame(['volume' => 2, 'part' => 2, 'page' => 543], Linker::parseCitation('اهدائی رهبر: 2اخبار2-543', 'اهدائی رهبر', true));
        $this->assertSame(['volume' => 2, 'part' => 1, 'page' => 401], Linker::parseCitation('اهدائی رهبر: 2 اخبار/ 1-401', 'اهدائی رهبر', true));
        $this->assertSame(['volume' => 1, 'part' => null, 'page' => 79], Linker::parseCitation('اهدائی رهبر: 1علوم قرآن - 79', 'اهدائی رهبر', true));
        $this->assertSame(['volume' => 3, 'part' => null, 'page' => 37], Linker::parseCitation('اهدائی رهبر: 3 - 37', 'اهدائی رهبر', true));

        $volumes = [
            ['number' => 3, 'part' => 1, 'pages' => [1, 440]],
            ['number' => 3, 'part' => 2, 'pages' => [446, 879]],
            ['number' => 8, 'part' => null, 'pages' => null],
        ];
        $this->assertSame(1, Linker::pickVolume($volumes, ['volume' => 3, 'part' => null, 'page' => 500], null));
        $this->assertSame(0, Linker::pickVolume($volumes, ['volume' => 3, 'part' => 1, 'page' => 500], null));
        $this->assertNull(Linker::pickVolume($volumes, ['volume' => 3, 'part' => null, 'page' => 443], null));
        $this->assertSame(2, Linker::pickVolume($volumes, ['volume' => 8, 'part' => null, 'page' => 5], null));
        $this->assertNull(Linker::pickVolume($volumes, ['volume' => 10, 'part' => null, 'page' => 5], null));
    }

    public function test_wrong_volumes_are_released_and_parts_get_their_own_catalogers()
    {
        $library = Library::create(['name' => 'رضوی آزمایشی', 'city' => 'مشهد', 'slug' => 'rz-parts-' . uniqid()]);
        $surname = Cataloger::create(['name' => 'قاسمی', 'slug' => 'ghasemi-' . uniqid()]);
        $first = Cataloger::create(['name' => 'نگارنده‌ی بخش یک', 'slug' => 'part-one-' . uniqid()]);
        $wrong = CatalogVolume::create(['title' => 'جلد نادرست', 'volume_number' => 1, 'library_id' => $library->id]);
        $duplicate = CatalogVolume::create(['title' => 'جلد نادرست', 'volume_number' => 1, 'library_id' => $library->id]);
        $vol10 = $this->manuscript($library, '[اهدائی رهبر: 10 - 33]', null, $wrong->id);
        $vol10->update(['cataloger_id' => $surname->id]);
        $partOne = $this->manuscript($library, '[اهدائی رهبر: 2 اخبار 1-112]', null, $wrong->id);
        $partOne->update(['cataloger_id' => $surname->id]);
        $partTwo = $this->manuscript($library, '[اهدائی رهبر: 2اخبار2-600]');
        $newSlug = 'part-two-' . uniqid();

        $this->writeData([[
            'library' => $library->slug, 'series' => 'اهدائی رهبر', 'parts' => true, 'source' => 'fankha:545', 'source_text' => 'آزمایشی',
            'release_volumes' => ['جلد نادرست'],
            'volumes' => [
                ['number' => 2, 'part' => 1, 'pages' => [1, 475], 'title' => 'ج ۲ بخش ۱', 'catalogers' => [
                    ['slug' => $first->slug, 'name' => 'نگارنده‌ی بخش یک'], ['slug' => $surname->slug, 'name' => 'حسین قاسمی', 'rename' => true],
                ]],
                ['number' => 2, 'part' => 2, 'pages' => [488, 940], 'title' => 'ج ۲ بخش ۲', 'catalogers' => [
                    ['slug' => $newSlug, 'name' => 'نگارنده‌ی بخش دو', 'new' => true],
                ]],
            ],
        ]]);
        $this->artisan('catalogs:link-volumes', ['--data' => $this->dataPath, '--dry-run' => true])->assertSuccessful();
        $this->assertSame($wrong->id, $vol10->fresh()->catalog_volume_id);

        $this->artisan('catalogs:link-volumes', ['--data' => $this->dataPath])->assertSuccessful();
        $this->artisan('catalogs:link-volumes', ['--data' => $this->dataPath])->assertSuccessful();

        $one = CatalogVolume::where('title', 'ج ۲ بخش ۱')->sole();
        $two = CatalogVolume::where('title', 'ج ۲ بخش ۲')->sole();
        $this->assertSame([$one->id, $first->id], [$partOne->fresh()->catalog_volume_id, $partOne->fresh()->cataloger_id]);
        $this->assertSame([$two->id, Cataloger::where('slug', $newSlug)->value('id')], [$partTwo->fresh()->catalog_volume_id, $partTwo->fresh()->cataloger_id]);
        $this->assertSame([null, null], [$vol10->fresh()->catalog_volume_id, $vol10->fresh()->cataloger_id]);
        $this->assertNull(CatalogVolume::find($wrong->id));
        $this->assertNull(CatalogVolume::find($duplicate->id));
        $this->assertSame('حسین قاسمی', $surname->fresh()->name);
        $this->assertEqualsCanonicalizing([$first->id, $surname->id], $one->catalogers()->pluck('catalogers.id')->all());
    }

    public function test_citation_falls_back_to_the_last_bracket_of_the_raw_text()
    {
        $this->assertSame('الفبائی: -512', Linker::citationOf('{"catalog_citation": null}', "۱. مشهد؛ رضوی؛ شماره نسخه: 1\nخط: نسخ [الفبائی: -512]"));
        $this->assertSame('ف: 3-10', Linker::citationOf('{"catalog_citation": "[ف: 3-10]"}', 'متن [رایانه]'));
    }

    public function test_manuscripts_are_linked_by_natural_key_and_the_run_is_repeatable()
    {
        $library = Library::create(['name' => 'کتابخانه آزمایشی', 'city' => 'یزد', 'slug' => 'test-lib-' . uniqid()]);
        $existing = Cataloger::create(['name' => 'فهرست‌نگار موجود', 'slug' => 'existing-' . uniqid(), 'mtif_entry_id' => 990001]);
        $v1 = $this->manuscript($library, '[ف: 1-10]');
        $v2 = $this->manuscript($library, '[ف: 2-20]');
        $v9 = $this->manuscript($library, '[ف: 9-20]');
        $other = $this->manuscript($library, '[الفبائی: -5]');
        $database = $this->manuscript($library, null, "متن [رایانه]");

        $this->writeData([[
            'library' => $library->slug, 'series' => 'ف', 'source' => 'mtif.org/book/1', 'source_text' => 'نگارش آزمایشی',
            'volumes' => [
                ['number' => 1, 'mtif_entry_id' => 880001, 'title' => 'فهرست آزمایشی - 1'],
                ['number' => 2, 'mtif_entry_id' => 880002, 'title' => 'فهرست آزمایشی - 2'],
            ],
            'catalogers' => [
                ['mtif_entry_id' => 990001, 'name' => 'فهرست‌نگار موجود'],
                ['mtif_entry_id' => 990002, 'name' => 'فهرست‌نگار تازه', 'new' => true],
            ],
        ]]);

        $this->artisan('catalogs:link-volumes', ['--data' => $this->dataPath, '--dry-run' => true])->assertSuccessful();
        $this->assertNull($v1->fresh()->cataloger_id);
        $this->assertNull(Cataloger::where('mtif_entry_id', 990002)->first());

        $this->artisan('catalogs:link-volumes', ['--data' => $this->dataPath])->assertSuccessful();
        $vol1 = CatalogVolume::where('mtif_entry_id', 880001)->firstOrFail();
        $vol2 = CatalogVolume::where('mtif_entry_id', 880002)->firstOrFail();
        $new = Cataloger::where('mtif_entry_id', 990002)->firstOrFail();
        $this->assertSame([$vol1->id, $existing->id], [$v1->fresh()->catalog_volume_id, $v1->fresh()->cataloger_id]);
        $this->assertSame($vol2->id, $v2->fresh()->catalog_volume_id);
        $this->assertNull($v9->fresh()->catalog_volume_id);
        $this->assertNull($other->fresh()->catalog_volume_id);
        $this->assertEqualsCanonicalizing([$existing->id, $new->id], $vol1->catalogers()->pluck('catalogers.id')->all());
        $this->assertSame(1, $vol1->fresh()->manuscripts_count);
        $this->assertSame(2, $existing->fresh()->manuscripts_count);
        $this->assertSame('library_database', $database->fresh()->metadata['catalog_source']);

        $this->artisan('catalogs:link-volumes', ['--data' => $this->dataPath])->assertSuccessful();
        $this->assertSame(1, CatalogVolume::where('mtif_entry_id', 880001)->count());
        $this->assertSame(1, Cataloger::where('mtif_entry_id', 990002)->count());
    }

    public function test_an_authoritative_link_moves_copies_to_the_volume_their_citation_names()
    {
        $library = Library::create(['name' => 'مجلس آزمایشی', 'city' => 'تهران', 'slug' => 'majlis-auth-' . uniqid()]);
        $old = Cataloger::create(['name' => 'فهرست‌نگار جلد دوم', 'slug' => 'v2-' . uniqid()]);
        $mansur = Cataloger::create(['name' => 'منصور آزمایشی', 'slug' => 'mansur-' . uniqid()]);
        $nazari = Cataloger::create(['name' => 'نظری آزمایشی', 'slug' => 'nazari-' . uniqid()]);
        $vol2 = CatalogVolume::create(['title' => 'مجلس - جلد دوم', 'volume_number' => 2, 'library_id' => $library->id]);
        $vol2->catalogers()->attach($old->id, ['role' => 'cataloger']);
        $right = $this->manuscript($library, '[ف: 2-10]', null, $vol2->id);
        $right->update(['cataloger_id' => $old->id]);
        $partTwo = $this->manuscript($library, '[ف: 24/2 - 30]', null, $vol2->id);
        $partTwo->update(['cataloger_id' => $old->id]);
        $noPart = $this->manuscript($library, '[ف: 24 - 5]');
        $unlisted = $this->manuscript($library, '[ف: 60 - 5]', null, $vol2->id);
        $unlisted->update(['cataloger_id' => $old->id]);
        $noPage = $this->manuscript($library, '[ف: 2 -]');

        $this->writeData([[
            'library' => $library->slug, 'series' => 'ف', 'parts' => true, 'authoritative' => true, 'source' => 's', 'source_text' => 's',
            'volumes' => [
                ['number' => 2, 'title' => 'مجلس - جلد دوم', 'match_title' => 'مجلس - جلد دوم', 'existing_catalogers' => true],
                ['number' => 24, 'part' => 1, 'default' => true, 'title' => 'مجلس - جلد ۱/۲۴', 'catalogers' => [['slug' => $mansur->slug, 'name' => 'منصور آزمایشی']]],
                ['number' => 24, 'part' => 2, 'title' => 'مجلس - جلد ۲/۲۴', 'catalogers' => [['slug' => $nazari->slug, 'name' => 'نظری آزمایشی']]],
            ],
        ]]);
        $this->artisan('catalogs:link-volumes', ['--data' => $this->dataPath])->assertSuccessful();
        $this->artisan('catalogs:link-volumes', ['--data' => $this->dataPath])->assertSuccessful();

        $one = CatalogVolume::where('title', 'مجلس - جلد ۱/۲۴')->sole();
        $two = CatalogVolume::where('title', 'مجلس - جلد ۲/۲۴')->sole();
        $this->assertSame([$vol2->id, $old->id], [$right->fresh()->catalog_volume_id, $right->fresh()->cataloger_id]);
        $this->assertSame([$two->id, $nazari->id], [$partTwo->fresh()->catalog_volume_id, $partTwo->fresh()->cataloger_id]);
        $this->assertSame([$one->id, $mansur->id], [$noPart->fresh()->catalog_volume_id, $noPart->fresh()->cataloger_id]);
        $this->assertSame([null, null], [$unlisted->fresh()->catalog_volume_id, $unlisted->fresh()->cataloger_id]);
        $this->assertSame($vol2->id, $noPage->fresh()->catalog_volume_id);
    }

    public function test_volumes_without_mtif_are_keyed_by_title_and_a_shared_catalogue_stays_under_one_library()
    {
        $main = Library::create(['name' => 'کتابخانه اصلی', 'city' => 'کاشان', 'slug' => 'main-lib-' . uniqid()]);
        $second = Library::create(['name' => 'کتابخانه دوم', 'city' => 'کاشان', 'slug' => 'second-lib-' . uniqid()]);
        $unrelated = CatalogVolume::create(['title' => 'جلد دیگر بی‌شناسه', 'volume_number' => 1, 'library_id' => $main->id]);
        $known = Cataloger::create(['name' => 'نگارنده‌ی موجود', 'slug' => 'known-' . uniqid()]);
        $inMain = $this->manuscript($main, '[ف مخ: -12]');
        $inSecond = $this->manuscript($second, '[ف مخ]');
        $newSlug = 'new-no-mtif-' . uniqid();

        $link = fn (Library $library) => [
            'library' => $library->slug, 'series' => 'ف مخ', 'source' => 'fankha:1', 'source_text' => 'فهرست مخطوط',
            'volume_library' => $main->slug,
            'volumes' => [['number' => 1, 'title' => 'فهرست مخطوط آزمایشی']],
            'catalogers' => [
                ['mtif_entry_id' => 990011, 'slug' => $known->slug, 'name' => 'نگارنده‌ی موجود'],
                ['slug' => $newSlug, 'name' => 'نگارنده‌ی بی‌شناسه', 'new' => true],
            ],
        ];
        $this->writeData([$link($main), $link($second)]);
        $this->artisan('catalogs:link-volumes', ['--data' => $this->dataPath])->assertSuccessful();
        $this->artisan('catalogs:link-volumes', ['--data' => $this->dataPath])->assertSuccessful();

        $volume = CatalogVolume::where('title', 'فهرست مخطوط آزمایشی')->sole();
        $this->assertSame($main->id, $volume->library_id);
        $this->assertNotSame($unrelated->id, $volume->id);
        $this->assertSame([$volume->id, $known->id], [$inMain->fresh()->catalog_volume_id, $inMain->fresh()->cataloger_id]);
        $this->assertSame($volume->id, $inSecond->fresh()->catalog_volume_id);
        $this->assertSame(990011, (int) $known->fresh()->mtif_entry_id);
        $this->assertSame(1, Cataloger::where('name', 'نگارنده‌ی بی‌شناسه')->count());
        $this->assertSame(0, $unrelated->fresh()->manuscripts_count);
    }

    public function test_copies_of_a_duplicate_library_follow_the_catalogers_of_an_existing_volume()
    {
        $main = Library::create(['name' => 'مجلس آزمایشی', 'city' => 'تهران', 'slug' => 'majlis-' . uniqid()]);
        $duplicate = Library::create(['name' => 'شورا آزمایشی', 'city' => 'تهران', 'slug' => 'shora-' . uniqid()]);
        $first = Cataloger::create(['name' => 'فهرست‌نگار نخست', 'slug' => 'f1-' . uniqid()]);
        $second = Cataloger::create(['name' => 'فهرست‌نگار دوم', 'slug' => 'f2-' . uniqid()]);
        $volume = CatalogVolume::create(['title' => 'فهرست مجلس - جلد ۲۹', 'volume_number' => 29, 'library_id' => $main->id]);
        $volume->catalogers()->attach($second->id, ['role' => 'editor']);
        $volume->catalogers()->attach($first->id, ['role' => 'cataloger']);
        $copy = $this->manuscript($duplicate, '[ف: 1/29 - 455]');
        $other = $this->manuscript($duplicate, '[ف: 24 - 10]');

        $this->writeData([[
            'library' => $duplicate->slug, 'volume_library' => $main->slug, 'series' => 'ف', 'parts' => true,
            'source' => 'mirror', 'source_text' => 'آزمایشی',
            'volumes' => [['number' => 29, 'title' => 'فهرست مجلس - جلد ۲۹', 'match_title' => 'فهرست مجلس - جلد ۲۹', 'existing_catalogers' => true]],
        ]]);
        $this->artisan('catalogs:link-volumes', ['--data' => $this->dataPath])->assertSuccessful();

        $this->assertSame([$volume->id, $first->id], [$copy->fresh()->catalog_volume_id, $copy->fresh()->cataloger_id]);
        $this->assertNull($other->fresh()->catalog_volume_id);
        $this->assertSame(1, $volume->catalogers()->count() - 1);
    }

    public function test_unknown_cataloger_not_marked_new_stops_the_run()
    {
        $library = Library::create(['name' => 'کتابخانه آزمایشی', 'city' => 'یزد', 'slug' => 'test-lib-' . uniqid()]);
        $ms = $this->manuscript($library, '[ف: -10]');
        $this->writeData([[
            'library' => $library->slug, 'series' => 'ف', 'source' => 's', 'source_text' => 's',
            'volumes' => [['number' => 1, 'mtif_entry_id' => 880003, 'title' => 'فهرست']],
            'catalogers' => [['mtif_entry_id' => 990009, 'name' => 'ناشناخته']],
        ]]);

        try {
            $this->artisan('catalogs:link-volumes', ['--data' => $this->dataPath])->run();
            $this->fail('Expected the run to stop');
        } catch (\RuntimeException) {
        }
        $this->assertNull($ms->fresh()->catalog_volume_id);
    }

    public function test_razavi_copies_on_malek_volumes_go_to_the_cited_razavi_volume()
    {
        $razavi = Library::create(['name' => 'رضوی آزمایشی', 'city' => 'مشهد', 'slug' => 'razavi-' . uniqid()]);
        $malek = Library::create(['name' => 'ملک آزمایشی', 'city' => 'تهران', 'slug' => 'malek-' . uniqid()]);
        $oktayi = Cataloger::create(['name' => 'فهرست‌نگار رضوی', 'slug' => 'rz-' . uniqid()]);
        $malekVol = CatalogVolume::create(['title' => 'فهرست ملک - 2', 'volume_number' => 2, 'library_id' => $razavi->id, 'mtif_entry_id' => 870002]);
        $razaviVol = CatalogVolume::create(['title' => 'فهرست رضوی - 2', 'volume_number' => 2, 'library_id' => $razavi->id, 'mtif_entry_id' => 870102]);
        $razaviVol->catalogers()->attach($oktayi->id, ['role' => 'cataloger']);
        $cited = $this->manuscript($razavi, '[ف: 2-30]', null, $malekVol->id);
        $donated = $this->manuscript($razavi, '[اهدائی ف: 26]', null, $malekVol->id);

        file_put_contents($this->dataPath, json_encode(['links' => [], 'malek' => [
            'library' => $malek->slug, 'volumes' => [870002],
            'razavi_library' => $razavi->slug, 'razavi_volumes' => ['2' => 870102],
        ]], JSON_UNESCAPED_UNICODE));
        $this->artisan('catalogs:link-volumes', ['--data' => $this->dataPath])->assertSuccessful();

        $this->assertSame($malek->id, $malekVol->fresh()->library_id);
        $this->assertSame([$razaviVol->id, $oktayi->id], [$cited->fresh()->catalog_volume_id, $cited->fresh()->cataloger_id]);
        $this->assertNull($donated->fresh()->catalog_volume_id);
    }

    private function manuscript(Library $library, ?string $citation, ?string $raw = null, ?int $volumeId = null): Manuscript
    {
        return Manuscript::create([
            'work_id' => $this->work->id, 'library_id' => $library->id, 'catalog_volume_id' => $volumeId,
            'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1,
            'metadata' => ['catalog_citation' => $citation], 'raw_text' => $raw,
        ]);
    }

    private function writeData(array $links): void
    {
        file_put_contents($this->dataPath, json_encode(['links' => $links], JSON_UNESCAPED_UNICODE));
    }
}

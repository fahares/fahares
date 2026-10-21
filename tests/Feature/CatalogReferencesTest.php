<?php

namespace Tests\Feature;

use App\Models\CatalogVolume;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\ManuscriptCatalogReference as Ref;
use App\Models\Work;
use Tests\TestCase;

class CatalogReferencesTest extends TestCase
{
    private const SECRET_URL = 'https://scan-source.example/book/504138';

    protected Library $library;

    protected CatalogVolume $fankhaVolume;

    protected CatalogVolume $edition;

    protected function setUp(): void
    {
        parent::setUp();
        $this->library = Library::create(['name' => 'مسجد اعظم', 'city' => 'قم', 'slug' => 'masjid-azam-' . uniqid()]);
        $this->fankhaVolume = CatalogVolume::create(['title' => 'فهرست نسخ خطی کتابخانه مسجد اعظم قم - جلد دوم', 'volume_number' => 2, 'library_id' => $this->library->id]);
        $this->edition = CatalogVolume::create([
            'title' => 'فهرست نسخه‌های خطی کتابخانه مسجد اعظم قم - جلد دوم', 'volume_number' => 2, 'library_id' => $this->library->id,
            'edition_of_id' => $this->fankhaVolume->id, 'edition_label' => 'چاپ ۱۳۶۵، رضا استادی',
            'facsimile_code' => 'masjid-azam-ostadi', 'page_first' => 1, 'page_last' => 827, 'source_url' => self::SECRET_URL,
        ]);
    }

    protected function manuscript(array $attributes = []): Manuscript
    {
        $work = Work::create(['primary_title' => 'دیوان قدسی', 'clean_title' => 'دیوان قدسی', 'slug' => 'work-ref-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);

        return Manuscript::create($attributes + [
            'work_id' => $work->id, 'library_id' => $this->library->id, 'shelfmark' => '1939',
            'catalog_volume_id' => $this->fankhaVolume->id, 'volume_number' => 1, 'page_start' => 1, 'sequence_number' => 1,
        ]);
    }

    public function test_an_edition_links_to_the_volume_fankha_cites_and_hides_its_source()
    {
        $this->assertTrue($this->edition->editionOf->is($this->fankhaVolume));
        $this->assertTrue($this->fankhaVolume->editions->first()->is($this->edition));
        $this->assertSame('فهرست نسخه‌های خطی کتابخانه مسجد اعظم قم', $this->edition->short_title);
        $this->assertArrayNotHasKey('source_url', $this->edition->toArray());
        $this->assertStringNotContainsString('scan-source', $this->edition->toJson());
    }

    public function test_a_reference_opens_the_page_where_the_entry_starts()
    {
        $ms = $this->manuscript();
        $ref = Ref::create([
            'manuscript_id' => $ms->id, 'catalog_volume_id' => $this->edition->id, 'volume_number' => 2,
            'page_start' => 414, 'shelfmark_page' => 415, 'entry_number' => 606, 'source' => Ref::SOURCE_AI_MATCH, 'confidence' => 90,
        ]);

        $this->assertTrue($ms->catalogReferences->first()->is($ref));
        $this->assertTrue($ref->isAwaitingReview());
        $this->assertSame(1, Ref::awaitingReview()->where('manuscript_id', $ms->id)->count());
        $this->assertSame(['catalog' => 'masjid-azam-ostadi', 'catalogName' => 'فهرست نسخه‌های خطی کتابخانه مسجد اعظم قم', 'volume' => 2, 'page' => 414], $ref->viewer());

        $ref->update(['catalog_volume_id' => $this->fankhaVolume->id]);
        $this->assertNull($ref->fresh()->viewer(), 'a volume without page images has no viewer');
    }

    public function test_the_source_url_never_reaches_the_manuscript_page()
    {
        $ms = $this->manuscript();
        Ref::create(['manuscript_id' => $ms->id, 'catalog_volume_id' => $this->edition->id, 'volume_number' => 2, 'page_start' => 414, 'source' => Ref::SOURCE_AI_MATCH, 'confidence' => 90]);

        $html = $this->get(route('manuscripts.show', $ms))->assertOk()->getContent();
        $this->assertStringNotContainsString('scan-source', $html);
        $this->assertStringNotContainsString('504138', $html);
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $json) {
            $this->assertStringNotContainsString('scan-source', $json);
        }
    }
}

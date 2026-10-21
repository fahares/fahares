<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFankhaCatalogReferencesCommand as Import;
use App\Console\Commands\MatchCatalogEditionCommand as Matcher;
use App\Models\AuditLog;
use App\Models\Catalog;
use App\Models\CatalogVolume;
use App\Models\FieldSuggestion;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\ManuscriptCatalogReference as Ref;
use App\Models\User;
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
        $fankha = Catalog::firstOrCreate(['code' => 'fankha'], ['name' => 'فهرستگان نسخه‌های خطی ایران', 'short_name' => 'فنخا']);
        $this->fankhaVolume = CatalogVolume::create(['title' => 'فهرست نسخ خطی کتابخانه مسجد اعظم قم - جلد دوم', 'volume_number' => 2, 'library_id' => $this->library->id, 'catalog_id' => $fankha->id]);
        $this->edition = CatalogVolume::create([
            'title' => 'فهرست نسخه‌های خطی کتابخانه مسجد اعظم قم - جلد دوم', 'volume_number' => 2, 'library_id' => $this->library->id,
            'edition_of_id' => $this->fankhaVolume->id, 'edition_label' => '۱۳۶۵، رضا استادی',
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

        $html = $this->get(route('manuscripts.show', $ms))->assertOk()->assertSee('ص ۴۱۴')->getContent();
        $this->assertStringNotContainsString('scan-source', $html);
        $this->assertStringNotContainsString('504138', $html);
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $json) {
            $this->assertStringNotContainsString('scan-source', $json);
        }
    }

    public function test_fankha_citations_are_parsed_into_volume_and_page()
    {
        $cases = [
            '[ف: 2 - 224]' => ['ف', 2, 224],
            '[ف: ۲-۲۲۴]' => ['ف', 2, 224],
            '[ف مخ: 2 - 817]' => ['ف مخ', 2, 817],
            '[ف مخ - 3 - 1159]' => ['ف مخ', 3, 1159],
            '[رشت و همدان: ف: -1398]' => ['رشت و همدان', null, 1398],
            '[سنا: ف2-157]' => ['سنا', 2, 157],
            '[الفبائی: 9-]' => ['الفبائی', 9, null],
            '[ف: 47/1 - 15]' => ['ف', 47, 15],
            '[ف: 1/27-9]' => ['ف', 27, 9],
            '[اهدائی رهبر: 2 اخبار 1-112]' => ['اهدائی رهبر', 2, 112],
            '[تراثنا: س13ش1-123]' => ['تراثنا', null, 123],
            '[ف: 18-5؛ فهرست رایانه‌ای ج3-730]' => ['ف', 18, 5],
            '[مختصر ف: 10]' => ['مختصر ف', null, null],
            '[رایانه]' => ['رایانه', null, null],
            '[ف مخ]' => ['ف مخ', null, null],
        ];
        foreach ($cases as $citation => [$series, $volume, $page]) {
            $this->assertSame(['series' => $series, 'volume' => $volume, 'page' => $page], Import::parse($citation), $citation);
        }
        $this->assertSame('[ف مخ: 1]', Import::rawCitation(null, "متن نسخه [ف مخ: 1]"));
        $this->assertNull(Import::rawCitation('{"catalog_citation": ""}', 'بی‌ارجاع'));
    }

    public function test_the_import_adds_one_fankha_row_per_copy_and_never_duplicates()
    {
        $ms = $this->manuscript(['metadata' => ['catalog_citation' => '[ف مخ: 2 - 817]']]);
        $bare = $this->manuscript(['catalog_volume_id' => null, 'metadata' => []]);

        $this->artisan('catalogs:import-fankha-references', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, Ref::count());

        $this->artisan('catalogs:import-fankha-references')->assertSuccessful();
        $this->artisan('catalogs:import-fankha-references')->assertSuccessful();
        $rows = Ref::where('manuscript_id', $ms->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(['[ف مخ: 2 - 817]', 2, 817, $this->fankhaVolume->id, Ref::SOURCE_FANKHA],
            [$rows[0]->raw_text, $rows[0]->volume_number, $rows[0]->page_start, $rows[0]->catalog_volume_id, $rows[0]->source]);
        $this->assertSame(0, Ref::where('manuscript_id', $bare->id)->count());

        // a later relink of the copy is followed on the next run
        $ms->update(['catalog_volume_id' => $this->edition->id]);
        $this->artisan('catalogs:import-fankha-references')->assertSuccessful();
        $this->assertSame([$this->edition->id], Ref::where('manuscript_id', $ms->id)->pluck('catalog_volume_id')->all());
    }

    public function test_editions_are_synced_by_natural_key()
    {
        $path = tempnam(sys_get_temp_dir(), 'editions');
        file_put_contents($path, json_encode(['editions' => [[
            'facsimile_code' => 'test-edition', 'volume_number' => 2, 'library_slug' => $this->library->slug, 'edition_of' => ['volume_number' => 2],
            'title' => 'فهرست آزمایشی - جلد دوم', 'edition_label' => '۱۳۶۵', 'page_first' => 1, 'page_last' => 10, 'source_url' => self::SECRET_URL,
        ]]], JSON_UNESCAPED_UNICODE));

        $this->artisan('catalogs:sync-editions', ['--data' => $path, '--dry-run' => true])->assertSuccessful();
        $this->assertNull(CatalogVolume::where('facsimile_code', 'test-edition')->first());
        $this->artisan('catalogs:sync-editions', ['--data' => $path])->assertSuccessful();
        $this->artisan('catalogs:sync-editions', ['--data' => $path])->expectsOutputToContain('unchanged')->assertSuccessful();
        unlink($path);

        $edition = CatalogVolume::where('facsimile_code', 'test-edition')->sole();
        $this->assertSame([$this->fankhaVolume->id, null, self::SECRET_URL, 10], [$edition->edition_of_id, $edition->catalog_id, $edition->source_url, $edition->page_last]);
        $this->assertCount(0, $edition->catalogers);
    }

    private function pages(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pages') . '.jsonl';
        $pages = [
            [414, "606- دیوان قاسمی (فارسی)\nاز: قاسمی\nآغاز: «ای دل و جان عاشقان»"],
            [415, "انجام: «صلوات خدای»\nش ۱۹۳۹، ۲۰۷ برگ، نستعلیق\n\n607- دیوان قدسی (فارسی)\nاز: قدسی\nش 3543، 164 برگ\nش 2000، جنگ"],
            [416, "608- جنگ اشعار = بیاض (فارسی)\nش 2000/2، 50 برگ\nش 1939، 10 برگ"],
        ];
        file_put_contents($path, implode("\n", array_map(fn ($p) => json_encode(['page' => $p[0], 'text' => $p[1]], JSON_UNESCAPED_UNICODE), $pages)));

        return $path;
    }

    public function test_titles_are_compared_like_the_pilot()
    {
        $this->assertSame('حدیقه شیعه', Matcher::norm('حديقة الشيعة'));
        $this->assertSame('تحریر مساکن', Matcher::norm('رساله در تحریر المساکن'));
        $this->assertEqualsWithDelta(1.0, Matcher::similarity('دیوان قدسی', 'دیوان قدسی'), 0.001);
        $this->assertEqualsWithDelta(0.75, Matcher::similarity('ابجد', 'ابجه'), 0.001); // 3 of 4 + 4 characters
        $this->assertSame(0.0, Matcher::similarity('ا', 'ب'));
    }

    public function test_copies_are_matched_by_shelfmark_and_title_to_the_page_where_the_entry_starts()
    {
        $ms = $this->manuscript(['shelfmark' => '1939']);
        $ms->work->update(['primary_title' => 'دیوان قاسمی']);
        $generic = $this->manuscript(['shelfmark' => '2000']);
        $generic->work->update(['primary_title' => 'مجموعه']);
        $absent = $this->manuscript(['shelfmark' => '9999']);
        $pages = $this->pages();

        $this->artisan('catalogs:match-edition', ['edition' => 'masjid-azam-ostadi', 'pages' => $pages, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, Ref::count());
        $this->artisan('catalogs:match-edition', ['edition' => 'masjid-azam-ostadi', 'pages' => $pages])->assertSuccessful();

        $ref = Ref::where('manuscript_id', $ms->id)->sole();
        $this->assertSame([Ref::SOURCE_AI_MATCH, Ref::STATUS_UNVERIFIED, Matcher::METHOD_TITLE, 100, 414, 415, 415, 606, $this->edition->id, 2],
            [$ref->source, $ref->status, $ref->match_method, $ref->confidence, $ref->page_start, $ref->page_end, $ref->shelfmark_page, $ref->entry_number, $ref->catalog_volume_id, $ref->volume_number]);
        $this->assertStringContainsString('ش 1939', $ref->raw_text);

        // «2000» stands in two entries on two pages and the title fits neither: recorded with low confidence
        $low = Ref::where('manuscript_id', $generic->id)->sole();
        $this->assertSame(Matcher::METHOD_AMBIGUOUS, $low->match_method);
        $this->assertSame(10, $low->confidence);
        $this->assertStringContainsString('جاهای دیگر', $low->notes);
        $this->assertSame(0, Ref::where('manuscript_id', $absent->id)->count());

        // a reviewed match is kept as it is on the next run, and nothing is duplicated
        $ref->update(['status' => Ref::STATUS_REJECTED, 'page_start' => 1]);
        $this->artisan('catalogs:match-edition', ['edition' => 'masjid-azam-ostadi', 'pages' => $pages])->assertSuccessful();
        $this->assertSame([1, Ref::STATUS_REJECTED], [$ref->fresh()->page_start, $ref->fresh()->status]);
        $this->assertSame(2, Ref::where('source', Ref::SOURCE_AI_MATCH)->count());
        unlink($pages);
    }

    public function test_the_manuscript_page_links_the_entry_page_with_the_review_badge()
    {
        $ms = $this->manuscript(['metadata' => ['catalog_citation' => '[ف مخ: 2 - 817]']]);
        $ref = Ref::create(['manuscript_id' => $ms->id, 'catalog_volume_id' => $this->edition->id, 'volume_number' => 2, 'page_start' => 414, 'shelfmark_page' => 415, 'source' => Ref::SOURCE_AI_MATCH, 'confidence' => 90]);
        Ref::create(['manuscript_id' => $ms->id, 'catalog_volume_id' => $this->fankhaVolume->id, 'raw_text' => '[ف مخ: 2 - 817]', 'volume_number' => 2, 'page_start' => 817, 'source' => Ref::SOURCE_FANKHA]);
        $note = 'این نشانی را هوشواره با تطبیق شماره‌ی نسخه استخراج کرده و هنوز به دست انسان بازبینی نشده است.';

        $this->get(route('manuscripts.show', $ms))->assertOk()
            // the library catalogue first, Fankha last, without repeating the volume title under it
            ->assertSeeInOrder(['در مآخذ نسخه‌شناسی', 'چاپ ۱۳۶۵', 'دیدن برگه در فنخا'])
            ->assertDontSee($this->fankhaVolume->title)->assertSee('فهرست نسخه‌های خطی کتابخانه مسجد اعظم قم، چاپ ۱۳۶۵، رضا استادی، ج ۲، ص ۴۱۴')->assertSee('catalogs/masjid-azam-ostadi/v02/p0414.webp', false)
            ->assertSee('masjid-azam-ostadi')
            ->assertSee($note)
            ->assertSee('گزارش نشانی نادرست');

        $ref->update(['status' => Ref::STATUS_VERIFIED]);
        $this->get(route('manuscripts.show', $ms))->assertSee('ص ۴۱۴')->assertDontSee($note);

        $ref->update(['status' => Ref::STATUS_REJECTED]);
        $this->get(route('manuscripts.show', $ms))->assertDontSee('ص ۴۱۴')->assertSee($this->fankhaVolume->title);
    }

    public function test_reviewers_verify_and_reject_machine_matches_in_the_queue()
    {
        $admin = User::firstOrCreate(['email' => 'refs-admin@fahares.net'], ['name' => 'داور آزمایشی', 'password' => 'password123', 'role' => 'admin']);
        $admin->forceFill(['role' => 'admin'])->save();
        $user = User::firstOrCreate(['email' => 'refs-user@fahares.net'], ['name' => 'کاربر', 'password' => 'password123', 'role' => 'user']);
        $high = Ref::create(['manuscript_id' => $this->manuscript(['shelfmark' => '3002'])->id, 'catalog_volume_id' => $this->edition->id, 'volume_number' => 2, 'page_start' => 7, 'shelfmark_page' => 8, 'source' => Ref::SOURCE_AI_MATCH, 'match_method' => 'shelfmark_title', 'confidence' => 95]);
        $low = Ref::create(['manuscript_id' => $this->manuscript(['shelfmark' => '2000'])->id, 'catalog_volume_id' => $this->edition->id, 'volume_number' => 2, 'page_start' => 50, 'source' => Ref::SOURCE_AI_MATCH, 'match_method' => 'ambiguous', 'confidence' => 10, 'notes' => 'جاهای دیگر: ص 60']);

        $this->actingAs($user)->get(route('admin.catalog-references.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.catalog-references.verify', $high))->assertForbidden();

        $this->actingAs($admin)->get(route('admin.catalog-references.index', ['volume' => $this->edition->id]))->assertOk()
            ->assertSee('۳۰۰۲')->assertSee('۲۰۰۰')->assertSee('شماره در ص ۸')->assertSee('جاهای دیگر')->assertDontSee('scan-source');
        $this->actingAs($admin)->get(route('admin.catalog-references.index', ['confidence' => 'low']))->assertOk()
            ->assertSee('۲۰۰۰')->assertDontSee('۳۰۰۲');

        $this->actingAs($admin)->post(route('admin.catalog-references.verify', $high))->assertRedirect();
        $this->actingAs($admin)->post(route('admin.catalog-references.reject', $low))->assertRedirect();
        $this->assertSame([Ref::STATUS_VERIFIED, $admin->id], [$high->fresh()->status, $high->fresh()->reviewed_by]);
        $this->assertNotNull($high->fresh()->reviewed_at);
        $this->assertSame(Ref::STATUS_REJECTED, $low->fresh()->status);
        $this->actingAs($admin)->get(route('admin.catalog-references.index'))->assertOk()->assertDontSee('۳۰۰۲');
        $this->actingAs($admin)->get(route('admin.catalog-references.index', ['status' => 'verified']))->assertOk()->assertSee('۳۰۰۲')->assertSee('داور آزمایشی');

        // Fankha rows are not machine matches and cannot be reviewed here
        $fankha = Ref::create(['manuscript_id' => $high->manuscript_id, 'catalog_volume_id' => $this->fankhaVolume->id, 'source' => Ref::SOURCE_FANKHA]);
        $this->actingAs($admin)->post(route('admin.catalog-references.verify', $fankha))->assertNotFound();
    }

    private function admin(): User
    {
        $admin = User::firstOrCreate(['email' => 'refs-admin@fahares.net'], ['name' => 'داور آزمایشی', 'password' => 'password123', 'role' => 'admin']);
        $admin->forceFill(['role' => 'admin'])->save();

        return $admin;
    }

    private function suggestAddress(Manuscript $ms, string $field, string $value)
    {
        return $this->postJson('/suggestions', ['type' => 'manuscript', 'id' => $ms->id, 'kind' => 'field', 'field_name' => $field,
            'suggested_value' => $value, 'rationale_citation' => 'برگه‌ی چاپی فهرست را دیدم']);
    }

    private function approveLatest(Manuscript $ms, array $data = [])
    {
        $s = FieldSuggestion::where('suggestable_id', $ms->id)->where('status', 'pending')->latest('id')->firstOrFail();

        return $this->actingAs($this->admin())->post("/admin/suggestions/{$s->id}/approve", $data + ['applied_value' => $s->suggested_value, 'outcome' => 'adopt']);
    }

    public function test_address_pencils_appear_beside_each_source_in_suggestion_mode()
    {
        $ms = $this->manuscript();
        $ref = Ref::create(['manuscript_id' => $ms->id, 'catalog_volume_id' => $this->edition->id, 'volume_number' => 2, 'page_start' => 414, 'source' => Ref::SOURCE_AI_MATCH, 'confidence' => 90]);

        $this->get(route('manuscripts.show', $ms))->assertOk()
            ->assertSee("field: 'fankha_page'", false)
            ->assertSee("field: 'catalog_reference_{$ref->id}'", false)
            ->assertSee('نشانی در فهرست نسخه‌های خطی کتابخانه مسجد اعظم قم، چاپ ۱۳۶۵، رضا استادی')
            ->assertSee('ج ۲، ص ۴۱۴');
    }

    public function test_an_approved_address_corrects_the_reference_and_the_matcher_keeps_it()
    {
        $ms = $this->manuscript(['shelfmark' => '1939']);
        $ms->work->update(['primary_title' => 'دیوان قاسمی']);
        $pages = $this->pages();
        $this->artisan('catalogs:match-edition', ['edition' => 'masjid-azam-ostadi', 'pages' => $pages])->assertSuccessful();
        $ref = Ref::where('manuscript_id', $ms->id)->sole();
        $this->assertSame(414, $ref->page_start);

        // another copy's reference cannot be corrected through this one
        $other = $this->manuscript(['shelfmark' => '1']);
        $this->suggestAddress($other, "catalog_reference_{$ref->id}", 'ج ۲، ص ۴۱۶')->assertStatus(422);
        $this->suggestAddress($ms, "catalog_reference_{$ref->id}", 'ص ۴۱۶')->assertStatus(422);

        $this->suggestAddress($ms, "catalog_reference_{$ref->id}", 'ج 2، ص 416')->assertOk();
        $this->assertSame('ج ۲، ص ۴۱۶', FieldSuggestion::latest('id')->first()->suggested_value);
        $this->assertSame('ج ۲، ص ۴۱۴', FieldSuggestion::latest('id')->first()->current_value);
        $this->approveLatest($ms)->assertSessionHasNoErrors();

        $ref->refresh();
        $this->assertSame([416, Ref::SOURCE_MANUAL, Ref::STATUS_VERIFIED, $this->admin()->id], [$ref->page_start, $ref->source, $ref->status, $ref->reviewed_by]);
        $this->assertSame([1, 1, 1], [$ms->fresh()->volume_number, $ms->fresh()->page_start, $ms->fresh()->sequence_number], 'the Fankha columns are untouched');
        $this->get(route('manuscripts.show', $ms))->assertSee('ج ۲، ص ۴۱۶')->assertDontSee('هنوز به دست انسان بازبینی نشده');

        $this->artisan('catalogs:match-edition', ['edition' => 'masjid-azam-ostadi', 'pages' => $pages])->assertSuccessful();
        $this->assertSame([416], Ref::where('manuscript_id', $ms->id)->pluck('page_start')->all());

        // the rollback of the approval puts the machine match back
        $this->actingAs($this->admin())->post('/admin/audit-logs/' . AuditLog::where('action', 'verify')->latest('id')->value('id') . '/rollback')->assertSessionHas('success');
        $this->assertSame([414, Ref::SOURCE_AI_MATCH, Ref::STATUS_UNVERIFIED], [$ref->fresh()->page_start, $ref->fresh()->source, $ref->fresh()->status]);
        unlink($pages);
    }

    public function test_a_copy_reported_absent_loses_the_link_and_fankha_addresses_are_applied_to_the_copy()
    {
        $ms = $this->manuscript();
        $ref = Ref::create(['manuscript_id' => $ms->id, 'catalog_volume_id' => $this->edition->id, 'volume_number' => 2, 'page_start' => 414, 'source' => Ref::SOURCE_AI_MATCH, 'confidence' => 30]);

        $this->suggestAddress($ms, "catalog_reference_{$ref->id}", 'نیست')->assertOk();
        $this->approveLatest($ms)->assertSessionHasNoErrors();
        $this->assertSame(Ref::STATUS_REJECTED, $ref->fresh()->status);
        $this->get(route('manuscripts.show', $ms))->assertDontSee('masjid-azam-ostadi/v02/p0414.webp', false);

        $this->suggestAddress($ms, 'fankha_page', 'نیست')->assertStatus(422);
        $this->suggestAddress($ms, 'fankha_page', 'ج ۳، ص ۱۲، ردیف ۴')->assertOk();
        $this->assertSame('ج ۱، ص ۱، ردیف ۱', FieldSuggestion::latest('id')->first()->current_value);
        $this->approveLatest($ms)->assertSessionHasNoErrors();
        $this->assertSame([3, 12, 4], [$ms->fresh()->volume_number, $ms->fresh()->page_start, $ms->fresh()->sequence_number]);
    }

    public function test_bibliography_citations_are_rewritten_in_their_own_form()
    {
        $old = ['catalog' => 'dharia', 'volume' => 25, 'page' => 101];
        $rewrite = fn (array $entries, int $v, int $p, array $o = null) => \App\Support\BibliographyLinks::rewriteCitation($entries, $o ?? $old, $v, $p);

        $this->assertSame(['منزوی 977/2', 'الذریعه 102/25'], $rewrite(['منزوی 977/2', 'الذریعه 101/25'], 25, 102));
        $this->assertSame(['الذریعه 50/24'], $rewrite(['الذریعه 101/25'], 24, 50));
        $this->assertSame(['الذریعة ج 25، ص 102'], $rewrite(['الذریعة ج 25، ص 101'], 25, 102));
        $joined = ['catalog' => 'dharia', 'volume' => 24, 'page' => 131];
        $this->assertSame(['الذریعه 130/24 و 132'], $rewrite(['الذریعه 130/24 و 131'], 24, 132, $joined));
        $this->assertNull($rewrite(['الذریعه 130/24 و 131'], 25, 132, $joined), 'a bare page cannot change volume');
        $this->assertNull($rewrite(['منزوی 977/2'], 25, 102), 'the page is not cited');
    }

    public function test_work_source_addresses_are_suggested_and_applied()
    {
        $work = Work::create(['primary_title' => 'اثر مآخذدار', 'clean_title' => 'اثر مآخذدار', 'slug' => 'work-bib-' . uniqid(),
            'volume_number' => 3, 'page_start' => 10, 'page_end' => 11, 'bibliography' => ['منزوی 977/2', 'الذریعه 101/25']]);
        $suggest = fn (string $field, string $value) => $this->postJson('/suggestions', ['type' => 'work', 'id' => $work->id, 'kind' => 'field',
            'field_name' => $field, 'suggested_value' => $value, 'rationale_citation' => 'برگه‌ی چاپی را دیدم']);
        $approve = function () use ($work) {
            $s = FieldSuggestion::where('suggestable_type', Work::class)->where('suggestable_id', $work->id)->where('status', 'pending')->latest('id')->firstOrFail();

            return $this->actingAs($this->admin())->post("/admin/suggestions/{$s->id}/approve", ['applied_value' => $s->suggested_value, 'outcome' => 'adopt']);
        };

        $this->get(route('works.show', $work))->assertOk()
            ->assertSee("field: 'bibliography_page_dharia_25_101'", false)->assertSee("field: 'fankha_page'", false);

        $suggest('bibliography_page_dharia_25_99', 'ج ۲۵، ص ۱۰۲')->assertStatus(422);
        $suggest('bibliography_page_dharia_25_101', 'ج ۲۵، ص ۱۰۲')->assertOk();
        $this->assertSame(['ج ۲۵، ص ۱۰۱', 'ج ۲۵، ص ۱۰۲'], [FieldSuggestion::latest('id')->first()->current_value, FieldSuggestion::latest('id')->first()->suggested_value]);
        $approve()->assertSessionHasNoErrors();
        $this->assertSame(['منزوی 977/2', 'الذریعه 102/25'], $work->fresh()->bibliography);

        $suggest('fankha_page', 'ج ۴، ص ۲۰')->assertOk();
        $approve()->assertSessionHasNoErrors();
        $this->assertSame([4, 20, 21], [$work->fresh()->volume_number, $work->fresh()->page_start, $work->fresh()->page_end]);

        // the rollback restores the bibliography text
        $this->actingAs($this->admin())->post('/admin/audit-logs/' . AuditLog::where('action', 'verify')->where('auditable_id', $work->id)->orderBy('id')->value('id') . '/rollback')->assertSessionHas('success');
        $this->assertSame(['منزوی 977/2', 'الذریعه 101/25'], $work->fresh()->bibliography);
    }
}

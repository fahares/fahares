<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CatalogVolume;
use App\Models\FieldSuggestion;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\SourceEntry;
use App\Models\SourceVolume;
use App\Models\User;
use App\Models\Work;
use App\Sources\Extractors\Extractors;
use App\Sources\Extractors\NumberedCatalogueExtractor;
use Tests\TestCase;

/** An entry's printed text is taken again at its corrected place, from its source's pages (step 13.6). */
class SourceTextRefreshTest extends TestCase
{
    /** Pages 595-597 of vol. 4 of the Masjid-e A'zam catalogue, shortened: treatises 6-9 of the composite volume 2967. */
    private const PAGES = [
        595 => "6 ـ سؤال و جواب «58 ـ 65» (کلام ـ فارسی)\nاز: محمد باقر.\n\n7 ـ اجوبة مسائل علی اکبر بن محمد سمیع «76 ـ 83» (کلام ـ عربی)\nاز: احمد بن زین الدین احسایی، ـ 1242 ق\n",
        596 => "آغاز: «الحمد لله....ان الرجل الافخر المکرم».\n\n8 ـ کیفیت بقاء اجساد ائمه معصومین «84 ـ 89» (کلام ـ فارسی)\nاز: سید کاظم رشتی\n\n9 ـ اجوبة مسائل الاصفهانی «91 ـ 93» (کلام ـ عربی و فارسی)\nاز: ؟.\nانجام: «...و هیچ بذهن شما خطور نکرده است»\nرساله اول نستعلیق محمد رضا در 1300\n",
        597 => "رساله نهم بدون تاریخ. تحریر سده 13\n97 برگ، 10 × 18\n\n( 3007 )\n\n1 ـ اصول دین «3 ـ 24» (کلام ـ فارسی)\nاز: استر آبادی\n",
    ];

    protected Manuscript $manuscript;

    protected SourceVolume $volume;

    protected SourceEntry $entry;

    protected function setUp(): void
    {
        parent::setUp();
        $library = Library::create(['name' => 'مسجد اعظم', 'city' => 'قم', 'slug' => 'masjid-azam-' . uniqid()]);
        $cited = CatalogVolume::create(['title' => 'فهرست نسخ خطی کتابخانه مسجد اعظم قم - جلد چهارم', 'volume_number' => 4, 'library_id' => $library->id]);
        $edition = CatalogVolume::create(['title' => 'فهرست نسخه‌های خطی کتابخانه مسجد اعظم قم - جلد چهارم', 'volume_number' => 4, 'library_id' => $library->id,
            'edition_of_id' => $cited->id, 'facsimile_code' => 'masjid-azam-ostadi', 'edition_label' => 'کتابخانه مسجد اعظم، به کوشش رضا استادی']);
        $this->volume = SourceVolume::findOrFail($edition->id);
        // the head of the composite volume stands two pages before; the extractor reads the whole volume
        $pages = [593 => "( 2967 )\n\n1 ـ رساله اول «1 ـ 10» (کلام ـ عربی)\nاز: ؟\n\n2 ـ رساله دوم «11 ـ 20» (کلام ـ عربی)\nاز: ؟\n",
            594 => "3 ـ رساله سوم «21 ـ 30» (کلام ـ عربی)\nاز: ؟\n\n4 ـ رساله چهارم «31 ـ 40» (کلام ـ عربی)\nاز: ؟\n\n5 ـ مقاله‌ای در معرفت (2 برگ)\n"] + self::PAGES;
        foreach ($pages as $page => $text) {
            \DB::table('source_pages')->insert(['source_volume_id' => $this->volume->id, 'page' => $page, 'text' => $text]);
        }
        $this->volume->source->update(['extractor' => 'numbered_catalogue']);

        $work = Work::create(['primary_title' => 'اجوبة مسائل الاصفهانی', 'clean_title' => 'اجوبة مسائل الاصفهانی', 'slug' => 'w-retext-' . uniqid(), 'volume_number' => 1, 'page_start' => 801]);
        $this->manuscript = Manuscript::create(['work_id' => $work->id, 'library_id' => $library->id, 'shelfmark' => '2967/7', 'volume_number' => 1, 'page_start' => 801, 'sequence_number' => 1]);
        $this->entry = SourceEntry::create(['citable_type' => Manuscript::class, 'citable_id' => $this->manuscript->id, 'source_id' => $this->volume->source_id,
            'source_volume_id' => $this->volume->id, 'volume_number' => 4, 'page_start' => 595, 'entry_number' => 7, 'origin' => SourceEntry::ORIGIN_MATCH,
            'match_method' => 'shelfmark_single_page', 'confidence' => 40, 'entry_text' => 'متن رساله‌ی هفتم', 'copy_text' => 'یادداشت‌های مجموعه',
            'text_volume_number' => 4, 'text_page' => 595, 'text_status' => SourceEntry::TEXT_AUTO]);
    }

    protected function admin(): User
    {
        $admin = User::firstOrCreate(['email' => 'retext-admin@fahares.net'], ['name' => 'داور', 'password' => 'password123', 'role' => 'admin']);
        $admin->forceFill(['role' => 'admin'])->save();

        return $admin;
    }

    protected function suggest(string $field, string $value): FieldSuggestion
    {
        $this->postJson('/suggestions', ['type' => 'manuscript', 'id' => $this->manuscript->id, 'kind' => 'field', 'field_name' => $field,
            'suggested_value' => $value, 'rationale_citation' => 'برگه‌ی چاپی را دیدم'])->assertOk();

        return FieldSuggestion::latest('id')->firstOrFail();
    }

    protected function approve(FieldSuggestion $s, array $data = [])
    {
        return $this->actingAs($this->admin())->post("/admin/suggestions/{$s->id}/approve", $data + ['applied_value' => $s->suggested_value, 'outcome' => 'adopt']);
    }

    public function test_the_numbered_catalogue_extractor_finds_the_treatise_of_the_shelfmarks_part()
    {
        $extractor = new NumberedCatalogueExtractor;
        $pages = Extractors::pages($this->volume->id);

        $found = $extractor->extract($pages, '2967/9', 596);
        $this->assertSame([596, 9], [$found['page_start'], $found['entry_number']]);
        $this->assertStringStartsWith('9 ـ اجوبة مسائل الاصفهانی', $found['entry_text']);
        $this->assertStringContainsString('رساله نهم بدون تاریخ', $found['copy_text'], "the volume's closing notes run over to the next page");
        $this->assertSame(7, $extractor->extract($pages, '2967/7', 595)['entry_number']);
        $this->assertNull($extractor->extract($pages, '2967/9', 595, ['strict_part' => true]), 'a corrected shelfmark never takes another treatise of the volume');
        // as the matcher found it: the shelfmark's part is a preference, as Fankha and the catalogue may number a treatise apart
        $this->assertSame(6, $extractor->extract($pages, '2967/6', 595)['entry_number']);
        $this->assertSame(7, $extractor->extract($pages, '2967/6', 595, ['entry_number' => 7])['entry_number']);
        $this->assertNull($extractor->extract($pages, '1111', 596));
    }

    public function test_an_address_is_approved_with_the_text_at_the_new_page_and_rolled_back()
    {
        $this->manuscript->update(['shelfmark' => '2967/9']);
        $s = $this->suggest("source_entry_{$this->entry->id}", 'ج ۴، ص ۵۹۶');

        $this->actingAs($this->admin())->get("/admin/suggestions/{$s->id}")->assertOk()
            ->assertSeeInOrder(['متن مأخذ در جای تازه', 'متن رساله‌ی هفتم', 'متن در جای تازه (ص ۵۹۶، مدخل ۹)', '۹ ـ اجوبة مسائل الاصفهانی', 'متن را هم به متن جای تازه تغییر بده'])
            ->assertSee('name="retext[]" value="' . $this->entry->id . '"', false);

        $this->approve($s, ['retext' => [$this->entry->id]])->assertSessionHasNoErrors();
        $entry = $this->entry->fresh();
        $this->assertSame([596, 9, 596, SourceEntry::TEXT_AUTO, SourceEntry::ORIGIN_MANUAL], [$entry->page_start, $entry->entry_number, $entry->text_page, $entry->text_status, $entry->origin]);
        $this->assertStringStartsWith('9 ـ اجوبة مسائل الاصفهانی', $entry->entry_text);
        $this->assertFalse($entry->textIsOutOfPlace());
        $this->get(route('manuscripts.show', $this->manuscript))->assertSee('۹ ـ اجوبة مسائل الاصفهانی')->assertDontSee('data-stale-text', false);

        $log = AuditLog::where('action', 'verify')->latest('id')->firstOrFail();
        $this->actingAs($this->admin())->post("/admin/audit-logs/{$log->id}/rollback")->assertSessionHas('success');
        $entry = $this->entry->fresh();
        $this->assertSame(['متن رساله‌ی هفتم', 595, 7, 595, SourceEntry::ORIGIN_MATCH], [$entry->entry_text, $entry->page_start, $entry->entry_number, $entry->text_page, $entry->origin]);
    }

    public function test_an_address_approved_without_the_text_leaves_it_marked()
    {
        $this->manuscript->update(['shelfmark' => '2967/9']);
        $this->approve($this->suggest("source_entry_{$this->entry->id}", 'ج ۴، ص ۵۹۶'))->assertSessionHasNoErrors();

        $entry = $this->entry->fresh();
        $this->assertSame(['متن رساله‌ی هفتم', 596, SourceEntry::TEXT_STALE], [$entry->entry_text, $entry->page_start, $entry->text_status]);
        $this->actingAs($this->admin())->get(route('manuscripts.show', $this->manuscript))
            ->assertSee('data-stale-text', false)->assertSee(route('admin.source-entries.text', $entry));
    }

    public function test_a_shelfmark_is_approved_with_the_text_of_the_treatise_it_now_names()
    {
        $s = $this->suggest('shelfmark', '2967/9');
        $this->actingAs($this->admin())->get("/admin/suggestions/{$s->id}")->assertOk()->assertSee('متن در جای تازه (ص ۵۹۶، مدخل ۹)');

        $this->approve($s, ['retext' => [$this->entry->id]])->assertSessionHasNoErrors();
        $entry = $this->entry->fresh();
        $this->assertSame(['2967/9', 596, 9], [$this->manuscript->fresh()->shelfmark, $entry->page_start, $entry->entry_number]);
        $this->assertStringStartsWith('9 ـ اجوبة مسائل الاصفهانی', $entry->entry_text);
    }

    public function test_a_reviewer_takes_the_text_again_or_writes_it_by_hand()
    {
        $user = User::firstOrCreate(['email' => 'retext-user@fahares.net'], ['name' => 'کاربر', 'password' => 'password123', 'role' => 'user']);
        $this->actingAs($user)->get(route('admin.source-entries.text', $this->entry))->assertForbidden();
        $this->manuscript->update(['shelfmark' => '2967/9']);
        $this->entry->update(['page_start' => 596]);

        $this->actingAs($this->admin())->get(route('admin.source-entries.text', $this->entry))->assertOk()
            ->assertSeeInOrder(['متنی که صفحه‌های مأخذ در نشانی کنونی نشان می‌دهند', '۹ ـ اجوبة مسائل الاصفهانی', 'همین متن را بگذار', 'ویرایش دستی', 'متن صفحه‌های مأخذ (ص ۵۹۶ تا ۵۹۶)'])
            ->assertSee('صفحه‌ی بعد');
        // the entry runs over two pages: «صفحه‌ی بعد» adds the next
        $this->actingAs($this->admin())->get(route('admin.source-entries.text', ['entry' => $this->entry, 'from' => 596, 'to' => 597]))
            ->assertSeeInOrder(['ص ۵۹۶', 'ص ۵۹۷', 'رساله نهم بدون تاریخ']);

        $this->actingAs($this->admin())->post(route('admin.source-entries.update', $this->entry), ['mode' => 'found'])->assertRedirect(route('manuscripts.show', $this->manuscript->id));
        $this->assertSame([9, SourceEntry::TEXT_AUTO], [$this->entry->fresh()->entry_number, $this->entry->fresh()->text_status]);

        $this->actingAs($this->admin())->post(route('admin.source-entries.update', $this->entry), ['mode' => 'manual', 'entry_text' => "متن درست‌شده\r\n", 'copy_text' => ''])->assertRedirect();
        $entry = $this->entry->fresh();
        $this->assertSame(['متن درست‌شده', null, SourceEntry::TEXT_EDITED, 596], [$entry->entry_text, $entry->copy_text, $entry->text_status, $entry->text_page]);

        // the edit is in the audit log and rolls back
        $log = AuditLog::where('auditable_type', SourceEntry::class)->where('action', 'update')->latest('id')->firstOrFail();
        $this->actingAs($this->admin())->post("/admin/audit-logs/{$log->id}/rollback")->assertSessionHas('success');
        $this->assertSame(SourceEntry::TEXT_AUTO, $this->entry->fresh()->text_status);
    }

    public function test_the_command_takes_again_the_texts_marked_as_taken_elsewhere()
    {
        // corrected before the review could take the text: the address moved, the text stayed
        $this->manuscript->update(['shelfmark' => '2967/9']);
        $this->entry->update(['page_start' => 596, 'origin' => SourceEntry::ORIGIN_MANUAL, 'text_status' => SourceEntry::TEXT_STALE]);
        $edited = SourceEntry::create(['citable_type' => Manuscript::class, 'citable_id' => $this->manuscript->id, 'source_id' => $this->volume->source_id,
            'source_volume_id' => $this->volume->id, 'volume_number' => 4, 'page_start' => 596, 'origin' => SourceEntry::ORIGIN_MANUAL,
            'entry_text' => 'متن دستی', 'text_volume_number' => 4, 'text_page' => 595, 'text_status' => SourceEntry::TEXT_EDITED]);

        $this->artisan('sources:retext', ['--dry-run' => true])->expectsOutputToContain('entry 9, p. 596')->assertSuccessful();
        $this->assertSame('متن رساله‌ی هفتم', $this->entry->fresh()->entry_text);

        $this->artisan('sources:retext')->assertSuccessful();
        $entry = $this->entry->fresh();
        $this->assertSame([9, 596, SourceEntry::TEXT_AUTO], [$entry->entry_number, $entry->text_page, $entry->text_status]);
        $this->assertSame('متن دستی', $edited->fresh()->entry_text, 'a text written by hand is never taken again');
        $this->assertSame(1, AuditLog::where('auditable_type', SourceEntry::class)->where('auditable_id', $entry->id)->where('action', 'update')->count());
    }

    public function test_pages_are_imported_and_the_source_is_given_its_format()
    {
        $path = tempnam(sys_get_temp_dir(), 'pages') . '.jsonl';
        file_put_contents($path, json_encode(['page' => 598, 'text' => 'ص ۵۹۸'], JSON_UNESCAPED_UNICODE) . "\n" . json_encode(['page' => 595, 'text' => 'متن تازه'], JSON_UNESCAPED_UNICODE));
        $this->volume->source->update(['extractor' => null]);

        $this->artisan('sources:import-pages', ['volume' => 'masjid-azam-ostadi', 'pages' => $path, '--extractor' => 'unknown'])->assertFailed();
        $this->artisan('sources:import-pages', ['volume' => 'masjid-azam-ostadi', 'pages' => $path, '--extractor' => 'numbered_catalogue'])->assertSuccessful();
        unlink($path);

        $this->assertSame('numbered_catalogue', $this->volume->source->fresh()->extractor);
        $this->assertSame(['متن تازه', 'ص ۵۹۸'], \DB::table('source_pages')->where('source_volume_id', $this->volume->id)->whereIn('page', [595, 598])->orderBy('page')->pluck('text')->all());
    }
}

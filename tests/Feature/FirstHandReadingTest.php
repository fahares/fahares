<?php

namespace Tests\Feature;

use App\Console\Commands\FirsthandCoverageCommand;
use App\Models\Claim;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\Work;
use App\Sources\FirstHand\CopyLineComparer;
use App\Sources\FirstHand\Readers\GolpayeganiReader;
use App\Sources\FirstHand\Readers\MasjidAzamReader;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** A library catalogue's own copy lines read against the records (backlog item 147, steps 14.249 and 14.250). */
class FirstHandReadingTest extends TestCase
{
    public function test_the_golpayegani_line_gives_its_fields()
    {
        $line = (new GolpayeganiReader())->read("۱۴۲۸۷) نسخه شماره: ۸۶۴۶/۳-۵۸/۹۶\nآغاز:بسم الله\nنسخ، محمد بن ملامحمد بروجردی، ۱۳رمضان۱۱۹۷، بدون جلد، ۴برگ، ۷۷-۸۰، ۱۲ سطر ، ۱۳×۱۸سم");

        $this->assertSame('8646/3-58/96', $line->values['shelfmark']);
        $this->assertSame(['نسخ'], $line->values['scripts']);
        $this->assertSame('محمد بن ملامحمد بروجردی', $line->values['scribe']);
        $this->assertSame('13رمضان1197', $line->values['copy_date']);
        $this->assertSame(4, $line->values['folios']);
        $this->assertSame(12, $line->values['lines']);
        $this->assertSame([13.0, 18.0], $line->values['dimensions']);

        // «بی کا» and «مولف» are no names; a note is no date; «نسخه» is not the script «نسخ»
        $bika = (new GolpayeganiReader())->read("1) نسخه شماره: ۱۲\nتحریری، بی کا، قرن ۱۱، جلد:تیماج، ۱۷۴برگ");
        $this->assertTrue($bika->values['bika']);
        $this->assertSame('قرن 11', $bika->values['copy_date']);
        $author = (new GolpayeganiReader())->read("2) نسخه شماره: ۱۳\nنستعلیق، مولف، در اول نسخه تملکی از محمد در 1274، ۱۰برگ");
        $this->assertTrue($author->values['autograph']);
        $this->assertArrayNotHasKey('copy_date', $author->values);
        $this->assertArrayNotHasKey('scripts', (new GolpayeganiReader())->read("3) نسخه شماره: ۱۴\nنسخه حاضر ناتمام است، ۵برگ")->values);
    }

    public function test_the_masjid_azam_line_gives_its_fields_and_a_collection_only_its_volume()
    {
        $line = (new MasjidAzamReader())->read('ش 1776، 231 برگ، 18×24 نستعلیق محمدبن حسن اصفهانی در 995. از سوره یونس تا سوره کهف را دارد.');
        $this->assertSame('1776', $line->values['shelfmark']);
        $this->assertSame(231, $line->values['folios']);
        $this->assertSame(['نستعلیق'], $line->values['scripts']);
        $this->assertSame('محمدبن حسن اصفهانی', $line->values['scribe']);
        $this->assertSame('995', $line->values['copy_date']);

        $undated = (new MasjidAzamReader())->read('ش 2308، 114 برگ، 14×21، نستعلیق، بیکا، بیتا. از انجام افتادگی دارد.');
        $this->assertTrue($undated->values['bika']);
        $this->assertTrue($undated->values['bita']);

        $collection = (new MasjidAzamReader())->read("( 649 )\nرساله اول تا هفتم تاریخ کتابت ندارد.\n212 برگ، 11 × 18");
        $this->assertTrue($collection->wholeVolume);
        $this->assertSame('649', $collection->values['shelfmark']);
        $this->assertSame([11.0, 18.0], $collection->values['dimensions']);
    }

    public function test_the_comparer_confirms_what_agrees_and_claims_what_differs_or_fills()
    {
        $ms = new Manuscript(['shelfmark' => '7639/2-51/99', 'folios' => 80, 'dimensions' => '16/5×20/5سم', 'scribe_name' => 'کاشانی، محمد بن محمد باقر',
            'copy_date_raw' => '1203ق', 'copy_date_from' => 1203, 'copy_date_to' => 1203, 'copy_date_hijri_year' => 1203, 'script_names' => 'نستعلیق شکسته']);
        $line = (new GolpayeganiReader())->read("5213) نسخه شماره: ۲/ ۷۶۳۹-۵۱/۹۹\nشکسته نستعلیق، محمد بن محمد باقرکاشانی، ۱۲۰۳، بدون جلد، 85برگ، ۱۹ سطر ، ۲۰×۱۶سم");
        $result = (new CopyLineComparer())->compare($line, $ms);

        $this->assertEqualsCanonicalizing(['shelfmark', 'dimensions', 'scribe_name', 'copy_date', 'script_names'], $result['confirmed']);
        $claims = collect($result['claims'])->keyBy('field');
        $this->assertSame('85', $claims['folios']['value']);
        $this->assertFalse($claims['folios']['fills']);
        $this->assertTrue($claims['lines']['fills']);

        // a line that names the volume of a treatise confirms its shelfmark, and its leaves are the volume's
        $part = new Manuscript(['shelfmark' => '1341/2', 'folios' => 30]);
        $result = (new CopyLineComparer())->compare((new MasjidAzamReader())->read('ش 1341، 212 برگ، 14×20، نسخ، بیکا، بیتا.'), $part);
        $this->assertContains('shelfmark', $result['confirmed']);
        $this->assertNotContains('folios', collect($result['claims'])->pluck('field')->all());
    }

    public function test_the_command_saves_confirmed_fields_and_claims_and_runs_again_cleanly()
    {
        [$entry, $ms] = $this->copyWithLine('ش 176، 397 برگ، 18×28، نسخ محمد در 1084، با سر لوح زرین.', ['shelfmark' => '176', 'folios' => 379, 'scribe_name' => 'محمد']);

        $this->artisan('sources:read-copy-lines', ['source' => 'masjid-azam-ostadi'])->assertOk();
        $this->assertNull($entry->fresh()->fields_read_at);

        $this->artisan('sources:read-copy-lines', ['source' => 'masjid-azam-ostadi', '--apply' => true])->assertOk();
        $this->artisan('sources:read-copy-lines', ['source' => 'masjid-azam-ostadi', '--apply' => true])->assertOk();
        $entry->refresh();
        $this->assertContains('shelfmark', $entry->confirmed_fields);
        $this->assertContains('scribe_name', $entry->confirmed_fields);
        $this->assertNotNull($entry->fields_read_at);
        $claim = Claim::where('subject_id', $ms->id)->where('field', 'folios')->sole();
        $this->assertSame(['397', '379', Claim::STATUS_CANDIDATE], [$claim->value_text, $claim->current_text, $claim->status]);
        $this->assertSame(379, $ms->fresh()->folios);

        // an accepted claim stays when the line is read again
        $claim->update(['status' => Claim::STATUS_ACCEPTED]);
        $this->artisan('sources:read-copy-lines', ['source' => 'masjid-azam-ostadi', '--apply' => true])->assertOk();
        $this->assertSame(2, Claim::where('subject_id', $ms->id)->where('field', 'folios')->count());
    }

    public function test_the_coverage_counts_a_copy_complete_when_its_core_fields_are_confirmed()
    {
        [, $ms] = $this->copyWithLine('ش 2308، 114 برگ، 14×21، نستعلیق، بیکا، بیتا.', ['shelfmark' => '2308', 'folios' => 114]);
        [, $other] = $this->copyWithLine('ش 2309، 50 برگ، 14×21، نستعلیق، بیکا، بیتا.', ['shelfmark' => '2309', 'folios' => 52]);
        $this->artisan('sources:read-copy-lines', ['source' => 'masjid-azam-ostadi', '--apply' => true])->assertOk();

        $this->artisan('fahares:firsthand-coverage')->assertOk();
        $report = Cache::get(FirsthandCoverageCommand::CACHE_KEY);
        $this->assertSame(2, $report['total']['was_read']);
        $this->assertSame(1, $report['total']['complete']);
        $this->assertSame(1, $report['total']['fields']['folios']['claimed']);

        // the claim accepted, the second copy is complete too
        Claim::where('subject_id', $other->id)->where('field', 'folios')->update(['status' => Claim::STATUS_ACCEPTED]);
        $this->artisan('fahares:firsthand-coverage')->assertOk();
        $this->assertSame(2, Cache::get(FirsthandCoverageCommand::CACHE_KEY)['total']['complete']);
    }

    /** @return array{SourceEntry, Manuscript} */
    private function copyWithLine(string $line, array $values): array
    {
        $library = Library::firstOrCreate(['slug' => 'masjid-azam-test'], ['name' => 'مسجد اعظم', 'city' => 'قم']);
        $source = Source::firstOrCreate(['code' => 'masjid-azam-ostadi'], ['kind' => Source::KIND_LIBRARY_CATALOGUE, 'rank' => 10,
            'title' => 'فهرست نسخه‌های خطی کتابخانه مسجد اعظم قم', 'library_id' => $library->id]);
        $work = Work::create(['primary_title' => 'اثر آزمون', 'clean_title' => 'اثر آزمون', 'slug' => 'work-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $ms = Manuscript::create($values + ['work_id' => $work->id, 'library_id' => $library->id, 'volume_number' => 1, 'page_start' => 1]);
        $entry = SourceEntry::create(['citable_type' => Manuscript::class, 'citable_id' => $ms->id, 'source_id' => $source->id,
            'origin' => SourceEntry::ORIGIN_FANKHA_CITATION, 'copy_text' => $line, 'volume_number' => 1, 'page_start' => 10]);

        return [$entry, $ms];
    }
}

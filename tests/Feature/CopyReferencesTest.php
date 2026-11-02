<?php

namespace Tests\Feature;

use App\Models\FieldSuggestion;
use App\Models\Manuscript;
use App\Models\Work;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Fankha's «همان نسخه بالا» linked, merged or kept apart, and the work and copy pages that show it (step 14.16) */
class CopyReferencesTest extends TestCase
{
    use DatabaseTransactions;

    private Work $work;

    private function copy(string $library, string $shelfmark, string $text, array $extra = []): Manuscript
    {
        return Manuscript::create(['work_id' => $this->work->id, 'volume_number' => 31, 'page_start' => 12, 'sequence_number' => 1,
            'library' => $library, 'city' => 'مشهد', 'shelfmark' => $shelfmark, 'raw_text' => $text] + $extra);
    }

    private function relation(Manuscript $from, string $type): ?int
    {
        $id = DB::table('manuscript_relations')->where('manuscript_id', $from->id)->where('type', $type)->value('related_manuscript_id');

        return $id ? (int) $id : null;
    }

    public function test_entries_said_to_be_the_copy_above_are_linked_merged_or_kept_apart_and_reverted()
    {
        $this->work = Work::create(['primary_title' => 'اثر آزمون همان نسخه', 'clean_title' => 'اثر آزمون همان نسخه', 'slug' => 'same-copy-' . uniqid(), 'volume_number' => 31, 'page_start' => 12]);
        $ilahiyat = $this->copy('الهیات', '217', 'مشهد؛ الهیات؛ شماره نسخه: 217 بی‌کا، بی‌تا [ف: 1- 128]',
            ['is_bika' => true, 'folios' => 11, 'copy_place' => 'هرات', 'metadata' => ['catalog_citation' => '[ف: 1- 128]']]);
        $mowlavi = $this->copy('مولوی', '217', 'مشهد؛ مولوی؛ شماره نسخه: 217 انجام: کند مجنون مسکین را دعایی همان نسخه بالا [نشریه: 5 - 31]',
            ['folios' => 12, 'explicit_text' => 'کند مجنون مسکین را دعایی', 'metadata' => ['catalog_citation' => '[نشریه: 5 - 31]', 'editorial_notes' => 'همان نسخه بالا']]);
        $film = $this->copy('دانشگاه', '3897-ف', 'تهران؛ دانشگاه؛ شماره نسخه: 3897-ف نسخه اصل: همان نسخه بالا [فیلمها ف: 2 - 277]',
            ['is_facsimile' => true, 'metadata' => ['catalog_citation' => '[فیلمها ف: 2 - 277]']]);
        $majlis = $this->copy('مجلس', '14176/2', 'تهران؛ مجلس؛ شماره نسخه: 14176/2 خط: نستعلیق، بی‌کا، بی‌تا', ['copy_date_raw' => '1100ق']);
        $marashi = $this->copy('مرعشی', '5', 'قم؛ مرعشی؛ شماره نسخه: 5 همان نسخه بالا [نشریه: 2 - 10]');
        $photo = $this->copy('دائرة المعارف', '431-عکسی', 'تهران؛ دائرة المعارف؛ شماره نسخه: 431-عکسی اصل نسخه: همان نسخه بالا [عکسی ف: 2-1]',
            ['metadata' => ['catalog_citation' => '[عکسی ف: 2-1]']]);
        $outside = $this->copy('دانشگاه', '9-ف', 'تهران؛ دانشگاه؛ شماره نسخه: 9-ف نسخه اصل: اسکوریال - مادرید ش 1324؛ خط: نسخ [فیلمها ف: 1 - 1]', ['is_facsimile' => true]);
        $backup = storage_path('app/test-link-copy-references-' . uniqid() . '.json');

        $this->artisan('fahares:link-copy-references', ['--work' => $this->work->id, '--backup' => $backup])->assertSuccessful();

        // The copy in the collection Fankha records as moved is one record with the copy in its present library
        $this->assertNull(Manuscript::find($mowlavi->id));
        $kept = $ilahiyat->fresh();
        $this->assertSame(['مولوی', '217', 'نشریه: 5 - 31'], [$kept->metadata['former_locations'][0]['library'], $kept->metadata['former_locations'][0]['shelfmark'], $kept->metadata['former_locations'][0]['catalog']]);
        $this->assertSame('کند مجنون مسکین را دعایی', $kept->explicit_text);
        $this->assertSame(11, (int) $kept->folios);
        // The other catalogue's leaf count is not lost: it is an alternative reading citing that catalogue
        $reading = FieldSuggestion::where('suggestable_id', $kept->id)->where('field_name', 'folios')->firstOrFail();
        $this->assertSame(['alternative', '12', '11', 'فهرست نشریه: 5 - 31'], [$reading->standing, $reading->suggested_value, $reading->current_value, $reading->credit_name]);
        $this->assertArrayNotHasKey('editorial_notes', $kept->metadata);
        $this->assertSame('[ف: 1- 128]', $kept->catalog_citation);

        // A film of the copy above; «همان نسخه بالا» alone, kept apart; a photograph written «اصل نسخه»
        $this->assertSame($ilahiyat->id, $this->relation($film, 'facsimile_of'));
        $this->assertSame($majlis->id, $this->relation($marashi, 'possibly_same'));
        $this->assertSame($marashi->id, $this->relation($photo, 'facsimile_of'));
        $this->assertTrue((bool) $photo->fresh()->is_facsimile);

        // The work page lists the handwritten copies with their photographs and repeated descriptions under them
        $this->get(route('works.show', $this->work))->assertOk()
            ->assertSeeText('۲ نسخه‌ی خطی · ۲ عکسی از همین نسخه‌ها · ۱ عکسیِ دیگر · ۱ معرفیِ احتمالاً تکراری')
            ->assertSee('data-linked-copy="' . $film->id . '"', false)
            ->assertSee('data-linked-copy="' . $marashi->id . '"', false)
            ->assertSeeText('مادر: اسکوریال - مادرید ش ۱۳۲۴');

        // The film's page names its mother and shows what it does not say from there
        $this->get(route('manuscripts.show', $film))->assertOk()
            ->assertSeeText('این نسخه عکسی یا میکروفیلمِ')
            ->assertSee('هرات</span> <span class="text-[11px] text-muted">از نسخه‌ی مادر</span>', false);
        $this->get(route('manuscripts.show', $marashi))->assertOk()->assertSeeText('احتمالاً همان نسخه‌ی');
        $this->get(route('manuscripts.show', $outside))->assertOk()->assertSeeText('نسخه‌ی مادر: اسکوریال - مادرید ش ۱۳۲۴')->assertSeeText('این نسخه‌ی مادر را در سامانه یافته‌اید؟');
        $this->get(route('manuscripts.show', $mowlavi->id))->assertRedirect();

        // Run again: nothing more to do
        $this->artisan('fahares:link-copy-references', ['--work' => $this->work->id, '--dry-run' => true])
            ->expectsOutputToContain('skipped')->assertSuccessful();

        $this->artisan('fahares:link-copy-references', ['--revert' => $backup])->assertSuccessful();
        @unlink($backup);
        $this->assertNotNull(Manuscript::find($mowlavi->id));
        $this->assertSame('همان نسخه بالا', Manuscript::find($mowlavi->id)->metadata['editorial_notes']);
        $this->assertArrayNotHasKey('former_locations', $ilahiyat->fresh()->metadata ?? []);
        $this->assertSame(0, FieldSuggestion::where('suggestable_id', $ilahiyat->id)->count());
        $this->assertSame(0, DB::table('manuscript_relations')->whereIn('manuscript_id', [$film->id, $marashi->id, $photo->id])->count());
        $this->assertFalse((bool) $photo->fresh()->is_facsimile);
        $this->assertTrue((bool) $film->fresh()->is_facsimile);
    }

    public function test_the_catalogue_note_on_a_photographs_mother()
    {
        $this->assertSame('اسکوریال - مادرید ش 1324', (new Manuscript(['raw_text' => 'نسخه اصل: اسکوریال - مادرید ش 1324؛ خط: نسخ']))->original_note);
        $this->assertNull((new Manuscript(['raw_text' => 'نسخه اصل: همان نسخه اصل بالا [فیلمها ف: 3-89]']))->original_note);
        $this->assertNull((new Manuscript(['raw_text' => 'خط: نسخ']))->original_note);
    }
}

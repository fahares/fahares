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
        $this->get(route('manuscripts.show', $marashi))->assertOk()->assertSeeText('احتمالاً همان نسخه‌ی')
            ->assertSee('data-same-answer', false)->assertSeeText('دو نسخه‌ی جدایند');
        $this->get(route('manuscripts.show', $outside))->assertOk()->assertSeeText('نسخه‌ی مادر: اسکوریال - مادرید ش ۱۳۲۴')->assertSeeText('این نسخه‌ی مادر را در سامانه یافته‌اید؟');
        $this->get(route('manuscripts.show', $mowlavi->id))->assertRedirect();

        // Run again: nothing more to do
        $this->artisan('fahares:link-copy-references', ['--work' => $this->work->id, '--dry-run' => true])
            ->expectsOutputToContain('skipped')->assertSuccessful();

        // «Two copies, not one» takes the possibly_same link away, a rollback brings it back, and a later run respects it
        $added = app(\App\Suggestions\RecordRelations::class)->add($marashi, $majlis, 'distinct_from', null, null);
        $this->assertNull($this->relation($marashi, 'possibly_same'));
        $this->assertSame('possibly_same', $added['old']['removed_record_relations']['rows'][0]['type']);
        $this->artisan('fahares:link-copy-references', ['--work' => $this->work->id, '--dry-run' => true])
            ->expectsOutputToContain('distinct')->assertSuccessful();

        $this->artisan('fahares:link-copy-references', ['--revert' => $backup])->assertSuccessful();
        @unlink($backup);
        $this->assertNotNull(Manuscript::find($mowlavi->id));
        $this->assertSame('همان نسخه بالا', Manuscript::find($mowlavi->id)->metadata['editorial_notes']);
        $this->assertArrayNotHasKey('former_locations', $ilahiyat->fresh()->metadata ?? []);
        $this->assertSame(0, FieldSuggestion::where('suggestable_id', $ilahiyat->id)->count());
        // The run's links go; the reader's «two copies» stays
        $this->assertSame(['distinct_from'], DB::table('manuscript_relations')->whereIn('manuscript_id', [$film->id, $marashi->id, $photo->id])->pluck('type')->all());
        $this->assertFalse((bool) $photo->fresh()->is_facsimile);
        $this->assertTrue((bool) $film->fresh()->is_facsimile);
    }

    public function test_one_merge_of_a_run_is_split_back_into_two_copies_said_to_be_possibly_the_same()
    {
        $this->work = Work::create(['primary_title' => 'اثر آزمون جداسازی', 'clean_title' => 'اثر آزمون جداسازی', 'slug' => 'split-copy-' . uniqid(), 'volume_number' => 31, 'page_start' => 12]);
        $razavi = $this->copy('رضوی', '3967/1', 'مشهد؛ رضوی؛ شماره نسخه: 3967/1 واقف: نائینی، مرداد 1311؛ 14گ [ف: 12-361]',
            ['folios' => 14, 'metadata' => ['catalog_citation' => '[ف: 12-361]']]);
        $sah = $this->copy('شیخ علی حیدر', '1039/7', 'مشهد؛ شیخ علی حیدر؛ شماره نسخه: 1039/7 همان نسخه بالا، 9گ [مؤید: 3-50]',
            ['folios' => 9, 'metadata' => ['catalog_citation' => '[مؤید: 3-50]', 'editorial_notes' => 'همان نسخه بالا']]);
        $ilahiyat = $this->copy('الهیات', '217', 'مشهد؛ الهیات؛ شماره نسخه: 217 [ف: 1- 128]', ['folios' => 11]);
        $mowlavi = $this->copy('مولوی', '217', 'مشهد؛ مولوی؛ شماره نسخه: 217 همان نسخه بالا، 12گ [نشریه: 5 - 31]',
            ['folios' => 12, 'metadata' => ['catalog_citation' => '[نشریه: 5 - 31]', 'editorial_notes' => 'همان نسخه بالا']]);
        $backup = storage_path('app/test-link-copy-references-' . uniqid() . '.json');
        $this->artisan('fahares:link-copy-references', ['--work' => $this->work->id, '--backup' => $backup])->assertSuccessful();
        $this->assertNull(Manuscript::find($sah->id));
        $this->assertNull(Manuscript::find($mowlavi->id));

        $this->artisan('fahares:link-copy-references', ['--revert' => $backup, '--only' => (string) $razavi->id, '--dry-run' => true])
            ->expectsOutputToContain("Would split {$sah->id} from {$razavi->id}: 1 readings removed")->assertSuccessful();
        $this->assertNull(Manuscript::find($sah->id));

        $this->artisan('fahares:link-copy-references', ['--revert' => $backup, '--only' => (string) $razavi->id])
            ->expectsOutputToContain("Split {$sah->id} from {$razavi->id}")->assertSuccessful();
        // The two are records again, with their own catalogue and no reading, linked for a reviewer
        $this->assertSame('شیخ علی حیدر', Manuscript::find($sah->id)->library);
        $this->assertArrayNotHasKey('former_locations', $razavi->fresh()->metadata ?? []);
        $this->assertSame(0, FieldSuggestion::where('suggestable_id', $razavi->id)->count());
        $this->assertSame($razavi->id, $this->relation(Manuscript::find($sah->id), 'possibly_same'));
        // The other merge of the run stays
        $this->assertNull(Manuscript::find($mowlavi->id));
        $this->assertSame(1, FieldSuggestion::where('suggestable_id', $ilahiyat->id)->count());

        // A second split finds nothing to do
        $this->artisan('fahares:link-copy-references', ['--revert' => $backup, '--only' => (string) $razavi->id])
            ->expectsOutputToContain('already split')->assertSuccessful();
        @unlink($backup);
    }

    public function test_the_catalogue_note_on_a_photographs_mother()
    {
        $this->assertSame('اسکوریال - مادرید ش 1324', (new Manuscript(['raw_text' => 'نسخه اصل: اسکوریال - مادرید ش 1324؛ خط: نسخ']))->original_note);
        $this->assertNull((new Manuscript(['raw_text' => 'نسخه اصل: همان نسخه اصل بالا [فیلمها ف: 3-89]']))->original_note);
        $this->assertNull((new Manuscript(['raw_text' => 'خط: نسخ']))->original_note);
    }
}

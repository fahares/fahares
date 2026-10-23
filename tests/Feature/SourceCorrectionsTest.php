<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FieldSuggestion;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\ManuscriptCatalogReference as Ref;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\SourceVolume;
use App\Models\User;
use App\Models\Work;
use App\Suggestions\RecordMerger;
use App\Suggestions\RecordSplitter;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** Corrections and reviews are written to source_entries (step 13.3), and the record's columns follow. */
class SourceCorrectionsTest extends TestCase
{
    protected Work $work;

    protected Manuscript $manuscript;

    protected SourceVolume $edition;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('sources:backfill', ['--only' => ['registry']]);
        $library = Library::create(['name' => 'مسجد اعظم', 'city' => 'قم', 'slug' => 'masjid-azam-' . uniqid()]);
        $this->work = Work::create(['primary_title' => 'دیوان قدسی', 'clean_title' => 'دیوان قدسی', 'slug' => 'work-corr-' . uniqid(),
            'volume_number' => 15, 'page_start' => 120, 'page_end' => 121, 'raw_text' => 'متن فنخای اثر', 'bibliography' => ['منزوی 977/2', 'الذریعه 101/25']]);
        $this->manuscript = $this->manuscript('1939');
        $source = Source::create(['code' => 'test-edition', 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'title' => 'فهرست نسخه‌های خطی کتابخانه مسجد اعظم قم', 'library_id' => $library->id]);
        $this->edition = SourceVolume::create(['source_id' => $source->id, 'volume_number' => 2, 'facsimile_code' => 'masjid-azam-ostadi', 'edition_label' => '۱۳۸۸، به کوشش رضا استادی']);
    }

    protected function manuscript(string $shelfmark): Manuscript
    {
        return Manuscript::create(['work_id' => $this->work->id, 'shelfmark' => $shelfmark, 'volume_number' => 15, 'page_start' => 121,
            'sequence_number' => 2, 'raw_text' => "قم؛ مسجد اعظم؛ شماره نسخه: {$shelfmark}"]);
    }

    protected function match(Manuscript $ms, array $values = []): SourceEntry
    {
        return SourceEntry::create($values + ['citable_type' => Manuscript::class, 'citable_id' => $ms->id, 'source_id' => $this->edition->source_id,
            'source_volume_id' => $this->edition->id, 'volume_number' => 2, 'page_start' => 414, 'origin' => SourceEntry::ORIGIN_MATCH, 'confidence' => 90,
            'entry_text' => 'متن مدخل فهرست', 'text_volume_number' => 2, 'text_page' => 414, 'text_status' => SourceEntry::TEXT_AUTO]);
    }

    protected function admin(): User
    {
        return User::firstOrCreate(['email' => 'corr-admin@fahares.net'], ['name' => 'داور', 'password' => 'password123', 'role' => 'admin'])->forceFill(['role' => 'admin']);
    }

    protected function suggestAndApprove(Work|Manuscript $record, string $field, string $value)
    {
        $this->postJson('/suggestions', ['type' => $record instanceof Work ? 'work' : 'manuscript', 'id' => $record->id, 'kind' => 'field',
            'field_name' => $field, 'suggested_value' => $value, 'rationale_citation' => 'برگه‌ی چاپی را دیدم'])->assertOk();
        $s = FieldSuggestion::where('suggestable_id', $record->id)->where('status', 'pending')->latest('id')->firstOrFail();
        $admin = $this->admin();
        $admin->save();

        return $this->actingAs($admin)->post("/admin/suggestions/{$s->id}/approve", ['applied_value' => $s->suggested_value, 'outcome' => 'adopt']);
    }

    protected function rollbackLatest(): void
    {
        $log = AuditLog::where('action', 'verify')->latest('id')->firstOrFail();
        $this->actingAs($this->admin())->post("/admin/audit-logs/{$log->id}/rollback")->assertSessionHas('success');
    }

    protected function primary(Work|Manuscript $record): SourceEntry
    {
        return SourceEntry::where('citable_type', $record::class)->where('citable_id', $record->id)->where('is_primary', true)->sole();
    }

    public function test_saving_a_record_writes_its_fankha_entry_and_bibliography()
    {
        $entry = $this->primary($this->work);
        $this->assertSame([1, 15, 120, 121, 'متن فنخای اثر', 15, 120], [$entry->source_id, $entry->volume_number, $entry->page_start, $entry->page_end,
            $entry->entry_text, $entry->text_volume_number, $entry->text_page]);
        $this->assertSame('fankha', $entry->sourceVolume->facsimile_code);
        $this->assertSame('قم؛ مسجد اعظم؛ شماره نسخه: 1939', $this->primary($this->manuscript)->copy_text);
        $this->assertSame(['منزوی 977/2', 'الذریعه 101/25'], $this->work->sources()->bibliography()->pluck('citation_text')->all());

        $this->work->update(['raw_text' => 'متن تازه', 'bibliography' => ['الذریعه 101/25']]);
        $this->assertSame('متن تازه', $this->primary($this->work)->entry_text);
        $this->assertSame(['الذریعه 101/25'], $this->work->fresh()->sources()->bibliography()->pluck('citation_text')->all());
    }

    public function test_a_fankha_address_moves_the_entry_with_its_text_and_rolls_back()
    {
        $this->suggestAndApprove($this->manuscript, 'fankha_page', 'ج ۱۶، ص ۳۰، ردیف ۴')->assertSessionHasNoErrors();

        $entry = $this->primary($this->manuscript);
        $this->assertSame([16, 30, 16, 30], [$entry->volume_number, $entry->page_start, $entry->text_volume_number, $entry->text_page]);
        $this->assertFalse($entry->textIsOutOfPlace(), "Fankha's text is the record's: it stands where the record does");
        $this->assertSame([16, 30, 4], [$this->manuscript->fresh()->volume_number, $this->manuscript->fresh()->page_start, $this->manuscript->fresh()->sequence_number]);

        $this->rollbackLatest();
        $this->assertSame([15, 121], [$this->primary($this->manuscript)->volume_number, $this->primary($this->manuscript)->page_start]);
    }

    public function test_a_bibliography_page_is_corrected_in_the_entry_and_the_column()
    {
        $this->get(route('works.show', $this->work))->assertSee("field: 'bibliography_page_dharia_25_101'", false);
        $this->suggestAndApprove($this->work, 'bibliography_page_dharia_25_101', 'ج ۲۵، ص ۱۰۲')->assertSessionHasNoErrors();

        $this->assertSame(['منزوی 977/2', 'الذریعه 102/25'], $this->work->fresh()->bibliography);
        $cited = $this->work->fresh()->sources()->bibliography()[1];
        $this->assertSame(['الذریعه 102/25', 25, 102], [$cited->citation_text, $cited->volume_number, $cited->page_start]);

        $this->rollbackLatest();
        $this->assertSame('الذریعه 101/25', $this->work->fresh()->sources()->bibliography()[1]->citation_text);
    }

    public function test_a_catalogue_address_marks_the_text_taken_at_the_old_page_and_rolls_back()
    {
        $entry = $this->match($this->manuscript);
        $this->get(route('manuscripts.show', $this->manuscript))->assertSee("field: 'source_entry_{$entry->id}'", false);

        $this->suggestAndApprove($this->manuscript, "source_entry_{$entry->id}", 'ج ۲، ص ۴۱۶')->assertSessionHasNoErrors();
        $entry->refresh();
        $this->assertSame([416, SourceEntry::ORIGIN_MANUAL, SourceEntry::STATUS_VERIFIED, SourceEntry::TEXT_STALE], [$entry->page_start, $entry->origin, $entry->status, $entry->text_status]);
        $this->get(route('manuscripts.show', $this->manuscript))->assertSee('این متن از نشانی پیشین (ج ۲، ص ۴۱۴) برداشته شده')->assertSee('ج ۲، ص ۴۱۶');

        $this->rollbackLatest();
        $entry->refresh();
        $this->assertSame([414, SourceEntry::ORIGIN_MATCH, SourceEntry::STATUS_UNVERIFIED, SourceEntry::TEXT_AUTO], [$entry->page_start, $entry->origin, $entry->status, $entry->text_status]);
    }

    public function test_a_correction_logged_before_step_13_3_rolls_back_its_reference_and_entry()
    {
        $ref = Ref::create(['manuscript_id' => $this->manuscript->id, 'volume_number' => 2, 'page_start' => 416, 'source' => Ref::SOURCE_MANUAL, 'status' => Ref::STATUS_VERIFIED]);
        $entry = $this->match($this->manuscript, ['page_start' => 416, 'origin' => SourceEntry::ORIGIN_MANUAL, 'status' => SourceEntry::STATUS_VERIFIED,
            'legacy_type' => 'catalog_reference', 'legacy_id' => $ref->id]);
        $log = AuditLog::create(['user_id' => null, 'auditable_type' => Manuscript::class, 'auditable_id' => $this->manuscript->id, 'action' => 'verify',
            'old_values' => ['catalog_reference' => ['id' => $ref->id, 'page_start' => 414, 'source' => Ref::SOURCE_AI_MATCH, 'status' => Ref::STATUS_UNVERIFIED]],
            'new_values' => ['catalog_reference' => ['id' => $ref->id, 'page_start' => 416, 'source' => Ref::SOURCE_MANUAL, 'status' => Ref::STATUS_VERIFIED]]]);
        $admin = $this->admin();
        $admin->save();

        $this->actingAs($admin)->post("/admin/audit-logs/{$log->id}/rollback")->assertSessionHas('success');
        $this->assertSame([414, Ref::SOURCE_AI_MATCH], [$ref->fresh()->page_start, $ref->fresh()->source]);
        $this->assertSame([414, SourceEntry::ORIGIN_MATCH, SourceEntry::STATUS_UNVERIFIED], [$entry->fresh()->page_start, $entry->fresh()->origin, $entry->fresh()->status]);
    }

    public function test_a_bulk_verification_of_references_reverts_through_their_entries()
    {
        $admin = $this->admin();
        $admin->save();
        $ref = Ref::create(['manuscript_id' => $this->manuscript->id, 'volume_number' => 2, 'page_start' => 414, 'source' => Ref::SOURCE_AI_MATCH,
            'status' => Ref::STATUS_VERIFIED, 'reviewed_by' => $admin->id, 'reviewed_at' => '2026-10-21 10:00:00']);
        $entry = $this->match($this->manuscript, ['status' => SourceEntry::STATUS_VERIFIED, 'reviewed_by' => $admin->id, 'reviewed_at' => '2026-10-21 10:00:00',
            'legacy_type' => 'catalog_reference', 'legacy_id' => $ref->id]);
        $batch = AuditLog::create(['user_id' => $admin->id, 'auditable_type' => Ref::class, 'auditable_id' => 0, 'action' => Ref::ACTION_BULK_VERIFY,
            'old_values' => ['status' => 'unverified', 'ids' => [$ref->id]], 'new_values' => ['status' => 'verified', 'count' => 1, 'filters' => [], 'reviewed_at' => '2026-10-21 10:00:00']]);

        $this->assertTrue(SourceEntry::lastBulkVerify()->is($batch));
        $this->assertSame([1, 0], SourceEntry::revertBulkVerify($batch, $admin));
        $this->assertSame([SourceEntry::STATUS_UNVERIFIED, Ref::STATUS_UNVERIFIED], [$entry->fresh()->status, $ref->fresh()->status]);
        $this->assertNull(SourceEntry::lastBulkVerify());
    }

    public function test_a_merged_copy_brings_its_entries_and_an_undo_takes_them_back()
    {
        $dup = $this->manuscript('1940');
        $match = $this->match($dup);
        $primary = $this->primary($dup);
        $merger = app(RecordMerger::class);

        $old = $merger->mergeManuscript($dup, $this->manuscript);
        $this->assertSame($this->manuscript->id, $match->fresh()->citable_id);
        $this->assertSame([$this->manuscript->id, false, null], [$primary->fresh()->citable_id, $primary->fresh()->is_primary, $primary->fresh()->legacy_type],
            "the duplicate's Fankha entry stays citable on the kept copy");
        $this->assertSame($this->primary($this->manuscript)->id, $this->manuscript->fresh()->sources()->primary()->id);
        // the duplicate's texts stand with the kept copy's, all alike: the library catalogue first, then both Fankha entries
        $this->get(route('manuscripts.show', $this->manuscript))
            ->assertSeeInOrder(['متن مدخل فهرست', 'متن فنخا', 'قم؛ مسجد اعظم؛ شماره نسخه: ۱۹۳۹', 'متن فنخا', 'قم؛ مسجد اعظم؛ شماره نسخه: ۱۹۴۰'])
            ->assertDontSee('متن مأخذ')->assertDontSee('ارجاع‌های دیگر');

        $merger->undoManuscriptMerge($this->manuscript->fresh(), $old);
        $this->assertSame([$dup->id, true, 'manuscript', $dup->id], [$primary->fresh()->citable_id, $primary->fresh()->is_primary, $primary->fresh()->legacy_type, $primary->fresh()->legacy_id]);
        $this->assertSame($dup->id, $match->fresh()->citable_id);
    }

    public function test_a_split_work_gets_its_fankha_place_and_an_undo_removes_it()
    {
        $splitter = app(RecordSplitter::class);
        $old = $splitter->splitWork($this->work, ['title' => 'اثر جداشده'], [$this->manuscript->id]);

        $created = Work::findOrFail($old['created_id']);
        $entry = $this->primary($created);
        $this->assertSame([15, 120, null], [$entry->volume_number, $entry->page_start, $entry->entry_text]);

        $splitter->undo(Work::class, $this->work->id, $old);
        $this->assertSame(0, SourceEntry::where('citable_type', Work::class)->where('citable_id', $created->id)->count());
    }
}

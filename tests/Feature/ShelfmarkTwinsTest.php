<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\EntityRedirect;
use App\Models\FieldSuggestion;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\SourceVolume;
use App\Models\Work;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** A copy under Fankha's wrong shelfmark is merged into its record under the printed one, or takes the printed one (step 14.387) */
class ShelfmarkTwinsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_wrong_shelfmark_is_merged_or_corrected_from_the_data_file_and_a_run_reverts()
    {
        $library = Library::create(['name' => 'مروی', 'city' => 'تهران', 'slug' => 'marvi-' . uniqid()]);
        $source = Source::create(['code' => 'catalogue-' . uniqid(), 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'rank' => 10, 'title' => 'فهرست مروی', 'library_id' => $library->id]);
        $volume = SourceVolume::create(['source_id' => $source->id, 'volume_number' => 1, 'facsimile_code' => 'twins-test', 'page_first' => 1, 'page_last' => 400]);
        $work = Work::create(['primary_title' => 'صحيفة الرضا', 'clean_title' => 'صحيفة الرضا', 'slug' => 'twin-w-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $other = Work::create(['primary_title' => 'دیوان راقم', 'clean_title' => 'دیوان راقم', 'slug' => 'twin-o-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $copy = fn (Work $w, string $shelfmark, array $extra = []) => Manuscript::create(['work_id' => $w->id, 'library_id' => $library->id, 'library' => 'مروی', 'city' => 'تهران',
            'shelfmark' => $shelfmark, 'volume_number' => 1, 'page_start' => 1, 'raw_text' => "تهران؛ مروی؛ شماره نسخه: {$shelfmark} [ف: - 284]", 'metadata' => ['catalog_citation' => '[ف: - 284]']] + $extra);
        $cite = fn (Manuscript $m, Source $s, string $status) => SourceEntry::create(['citable_type' => Manuscript::class, 'citable_id' => $m->id, 'source_id' => $s->id,
            'source_volume_id' => $s->is($source) ? $volume->id : null, 'page_start' => 284, 'role' => SourceEntry::ROLE_ENTRY, 'origin' => SourceEntry::ORIGIN_FANKHA_CITATION,
            'status' => SourceEntry::STATUS_UNVERIFIED, 'citation_text' => '[ف: - 284]', 'text_status' => $status]);

        $kept = $copy($work, '521/2', ['copy_date_raw' => '1074ق', 'copy_date_from' => 1074, 'copy_date_to' => 1074, 'paper' => null]);
        $removed = $copy($work, '512/2', ['copy_date_raw' => '1071ق', 'copy_date_from' => 1071, 'copy_date_to' => 1071, 'paper' => 'ترمه']);
        $cite($kept, $source, SourceEntry::TEXT_AUTO);
        $repeat = $cite($removed, $source, SourceEntry::TEXT_NONE);
        // a copy's own Fankha entry is made with it (SourceEntrySync)
        $fankha = Source::where('code', 'fankha')->firstOrFail();
        $place = SourceEntry::where('citable_type', Manuscript::class)->where('citable_id', $removed->id)->where('source_id', $fankha->id)->first()
            ?? $cite($removed, $fankha, SourceEntry::TEXT_AUTO);
        $misnumbered = $copy($other, '1230/30');
        $data = tempnam(sys_get_temp_dir(), 'twins');
        file_put_contents($data, json_encode([
            'merges' => [
                ['library' => $library->slug, 'removed' => ['shelfmark' => '512/2', 'work' => $work->slug], 'kept' => ['shelfmark' => '521/2', 'work' => $work->slug], 'fields' => ['copy_date_raw' => 'source']],
                ['library' => $library->slug, 'removed' => ['shelfmark' => '999/1', 'work' => $work->slug], 'kept' => ['shelfmark' => '521/2', 'work' => $work->slug]],
            ],
            'corrections' => [['library' => $library->slug, 'shelfmark' => '1230/30', 'work' => $other->slug, 'to' => '1232/30']],
        ]));
        $backup = storage_path('app/test-merge-shelfmark-twins-' . uniqid() . '.json');

        $this->artisan('fahares:merge-shelfmark-twins', ['--data' => $data])->expectsOutputToContain('Dry run: nothing changed.')->assertSuccessful();
        $this->assertNotNull(Manuscript::find($removed->id));

        $this->artisan('fahares:merge-shelfmark-twins', ['--data' => $data, '--apply' => true, '--backup' => $backup])->expectsOutputToContain('Backup:')->assertSuccessful();
        // the printed shelfmark stays, the print's date is taken, what only the removed record gives fills the kept one
        $this->assertNull(Manuscript::find($removed->id));
        $merged = $kept->fresh();
        $this->assertSame(['521/2', '1071ق', 'ترمه'], [$merged->shelfmark, $merged->copy_date_raw, $merged->paper]);
        $this->assertSame(0, FieldSuggestion::where('suggestable_id', $kept->id)->where('field_name', 'copy_date_raw')->count());
        $this->assertSame($kept->id, EntityRedirect::where('entity_type', 'manuscripts')->where('source_id', $removed->id)->value('target_id'));
        // Fankha's second place stays on the kept copy; the repeat of the printed catalogue's entry goes
        $this->assertSame($kept->id, (int) $place->fresh()->citable_id);
        $this->assertNull(SourceEntry::find($repeat->id));
        $log = AuditLog::where('auditable_id', $kept->id)->where('action', 'merge')->firstOrFail();
        // with the removed copy's own Fankha citation, which the merge drops as a repeat (step 14.455)
        $this->assertContains($repeat->id, array_map('intval', array_column($log->new_values['dropped_entries'], 'id')));
        $this->assertSame('1232/30', $misnumbered->fresh()->shelfmark);
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $misnumbered->id, 'action' => 'update']);

        // a second run finds both done
        $this->artisan('fahares:merge-shelfmark-twins', ['--data' => $data, '--apply' => true])->expectsOutputToContain('done before: ' . $library->slug . ' 512/2')
            ->expectsOutputToContain('Nothing to change.')->assertSuccessful();

        $this->artisan('fahares:merge-shelfmark-twins', ['--revert' => $backup])->expectsOutputToContain('Reverted 1 merges and 1 corrections.')->assertSuccessful();
        $this->assertSame('512/2', Manuscript::find($removed->id)?->shelfmark);
        // the «مجموعه» the merge removed with it is made again
        $this->assertNotNull(Manuscript::find($removed->id)->collection_id);
        $this->assertSame(['1074ق', null], [$kept->fresh()->copy_date_raw, $kept->fresh()->paper]);
        $this->assertSame($removed->id, (int) SourceEntry::find($repeat->id)?->citable_id);
        $this->assertSame($removed->id, (int) $place->fresh()->citable_id);
        $this->assertSame('1230/30', $misnumbered->fresh()->shelfmark);
        @unlink($backup);
        @unlink($data);
    }
}

<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Manuscript;
use App\Models\ManuscriptNote;
use App\Models\SourceEntry;
use App\Models\Work;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PatchTextWordsCommandTest extends TestCase
{
    private array $works = [];
    private array $copies = [];
    private array $notes = [];
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'collection']);
        $this->file = tempnam(sys_get_temp_dir(), 'patch');
    }

    protected function tearDown(): void
    {
        AuditLog::where('auditable_type', Work::class)->whereIn('auditable_id', $this->works)->delete();
        AuditLog::where('auditable_type', Manuscript::class)->whereIn('auditable_id', $this->copies)->delete();
        AuditLog::where('auditable_type', ManuscriptNote::class)->whereIn('auditable_id', $this->notes)->delete();
        DB::table('manuscript_notes')->whereIn('id', $this->notes)->delete();
        DB::table('source_entries')->where('citable_type', Manuscript::class)->whereIn('citable_id', $this->copies)->delete();
        DB::table('source_entries')->where('citable_type', Work::class)->whereIn('citable_id', $this->works)->delete();
        DB::table('manuscripts')->whereIn('id', $this->copies)->delete();
        DB::table('works')->whereIn('id', $this->works)->delete();
        @unlink($this->file);
        parent::tearDown();
    }

    private function work(array $columns): int
    {
        return $this->works[] = \Tests\Support\InsertRecords::work($columns + [
            'primary_title' => 'اثر آزمایشی وصله', 'clean_title' => 'اثر آزمایشی وصله',
            'slug' => 'test-patch-words-' . uniqid(), 'volume_number' => 99, 'page_start' => 1,
        ]);
    }

    private function patchWords(array $patches, bool $apply = true): \Illuminate\Testing\PendingCommand
    {
        file_put_contents($this->file, json_encode(['step' => '0.0', 'patches' => $patches], JSON_UNESCAPED_UNICODE));

        return $this->artisan('fahares:patch-text-words', ['--file' => $this->file] + ($apply ? ['--apply' => true] : []));
    }

    public function test_a_word_is_patched_where_its_stretch_stands_once_and_logged()
    {
        $id = $this->work(['description' => 'و حیث لاتییز فالوقف و عن']);

        $this->patchWords([['table' => 'work', 'id' => $id, 'column' => 'description', 'patches' => [['old' => 'حیث لاتییز فالوقف', 'new' => 'حیث لاتمییز فالوقف']]]])->assertSuccessful();

        $this->assertSame('و حیث لاتمییز فالوقف و عن', DB::table('works')->where('id', $id)->value('description'));
        $log = AuditLog::where('auditable_type', Work::class)->where('auditable_id', $id)->where('action', 'update')->first();
        $this->assertSame('و حیث لاتییز فالوقف و عن', $log->old_values['description']);
        $this->assertSame('fahares:patch-text-words', $log->new_values['command']);

        // a second run finds it done, though the new word stands twice in the field
        DB::table('works')->where('id', $id)->update(['description' => 'و حیث لاتمییز فالوقف و عن لاتمییز']);
        $this->patchWords([['table' => 'work', 'id' => $id, 'column' => 'description', 'patches' => [['old' => 'لاتییز', 'new' => 'لاتمییز']]]])
            ->expectsTable(['outcome', 'fields'], [['patched', 0], ['already', 1], ['conflict', 0], ['protected', 0], ['edited entry', 0], ['missing', 0], ['refused', 0]]);
    }

    public function test_an_edited_or_doubled_stretch_a_protected_column_and_a_foreign_column_are_left()
    {
        $edited = $this->work(['description' => 'متنی که ویرایشگر بازنوشت']);
        $doubled = $this->work(['incipit_text' => 'نگردد و نگردد']);
        $protected = $this->work(['explicit_text' => 'الحسنیه']);
        AuditLog::create(['auditable_type' => Work::class, 'auditable_id' => $protected, 'action' => 'verify', 'new_values' => ['explicit_text' => 'الحسنیه']]);

        $this->patchWords([
            ['table' => 'work', 'id' => $edited, 'column' => 'description', 'patches' => [['old' => 'لاتییز', 'new' => 'لاتمییز']]],
            ['table' => 'work', 'id' => $doubled, 'column' => 'incipit_text', 'patches' => [['old' => 'نگردد', 'new' => 'نگررد']]],
            ['table' => 'work', 'id' => $protected, 'column' => 'explicit_text', 'patches' => [['old' => 'الحسنیه', 'new' => 'الحسینیه']]],
            ['table' => 'work', 'id' => $edited, 'column' => 'primary_title', 'patches' => [['old' => 'اثر', 'new' => 'کتاب']]],
        ])->expectsTable(['outcome', 'fields'], [['patched', 0], ['already', 0], ['conflict', 2], ['protected', 1], ['edited entry', 0], ['missing', 0], ['refused', 1]]);

        $this->assertSame('نگردد و نگردد', DB::table('works')->where('id', $doubled)->value('incipit_text'));
        $this->assertSame('الحسنیه', DB::table('works')->where('id', $protected)->value('explicit_text'));
        $this->assertSame('اثر آزمایشی وصله', DB::table('works')->where('id', $edited)->value('primary_title'));
    }

    public function test_a_copy_note_and_the_copy_entry_text_are_patched_but_a_hand_written_entry_is_not()
    {
        $work = $this->work([]);
        $copy = $this->copies[] = \Tests\Support\InsertRecords::copy([
            'work_id' => $work, 'sequence_number' => 1, 'volume_number' => 99, 'page_start' => 1, 'page_end' => 1,
            'city' => 'قم', 'library' => 'مرعشی', 'shelfmark' => '1', 'raw_text' => 'خط: نسخ؛ اندازه: 10×20سم، قضای خوش',
        ]);
        \App\Sources\SourceEntrySync::syncIds(Manuscript::class, [$copy]);
        $entry = DB::table('source_entries')->where('citable_type', Manuscript::class)->where('citable_id', $copy)->whereNotNull('copy_text')->value('id');
        $note = $this->notes[] = DB::table('manuscript_notes')->insertGetId(['manuscript_id' => $copy, 'position' => 1, 'typed_by' => 'rule', 'type' => 'other', 'text' => 'در حین اموال و وحشت', 'created_at' => now(), 'updated_at' => now()]);

        $this->patchWords([
            ['table' => 'note', 'id' => $note, 'column' => 'text', 'patches' => [['old' => 'حین اموال', 'new' => 'حین اهوال']]],
            ['table' => 'entry', 'id' => $entry, 'column' => 'copy_text', 'patches' => [['old' => 'قضای خوش', 'new' => 'فضای خوش']]],
        ])->assertSuccessful();

        $this->assertSame('در حین اهوال و وحشت', DB::table('manuscript_notes')->where('id', $note)->value('text'));
        $this->assertStringContainsString('فضای خوش', DB::table('source_entries')->where('id', $entry)->value('copy_text'));

        DB::table('source_entries')->where('id', $entry)->update(['text_status' => SourceEntry::TEXT_EDITED, 'copy_text' => 'متن دستی قضای خوش']);
        $this->patchWords([['table' => 'entry', 'id' => $entry, 'column' => 'copy_text', 'patches' => [['old' => 'قضای خوش', 'new' => 'فضای خوش']]]])
            ->expectsTable(['outcome', 'fields'], [['patched', 0], ['already', 0], ['conflict', 0], ['protected', 0], ['edited entry', 1], ['missing', 0], ['refused', 0]]);
        $this->assertSame('متن دستی قضای خوش', DB::table('source_entries')->where('id', $entry)->value('copy_text'));
    }
}

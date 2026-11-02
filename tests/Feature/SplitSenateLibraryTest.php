<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Library;
use App\Models\Manuscript;
use App\Support\SenateLibrary;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The former Senate library gets its own record, apart from the Majlis, and the move can be undone (step 13.148). */
class SplitSenateLibraryTest extends TestCase
{
    private array $libraries = [];

    private array $works = [];

    private array $copies = [];

    private array $volumes = [];

    /** Libraries the test made and its undo removed: their split logs stay behind */
    private array $splitLibraries = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'collection']);
        SenateLibrary::forget();
    }

    protected function tearDown(): void
    {
        if ($senate = SenateLibrary::find()) {
            $this->libraries[] = $senate->id;
        }
        AuditLog::where('auditable_type', Manuscript::class)->whereIn('auditable_id', $this->copies)->delete();
        AuditLog::where('auditable_type', Library::class)->whereIn('auditable_id', array_merge($this->libraries, $this->splitLibraries))->delete();
        DB::table('manuscripts')->whereIn('id', $this->copies)->delete();
        DB::table('works')->whereIn('id', $this->works)->delete();
        DB::table('catalog_volumes')->whereIn('id', $this->volumes)->delete();
        DB::table('libraries')->whereIn('id', $this->libraries)->delete();
        SenateLibrary::forget();
        parent::tearDown();
    }

    private function copy(int $library, string $shelfmark, string $cites): int
    {
        $work = $this->works[] = DB::table('works')->insertGetId(['primary_title' => "اثر آزمایشی سنا {$shelfmark}", 'clean_title' => 'اثر آزمایشی سنا',
            'slug' => 'test-senate-' . uniqid(), 'volume_number' => 99, 'page_start' => 1]);

        return $this->copies[] = DB::table('manuscripts')->insertGetId(['work_id' => $work, 'sequence_number' => 1, 'volume_number' => 99, 'page_start' => 1,
            'city' => 'تهران', 'library' => 'مجلس', 'library_id' => $library, 'shelfmark' => $shelfmark,
            'raw_text' => "تهران؛ مجلس؛ شماره نسخه: {$shelfmark}\nخط: نستعلیق، بی‌کا؛ 38ص (1-38) {$cites}"]);
    }

    public function test_the_senate_copies_and_catalogue_move_to_their_own_library_and_back()
    {
        $majlis = SenateLibrary::majlis();
        if (! $majlis) {
            $majlis = Library::create(['city' => 'تهران', 'name' => 'مجلس', 'country' => 'ایران', 'slug' => 'test-majlis-' . uniqid()]);
            $this->libraries[] = $majlis->id;
        }
        $senateCopy = $this->copy($majlis->id, '9791/1', '[سنا: ف: 2-61]');
        $spaced = $this->copy($majlis->id, '9791/2', '[ سنا - ف: 1-75]');
        $dashed = $this->copy($majlis->id, '9791/3', '[سنا – ف: 2 – 227]');
        $bare = $this->copy($majlis->id, '9791/4', '[سنا ف: 2-272]');
        $majlisCopy = $this->copy($majlis->id, '9791/1', '[ف: 3-6]');
        $reviewed = $this->copy($majlis->id, '9792', '[سنا: ف: 2-62]');
        AuditLog::create(['user_id' => null, 'auditable_type' => Manuscript::class, 'auditable_id' => $reviewed, 'action' => 'verify',
            'old_values' => [], 'new_values' => ['library_id' => $majlis->id, 'direct_edit' => true]]);
        $volume = $this->volumes[] = DB::table('catalog_volumes')->insertGetId(['title' => 'فهرست کتابخانه مجلس شورای اسلامی - مجلس سنا - جلد آزمایشی',
            'volume_number' => 1, 'library_id' => $majlis->id]);

        // A Majlis copy on the Senate's volume that cites the Majlis short catalogue goes to that catalogue
        $short = $this->volumes[] = DB::table('catalog_volumes')->insertGetId(['title' => 'فهرست مختصر نسخه های خطی کتابخانه مجلس شورای اسلامی - آزمایشی',
            'volume_number' => 1, 'library_id' => $majlis->id]);
        $mislinked = $this->copy($majlis->id, '914546/4', '[مختصر ف: - 212]');
        DB::table('manuscripts')->where('id', $mislinked)->update(['catalog_volume_id' => $volume]);

        $this->artisan('fahares:split-senate-library')->expectsOutputToContain('Dry run: nothing written.')->assertSuccessful();
        $this->assertNull(SenateLibrary::find());

        $this->artisan('fahares:split-senate-library', ['--apply' => true])->assertSuccessful();
        $senate = SenateLibrary::find();
        $this->assertNotNull($senate);
        $this->splitLibraries[] = $senate->id;
        $this->assertSame(['مجلس سنا', 'کتابخانه مجلس سنا'], [$senate->name, $senate->full_name]);
        $this->assertStringEndsWith('-' . $senate->id, $senate->slug);
        $row = fn ($id) => DB::table('manuscripts')->where('id', $id)->first();
        $this->assertSame([$senate->id, 'مجلس سنا'], [(int) $row($senateCopy)->library_id, $row($senateCopy)->library]);
        $this->assertSame([$senate->id, $senate->id, $senate->id], [(int) $row($spaced)->library_id, (int) $row($dashed)->library_id, (int) $row($bare)->library_id]);
        $this->assertSame($majlis->id, (int) $row($majlisCopy)->library_id);
        $this->assertSame($majlis->id, (int) $row($reviewed)->library_id);
        $this->assertSame($senate->id, (int) DB::table('catalog_volumes')->where('id', $volume)->value('library_id'));
        $this->assertSame(4, (int) $senate->fresh()->manuscripts_count);
        $this->assertSame([$majlis->id, $short], [(int) $row($mislinked)->library_id, (int) $row($mislinked)->catalog_volume_id]);
        // The corpus commands put a new Senate copy under its library, and leave a Majlis one alone
        $this->assertSame($senate->id, SenateLibrary::libraryFor($majlis->id, 'تهران؛ مجلس؛ شماره نسخه: 1 [سنا: ف: 1-9]'));
        $this->assertSame($majlis->id, SenateLibrary::libraryFor($majlis->id, 'تهران؛ مجلس؛ شماره نسخه: 1 [ف: 1-9]'));
        // Run again: nothing more to move
        $this->artisan('fahares:split-senate-library')->expectsOutputToContain('Dry run')->assertSuccessful();

        $this->artisan('fahares:split-senate-library', ['--undo' => true])->expectsOutputToContain('the Senate library is removed')->assertSuccessful();
        $this->assertNull(SenateLibrary::find());
        $this->assertSame([$majlis->id, 'مجلس'], [(int) $row($senateCopy)->library_id, $row($senateCopy)->library]);
        $this->assertSame($majlis->id, (int) DB::table('catalog_volumes')->where('id', $volume)->value('library_id'));
    }
}

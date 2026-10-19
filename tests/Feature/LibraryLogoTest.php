<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LibraryLogoTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function library(): Library
    {
        return Library::create(['name' => 'آزمایشی' . uniqid(), 'full_name' => 'کتابخانه‌ی آزمایشی نشان', 'city' => 'شهر آزمایشی', 'slug' => 'lib-logo-' . uniqid()]);
    }

    /** A real 1×1 PNG: fake()->image() needs GD, which the app image does not have. */
    private function png(string $name = 'logo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
    }

    private function admin(): User
    {
        $admin = User::firstOrCreate(['email' => 'logo-admin@example.test'], ['name' => 'Logo Admin', 'password' => 'password123', 'role' => 'admin']);
        $admin->role = 'admin';
        $admin->save();

        return $admin;
    }

    public function test_no_logo_or_a_missing_file_gives_no_url_and_no_plate()
    {
        $library = $this->library();
        $this->assertNull($library->logo_url);

        $library->update(['logo_path' => 'libraries/logos/gone.png']);
        $this->assertNull($library->logo_url);
        $this->get(route('libraries.show', $library))->assertOk()->assertDontSee('نشان کتابخانه‌ی آزمایشی نشان');
    }

    public function test_logo_shows_on_library_list_and_manuscript_pages()
    {
        $library = $this->library();
        $library->storeLogo($this->png('logo.png'));
        $url = $library->logo_url;
        $this->assertStringContainsString('storage/libraries/logos/library_' . $library->id . '_', $url);

        $this->get(route('libraries.show', $library))->assertOk()
            ->assertSee('alt="نشان کتابخانه‌ی آزمایشی نشان"', false)
            ->assertSee(e($url), false);

        $this->get(route('libraries.index', ['q' => $library->name]))->assertOk()->assertSee(e($url), false);

        $work = Work::create(['primary_title' => 'اثر آزمایشی نشان', 'clean_title' => 'اثر آزمایشی نشان', 'slug' => 'work-logo-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $manuscript = Manuscript::create(['work_id' => $work->id, 'library_id' => $library->id, 'library' => $library->name, 'city' => $library->city, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1]);
        $this->get(route('manuscripts.show', $manuscript))->assertOk()->assertSee(e($url), false);
    }

    public function test_light_logos_get_the_dark_plate()
    {
        $library = $this->library();
        $library->logo_on_dark = true;
        $library->storeLogo($this->png('logo.png'));

        $this->get(route('libraries.show', $library))->assertOk()->assertSee('bg-[#221C17]', false);
    }

    public function test_admin_uploads_replaces_and_removes_a_logo()
    {
        $library = $this->library();
        $fields = ['name' => $library->name, 'full_name' => $library->full_name, 'city' => $library->city, 'country' => 'ایران'];

        $this->actingAs($this->admin())->put(route('admin.libraries.update', $library), $fields + ['logo' => $this->png('a.png'), 'logo_on_dark' => '1'])
            ->assertRedirect(route('admin.libraries.index'));
        $first = $library->fresh()->logo_path;
        Storage::disk('public')->assertExists($first);
        $this->assertTrue($library->fresh()->logo_on_dark);

        $this->travel(2)->seconds();
        $this->put(route('admin.libraries.update', $library), $fields + ['logo' => $this->png('b.webp')]);
        $second = $library->fresh()->logo_path;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        $this->assertFalse($library->fresh()->logo_on_dark);

        $this->put(route('admin.libraries.update', $library), $fields + ['remove_logo' => '1']);
        $this->assertNull($library->fresh()->logo_path);
        Storage::disk('public')->assertMissing($second);
    }

    public function test_svg_uploads_are_refused()
    {
        $library = $this->library();
        $svg = UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->actingAs($this->admin())->put(route('admin.libraries.update', $library), ['name' => $library->name, 'city' => $library->city, 'country' => 'ایران', 'logo' => $svg])
            ->assertSessionHasErrors('logo');
        $this->assertNull($library->fresh()->logo_path);
    }

    public function test_command_matches_by_name_and_city_and_keeps_existing_logos()
    {
        $library = $this->library();
        $kept = $this->library();
        $kept->storeLogo($this->png('own.png'));
        $keptPath = $kept->logo_path;

        $dir = storage_path('framework/testing/logos-' . uniqid());
        File::ensureDirectoryExists($dir);
        File::put("$dir/x.png", $this->png()->getContent());
        $data = "$dir/logos.json";
        File::put($data, json_encode([
            ['name' => $library->name, 'city' => $library->city, 'file' => 'x.png', 'on_dark' => true],
            ['name' => $kept->name, 'city' => $kept->city, 'file' => 'x.png', 'on_dark' => false],
            ['name' => 'ناموجود', 'city' => 'هیچ‌جا', 'file' => 'x.png', 'on_dark' => false],
        ]));
        $rel = fn ($p) => ltrim(str_replace(base_path(), '', $p), '/');

        try {
            $this->artisan('fahares:apply-library-logos', ['--file' => $rel($data), '--dir' => $rel($dir)])
                ->expectsOutputToContain('Set 1 of 3 logos; 1 already had one, 1 not found.')
                ->assertSuccessful();
        } finally {
            File::deleteDirectory($dir);
        }

        $this->assertNotNull($library->fresh()->logo_url);
        $this->assertTrue($library->fresh()->logo_on_dark);
        $this->assertSame($keptPath, $kept->fresh()->logo_path);
    }

    public function test_command_only_and_force_replace_one_logo()
    {
        $replaced = $this->library();
        $replaced->storeLogo($this->png('old.png'));
        $oldPath = $replaced->logo_path;
        $untouched = $this->library();

        $dir = storage_path('framework/testing/logos-' . uniqid());
        File::ensureDirectoryExists($dir);
        File::put("$dir/new.png", $this->png()->getContent());
        File::put("$dir/other.png", $this->png()->getContent());
        $data = "$dir/logos.json";
        File::put($data, json_encode([
            ['name' => $replaced->name, 'city' => $replaced->city, 'file' => 'new.png', 'on_dark' => true],
            ['name' => $untouched->name, 'city' => $untouched->city, 'file' => 'other.png', 'on_dark' => false],
        ]));
        $rel = fn ($p) => ltrim(str_replace(base_path(), '', $p), '/');
        // Stored names carry a timestamp, so the new file needs a later second than the old one
        $this->travel(1)->seconds();

        try {
            $this->artisan('fahares:apply-library-logos', ['--file' => $rel($data), '--dir' => $rel($dir), '--only' => 'new.png', '--force' => true])
                ->expectsOutputToContain('Set 1 of 1 logos; 0 already had one, 0 not found.')
                ->assertSuccessful();
        } finally {
            File::deleteDirectory($dir);
        }

        $this->assertNotSame($oldPath, $replaced->fresh()->logo_path);
        $this->assertTrue($replaced->fresh()->logo_on_dark);
        Storage::disk('public')->assertMissing($oldPath);
        $this->assertNull($untouched->fresh()->logo_url);
    }
}

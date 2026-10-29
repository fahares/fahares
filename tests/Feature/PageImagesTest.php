<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** The page range the page viewer caps «next» with (backlog item 94), read from a volume's image files. */
class PageImagesTest extends TestCase
{
    protected string $code;

    protected function setUp(): void
    {
        parent::setUp();
        $this->code = 'test-pages-' . uniqid();
        $dir = storage_path("app/public/catalogs/{$this->code}/v02");
        File::ensureDirectoryExists($dir);
        foreach ([945, 946, 2056] as $page) {
            File::put($dir . '/p' . str_pad($page, 4, '0', STR_PAD_LEFT) . '.webp', '');
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path("app/public/catalogs/{$this->code}"));
        Cache::forget("page-images:{$this->code}:2");
        parent::tearDown();
    }

    public function test_it_gives_the_first_and_last_page_of_a_volume(): void
    {
        $this->getJson("/api/page-images/{$this->code}/2")->assertOk()->assertExactJson(['first' => 945, 'last' => 2056]);
    }

    public function test_a_volume_without_images_is_not_found(): void
    {
        $this->getJson("/api/page-images/{$this->code}/3")->assertNotFound();
        $this->get('/api/page-images/Bad_Code/1')->assertNotFound();
    }

    public function test_the_viewer_is_wired_to_the_endpoint(): void
    {
        $this->get('/about')->assertOk()->assertSee('catalogViewer(', false)->assertSee('api\/page-images', false);
    }
}

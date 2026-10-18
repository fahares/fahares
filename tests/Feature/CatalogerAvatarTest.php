<?php

namespace Tests\Feature;

use App\Models\Cataloger;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CatalogerAvatarTest extends TestCase
{
    public function test_missing_avatar_file_gives_no_url()
    {
        $cataloger = new Cataloger(['name' => 'تست', 'avatar_path' => 'catalogers/avatars/missing_' . uniqid() . '.jpg']);

        $this->assertNull($cataloger->avatar_url);
    }

    public function test_existing_avatar_file_gives_versioned_url()
    {
        $path = 'catalogers/avatars/test_' . uniqid() . '.jpg';
        $file = public_path('storage/' . $path);
        File::ensureDirectoryExists(dirname($file));
        File::put($file, 'x');

        try {
            $cataloger = new Cataloger(['name' => 'تست', 'avatar_path' => $path]);
            $this->assertStringContainsString('storage/' . $path . '?v=', $cataloger->avatar_url);
        } finally {
            File::delete($file);
        }
    }

    public function test_external_avatar_url_is_kept()
    {
        $cataloger = new Cataloger(['name' => 'تست', 'avatar_path' => 'https://example.org/a.jpg']);

        $this->assertSame('https://example.org/a.jpg', $cataloger->avatar_url);
    }
}

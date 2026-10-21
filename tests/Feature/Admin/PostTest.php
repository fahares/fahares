<?php

namespace Tests\Feature\Admin;

use App\Models\Post;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PostTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->admin = User::create([
            'name' => 'Admin Tester', 'email' => 'test-admin@fahares.net', 'password' => 'password123', 'role' => 'admin',
        ]);
    }

    /** A real 1×1 JPEG, so the image rule passes without the GD extension. */
    private function jpeg(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(
            '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='
        ));
    }

    public function test_admin_writes_a_draft_then_publishes_it()
    {
        $this->actingAs($this->admin)->get(route('admin.posts.create'))->assertOk();

        $this->post(route('admin.posts.store'), [
            'title' => 'گزارش نشست آزمایشی',
            'byline' => 'گزارشگر آزمایشی',
            'body' => "## بخش نخست\n\nمتنی **پررنگ** با [پیوند](/works/1).",
            'cover' => $this->jpeg('cover.jpg'),
        ])->assertRedirect();

        $post = Post::where('title', 'گزارش نشست آزمایشی')->firstOrFail();
        $this->assertNull($post->published_at);
        Storage::disk('public')->assertExists($post->cover_path);

        // A draft is hidden from visitors but open to the panel for preview
        auth()->logout();
        $this->get("/posts/{$post->id}")->assertNotFound();
        $this->get(route('posts.index'))->assertOk()->assertDontSee('گزارش نشست آزمایشی');
        $this->actingAs($this->admin)->get(route('posts.show', $post))->assertOk()->assertSee('پیش‌نویس');

        $this->put(route('admin.posts.update', $post), ['title' => 'گزارش نشست آزمایشی', 'body' => $post->body, 'is_published' => 1])->assertRedirect();
        $publishedAt = $post->fresh()->published_at;
        $this->assertNotNull($publishedAt);

        // Saving again keeps the first publication date
        $this->travel(2)->days();
        $this->put(route('admin.posts.update', $post), ['title' => 'گزارش نشست آزمایشی', 'body' => 'متن تازه', 'is_published' => 1]);
        $this->assertTrue($publishedAt->equalTo($post->fresh()->published_at));
        $this->travelBack();

        auth()->logout();
        $this->get(route('posts.index'))->assertOk()->assertSee('گزارش نشست آزمایشی');
        $this->get("/posts/{$post->id}")->assertRedirect(route('posts.show', $post->fresh()));
        $this->get(route('posts.show', $post->fresh()))->assertOk()->assertSee('متن تازه')->assertDontSee('پیش‌نویس');
    }

    public function test_markdown_is_rendered_and_raw_html_is_escaped()
    {
        $post = Post::create([
            'title' => 'یادداشت', 'published_at' => now()->subMinute(),
            'body' => "## تیتر\n\n**پررنگ**\n\n<script>alert(1)</script>\n\n[بد](javascript:alert(1))",
        ]);

        $this->get(route('posts.show', $post))->assertOk()
            ->assertSee('<h2>تیتر</h2>', false)
            ->assertSee('<strong>پررنگ</strong>', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('href="javascript:', false);
    }

    public function test_a_picture_on_its_own_line_gets_its_alt_text_as_a_caption()
    {
        $post = new Post(['body' => "![برگ نخست نسخه](/storage/a.jpg)\n\n![توضیح تصویر](/storage/b.jpg)\n\nمتن با ![درون‌خطی](/storage/c.jpg) در میان."]);

        $this->assertStringContainsString('<figure><img src="/storage/a.jpg" alt="برگ نخست نسخه" /><figcaption>برگ نخست نسخه</figcaption></figure>', $post->body_html);
        // The placeholder of the copied snippet is not shown as a caption
        $this->assertStringContainsString('<figure><img src="/storage/b.jpg" alt="توضیح تصویر" /></figure>', $post->body_html);
        $this->assertStringNotContainsString('<figcaption>درون‌خطی', $post->body_html);
    }

    public function test_images_for_the_body_are_uploaded_listed_and_removed_with_the_post()
    {
        $post = Post::create(['title' => 'معرفی نسخه']);

        $this->actingAs($this->admin)
            ->post(route('admin.posts.images.store', $post), ['images' => [$this->jpeg('folio 12.jpg')]])
            ->assertRedirect();

        $images = $post->images();
        $this->assertCount(1, $images);
        $this->assertStringStartsWith('/storage/posts/' . $post->id . '/images/folio-12-', $images[0]['url']);
        $this->get(route('admin.posts.edit', $post))->assertOk()->assertSee($images[0]['url']);

        $this->delete(route('admin.posts.destroy', $post))->assertRedirect(route('admin.posts.index'));
        $this->assertNull(Post::find($post->id));
        Storage::disk('public')->assertMissing($images[0]['path']);
    }

    public function test_visitors_and_users_cannot_manage_posts()
    {
        $this->get(route('admin.posts.index'))->assertRedirect(route('login'));

        $user = User::create(['name' => 'Reader', 'email' => 'reader@fahares.net', 'password' => 'password123', 'role' => 'user']);
        $this->actingAs($user)->post(route('admin.posts.store'), ['title' => 'نباید ساخته شود'])->assertForbidden();
        $this->assertNull(Post::where('title', 'نباید ساخته شود')->first());
    }
}

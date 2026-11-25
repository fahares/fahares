<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Tests\TestCase;

/** Step 14.411: while config app.posts_public is off, the Jarideh is out of the public site. */
class JaridehHiddenTest extends TestCase
{
    public function test_no_link_sitemap_entry_or_page_for_visitors()
    {
        config(['app.posts_public' => false]);
        $post = Post::create(['title' => 'نوشتۀ پنهان', 'slug' => 'hidden-' . uniqid(), 'body' => 'متن', 'published_at' => now()->subDay()]);

        $this->get(route('about'))->assertOk()
            ->assertDontSee('href="' . route('posts.index') . '"', false)
            ->assertDontSee('جریده');
        $this->get(route('posts.index'))->assertNotFound();
        $this->get(route('posts.show', $post))->assertNotFound();
        $this->get(route('sitemap.chunk', ['type' => 'static', 'page' => 1]))->assertOk()
            ->assertDontSee(route('posts.index'), false)->assertDontSee(route('posts.show', $post), false);
    }

    public function test_those_who_manage_posts_still_preview_them()
    {
        config(['app.posts_public' => false]);
        $admin = User::create(['name' => 'Admin', 'email' => 'jarideh-admin@fahares.net', 'password' => 'password123', 'role' => 'admin']);
        $post = Post::create(['title' => 'نوشتۀ پنهان', 'slug' => 'hidden-' . uniqid(), 'body' => 'متن', 'published_at' => now()->subDay()]);

        $this->actingAs($admin)->get(route('posts.index'))->assertOk()->assertSee('نوشتۀ پنهان');
        $this->actingAs($admin)->get(route('posts.show', $post))->assertOk();
    }

    public function test_shown_again_when_turned_on()
    {
        config(['app.posts_public' => true]);

        $this->get(route('about'))->assertOk()->assertSee('href="' . route('posts.index') . '"', false);
        $this->get(route('posts.index'))->assertOk();
    }
}

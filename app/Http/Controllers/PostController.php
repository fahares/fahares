<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\Request;

class PostController extends Controller
{
    public function index(Request $request)
    {
        $this->abortUnlessShown($request);
        $posts = Post::published()->orderByDesc('published_at')->orderByDesc('id')->paginate(20);

        return view('posts.index', compact('posts'));
    }

    public function show(Request $request, $id)
    {
        $this->abortUnlessShown($request);
        $post = Post::find((int) $id);
        // A draft is visible only to those who manage posts, for preview
        abort_unless($post && ($post->isPublished() || $request->user()?->can('posts.manage')), 404);

        $canonicalKey = $post->getRouteKey();
        if ($id !== $canonicalKey && urldecode($id) !== $canonicalKey) {
            return redirect()->route('posts.show', array_merge(['id' => $canonicalKey], $request->query()), 301);
        }

        return view('posts.show', compact('post'));
    }

    /** While the Jarideh is off (config app.posts_public, step 14.411), only those who manage posts see its pages. */
    private function abortUnlessShown(Request $request): void
    {
        abort_unless(config('app.posts_public') || $request->user()?->can('posts.manage'), 404);
    }
}

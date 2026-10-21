<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\Request;

class PostController extends Controller
{
    public function index()
    {
        $posts = Post::published()->orderByDesc('published_at')->orderByDesc('id')->paginate(20);

        return view('posts.index', compact('posts'));
    }

    public function show(Request $request, $id)
    {
        $post = Post::find((int) $id);
        // A draft is visible only to those who can open the admin panel, for preview
        abort_unless($post && ($post->isPublished() || $request->user()?->canAccessAdmin()), 404);

        $canonicalKey = $post->getRouteKey();
        if ($id !== $canonicalKey && urldecode($id) !== $canonicalKey) {
            return redirect()->route('posts.show', array_merge(['id' => $canonicalKey], $request->query()), 301);
        }

        return view('posts.show', compact('post'));
    }
}

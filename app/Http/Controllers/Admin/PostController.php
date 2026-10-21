<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The admin pages for writings published on the site: write, publish or keep as draft, and
 * upload the pictures that the Markdown body uses.
 */
class PostController extends Controller
{
    public function index()
    {
        $posts = Post::query()->orderByRaw('published_at is null desc')->orderByDesc('published_at')->orderByDesc('id')->paginate(30);

        return view('admin.posts.index', compact('posts'));
    }

    public function create()
    {
        return view('admin.posts.edit', ['post' => new Post()]);
    }

    public function store(Request $request)
    {
        $data = $this->validatePost($request);
        $post = Post::create($data + ['user_id' => auth()->id()]);
        if ($request->hasFile('cover')) {
            $post->update(['cover_path' => $request->file('cover')->store("posts/{$post->id}/cover", 'public')]);
        }

        $this->audit($request, $post->id, 'create', null, $post->toArray());

        return redirect()->route('admin.posts.edit', $post)->with('success', 'یادداشت ذخیره شد. اکنون می‌توانید تصویرهای متن را بارگذاری کنید.');
    }

    public function edit(Post $post)
    {
        return view('admin.posts.edit', compact('post'));
    }

    public function update(Request $request, Post $post)
    {
        $data = $this->validatePost($request, $post);
        $old = $post->toArray();

        if ($request->hasFile('cover')) {
            $data['cover_path'] = $request->file('cover')->store("posts/{$post->id}/cover", 'public');
        } elseif ($request->boolean('remove_cover')) {
            $data['cover_path'] = null;
        }
        $post->update($data);
        if ($old['cover_path'] && $old['cover_path'] !== $post->cover_path) {
            Storage::disk('public')->delete($old['cover_path']);
        }

        $this->audit($request, $post->id, 'update', $old, $post->toArray());

        return back()->with('success', 'تغییرات ذخیره شد.');
    }

    public function destroy(Request $request, Post $post)
    {
        $old = $post->toArray();
        $post->delete();
        $post->deleteFiles();

        $this->audit($request, $old['id'], 'post_delete', $old, null);

        return redirect()->route('admin.posts.index')->with('success', "یادداشت «{$old['title']}» حذف شد.");
    }

    public function storeImage(Request $request, Post $post)
    {
        $request->validate([
            'images' => 'required|array|max:20',
            'images.*' => 'image|mimes:jpg,jpeg,png,webp|max:5120',
        ], [
            'images.*.max' => 'حجم هر تصویر حداکثر ۵ مگابایت است.',
            'images.*.mimes' => 'تصویر باید JPG، PNG یا WebP باشد.',
        ]);

        foreach ($request->file('images') as $file) {
            // Keep a readable name, so the Markdown in the body says which picture it is
            $base = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), '-', null) ?: 'image';
            $name = mb_substr($base, 0, 60) . '-' . Str::lower(Str::random(6)) . '.' . $file->extension();
            $file->storeAs($post->imagesDirectory(), $name, 'public');
        }

        return back()->with('success', 'تصویر بارگذاری شد. نشانی Markdown آن را در متن بگذارید.')->withFragment('images');
    }

    public function destroyImage(Request $request, Post $post)
    {
        $name = basename((string) $request->input('name'));
        Storage::disk('public')->delete($post->imagesDirectory() . '/' . $name);

        return back()->with('success', 'تصویر حذف شد.')->withFragment('images');
    }

    private function validatePost(Request $request, ?Post $post = null): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'byline' => 'nullable|string|max:255',
            'summary' => 'nullable|string|max:1000',
            'body' => 'nullable|string|max:200000',
            'cover' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'cover_caption' => 'nullable|string|max:255',
            'is_published' => 'nullable|boolean',
            'remove_cover' => 'nullable|boolean',
        ], [
            'cover.max' => 'حجم تصویر شاخص حداکثر ۵ مگابایت است.',
        ]);
        unset($data['cover'], $data['remove_cover'], $data['is_published']);

        // The first publication date stays when a published post is saved again
        $data['published_at'] = $request->boolean('is_published') ? ($post?->published_at ?? now()) : null;

        return $data;
    }

    private function audit(Request $request, int $id, string $action, ?array $old, ?array $new): void
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'auditable_type' => Post::class,
            'auditable_id' => $id,
            'action' => $action,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request->ip(),
        ]);
    }
}

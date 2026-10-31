<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Cataloger;
use App\Models\HomeItem;
use App\Models\SiteSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * The admin page for the home page: texts, banner slides, «از گنجینه‌ها» images,
 * and the catalogers shown on the home page.
 */
class HomePageController extends Controller
{
    public function index()
    {
        $items = HomeItem::query()->orderBy('sort_order')->orderBy('id')->get()->groupBy('section');
        $homeCatalogers = Cataloger::whereNotNull('home_order')->orderBy('home_order')->get();
        $otherCatalogers = Cataloger::whereNull('home_order')->orderBy('name')->get(['id', 'name', 'avatar_path', 'mtif_entry_id']);
        $settings = collect(SiteSetting::keys('home'))->mapWithKeys(fn ($key) => [$key => [
            'label' => SiteSetting::LABELS[$key],
            'value' => SiteSetting::get($key),
            'default' => SiteSetting::DEFAULTS[$key],
        ]]);

        return view('admin.home.index', compact('items', 'homeCatalogers', 'otherCatalogers', 'settings'));
    }

    public function updateSettings(Request $request)
    {
        $rules = collect(SiteSetting::keys('home'))
            ->mapWithKeys(fn ($key) => [str_replace('.', '__', $key) => 'nullable|string|max:1000'])
            ->all();
        $rules['home__slide_interval'] = 'nullable|integer|min:3|max:60';
        $validated = $request->validate($rules);

        $old = [];
        $new = [];
        foreach (SiteSetting::keys('home') as $key) {
            $value = trim((string) ($validated[str_replace('.', '__', $key)] ?? ''));
            $value = $value === '' ? null : $value;
            $current = SiteSetting::find($key)?->value;
            if ($current !== $value) {
                $old[$key] = $current;
                $new[$key] = $value;
                SiteSetting::put($key, $value);
            }
        }

        if ($new) {
            $this->audit($request, SiteSetting::class, 0, 'home_texts', $old, $new);
        }

        return back()->with('success', 'متن‌های صفحه‌ی خانه ذخیره شد.')->withFragment('texts');
    }

    public function storeItem(Request $request)
    {
        $data = $this->validateItem($request, true);
        $data['image_path'] = $this->storeImage($request, $data['section']);
        $data['sort_order'] = (int) HomeItem::where('section', $data['section'])->max('sort_order') + 1;
        $item = HomeItem::create($data);

        $this->audit($request, HomeItem::class, $item->id, 'create', null, $item->toArray());

        return back()->with('success', 'تصویر افزوده شد.')->withFragment($item->section);
    }

    public function updateItem(Request $request, HomeItem $item)
    {
        $data = $this->validateItem($request, false);
        unset($data['section']);
        $old = $item->toArray();

        if ($request->hasFile('image')) {
            $data['image_path'] = $this->storeImage($request, $item->section);
        }
        $item->update($data);
        if (isset($data['image_path']) && $old['image_path'] !== $item->image_path) {
            Storage::disk('public')->delete($old['image_path']);
        }

        $this->audit($request, HomeItem::class, $item->id, 'update', $old, $item->toArray());

        return back()->with('success', 'تغییرات ذخیره شد.')->withFragment($item->section);
    }

    public function destroyItem(Request $request, HomeItem $item)
    {
        $old = $item->toArray();
        $item->delete();
        Storage::disk('public')->delete($old['image_path']);

        $this->audit($request, HomeItem::class, $old['id'], 'home_item_delete', $old, null);

        return back()->with('success', 'تصویر حذف شد.')->withFragment($old['section']);
    }

    public function moveItem(Request $request, HomeItem $item)
    {
        $direction = $request->validate(['direction' => 'required|in:up,down'])['direction'];
        $siblings = HomeItem::section($item->section)->get()->values();
        $index = $siblings->search(fn ($s) => $s->id === $item->id);
        $swap = $direction === 'up' ? $index - 1 : $index + 1;

        if ($swap >= 0 && $swap < $siblings->count()) {
            DB::transaction(function () use ($siblings, $index, $swap) {
                $ordered = $siblings->all();
                [$ordered[$index], $ordered[$swap]] = [$ordered[$swap], $ordered[$index]];
                foreach ($ordered as $i => $s) {
                    $s->update(['sort_order' => $i + 1]);
                }
            });
        }

        return back()->withFragment($item->section);
    }

    public function addCataloger(Request $request)
    {
        $id = $request->validate(['cataloger_id' => 'required|exists:catalogers,id'])['cataloger_id'];
        $cataloger = Cataloger::findOrFail($id);
        if ($cataloger->home_order === null) {
            $cataloger->home_order = (int) Cataloger::max('home_order') + 1;
            $cataloger->save();
            $this->audit($request, Cataloger::class, $cataloger->id, 'home_cataloger_add', ['home_order' => null], ['home_order' => $cataloger->home_order]);
        }

        return back()->with('success', "«{$cataloger->name}» به صفحه‌ی خانه افزوده شد.")->withFragment('catalogers');
    }

    public function removeCataloger(Request $request, Cataloger $cataloger)
    {
        $old = $cataloger->home_order;
        $cataloger->home_order = null;
        $cataloger->save();
        $this->renumberCatalogers();
        $this->audit($request, Cataloger::class, $cataloger->id, 'home_cataloger_remove', ['home_order' => $old], ['home_order' => null]);

        return back()->with('success', "«{$cataloger->name}» از صفحه‌ی خانه برداشته شد.")->withFragment('catalogers');
    }

    public function moveCataloger(Request $request, Cataloger $cataloger)
    {
        $direction = $request->validate(['direction' => 'required|in:up,down'])['direction'];
        $list = Cataloger::whereNotNull('home_order')->orderBy('home_order')->get()->values();
        $index = $list->search(fn ($c) => $c->id === $cataloger->id);
        $swap = $direction === 'up' ? $index - 1 : $index + 1;

        if ($index !== false && $swap >= 0 && $swap < $list->count()) {
            $ordered = $list->all();
            [$ordered[$index], $ordered[$swap]] = [$ordered[$swap], $ordered[$index]];
            DB::transaction(function () use ($ordered) {
                foreach ($ordered as $i => $c) {
                    $c->update(['home_order' => $i + 1]);
                }
            });
        }

        return back()->withFragment('catalogers');
    }

    private function renumberCatalogers(): void
    {
        Cataloger::whereNotNull('home_order')->orderBy('home_order')->get()
            ->each(fn ($c, $i) => $c->update(['home_order' => $i + 1]));
    }

    private function validateItem(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'section' => [$creating ? 'required' : 'nullable', Rule::in(array_keys(HomeItem::SECTIONS))],
            'title' => 'nullable|string|max:255',
            'subtitle' => 'nullable|string|max:1000',
            'credit' => 'nullable|string|max:255',
            'link_url' => ['nullable', 'string', 'max:500', 'regex:#^(https?://|/)#'],
            'image' => [$creating ? 'required' : 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'show_caption' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ], [
            'link_url.regex' => 'پیوند باید با http، https یا / آغاز شود.',
            'image.max' => 'حجم تصویر حداکثر ۲ مگابایت است.',
        ]);
        unset($data['image']);
        $data['show_caption'] = $request->boolean('show_caption');
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    private function storeImage(Request $request, string $section): string
    {
        return $request->file('image')->store("home/{$section}", 'public');
    }

    private function audit(Request $request, string $type, int $id, string $action, ?array $old, ?array $new): void
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'auditable_type' => $type,
            'auditable_id' => $id,
            'action' => $action,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request->ip(),
        ]);
    }
}

<?php

namespace App\Support;

use App\Models\Work;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The copies of a work that stand under another copy of it on the work page (step 14.16): a photograph or film of a
 * copy (facsimile_of) and a description possibly of the same copy (possibly_same). Each is shown under the copy at the
 * top of its chain, and the counts tell the copies apart from the photographs and the repeated descriptions.
 */
class CopyLinks
{
    /** @var array<int, array{parent: int, type: string, note: ?string}> by the linked copy's id */
    public array $links = [];

    /** @var array<int, int> the top copy of each linked one */
    public array $roots = [];

    public function __construct(public Work $work)
    {
        $rows = DB::table('manuscript_relations as r')
            ->join('manuscripts as a', 'a.id', '=', 'r.manuscript_id')
            ->join('manuscripts as b', 'b.id', '=', 'r.related_manuscript_id')
            ->where('a.work_id', $work->id)->where('b.work_id', $work->id)
            ->whereIn('r.type', ['facsimile_of', 'possibly_same'])
            ->orderBy('r.id')
            ->get(['r.manuscript_id', 'r.related_manuscript_id', 'r.type', 'r.note']);
        foreach ($rows as $row) {
            $this->links[(int) $row->manuscript_id] ??= ['parent' => (int) $row->related_manuscript_id, 'type' => $row->type, 'note' => $row->note];
        }
        foreach (array_keys($this->links) as $id) {
            $root = $id;
            for ($i = 0; $i < 6 && isset($this->links[$root]); $i++) {
                $root = $this->links[$root]['parent'];
            }
            // A loop has no top copy: the copy stays in the list of its own
            if (! isset($this->links[$root]) && $root !== $id) {
                $this->roots[$id] = $root;
            }
        }
    }

    /** The ids kept out of the main list */
    public function linkedIds(): array
    {
        return array_keys($this->roots);
    }

    /** @return Collection<int, Collection> the linked copies of the given top copies, by top copy, in id order */
    public function under(array $topIds): Collection
    {
        $ids = array_keys(array_filter($this->roots, fn ($root) => in_array($root, $topIds, true)));
        if (! $ids) {
            return collect();
        }

        return \App\Models\Manuscript::with('libraryRecord')->whereIn('id', $ids)->orderBy('id')->get()
            ->groupBy(fn ($m) => $this->roots[$m->id]);
    }

    /**
     * @return array{copies: int, photos_linked: int, photos_other: int, possibly_same: int}
     *  copies: the handwritten copies in the main list; photos_linked: photographs of one of them; photos_other: every
     *  other photograph; possibly_same: descriptions possibly of a copy already counted
     */
    public function counts(): array
    {
        $facsimile = DB::table('manuscripts')->where('work_id', $this->work->id)->where('is_facsimile', true)->pluck('id')->flip();
        $counts = ['copies' => 0, 'photos_linked' => 0, 'photos_other' => 0, 'possibly_same' => 0];
        foreach ($this->roots as $id => $root) {
            if ($this->links[$id]['type'] === 'possibly_same' && ! $facsimile->has($id)) {
                $counts['possibly_same']++;
            } else {
                $counts[$facsimile->has($root) ? 'photos_other' : 'photos_linked']++;
            }
        }
        $top = (int) $this->work->manuscripts()->count() - count($this->roots);
        $topPhotos = $facsimile->keys()->diff($this->linkedIds())->count();
        $counts['photos_other'] += $topPhotos;
        $counts['copies'] = $top - $topPhotos;

        return $counts;
    }
}

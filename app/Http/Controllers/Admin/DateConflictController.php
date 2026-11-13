<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DateConflict;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The panel's «تناقض‌های تاریخی» (step 14.167): the documented dates fahares:estimate-dates found to conflict, widest
 * gap first, each with the two records to open and mend. The page changes no date: a conflict goes when the nightly
 * run no longer finds it. «خطا نیست» dismisses one that is right as it stands (a later hand named as the scribe, a
 * copy of a dated original), and that is kept across runs.
 */
class DateConflictController extends Controller
{
    public function index(Request $request)
    {
        $direct = $request->query('kind') !== 'indirect';
        $dismissed = $request->query('view') === 'dismissed';
        $rule = array_key_exists($request->query('rule'), DateConflict::RULE_LABELS) ? $request->query('rule') : null;
        $smallFirst = $request->query('sort') === 'small';

        $base = DateConflict::where('direct', $direct)->when($dismissed, fn ($q) => $q->whereNotNull('dismissed_at'), fn ($q) => $q->open());
        $ruleCounts = (clone $base)->selectRaw('rule, COUNT(*) n')->groupBy('rule')->pluck('n', 'rule');
        $conflicts = $base->when($rule, fn ($q) => $q->where('rule', $rule))->with('dismisser')
            ->orderBy('gap', $smallFirst ? 'asc' : 'desc')->orderBy('id')->paginate(30)->withQueryString();
        $counts = DateConflict::selectRaw('direct, dismissed_at IS NOT NULL dismissed, COUNT(*) n')->groupBy('direct', DB::raw('dismissed_at IS NOT NULL'))->get();

        return view('admin.date-conflicts.index', [
            'conflicts' => $conflicts, 'records' => $this->records($conflicts->getCollection()), 'ruleCounts' => $ruleCounts, 'counts' => $counts,
            'direct' => $direct, 'dismissed' => $dismissed, 'rule' => $rule, 'smallFirst' => $smallFirst,
            'lastRun' => ($last = DateConflict::max('last_seen_at')) ? \Illuminate\Support\Carbon::parse($last) : null,
        ]);
    }

    public function dismiss(Request $request, DateConflict $conflict)
    {
        $validated = $request->validate(['note' => 'nullable|string|max:500']);
        $conflict->update(['dismissed_at' => now(), 'dismissed_by' => auth()->id(), 'dismiss_note' => $validated['note'] ?? null]);

        return back()->with('success', 'ثبت شد: خطا نیست. این مورد در اجراهای بعدی هم کنار می‌ماند.');
    }

    public function reopen(DateConflict $conflict)
    {
        $conflict->update(['dismissed_at' => null, 'dismissed_by' => null, 'dismiss_note' => null]);

        return back()->with('success', 'مورد به فهرست بازگشت.');
    }

    /**
     * What the page shows of each record a conflict names: "type:id" => [title, detail, dates as the record holds them].
     */
    private function records($conflicts): array
    {
        $ids = [];
        foreach ($conflicts as $c) {
            foreach (['a', 'b', 'via'] as $side) {
                if ($c->{"{$side}_id"}) {
                    $ids[$c->{"{$side}_type"}][] = $c->{"{$side}_id"};
                }
            }
        }
        $range = fn ($from, $to) => $from === null && $to === null ? null : ($from === $to ? (string) $from : ($from ?? '؟') . '–' . ($to ?? '؟'));
        $records = [];
        foreach (DB::table('people')->whereIn('id', $ids['person'] ?? [])->get(['id', 'name', 'birth_year_hijri', 'death_from', 'death_to', 'life_dates_raw']) as $p) {
            $records["person:{$p->id}"] = ['title' => $p->name, 'detail' => $p->life_dates_raw,
                'dates' => ['birth' => $range($p->birth_year_hijri, $p->birth_year_hijri), 'death' => $range($p->death_from, $p->death_to)]];
        }
        $copies = DB::table('manuscripts')->whereIn('id', $ids['manuscript'] ?? [])
            ->get(['id', 'work_id', 'city', 'library', 'shelfmark', 'copy_date_raw', 'copy_date_from', 'copy_date_to', 'scribe_name']);
        $workIds = array_merge($ids['work'] ?? [], $copies->pluck('work_id')->filter()->all());
        $works = DB::table('works')->whereIn('id', $workIds)->get(['id', 'primary_title', 'author_name', 'composition_date_raw', 'composition_date_from', 'composition_date_to'])->keyBy('id');
        foreach ($works as $w) {
            $records["work:{$w->id}"] = ['title' => $w->primary_title, 'detail' => $w->author_name,
                'dates' => ['composition' => $range($w->composition_date_from, $w->composition_date_to)], 'raw' => $w->composition_date_raw];
        }
        foreach ($copies as $m) {
            $records["manuscript:{$m->id}"] = ['title' => ($works[$m->work_id]->primary_title ?? 'نسخه'),
                'detail' => implode('، ', array_filter([$m->city, $m->library, $m->shelfmark ? 'ش ' . $m->shelfmark : null, $m->scribe_name ? 'کاتب: ' . $m->scribe_name : null])),
                'dates' => ['copy' => $range($m->copy_date_from, $m->copy_date_to)], 'raw' => $m->copy_date_raw];
        }

        return $records;
    }
}

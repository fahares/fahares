<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Manuscript;
use App\Models\Work;
use App\Suggestions\ProtectedColumns;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Applies reviewed categories of the parser v2 comparison (item 87, step 13.66) from a decision file written by the
 * corpus script `fankha_v2_apply_export.py`. A change is written only where the record is still the one the
 * comparison saw (natural key), the field still holds the compared value, no reviewed correction protects it, and
 * its own conditions hold (a citation leaving «چاپ» must already be in the bibliography). Every write is logged as
 * `corpus_update` with old and new values, so it can be traced and undone. Without --apply it only reports.
 */
class ApplyCorpusCategoriesCommand extends Command
{
    protected $signature = 'fahares:apply-corpus-categories
                            {--file= : Decision file (database/data/fankha_v2_categories_20261029.json)}
                            {--apply : Write the changes; without it the command only reports what it would do}';

    protected $description = 'Apply reviewed parser v2 categories to records the site has not changed since the comparison';

    /** Tables and the fields a decision file may change */
    private const FIELDS = [
        'works' => ['print_info'],
        'manuscripts' => ['is_facsimile', 'copy_date_raw', 'copy_date_note', 'lines', 'explicit_text', 'incipit_text', 'is_ruled', 'is_illuminated', 'binding'],
    ];

    /** A commissioner's phrase before the name in the copy's raw text («حسب الامر», «به جهت», «برای»…) */
    // A commissioner's phrase, then up to six words of titles or «...» before the name: «بر حسب امر نواب مستطاب ...
    // شاهزاده ... عماد الدوله», «بحسب الفرموده فضیلت و تقوی نشان آخوند مولانا محمد حسین باطاقی»
    private const COMMISSIONER_PHRASE = '/((?:(?:بر|از|به)[\s\x{200c}]+)?(?:ب?حسب[\s\x{200c}]*(?:ال)?(?:امر|فرمایش|فرموده|خواهش)|به[\s\x{200c}]*(?:جهت|دستور|سفارش|فرمایش|خواهش|درخواست|امر|فرمان)|بجه[ةت]|بنا[\s\x{200c}]+(?:بر|به)[\s\x{200c}]+(?:خواهش|درخواست|امر)|برای|جهت))(?:[\s\x{200c}:]+(?:\.{2,}|…|[^\s\x{200c}]+)){0,6}?[\s\x{200c}:.…]*$/u';

    private const MODELS = ['works' => Work::class, 'manuscripts' => Manuscript::class];

    public function handle(): int
    {
        $path = $this->option('file') ?: database_path('data/fankha_v2_categories_20261029.json');
        $plan = File::exists($path) ? json_decode(File::get($path), true) : null;
        if (! $plan || ! isset($plan['records'])) {
            $this->error("Decision file missing or invalid: {$path}");
            return 1;
        }
        $apply = (bool) $this->option('apply');
        $guards = array_map(fn ($model) => ProtectedColumns::for($model), self::MODELS);
        $counts = [];
        $problems = [];
        $writes = [];

        foreach (collect($plan['records'])->groupBy('table') as $table => $records) {
            foreach ($records->chunk(1000) as $chunk) {
                $rows = $this->rows($table, $chunk->pluck('id')->all());
                foreach ($chunk as $rec) {
                    [$outcome, $payload, $old] = $this->check($table, $rec, $rows[$rec['id']] ?? null, $guards[$table]);
                    $counts[$rec['category']][$outcome] = ($counts[$rec['category']][$outcome] ?? 0) + 1;
                    if ($outcome === 'write') {
                        $writes[] = [$table, $rec, $payload, $old];
                    } elseif (! in_array($outcome, ['already'], true)) {
                        $problems[] = "{$table} #{$rec['id']} ({$rec['category']}): {$outcome}";
                    }
                }
            }
        }

        $this->table(['category', 'outcome', 'records'], collect($counts)->flatMap(
            fn ($o, $cat) => collect($o)->map(fn ($n, $k) => [$cat, $k, $n])->values()
        )->all());
        foreach (array_slice($problems, 0, 50) as $line) {
            $this->warn($line);
        }
        if (count($problems) > 50) {
            $this->warn('… and ' . (count($problems) - 50) . ' more');
        }
        $this->info(($apply ? 'Writing ' : 'Would write ') . count($writes) . ' records.');
        if (! $apply || ! $writes) {
            return 0;
        }

        $note = basename($path);
        foreach (array_chunk($writes, 500) as $batch) {
            DB::transaction(function () use ($batch, $note) {
                foreach ($batch as [$table, $rec, $payload, $old]) {
                    // A shorter date keeps its year and range in step (Manuscript::booted does it for saved models)
                    if (array_key_exists('copy_date_raw', $payload)) {
                        $death = \App\Support\CopyDateRange::namesAuthorTime($payload['copy_date_raw'])
                            ? Manuscript::authorDeaths([DB::table('manuscripts')->where('id', $rec['id'])->value('work_id')]) : [];
                        $payload += ['copy_date_hijri_year' => \App\Support\HijriYear::parse($payload['copy_date_raw'])] + Manuscript::copyDateColumns($payload['copy_date_raw'], reset($death) ?: null);
                        // (a three-digit year is re-read by fahares:refresh-derived, which knows the work's floor)
                    }
                    if (array_key_exists('notes', $rec)) {
                        [$payload, $old] = $this->moveTail($rec, $payload, $old);
                    }
                    if ($payload) {
                        DB::table($table)->where('id', $rec['id'])->update($payload + ['updated_at' => now()]);
                    }
                    if (isset($rec['commissioner'])) {
                        $payload += $this->addCommissioner($rec);
                    }
                    $logged = $payload;
                    if (isset($logged['metadata'])) {
                        // only the parts of the metadata the move touched, not the whole of it
                        $meta = json_decode($logged['metadata'], true);
                        $logged['metadata'] = array_intersect_key($meta, array_flip(array_filter([self::tailList($rec)[1], 'editorial_notes', 'editorial_note_types'])));
                    }
                    AuditLog::create([
                        'user_id' => null,
                        'auditable_type' => self::MODELS[$table],
                        'auditable_id' => $rec['id'],
                        'action' => 'corpus_update',
                        'old_values' => $old,
                        'new_values' => $logged + ['corpus_fix' => $note, 'category' => $rec['category']],
                    ]);
                }
            });
        }
        // is_facsimile is a search filter of the manuscripts tab; print_info is not indexed. Small chunks: on
        // production 500 copies at a time ran out of memory (step 13.66)
        $copies = collect($writes)->filter(fn ($w) => $w[0] === 'manuscripts')->map(fn ($w) => $w[1]['id']);
        foreach ($copies->chunk(100) as $ids) {
            Manuscript::whereIn('id', $ids->values())->get()->searchable();
            gc_collect_cycles();
        }
        $this->info('Done; reindexed ' . $copies->count() . ' manuscripts.');

        return 0;
    }

    /**
     * The line the site kept after a copy's explicit («جلد اول», a note on the contents) leaves the explicit, in the
     * metadata's list of explicits too, and each of its parts becomes a cataloguer's note (step 13.139). v2's type of
     * a note goes to `editorial_note_types`, by its text, for a later split into fields; the page shows the notes as
     * before. A note already there is not added again, and a note listed twice is listed once.
     *
     * @return array{0: array, 1: array} the payload and the old values, with the metadata
     */
    private function moveTail(array $rec, array $payload, array $old): array
    {
        $raw = DB::table('manuscripts')->where('id', $rec['id'])->value('metadata');
        $meta = json_decode($raw ?? 'null', true) ?: [];
        // the explicit's tail, the incipit's (step 13.164) or the copy date's (14.2), and the metadata list the
        // first two are shown from; a date has none
        [$column, $list] = self::tailList($rec);
        $from = trim((string) $rec['changes'][$column]['from']);
        $to = $rec['changes'][$column]['to'];
        foreach ($list ? ($meta[$list] ?? []) : [] as $i => $item) {
            if (is_array($item) && trim((string) ($item['text'] ?? '')) === $from) {
                $meta[$list][$i]['text'] = $to;
            }
        }
        $notes = array_values(array_unique(array_filter($meta['editorial_notes'] ?? [], 'is_string')));
        foreach ($rec['notes'] as $n) {
            if (! in_array($n['text'], $notes, true)) {
                $notes[] = $n['text'];
            }
            if ($n['type'] !== null) {
                $meta['editorial_note_types'][$n['text']] = $n['type'];
            }
        }
        $meta['editorial_notes'] = $notes;
        $old['metadata'] = array_intersect_key(json_decode($raw ?? 'null', true) ?: [], array_flip(array_filter([$list, 'editorial_notes', 'editorial_note_types'])));

        return [$payload + ['metadata' => json_encode($meta, JSON_UNESCAPED_UNICODE)], $old];
    }

    /** @return array{0: string, 1: ?string} the column a record moves a tail out of, and its metadata list */
    private static function tailList(array $rec): array
    {
        $column = collect(['incipit_text', 'explicit_text', 'copy_date_raw'])->first(fn ($c) => isset($rec['changes'][$c]));

        return [$column, ['incipit_text' => 'incipits', 'explicit_text' => 'explicits', 'copy_date_raw' => null][$column]];
    }

    /**
     * The commissioner as a people row of role patron, with the catalogue's phrase before the name (label_raw), and
     * the name in commissioned_by, the column the manuscripts search reads, when it is empty.
     *
     * @return array what the audit log records
     */
    private function addCommissioner(array $rec): array
    {
        $manuscript = Manuscript::find($rec['id']);
        if (! $manuscript || $manuscript->otherPeople()->where('role', 'patron')->whereNull('person_id')->where('name', $rec['commissioner'])->exists()) {
            return [];
        }
        $label = $this->phrase((string) $manuscript->raw_text, $rec['commissioner']);
        $result = app(\App\Suggestions\ContributorRelations::class)->addRole($manuscript, 'patron', null, $rec['commissioner'], null, null, ['label_raw' => $label]);
        if (blank($manuscript->commissioned_by)) {
            DB::table('manuscripts')->where('id', $manuscript->id)->update(['commissioned_by' => mb_substr($rec['commissioner'], 0, 500)]);
        }

        return ['added_person_role' => $result['new']['added_person_role'], 'label_raw' => $label];
    }

    /**
     * The commissioner's phrase («حسب الامر») the raw text puts before the name, with at most six words of titles
     * between them, at any place the name is written.
     */
    public function phrase(string $raw, string $name): ?string
    {
        // A page tag can sit between the phrase and the name; «ي» and «ك» are written the Arabic way too
        $raw = str_replace(['ي', 'ك'], ['ی', 'ک'], preg_replace('/\s*<!--\s*page:\s*\d+\s*-->\s*/u', ' ', $raw));
        $name = str_replace(['ي', 'ك'], ['ی', 'ک'], $name);
        // «جا: شیراز (مدرسه میر سید شریف)، برای میر سید شریف»: the name may be written first where it is no commissioner
        for ($at = mb_strpos($raw, $name); $at !== false; $at = mb_strpos($raw, $name, $at + 1)) {
            $before = preg_split('/[،؛\n]/u', mb_substr($raw, max(0, $at - 90), min($at, 90)));
            $before = trim((string) end($before));
            if (preg_match(self::COMMISSIONER_PHRASE, $before, $m)) {
                return mb_substr(trim(preg_replace('/[\s:]+$/u', '', $m[1])), 0, 60);
            }
        }

        return null;
    }

    /** @return array<int, object> */
    private function rows(string $table, array $ids): array
    {
        $fields = self::FIELDS[$table];
        $query = DB::table($table)->whereIn("{$table}.id", $ids);
        if ($table === 'manuscripts') {
            $query->leftJoin('works', 'works.id', '=', 'manuscripts.work_id')
                ->select(['manuscripts.id', 'manuscripts.shelfmark', 'manuscripts.raw_text', 'manuscripts.metadata', 'works.slug as work_slug', DB::raw('NULL as bibliography'), ...array_map(fn ($f) => "manuscripts.{$f}", $fields)]);
        } else {
            $query->select(['id', 'slug as work_slug', 'bibliography', ...$fields]);
        }

        return $query->get()->keyBy('id')->all();
    }

    /**
     * Whether a record can take its changes, and what to write.
     *
     * @return array{0: string, 1: array, 2: array}
     */
    private function check(string $table, array $rec, ?object $row, ProtectedColumns $guard): array
    {
        if (! $row) {
            return ['record gone (merged or deleted)', [], []];
        }
        if (! str_ends_with((string) $row->work_slug, $rec['work_key'])
            || (isset($rec['shelfmark']) && $row->shelfmark !== $rec['shelfmark'])) {
            return ['natural key differs', [], []];
        }
        $payload = [];
        $old = [];
        $already = 0;
        foreach ($rec['changes'] as $field => $change) {
            if (! in_array($field, self::FIELDS[$table], true)) {
                return ["field {$field} not allowed", [], []];
            }
            $current = $row->$field;
            if ($this->same($current, $change['to'])) {
                $already++;
                continue;
            }
            if (! $this->same($current, $change['from'])) {
                return ["{$field} changed on the site since the comparison", [], []];
            }
            $payload[$field] = $change['to'];
            $old[$field] = $current;
        }
        if ($payload && array_diff_key($payload, $guard->strip($rec['id'], $payload))) {
            return ['protected by a reviewed correction', [], []];
        }
        // the explicit's tail goes into the notes, which a reviewer may have corrected (step 13.139)
        if (array_key_exists('notes', $rec) && ! $guard->strip($rec['id'], ['metadata' => 1])) {
            return ['notes protected by a reviewed correction', [], []];
        }
        // A commissioner (item 96) becomes a people row of role patron, unless reviewers changed the copy's people
        // or it is there already
        if (isset($rec['commissioner'])) {
            // No commissioner's phrase before the name: the parser took a library or «کتابخانه مؤلف» for one
            if ($this->phrase((string) $row->raw_text, $rec['commissioner']) === null) {
                return ['no commissioner phrase before the name in the raw text', [], []];
            }
            if ($guard->protectsRows($rec['id'], 'people')) {
                return ['people changed by a reviewer', [], []];
            }
            if (DB::table('manuscript_people')->where('manuscript_id', $rec['id'])->where('role', 'patron')->whereNull('person_id')->where('name', $rec['commissioner'])->exists()) {
                return [$payload ? 'write' : 'already', $payload, $old];
            }
            return ['write', $payload, $old];
        }
        if (! $payload) {
            return [$already ? 'already' : 'nothing to do', [], []];
        }
        $have = $this->plain(implode(' ', json_decode($row->bibliography ?? 'null', true) ?? []));  // works only
        foreach ($rec['require_in_bibliography'] ?? [] as $citation) {
            if (! str_contains($have, $this->plain($citation))) {
                return ["citation «{$citation}» not in the bibliography", [], []];
            }
        }

        return ['write', $payload, $old];
    }

    private function same(mixed $a, mixed $b): bool
    {
        return is_bool($b) || is_int($b) ? (int) $a === (int) $b : (string) $a === (string) $b;
    }

    /** Citations compared without spaces and punctuation */
    private function plain(string $s): string
    {
        return preg_replace('/[\s،؛;.\[\]]+/u', '', $s);
    }
}

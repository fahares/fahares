<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One note of a copy's description with its type (backlog item 87, step 14.254)
 */
class ManuscriptNote extends Model
{
    /**
     * The approved types (docs/plans/fankha-parser-v2.md, section 10) and, since step 14.260, three labelled fields
     * of the description the site has no column for: the copy's description, the part of the work it holds and
     * what it lacks. The label is the short one the copy's page sets before a note.
     */
    public const TYPES = [
        'acquisition' => 'ورود', 'ownership' => 'تملک', 'seal' => 'مهر', 'endowment' => 'وقف',
        'certificate' => 'اجازه و سماع', 'collation' => 'مقابله', 'marginalia' => 'حواشی', 'transcription' => 'کتابت',
        'decoration' => 'آرایه', 'illustration' => 'نگاره', 'diagram' => 'شکل و جدول', 'codicology' => 'نسخه‌شناسی',
        'description' => 'شرح نسخه', 'scope' => 'بخش موجود', 'defect' => 'افتادگی',
        'contents' => 'محتوا', 'annex' => 'افزوده‌ها', 'cataloguer' => 'فهرست‌نگار', 'other' => 'دیگر',
    ];

    /** The sections of the copy's page, in their order, and the types of each in theirs */
    public const GROUPS = [
        'contents' => ['title' => 'محتوای نسخه', 'types' => ['description', 'scope', 'defect', 'contents', 'annex', 'cataloguer', 'other']],
        'provenance' => ['title' => 'مهرها، تملک‌ها و وقف‌نامه‌ها', 'types' => ['ownership', 'seal', 'endowment', 'acquisition']],
        'marks' => ['title' => 'نشان‌های متنی', 'types' => ['certificate', 'collation', 'marginalia', 'transcription']],
        'physical' => ['title' => 'کالبد و آرایه', 'types' => ['decoration', 'illustration', 'diagram', 'codicology']],
    ];

    /**
     * Who gave a note its type; an editor's is kept by a later import. «site» is a piece of the site's old list of
     * cataloguer's notes that parser v2 reads nowhere, kept as «other» (step 14.260).
     */
    public const TYPED_BY = ['field', 'rule', 'sonnet', 'opus', 'site', 'editor'];

    protected $fillable = ['manuscript_id', 'position', 'type', 'text', 'also', 'by_author', 'by_author_hedge', 'hedge', 'typed_by'];

    protected function casts(): array
    {
        return ['also' => 'array', 'by_author' => 'boolean'];
    }

    public function manuscript(): BelongsTo
    {
        return $this->belongsTo(Manuscript::class);
    }

    /**
     * The types a copy's notes have, a note's other types (`also`) among them, in the order of TYPES: what the
     * search index holds and the filter of the copies asks for (step 14.269)
     *
     * @param iterable<object{type: string, also: mixed}> $notes
     * @return string[]
     */
    public static function typesOf(iterable $notes): array
    {
        $found = [];
        foreach ($notes as $note) {
            $also = is_string($note->also) ? json_decode($note->also, true) : $note->also;
            foreach ([$note->type, ...(array) $also] as $type) {
                $found[$type] = true;
            }
        }

        return array_values(array_filter(array_keys(self::TYPES), fn ($type) => isset($found[$type])));
    }

    /** The copies that have a note of the type, as its own type or one of its others */
    public static function copiesWith(string $type): \Illuminate\Database\Query\Builder
    {
        return \Illuminate\Support\Facades\DB::table('manuscript_notes')->where(fn ($q) => $q->where('type', $type)
            ->orWhereRaw('JSON_CONTAINS(also, ?)', [json_encode($type)]))->select('manuscript_id');
    }
}

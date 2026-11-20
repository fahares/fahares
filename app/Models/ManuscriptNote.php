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

    /** Who gave a note its type; an editor's is kept by a later import */
    public const TYPED_BY = ['field', 'rule', 'sonnet', 'opus', 'editor'];

    protected $fillable = ['manuscript_id', 'position', 'type', 'text', 'also', 'by_author', 'by_author_hedge', 'hedge', 'typed_by'];

    protected function casts(): array
    {
        return ['also' => 'array', 'by_author' => 'boolean'];
    }

    public function manuscript(): BelongsTo
    {
        return $this->belongsTo(Manuscript::class);
    }
}

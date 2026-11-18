<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One note of a copy's description with its type (backlog item 87, step 14.254)
 */
class ManuscriptNote extends Model
{
    /** The approved types (docs/plans/fankha-parser-v2.md, section 10), in their four groups */
    public const TYPES = [
        'acquisition' => 'ورود به کتابخانه', 'ownership' => 'تملک', 'seal' => 'مهر', 'endowment' => 'وقف',
        'certificate' => 'اجازه، سماع و بلاغ', 'collation' => 'مقابله و تصحیح', 'marginalia' => 'حواشی', 'transcription' => 'کتابت',
        'decoration' => 'تذهیب و آرایه', 'illustration' => 'نگاره', 'diagram' => 'شکل و جدول', 'codicology' => 'نسخه‌شناسی',
        'annex' => 'افزوده‌ها', 'contents' => 'محتوای نسخه', 'cataloguer' => 'یادداشت فهرست‌نگار', 'other' => 'دیگر',
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

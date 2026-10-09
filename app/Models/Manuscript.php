<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Laravel\Scout\Searchable;

class Manuscript extends Model
{
    use Searchable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sequence_number' => 'integer',
            'volume_number' => 'integer',
            'page_start' => 'integer',
            'page_end' => 'integer',
            'is_bika' => 'boolean',
            'is_bita' => 'boolean',
            'is_autograph' => 'boolean',
            'copy_date_hijri_year' => 'integer',
            'folios' => 'integer',
            'lines' => 'integer',
            'is_corrected' => 'boolean',
            'has_marginal_notes' => 'boolean',
            'is_ruled' => 'boolean',
            'has_catchwords' => 'boolean',
            'is_facsimile' => 'boolean',
            'is_collated' => 'boolean',
            'is_illuminated' => 'boolean',
            'is_illustrated' => 'boolean',
            'has_author_marginalia' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function getAccessionNumberAttribute(): ?string
    {
        return $this->shelfmark;
    }

    public function getPersianSlugAttribute(): string
    {
        $workTitle = null;
        if ($this->relationLoaded('work') && $this->work) {
            $workTitle = $this->work->clean_title ?: $this->work->primary_title;
        } elseif (!empty($this->work_id)) {
            $workTitle = $this->work?->clean_title ?: $this->work?->primary_title;
        }

        $libName = $this->libraryRecord?->name ?? $this->library;
        $shelfmark = $this->shelfmark;

        $parts = array_filter([$workTitle, $libName, $shelfmark]);
        $text = !empty($parts) ? implode(' ', $parts) : 'نسخه خطی';

        $slug = \Illuminate\Support\Str::slug($text, '-', null);
        if (mb_strlen($slug) > 80) {
            $slug = mb_substr($slug, 0, 80);
            $lastHyphen = mb_strrpos($slug, '-');
            if ($lastHyphen > 30) {
                $slug = mb_substr($slug, 0, $lastHyphen);
            }
        }
        return $slug ?: 'manuscript';
    }

    public function getRouteKey(): string
    {
        $slug = $this->persian_slug;
        return $slug ? "{$this->id}-{$slug}" : (string) $this->id;
    }

    public function getScribeDisplayNameAttribute(): string
    {
        if (!empty($this->scribe_name)) {
            return $this->scribe_name;
        }
        if ($this->is_autograph) {
            return 'مؤلف';
        }
        if ($this->is_bika) {
            return 'بی‌کاتب';
        }
        return 'نامشخص';
    }

    public function getCatalogCitationAttribute(): ?string
    {
        return $this->metadata['catalog_citation'] ?? null;
    }

    public function getCenturyTextAttribute(): ?string
    {
        if ($this->copy_date_hijri_year) {
            $century = (int) ceil($this->copy_date_hijri_year / 100);
            return "قرن {$century} هـ.ق";
        }
        if (!empty($this->copy_date_raw) && preg_match('/قرن\s*(\d+)/u', $this->copy_date_raw, $m)) {
            return "قرن {$m[1]} هـ.ق";
        }
        return null;
    }

    public function getIncipitMatchesWorkAttribute(): bool
    {
        if (isset($this->metadata['incipit_matches_work'])) {
            return (bool) $this->metadata['incipit_matches_work'];
        }
        if (!empty($this->incipit_text) && in_array(trim($this->incipit_text), ['برابر', 'برابر است', 'برابر؛', 'برابر.'])) {
            return true;
        }
        if (!empty($this->raw_text)) {
            return (bool) preg_match('/(?:^|[؛\n])\s*آغاز(?:\s*و\s*انجام)?[:\s]\s*برابر/u', $this->raw_text);
        }
        return false;
    }

    public function getExplicitMatchesWorkAttribute(): bool
    {
        if (isset($this->metadata['explicit_matches_work'])) {
            return (bool) $this->metadata['explicit_matches_work'];
        }
        if (!empty($this->explicit_text) && in_array(trim($this->explicit_text), ['برابر', 'برابر است', 'برابر؛', 'برابر.'])) {
            return true;
        }
        if (!empty($this->raw_text)) {
            return (bool) (
                preg_match('/(?:^|[؛\n])\s*آغاز\s*و\s*انجام[:\s]\s*برابر/u', $this->raw_text) ||
                preg_match('/(?:^|[؛\n])\s*انجام[:\s]\s*برابر/u', $this->raw_text)
            );
        }
        return false;
    }

    public function getIncipitAttribute(): ?string
    {
        $list = $this->incipits_list;
        return !empty($list[0]['text']) ? $list[0]['text'] : $this->incipit_text;
    }

    public function getExplicitAttribute(): ?string
    {
        $list = $this->explicits_list;
        return !empty($list[0]['text']) ? $list[0]['text'] : $this->explicit_text;
    }

    public function getIncipitsListAttribute(): array
    {
        $meta = $this->metadata['incipits'] ?? [];
        $list = [];
        if (!empty($meta) && is_array($meta)) {
            $list = $meta;
        } elseif (!empty($this->incipit_text)) {
            $list = [['label' => null, 'text' => $this->incipit_text]];
        }

        $filtered = [];
        $hasBarabar = false;
        foreach ($list as $item) {
            $txt = trim($item['text'] ?? '');
            if (in_array($txt, ['برابر', 'برابر است', 'برابر؛', 'برابر.'])) {
                $hasBarabar = true;
            } else {
                $filtered[] = $item;
            }
        }

        if (!empty($filtered)) {
            return $filtered;
        }

        if ($hasBarabar || $this->incipit_matches_work) {
            $workIncipit = !empty($this->work?->incipit_text) ? trim(preg_replace('/<!--\s*page:\s*\d+\s*-->/u', '', $this->work->incipit_text)) : null;
            if (!empty($workIncipit)) {
                return [[
                    'label' => 'منطبق بر آغاز اثر (در مأخذ: «برابر»)',
                    'text' => $workIncipit,
                    'is_work_match' => true,
                    'is_placeholder' => false,
                ]];
            } else {
                return [[
                    'label' => 'در مأخذ: «برابر»',
                    'text' => 'آغاز این نسخه در مأخذ فهرست‌نگاری، برابر با آغاز کتاب قید گردیده است.',
                    'is_work_match' => true,
                    'is_placeholder' => true,
                ]];
            }
        }

        return [];
    }

    public function getExplicitsListAttribute(): array
    {
        $meta = $this->metadata['explicits'] ?? [];
        $list = [];
        if (!empty($meta) && is_array($meta)) {
            $list = $meta;
        } elseif (!empty($this->explicit_text)) {
            $list = [['label' => null, 'text' => $this->explicit_text]];
        }

        $filtered = [];
        $hasBarabar = false;
        foreach ($list as $item) {
            $txt = trim($item['text'] ?? '');
            if (in_array($txt, ['برابر', 'برابر است', 'برابر؛', 'برابر.'])) {
                $hasBarabar = true;
            } else {
                $filtered[] = $item;
            }
        }

        if (!empty($filtered)) {
            return $filtered;
        }

        if ($hasBarabar || $this->explicit_matches_work) {
            $workExplicit = !empty($this->work?->explicit_text) ? trim(preg_replace('/<!--\s*page:\s*\d+\s*-->/u', '', $this->work->explicit_text)) : null;
            if (!empty($workExplicit)) {
                return [[
                    'label' => 'منطبق بر انجام اثر (در مأخذ: «برابر»)',
                    'text' => $workExplicit,
                    'is_work_match' => true,
                    'is_placeholder' => false,
                ]];
            } else {
                return [[
                    'label' => 'در مأخذ: «برابر»',
                    'text' => 'انجام این نسخه در مأخذ فهرست‌نگاری، برابر با انجام کتاب قید گردیده است.',
                    'is_work_match' => true,
                    'is_placeholder' => true,
                ]];
            }
        }

        return [];
    }

    public function getOwnershipAndSealsListAttribute(): array
    {
        $seals = $this->metadata['ownership_and_seals'] ?? [];
        return is_array($seals) ? array_values(array_filter($seals)) : [];
    }

    public function getEditorialNotesListAttribute(): array
    {
        $notes = $this->metadata['editorial_notes'] ?? [];
        return is_array($notes) ? array_values(array_filter($notes)) : [];
    }

    public function getResidualNotesTextAttribute(): ?string
    {
        $res = $this->metadata['residual_notes'] ?? null;
        if (is_string($res) && trim($res) !== '') {
            return trim($res);
        }
        if (is_array($res) && !empty($res)) {
            return implode(' ؛ ', array_filter($res));
        }
        return null;
    }

    public function getCleanRawTextAttribute(): ?string
    {
        if (empty($this->raw_text)) {
            return null;
        }
        $cleaned = preg_replace('/<!--\s*page:\s*\d+\s*-->/u', '', $this->raw_text);
        $cleaned = str_replace(["\r\n", "\r"], "\n", $cleaned);
        $cleaned = preg_replace('/^[ \t]+$/m', '', $cleaned);
        $cleaned = preg_replace('/[ \t]{2,}/u', ' ', $cleaned);
        $cleaned = preg_replace("/\n{3,}/u", "\n\n", $cleaned);
        return trim($cleaned);
    }

    public function searchableAs(): string
    {
        return 'manuscripts_index';
    }

    public function makeAllSearchableUsing($query)
    {
        return $query->with(['work:id,primary_title,clean_title,author_name', 'scripts:id,name']);
    }

    public function toSearchableArray(): array
    {
        $workTitle = null;
        $authorName = null;
        if ($this->relationLoaded('work') && $this->work) {
            $workTitle = $this->work->clean_title ?: $this->work->primary_title;
            $authorName = $this->work->author_name;
        } elseif (!empty($this->work_id)) {
            $w = $this->work;
            $workTitle = $w?->clean_title ?: $w?->primary_title;
            $authorName = $w?->author_name;
        }

        return [
            'id' => (int) $this->id,
            'work_id' => (int) $this->work_id,
            'work_title' => \App\Helpers\TextNormalizer::expandVariants($workTitle),
            'author_name' => \App\Helpers\TextNormalizer::normalize($authorName),
            'library_id' => $this->library_id ? (int) $this->library_id : null,
            'library' => $this->library,
            'city' => $this->city,
            'shelfmark' => $this->shelfmark,
            'shelfmark_key' => $this->shelfmark_key,
            'scribe_id' => $this->scribe_id ? (int) $this->scribe_id : null,
            'scribe_name' => \App\Helpers\TextNormalizer::normalize($this->scribe_name ?: ($this->is_autograph ? 'مؤلف' : null)),
            'is_bika' => (bool) $this->is_bika,
            'is_bita' => (bool) $this->is_bita,
            'is_autograph' => (bool) $this->is_autograph,
            'copy_date_raw' => $this->copy_date_raw,
            'copy_date_hijri_year' => $this->copy_date_hijri_year ? (int) $this->copy_date_hijri_year : null,
            'copy_place' => \App\Helpers\TextNormalizer::normalize($this->copy_place),
            'commissioned_by' => \App\Helpers\TextNormalizer::normalize($this->commissioned_by),
            'script_names' => $this->script_names,
            'script_style' => $this->script_style,
            'folios' => $this->folios ? (int) $this->folios : null,
            'lines' => $this->lines ? (int) $this->lines : null,
            'dimensions' => $this->dimensions,
            'paper' => $this->paper,
            'binding' => $this->binding,
            'incipit_text' => \App\Helpers\TextNormalizer::normalize($this->incipit_text),
            'explicit_text' => \App\Helpers\TextNormalizer::normalize($this->explicit_text),
            'volume_number' => (int) $this->volume_number,
            'sequence_number' => (int) $this->sequence_number,
            'is_corrected' => (bool) $this->is_corrected,
            'has_marginal_notes' => (bool) $this->has_marginal_notes,
            'is_ruled' => (bool) $this->is_ruled,
            'has_catchwords' => (bool) $this->has_catchwords,
            'is_facsimile' => (bool) $this->is_facsimile,
            'is_collated' => (bool) $this->is_collated,
            'is_illuminated' => (bool) $this->is_illuminated,
            'is_illustrated' => (bool) $this->is_illustrated,
            'has_author_marginalia' => (bool) $this->has_author_marginalia,
            'scripts' => $this->relationLoaded('scripts') ? $this->scripts->pluck('name')->all() : [],
        ];
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(Catalog::class);
    }

    public function cataloger(): BelongsTo
    {
        return $this->belongsTo(Cataloger::class);
    }

    public function catalogVolume(): BelongsTo
    {
        return $this->belongsTo(CatalogVolume::class);
    }

    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    public function libraryRecord(): BelongsTo
    {
        return $this->belongsTo(Library::class, 'library_id');
    }

    public function scribe(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'scribe_id');
    }

    public function scripts(): BelongsToMany
    {
        return $this->belongsToMany(Script::class)->withPivot('is_primary');
    }

    public function fieldSuggestions(): MorphMany
    {
        return $this->morphMany(FieldSuggestion::class, 'suggestable');
    }

    public function scholarlyAnnotations(): MorphMany
    {
        return $this->morphMany(ScholarlyAnnotation::class, 'annotatable');
    }
}

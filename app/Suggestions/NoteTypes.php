<?php

namespace App\Suggestions;

use Illuminate\Validation\ValidationException;

/**
 * Research notes: additions to a record rather than corrections of a field. A note is a
 * suggestion with kind "note", its type in field_name and its fields in payload; once a
 * reviewer approves it, it is listed on the record page with the contributor's name.
 */
class NoteTypes
{
    /** Field labels; "required" lists per type below. */
    public const FIELDS = [
        'text' => 'متن',
        'location' => 'جای آن در نسخه (مثلاً برگ ۲۰ پ)',
        'title' => 'عنوان',
        'author' => 'نویسنده، مصحح یا مترجم',
        'publication' => 'نشریه یا کتاب دربرگیرنده',
        'publisher' => 'ناشر',
        'place' => 'محل نشر',
        'year' => 'سال نشر',
        'volume' => 'جلد',
        'page' => 'صفحه',
        'pages' => 'صفحه‌ها',
        'url' => 'نشانی (پیوند)',
        'caption' => 'توضیح تصویر',
    ];

    public const TYPES = [
        'observation' => [
            'label' => 'مشاهده و نکته‌ی پژوهشی',
            'hint' => 'نکته‌ای که در شناسنامه نیامده، مثلاً «در برگ ۲۰ یادداشتی به خط فلان عالم دیده می‌شود».',
            'fields' => ['text', 'location'],
            'required' => ['text'],
        ],
        'catalog' => [
            'label' => 'معرفی در فهرست یا کتاب‌شناسی دیگر',
            'hint' => 'فهرست یا کتاب‌شناسی دیگری که این رکورد را معرفی کرده است.',
            'fields' => ['title', 'volume', 'page', 'url', 'text'],
            'required' => ['title'],
        ],
        'image' => [
            'label' => 'تصویر دیجیتال',
            'hint' => 'پیوند تصویر در سایت کتابخانه یا مخزن دیجیتال، یا بارگذاری چند برگ نمونه (مثلاً برگ نخست و ترقیمه).',
            'fields' => ['url', 'caption'],
            'required' => [],
            'files' => true,
        ],
        'edition' => [
            'label' => 'چاپ یا تصحیح منتشرشده',
            'hint' => 'چاپ سنگی، چاپ حروفی یا تصحیح انتقادی.',
            'fields' => ['title', 'author', 'publisher', 'place', 'year', 'url', 'text'],
            'required' => ['title'],
        ],
        'study' => [
            'label' => 'پژوهش یا منبع مرتبط',
            'hint' => 'مقاله، کتاب یا پایان‌نامه‌ای که درباره‌ی این رکورد است یا از آن استفاده کرده است.',
            'fields' => ['title', 'author', 'publication', 'year', 'pages', 'url', 'text'],
            'required' => ['title'],
        ],
        'link' => [
            'label' => 'پیوند مخزن دیجیتال یا فهرست برخط',
            'hint' => 'نشانی مخزن دیجیتال، فهرست برخط یا پایگاه کتابخانه.',
            'fields' => ['title', 'url', 'text'],
            'required' => ['title', 'url'],
        ],
    ];

    /** Which note types each record type takes, in display order. */
    public const BY_RECORD = [
        'manuscript' => ['observation', 'catalog', 'image', 'edition', 'study'],
        'work' => ['observation', 'catalog', 'edition', 'study'],
        'person' => ['observation', 'image', 'study'],
        'library' => ['link', 'observation', 'study'],
    ];

    public const MAX_FILES = 4;

    public static function forRecord(string $type): array
    {
        $types = [];
        foreach (self::BY_RECORD[SuggestionRegistry::typeKey($type)] ?? [] as $key) {
            $types[$key] = ['key' => $key] + self::TYPES[$key];
        }

        return $types;
    }

    public static function label(?string $key): string
    {
        return self::TYPES[$key]['label'] ?? 'یادداشت پژوهشی';
    }

    public static function allowed(string $recordType, ?string $key): bool
    {
        return in_array($key, self::BY_RECORD[SuggestionRegistry::typeKey($recordType)] ?? [], true);
    }

    /**
     * The type's fields from the submitted input, trimmed; empty ones dropped.
     *
     * @throws ValidationException when a required field is missing or a link is not http(s)
     */
    public static function clean(string $key, array $input, bool $hasFiles = false): array
    {
        $definition = self::TYPES[$key];
        $values = [];
        foreach ($definition['fields'] as $field) {
            $value = trim(str_replace(["\r\n", "\r"], "\n", (string) ($input[$field] ?? '')));
            if ($value !== '') {
                $values[$field] = mb_substr($value, 0, $field === 'text' ? 10000 : 1000);
            }
        }

        $errors = [];
        foreach ($definition['required'] as $field) {
            if (! isset($values[$field])) {
                $errors["note.{$field}"] = '«' . self::FIELDS[$field] . '» را بنویسید.';
            }
        }
        if (isset($values['url']) && ! preg_match('~^https?://\S+$~iu', $values['url'])) {
            $errors['note.url'] = 'نشانی باید با http:// یا https:// آغاز شود.';
        }
        if (! empty($definition['files']) && ! isset($values['url']) && ! $hasFiles) {
            $errors['note.url'] = 'نشانی تصویر را بنویسید یا تصویری بارگذاری کنید.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $values;
    }

    /** One-paragraph text of a note, for notifications, lists and search. */
    public static function summary(string $key, array $values): string
    {
        $parts = match ($key) {
            'observation' => [$values['text'] ?? '', isset($values['location']) ? "({$values['location']})" : null],
            'catalog' => [$values['title'] ?? '', isset($values['volume']) ? "ج {$values['volume']}" : null, isset($values['page']) ? "ص {$values['page']}" : null],
            'image' => [$values['caption'] ?? 'تصویر دیجیتال', $values['url'] ?? null],
            'edition', 'study' => [$values['author'] ?? null, $values['title'] ?? '', $values['publication'] ?? null, $values['publisher'] ?? null, $values['place'] ?? null, $values['year'] ?? null, $values['pages'] ?? null],
            default => [$values['title'] ?? '', $values['url'] ?? null],
        };

        return implode('، ', array_filter($parts, fn ($p) => $p !== null && $p !== ''));
    }
}

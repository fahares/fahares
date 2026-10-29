<?php

namespace App\Support;

use App\Helpers\TextNormalizer;

/**
 * Reads, from a work's title or from a related work recorded as text, how the work is made on another and that
 * other work's title (backlog item 6, step 1). «شرح نهج البلاغة» is a commentary on «نهج البلاغة»; «الروضة البهیة
 * فی شرح اللمعة» a commentary on «اللمعة» under a title of its own; «الرسالة الذهبیة (ترجمه)» a translation.
 * The search fills the works index's base titles from it (backlog item 95), and the linking of item 6 reads the
 * same results to find the base work. Types are those of RecordRelations::WORK_TYPES.
 */
class WorkRelationParser
{
    /** Normalized first word of a title => relation type. Only words that name a work made on another one. */
    public const OPENERS = [
        'شرح' => 'commentary',
        'حاشیه' => 'gloss', 'الحاشیه' => 'gloss', 'حواشی' => 'gloss', 'الحواشی' => 'gloss',
        'تعلیقه' => 'gloss', 'التعلیقه' => 'gloss', 'تعلیقات' => 'gloss', 'التعلیقات' => 'gloss', 'تحشیه' => 'gloss',
        'ترجمه' => 'translation',
        'مختصر' => 'abridgment', 'منتخب' => 'abridgment', 'تلخیص' => 'abridgment', 'گزیده' => 'abridgment', 'خلاصه' => 'abridgment',
        'نظم' => 'versification', 'منظومه' => 'versification',
        'ذیل' => 'supplement', 'تکمله' => 'supplement', 'تتمه' => 'supplement', 'مستدرک' => 'supplement', 'ملحقات' => 'supplement',
        'نقد' => 'refutation', 'رد' => 'refutation', 'الرد' => 'refutation', 'ردیه' => 'refutation',
    ];

    /** The same, marked after the title in parentheses: «الرسالة الذهبیة (ترجمه)» */
    public const MARKS = [
        'ترجمه' => 'translation', 'ترجمه و شرح' => 'translation', 'شرح' => 'commentary', 'حاشیه' => 'gloss',
        'مختصر' => 'abridgment', 'منتخب' => 'abridgment', 'گزیده' => 'abridgment', 'منظوم' => 'versification',
    ];

    /** Words joining the opener to the base title, dropped: «حاشیة علی الشرائع», «رد بر صوفیه» */
    private const LINKS = ['علی', 'بر', 'الی'];

    /** A base starting with these is a subject, not a title: «شرح حال …» is a life, «منظومة فی النحو» a poem */
    private const NOT_A_TITLE = ['حال', 'احوال', 'زندگی', 'زندگانی', 'فی', 'در', 'من', 'عن', 'به', 'از', 'و'];

    /** A base that is only one of these names the kind of commentary, not its base: «شرح کبیر», «شرح جدید» */
    private const KINDS = ['کبیر', 'صغیر', 'وسیط', 'جدید', 'قدیم', 'مزجی', 'مختصر', 'مبسوط', 'متوسط'];

    /** Words before an opener inside a title: «آمال العارفین فی شرح الصحیفة», «المنتخب من شرح نهج البلاغة» */
    private const IN = ['فی', 'در', 'من'];

    /** Types whose opener names a work made on another inside a title even without such a word */
    private const BARE = ['commentary', 'gloss'];

    /**
     * How a title is made on another work.
     *
     * @return array{type: string, base: string, head: ?string}|null the type, the base work's title as written
     *     and, for «X فی شرح Y», the work's own title X; null when the title names no other work
     */
    public static function parseTitle(?string $title): ?array
    {
        $raw = self::words($title);
        if ($raw === []) {
            return null;
        }
        $norm = array_map(fn ($w) => TextNormalizer::normalize($w), $raw);

        // «X (ترجمه)»
        if (preg_match('/^(.+?)\s*\(([^()]+)\)$/u', implode(' ', $raw), $m)
            && ($type = self::MARKS[TextNormalizer::normalize($m[2])] ?? null)) {
            return ['type' => $type, 'base' => self::trim($m[1]), 'head' => null];
        }

        // «شرح X», «حاشیة علی X»
        if (isset(self::OPENERS[$norm[0]]) && ($base = self::base($raw, $norm, 1)) !== null) {
            return ['type' => self::OPENERS[$norm[0]], 'base' => $base, 'head' => null];
        }

        // «X فی شرح Y», and a commentary or gloss without the joining word: «توضیح شرح اللمعة»
        for ($i = 1; $i < count($norm) - 1; $i++) {
            $joined = in_array($norm[$i], self::IN, true) && isset(self::OPENERS[$norm[$i + 1] ?? '']);
            $bare = ! $joined && in_array(self::OPENERS[$norm[$i]] ?? null, self::BARE, true);
            if (($joined || $bare) && ($base = self::base($raw, $norm, $joined ? $i + 2 : $i + 1)) !== null) {
                return ['type' => self::OPENERS[$norm[$joined ? $i + 1 : $i]], 'base' => $base, 'head' => self::trim(implode(' ', array_slice($raw, 0, $i)))];
            }
        }

        return null;
    }

    /**
     * A related work recorded as text: «عنوان = عنوان دیگر؛ پدیدآور (تولد-وفات)». A field may hold several,
     * separated like their parts by «؛», and end in a note; the note is left out.
     *
     * @return list<array{titles: list<string>, author: ?string, born: ?int, died: ?int, type: ?string, base: ?string}>
     *     each entry's titles as written, its author and life dates, and how its first title is made on another work
     */
    public static function parseEntries(?string $text): array
    {
        // Page markers and bracketed references of the corpus text
        $text = preg_replace(['/<!--.*?-->/su', '/\[[^\[\]]*\]/u'], ' ', (string) $text);
        $parts = array_values(array_filter(array_map('trim', preg_split('/[؛;]/u', $text)), fn ($p) => $p !== ''));

        $entries = [];
        for ($i = 0; $i < count($parts); $i++) {
            $author = isset($parts[$i + 1]) ? self::author($parts[$i + 1]) : null;
            // A part with no author after it is a title only when short, else the note that ends the field
            if (! $author && (mb_strlen($parts[$i]) > 120 || str_contains($parts[$i], '«'))) {
                break;
            }
            $titles = array_values(array_filter(array_map(fn ($t) => self::trim($t), explode('=', $parts[$i])), fn ($t) => $t !== ''));
            // «؛» written as «=» before the author: «اللمعة الدمشقیة = شهید اول، محمد بن مکی (734-786)»
            if (! $author && count($titles) > 1 && str_contains(end($titles), '،') && ($hidden = self::author(end($titles)))) {
                array_pop($titles);
                $author = $hidden + ['inline' => true];
            }
            if ($titles === []) {
                continue;
            }
            $relation = self::parseTitle($titles[0]);
            $entries[] = [
                'titles' => $titles,
                'author' => $author['name'] ?? null,
                'born' => $author['born'] ?? null,
                'died' => $author['died'] ?? null,
                'type' => $relation['type'] ?? null,
                'base' => $relation['base'] ?? null,
            ];
            if ($author) {
                $i += isset($author['inline']) ? 0 : 1;
                if ($author['note']) {
                    break;
                }
            }
        }

        return $entries;
    }

    /**
     * «سعدی چلپی، سعدالله بن عیسی (-945)»: a name followed by its life dates, perhaps followed by a note.
     *
     * @return array{name: string, born: ?int, died: ?int, note: bool}|null
     */
    private static function author(string $part): ?array
    {
        if (! preg_match('/^([^()=«]{2,80}?)\s*\(([^()]*\d[^()]*)\)\s*(.*)$/su', $part, $m)) {
            return null;
        }
        $born = $died = null;
        $dates = TextNormalizer::normalize($m[2]);
        if (preg_match('/^(\d*)\s*-\s*(\d*)/u', $dates, $d)) {
            [$born, $died] = [$d[1] !== '' ? (int) $d[1] : null, $d[2] !== '' ? (int) $d[2] : null];
        }

        return ['name' => self::trim($m[1]), 'born' => $born, 'died' => $died, 'note' => self::trim($m[3]) !== ''];
    }

    /** The title from word $from on, without a joining word, or null when it is not a title */
    private static function base(array $raw, array $norm, int $from): ?string
    {
        if (isset($norm[$from]) && in_array($norm[$from], self::LINKS, true)) {
            $from++;
        }
        if (! isset($norm[$from]) || in_array($norm[$from], self::NOT_A_TITLE, true)) {
            return null;
        }
        $base = self::trim(implode(' ', array_slice($raw, $from)));
        $norm = TextNormalizer::normalize($base);

        return mb_strlen($norm) >= 2 && ! in_array($norm, self::KINDS, true) ? $base : null;
    }

    /** @return list<string> the words as written, with half-spaces as spaces */
    private static function words(?string $text): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', str_replace("\u{200c}", ' ', (string) $text)));

        return $text === '' ? [] : explode(' ', $text);
    }

    private static function trim(string $text): string
    {
        return preg_replace(['/\s+/u', '/^[\s،,.:؛;-]+|[\s،,.:؛;-]+$/u'], [' ', ''], $text);
    }
}

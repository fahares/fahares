<?php

return [
    /*
     * The links sources:link-page-refs makes in a source's page texts (step 13.155, plan docs/plans/page-text-links.md),
     * by the source's code. A source added later uses the same code by giving its own forms here.
     *
     * - copies: a shelfmark right after a mark already linked to a library («(سپهسالار: 404)», «(المجلس 1012)»,
     *   «(إلهيات: 799 د)») links the copy, when the library has one copy of that number whose work is the entry's.
     *   - marks: the Noor kinds whose library links are followed.
     *   - skip_words: words after the number that are the sentence's, not a collection's mark («كتابته»، «و»).
     * - references: a reference to a volume and page of the source itself links the entry it names there, or the page.
     *   - forms: regexes, each with the named groups volume and page. Which of «(161:7)» is the volume is decided as
     *     config('bibliography.sources') decides it for a citation of the source: the order that fits its volumes.
     *   - cues: a regex the words right before a reference must end with, telling it is to the source itself and not to
     *     another book («مر في»، «يأتي في»، «راجع ذ»); a reference right after another one («(199:3) و (311:5)») needs none.
     *   - title_before / title_after: regexes whose group title is the entry's title, the words before the reference
     *     ending with the first, those after it starting with the second («مر بعنوان الدرة النظمية (175:19)»).
     *   - bibliography: the source's key in config('bibliography.sources'), which gives its volumes and pages.
     * - holdings: a line naming a copy in a library by its catalogue's page and volume or by its number («دانشگاه
     *   2705/12 ش (1) 3701», step 14.34, App\Support\PageLinks\Holdings) links the copy of the entry's work that has them.
     *   - libraries: the forms the source prints => the site's libraries they may be (city, name); a form of two
     *     («الهیات» of Tehran or of Mashhad) is told apart by the copies of the work.
     */
    'dharia' => [
        'copies' => [
            'marks' => ['OB'],
            'skip_words' => ['و', 'في', 'فی', 'من', 'ضمن', 'كما', 'بخط', 'نسخة', 'ناقص', 'كلها', 'كتابته', 'كتابتها', 'تاريخ',
                'بعنوان', 'أوله', 'ذكر', 'إلى', 'الى', 'عليه', 'المجموعة', 'مع', 'أيضا', 'كتب', 'بتاريخ', 'سنة', 'رأيته', 'عند', 'هو'],
        ],
        'references' => [
            'bibliography' => 'dharia',
            'forms' => [
                '\(\s*(?<first>\d{1,4})\s*:\s*(?<second>\d{1,4})\s*\)',
                '\(\s*ج\s*(?<volume>\d{1,2})\s*[-–،,]?\s*ص\s*(?<page>\d{1,4})\s*\)',
                '(?<![\d(:\/])(?<first>\d{1,4})\s*:\s*(?<second>\d{1,2})(?![\d:\/)])',
                '(?<![(\p{L}])ج\s*(?<volume>\d{1,2})\s*[-–،,]?\s*ص\s*(?<page>\d{1,4})',
                // the later volumes' «ذ 18: 388», volume first
                '(?<=ذ )(?<first>\d{1,2})\s*:\s*(?<second>\d{3,4})(?![\d:\/])',
            ],
            // «مر في»، «يأتي بعنوان … في»، «ذكرناه في»، «راجع ذ»: the cue, then at most a few words of the sentence
            'cues' => '(?:(?<!\p{L})(?:مر|مرّ|يأتي|سيأتي|تأتي|ستأتي|ذكرناه|ذكرناها|ذكرنا|قدمنا|تقدم|ذ)(?!\p{L})'
                . '(?:\s*[:،]?\s*(?:ذكره|ذكرها|ذكرهما|ذكر|له|لها|في|أيضا|آنفا|كما|بعنوان\s+[^().\d\n؛]{1,60}?))*'
                . '|(?<!\p{L})راجع\s*:?\s*(?:ذ\s+)?[^().\d\n؛]{0,60}?)\s*(?:في)?\s*:?\s*\(?\s*$',
            'title_before' => '(?:بعنوان|راجع\s*:?)\s+(?<title>[^().\d؛\n]{2,60}?)\s*(?:في)?\s*$',
            'title_after' => '^\s*\)?\s*،?\s*(?:و\s*)?بعنوان\s+(?<title>[^().\d؛،\n]{2,60}?)\s*(?:[.،؛(]|\sو\s*(?:في|\()|\sكما|\sيأتي|\sمر|$)',
        ],
    ],
    'monzavi' => [
        'holdings' => [
            // the forms Monzavi's holding lines print most of the libraries the site has; a city alone
            // («تهران»، «یزد») is a union catalogue of it, and the film catalogues («فیلم‌ها») are no library's copies
            'libraries' => [
                'دانشگاه' => [['تهران', 'دانشگاه']],
                'آستان قدس' => [['مشهد', 'رضوی']],
                'ملک' => [['تهران', 'ملک']],
                'مجلس' => [['تهران', 'مجلس']],
                'سنا' => [['تهران', 'مجلس سنا']],
                'مرعشی' => [['قم', 'مرعشی']], 'اشکوری' => [['قم', 'مرعشی']],
                'مرعشی - اشکوری' => [['قم', 'مرعشی']], 'اشکوری - مرعشی' => [['قم', 'مرعشی']],
                'ملی' => [['تهران', 'ملی']], 'ملّی' => [['تهران', 'ملی']],
                'سپهسالار' => [['تهران', 'سپهسالار']],
                'الهیات' => [['تهران', 'الهیات'], ['مشهد', 'الهیات']],
                'الهیات تهران' => [['تهران', 'الهیات']], 'الهیات مشهد' => [['مشهد', 'الهیات']],
                'ادبیات' => [['تهران', 'ادبیات'], ['مشهد', 'ادبیات']], 'ادبیات تهران' => [['تهران', 'ادبیات']],
                'دایرة‌المعارف' => [['تهران', 'دائرة المعارف']], 'دایرة المعارف' => [['تهران', 'دائرة المعارف']],
                'گلپایگانی' => [['قم', 'گلپایگانی']],
                'گوهرشاد' => [['مشهد', 'گوهرشاد']],
                'فیضیه' => [['قم', 'فیضیه']],
                'سلطنتی' => [['تهران', 'سلطنتی']],
                'نوربخش' => [['تهران', 'نوربخش']],
                'فاضل' => [['خوانسار', 'فاضل']],
                'شاهچراغ' => [['شیراز', 'شاهچراغ']],
                'مینوی' => [['تهران', 'مینوی']],
                'مفتاح' => [['تهران', 'مفتاح']],
                'وزیری' => [['یزد', 'وزیری']],
                'نمازی' => [['خوی', 'نمازی']],
                'مروی' => [['تهران', 'مروی']],
                'حقوق' => [['تهران', 'حقوق']],
            ],
        ],
    ],
];

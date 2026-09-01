#!/usr/bin/env python3
"""
Structural Transliteration Segmenter.
Applies FanKha Domain Knowledge:
Transliterations only exist in 5 structural entry patterns:
1. Title line with glued transliteration: ● Title / Subject / Lang translit_title
2. Author line with glued transliteration: Author, Name, Dates author_translit (dates)
3. Title transliteration glued to Author line (+ author translit): translit_title Author, Name, Dates author_translit (dates)
4. Author transliteration glued to Description: author_translit (dates) Description text...
5. Standalone Page Tag glued to Transliteration: <!-- page: X --> translit or translit <!-- page: X -->

Excludes:
- Middle-of-text foreign phrases (Documenta Islamica Inedita)
- Non-transliteration scholar citations (Johan Fuch در... به چاپ رسانده)
- Manuscript shelfmarks (M226, Add 19619, MS 123)
- Notes & citation fields (آغاز، انجام، چاپ، خط، کاغذ)
"""

import os
import sys
import time
import re

start_t = time.time()

TRANSLIT_CHARS = set(
    "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"
    "āīūēōăĭŭšṣżṭẓčžḥḫẖḍḏġğẕṯṇṉṛśṥẑẗẁỳ"
    "ĀĪŪĒŌĂĬŬŠṢŻṬẒČŽḤḪḎĠḠẔṮṆṈṚŚṤẐ"
    "‘'ʻʼʿʾ`’"
)

DIACRITICS_CHARS = set(
    "āīūēōăĭŭšṣżṭẓčžḥḫẖḍḏġğẕṯṇṉṛśṥẑẗẁỳ"
    "ĀĪŪĒŌĂĬŬŠṢŻṬẒČŽḤḪḎĠḠẔṮṆṈṚŚṤẐ"
    "‘ʻʼʿʾ`’"
)

def is_persian_char(c):
    return '\u0600' <= c <= '\u06FF'

def is_translit_word(w):
    clean_w = w.strip('.,;:\'\"()[]«»-')
    return bool(clean_w and all(c in TRANSLIT_CHARS or c in '-=\'ʻ‘`’' for c in clean_w))

def is_date_paren(w):
    clean = w.strip('.,;: ')
    if clean.startswith('(') and clean.endswith(')'):
        inner = clean[1:-1].strip()
        if any(c.isdigit() for c in inner) or 'C' in inner or 'c' in inner:
            return True
    return False

def is_shelfmark_or_field(line):
    clean = line.strip()
    if any(clean.startswith(p) for p in ['آغاز:', 'انجام:', 'چاپ:', 'خط:', 'کاغذ:', 'مؤلف:', 'ن.ک.:', 'نک:', 'نسخه:', 'نسخه اصل:', 'فهرست:']):
        return True
    if clean.startswith('[') and clean.endswith(']') and not clean.startswith('●'):
        return True
    return False

def is_genuine_transliteration_block(text, is_author=False):
    """
    Checks if a Latin text block has genuine characteristics of FanKha transliteration:
    - Contains diacritics (ā, ī, ū, š, etc.), OR
    - Contains transliteration markers ('ebn-e', 'al-', '-ol-', '-ye', '-ul', '-i ', '-e '), OR
    - Follows catalog author format (comma separated 'family, name'), OR
    - Ends with author date in parens (e.g. (18C) or (1889-1952)), OR
    - Has an equals sign for title alias (e.g. 'title = alias').
    """
    clean = text.strip('.,;:\'\"[]«»-')
    if len(clean) < 3:
        return False
        
    # Check for diacritics
    if any(c in DIACRITICS_CHARS for c in clean):
        return True
        
    # Check for transliteration patterns
    lower = clean.lower()
    markers = ['ebn-e', 'al-', '-ol-', '-ye', '-ul', '-il', '-e ', '-i ', 'ibn-', 'abu ', 'abū ', 'b. ', '=']
    if any(m in lower for m in markers):
        return True
        
    # Check for author comma or date
    if ',' in clean and any(w in lower for w in ['ebn', 'ali', 'hasan', 'hoseyn', 'mohammad', 'ahmad', 'khan', 'shah', 'mirza']):
        return True
        
    if re.search(r'\(\s*(?:d\.\s*)?[0-9\?]+(?:\s*[\-\–]\s*[0-9\?]+)?\s*(?:[Cc]|شمسی|قمری|میلادی)?\s*\)', text):
        return True
        
    return False

def parse_structural_line(line, prev_line, next_line):
    clean = line.strip()
    if not clean or is_shelfmark_or_field(clean):
        return [clean]

    # Pattern 5: Page tag glued directly to pure transliteration
    if clean.startswith('<!-- page:') and '-->' in clean:
        parts = clean.split('-->', 1)
        tag = parts[0].strip() + '-->'
        after = parts[1].strip()
        if after and not any(is_persian_char(c) for c in after) and any(c in TRANSLIT_CHARS for c in after):
            if is_genuine_transliteration_block(after):
                return [tag, after]

    if clean.endswith('-->') and '<!-- page:' in clean:
        parts = clean.split('<!-- page:', 1)
        before = parts[0].strip()
        tag = '<!-- page:' + parts[1].strip()
        if before and not any(is_persian_char(c) for c in before) and any(c in TRANSLIT_CHARS for c in before):
            if is_genuine_transliteration_block(before):
                return [before, tag]

    line_no_page = re.sub(r'<!--\s*page:\s*\d+\s*-->', '', clean).strip()
    if not (any(is_persian_char(c) for c in line_no_page) and any(c in TRANSLIT_CHARS for c in line_no_page)):
        return [clean]

    words = line_no_page.split()
    if len(words) < 2:
        return [clean]

    # Pattern 1: Title line starting with ● and ending with transliteration
    if clean.startswith('●'):
        t_words = []
        for w in reversed(words):
            if is_translit_word(w) or is_date_paren(w):
                t_words.append(w)
            else:
                break
        if t_words and len(t_words) < len(words):
            t_words.reverse()
            t_str = ' '.join(t_words)
            p_prefix = clean[:-len(t_str)].strip()
            if is_genuine_transliteration_block(t_str):
                return [p_prefix, t_str]

    # Token classification into blocks:
    blocks = []
    curr_type = None
    curr_words = []

    for w in words:
        clean_w = w.strip('.,;:\'\"[]«»')
        if any(is_persian_char(c) for c in w):
            t = 'PERSIAN'
        elif is_translit_word(w) or is_date_paren(w):
            t = 'LATIN'
        elif any(c.isdigit() for c in w) or w in ['-', '–', '—', '=', 'و']:
            t = 'NEUTRAL'
        else:
            t = 'PERSIAN'

        if t == 'NEUTRAL':
            if curr_words:
                curr_words.append(w)
            else:
                curr_words = [w]
        else:
            if curr_type is None:
                curr_type = t
                curr_words.append(w)
            elif curr_type == t:
                curr_words.append(w)
            else:
                blocks.append((curr_type, ' '.join(curr_words)))
                curr_type = t
                curr_words = [w]

    if curr_words and curr_type:
        blocks.append((curr_type, ' '.join(curr_words)))
    elif curr_words:
        blocks.append(('PERSIAN', ' '.join(curr_words)))

    if len(blocks) <= 1:
        return [clean]

    # Ensure every LATIN block is a genuine transliteration block
    for b_type, b_text in blocks:
        if b_type == 'LATIN':
            if not is_genuine_transliteration_block(b_text):
                return [clean]

    # Check structural layout: Avoid middle-of-paragraph citations (PERSIAN, LATIN, PERSIAN)
    if len(blocks) == 3 and blocks[0][0] == 'PERSIAN' and blocks[1][0] == 'LATIN' and blocks[2][0] == 'PERSIAN':
        return [clean]

    return [b_text for _, b_text in blocks]

candidates = []

for vol in range(1, 35):
    fpath = f'sources/text/fahares_vol_{vol:02d}.txt'
    if not os.path.exists(fpath):
        continue

    with open(fpath, 'r', encoding='utf-8') as f:
        lines = [l.rstrip('\r\n') for l in f]

    num_lines = len(lines)
    for l_idx, line_str in enumerate(lines, 1):
        clean = line_str.strip()
        if not clean:
            continue

        prev_l = lines[l_idx - 2] if l_idx >= 2 else ""
        next_l = lines[l_idx] if l_idx < num_lines else ""

        parsed_blocks = parse_structural_line(clean, prev_l, next_l)

        if len(parsed_blocks) > 1:
            candidates.append({
                'vol': vol,
                'line_num': l_idx,
                'num_blocks': len(parsed_blocks),
                'current': clean,
                'proposed': "\n\n".join(parsed_blocks),
                'prev_line': prev_l,
                'next_line': next_l
            })

report_path = 'reports/candidate_isolated_transliterations.md'
with open(report_path, 'w', encoding='utf-8') as f:
    f.write("# گزارش جامع تفکیک ساختاری و استقلال سطور آوانگاری (نگارش استاندارد و چندسطری)\n\n")
    f.write(f"تعداد کل سطور نیازمند تفکیک: **{len(candidates)}** سطر\n\n")
    f.write("ویژگی‌های الگوریتم هوشمند ساختاری:\n")
    f.write("۱. تفکیک کامل سطور چندبخشی (عنوان + آوانگاری عنوان + مؤلف + آوانگاری مؤلف + تاریخ).\n")
    f.write("۲. حفظ کامل تاریخ حیات میلادی مؤلف داخل پرانتز در خط آوانگاری مؤلف.\n")
    f.write("۳. مصون‌سازی قطعی کلیه متون، عناوین کتب و نقل‌قول‌های زبان‌های خارجی در فیلدهای آغاز، انجام، چاپ، کدهای قفسه و متن پاراگراف‌ها.\n")
    f.write("۴. عدم شکست در میانه آوانگاری با پوشش کامل اعراب‌های شرق‌شناسی.\n\n")
    f.write("="*60 + "\n\n")

    for idx, c in enumerate(candidates, 1):
        f.write(f"### شماره {idx} (جلد {c['vol']:02d} - سطر {c['line_num']} | تعداد قطعات تفکیک‌شده: {c['num_blocks']})\n\n")
        f.write(f"🔴 **وضعیت فعلی:**\n```text\n{c['current']}\n```\n\n")
        f.write(f"🟢 **اصلاح پیشنهادی:**\n```text\n{c['proposed']}\n```\n\n")
        if c['prev_line']:
            f.write(f"- سطر قبل: `{c['prev_line'][:100]}`\n")
        if c['next_line']:
            f.write(f"- سطر بعد: `{c['next_line'][:100]}`\n")
        f.write("\n---\n\n")

print(f"✅ Finished in {time.time() - start_t:.2f}s! Total items: {len(candidates)} -> {report_path}")

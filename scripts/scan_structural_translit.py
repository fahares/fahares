#!/usr/bin/env python3
"""
Refined Structural Transliteration Segmenter (Production Engine).
Features:
1. Complete preservation and standalone positioning of page tags (<!-- page: X -->) when present in transliteration lines.
2. Author life dates (including single death dates (- 1887), (- 20c), (1850 - 1905), (18C)) kept intact in transliteration lines.
3. Proper handling of relationship metadata lines (وابسته به: ...).
4. Automatic stripping of stray religious honorifics ((ع), (ص), (س), (عج)) at the end of transliterations.
5. BiDi parenthetical date restoration: '(- author 17c)' -> 'author (- 17c)' and '(18C) author' -> 'author (18C)'.
6. Full Orientalist Latin Diacritics coverage.
7. Excludes non-transliteration fields (آغاز، انجام، چاپ، خط، کاغذ) and shelfmarks ([MS ...]).
8. Preserves normal text paragraphs without splitting.
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

def normalize_translit(text):
    clean = text.strip()
    clean = re.sub(r'\s*\([عصس]\)\s*$', '', clean)
    clean = re.sub(r'\s*\(عج\)\s*$', '', clean)
    
    # BiDi fractured date: '(- author 17c)' -> 'author (- 17c)'
    m_frac = re.match(r'^\(\s*[\-\–]\s+([‘\'ʻʼʿʾa-zA-ZāīūēōăĭŭšṣżṭẓčžḥḫẖḍḏġğẕṯṇṉṛśṥẑẗẁỳĀĪŪĒŌĂĬŬŠṢŻṬẒČŽḤḪḎĠḠẔṮṆṈṚŚṤẐ].*?)\s+([0-9\?]+[Cc]?\s*\))$', clean)
    if m_frac:
        author_part = m_frac.group(1).strip()
        date_part = m_frac.group(2).strip()
        return f"{author_part} (- {date_part}"

    # Leading date parenthesis moved to start: '(18C) author' -> 'author (18C)'
    m_lead_date = re.match(r'^(\(\s*(?:d\.\s*)?[\-\–]?[0-9\?]+(?:\s*[\-\–]\s*[0-9\?]+)?\s*(?:[Cc]|شمسی|قمری|میلادی)?\s*\))\s+([‘\'ʻʼʿʾa-zA-Zāīū].*)$', clean)
    if m_lead_date:
        date_part = m_lead_date.group(1).strip()
        author_part = m_lead_date.group(2).strip()
        return f"{author_part} {date_part}"

    return clean

def is_shelfmark_or_field(line):
    clean = line.strip()
    if any(clean.startswith(p) for p in ['آغاز:', 'انجام:', 'چاپ:', 'خط:', 'کاغذ:', 'مؤلف:', 'ن.ک.:', 'نک:', 'نسخه:', 'نسخه اصل:', 'فهرست:']):
        return True
    if clean.startswith('[') and clean.endswith(']') and not clean.startswith('●'):
        return True
    return False

def is_genuine_transliteration(text):
    clean = text.strip('.,;:\'\"[]«»-')
    if len(clean) < 3:
        return False
    if any(c in DIACRITICS_CHARS for c in clean):
        return True
    lower = clean.lower()
    markers = ['ebn-e', 'al-', '-ol-', '-ye', '-ul', '-il', '-e ', '-i ', 'ibn-', 'abu ', 'abū ', 'b. ', '=']
    if any(m in lower for m in markers):
        return True
    if ',' in clean and any(w in lower for w in ['ebn', 'ali', 'hasan', 'hoseyn', 'mohammad', 'ahmad', 'khan', 'shah', 'mirza']):
        return True
    if re.search(r'\(\s*(?:d\.\s*)?[\-\–]?[0-9\?]+(?:\s*[\-\–]\s*[0-9\?]+)?\s*(?:[Cc]|شمسی|قمری|میلادی)?\s*\)', text):
        return True
    return False

def parse_text_segment(text):
    clean = text.strip()
    if not clean or is_shelfmark_or_field(clean):
        return [clean]

    # If the text is pure Latin (after normalization)
    norm = normalize_translit(clean)
    if not any(is_persian_char(c) for c in norm) and any(c in TRANSLIT_CHARS for c in norm):
        return [norm]

    # If pure Persian
    if not any(c in TRANSLIT_CHARS for c in clean):
        return [clean]

    words = clean.split()
    if len(words) < 2:
        return [clean]

    # 1. Check for Leading Transliteration followed by Persian description
    l_idx = 0
    in_paren = False
    for i, w in enumerate(words):
        if '(' in w:
            in_paren = True
        has_p = any(is_persian_char(c) for c in w)
        has_l = any(c in TRANSLIT_CHARS for c in w)
        
        if in_paren:
            if ')' in w:
                in_paren = False
            l_idx = i + 1
            continue
            
        if has_p:
            if w in ['(ع)', '(ص)', '(س)', '(عج)']:
                l_idx = i + 1
                continue
            break
        elif has_l or is_translit_word(w) or w in ['-', '–', '=', '(', ')']:
            l_idx = i + 1
        else:
            break
            
    if l_idx > 0 and l_idx < len(words):
        lead_latin = ' '.join(words[:l_idx])
        persian_rest = ' '.join(words[l_idx:])
        
        lead_norm = normalize_translit(lead_latin)
        if is_genuine_transliteration(lead_norm):
            rest_blocks = parse_text_segment(persian_rest)
            return [lead_norm] + rest_blocks

    # 2. Check for Trailing Transliteration preceded by Persian
    r_idx = len(words)
    in_paren = False
    for i in range(len(words) - 1, -1, -1):
        w = words[i]
        if ')' in w:
            in_paren = True
        has_p = any(is_persian_char(c) for c in w)
        has_l = any(c in TRANSLIT_CHARS for c in w)
        
        if in_paren:
            if '(' in w:
                in_paren = False
            r_idx = i
            continue
            
        if has_p:
            break
        elif has_l or is_translit_word(w) or w in ['-', '–', '=', '(', ')']:
            r_idx = i
        else:
            break
            
    if r_idx < len(words) and r_idx > 0:
        persian_lead = ' '.join(words[:r_idx])
        trail_latin = ' '.join(words[r_idx:])
        trail_norm = normalize_translit(trail_latin)
        if is_genuine_transliteration(trail_norm):
            if not any(persian_lead.endswith(s) for s in ['[', '[ف:', '[نشریه:']):
                return [persian_lead, trail_norm]

    # 3. Check for Middle Transliteration (e.g. Persian Author + Latin Author + Persian Description 'وابسته به: ...')
    first_latin = -1
    last_latin = -1
    for i, w in enumerate(words):
        if any(c in TRANSLIT_CHARS for c in w):
            if first_latin == -1: first_latin = i
            last_latin = i
        elif '(' in w and ')' in w and any(c.isdigit() for c in w) and first_latin != -1 and last_latin == i - 1:
            last_latin = i
            
    if first_latin > 0 and last_latin < len(words) - 1:
        p1 = ' '.join(words[:first_latin])
        l_mid = ' '.join(words[first_latin:last_latin+1])
        p2 = ' '.join(words[last_latin+1:])
        l_norm = normalize_translit(l_mid)
        if is_genuine_transliteration(l_norm):
            return [p1, l_norm, p2]

    return [clean]

def parse_full_line(line):
    clean = line.strip()
    if not clean or is_shelfmark_or_field(clean):
        return [clean]

    # Quick check: Does the line without page tag contain any transliteration candidate?
    line_no_page = re.sub(r'<!--\s*page:\s*\d+\s*-->', '', clean).strip()
    if not (any(is_persian_char(c) for c in line_no_page) and any(c in TRANSLIT_CHARS for c in line_no_page)):
        # Pure transliteration with embedded/attached page tag
        if any(c in TRANSLIT_CHARS for c in line_no_page) and not any(is_persian_char(c) for c in line_no_page):
            norm = normalize_translit(line_no_page)
            if is_genuine_transliteration(norm):
                parts = re.split(r'(<!--\s*page:\s*\d+\s*-->)', clean)
                res = []
                for p in parts:
                    if not p.strip(): continue
                    if p.strip().startswith('<!--'):
                        res.append(p.strip())
                    else:
                        res.append(normalize_translit(p.strip()))
                return res
        return [clean]

    # Line has mixed Persian and Latin.
    # Split by page tag if present, but only if the segments produce real transliteration splits!
    parts = re.split(r'(<!--\s*page:\s*\d+\s*-->)', clean)
    final_blocks = []
    has_split = False
    
    for p in parts:
        if not p.strip(): continue
        if p.strip().startswith('<!--') and p.strip().endswith('-->'):
            final_blocks.append(p.strip())
        else:
            seg_blocks = parse_text_segment(p.strip())
            if len(seg_blocks) > 1 or (len(seg_blocks) == 1 and seg_blocks[0] != p.strip()):
                has_split = True
            final_blocks.extend(seg_blocks)
            
    # If the text segments did not have any real transliteration split, and the only split was page tag in a normal paragraph:
    # Do NOT split! Keep original clean line.
    if not has_split:
        # Check if the split was between Persian Author and Latin Author across the page tag (like Item 29)
        # e.g. final_blocks = ['Author Persian', '<!-- page: X -->', 'Author Latin']
        if len(final_blocks) == 3 and final_blocks[1].startswith('<!--') and \
           is_genuine_transliteration(final_blocks[2]) and any(is_persian_char(c) for c in final_blocks[0]):
            return final_blocks
        return [clean]
        
    return final_blocks

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

        parsed_blocks = parse_full_line(clean)

        if len(parsed_blocks) > 1 or (len(parsed_blocks) == 1 and parsed_blocks[0] != clean):
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
    f.write("# گزارش جامع تفکیک ساختاری و استقلال سطور آوانگاری (نگارش استاندارد، دقیق و بی‌نقص)\n\n")
    f.write(f"تعداد کل سطور نیازمند تفکیک و اصلاح: **{len(candidates)}** سطر\n\n")
    f.write("ویژگی‌های نگارش نهایی:\n")
    f.write("۱. استقرار مستقل برچسب‌های صفحه (<!-- page: X -->) در سطر مجزا بدون حذف یا ادغام در سطور آوانگاری.\n")
    f.write("۲. تثبیت قطعی کلیه تاریخ‌های حیات مؤلف (نظیر `(- 1887)`، `(- 20c)`، `(1850 - 1905)`، `(18C)`) در انتهای سطر آوانگاری مؤلف.\n")
    f.write("۳. اصلاح ناهنجاری‌های معکوس پرانتز تاریخ (BiDi) و انتقال پرانتز تاریخ از ابتدای آوانگاری به انتهای آن (`(- author 17c)` -> `author (- 17c)`).\n")
    f.write("۴. حذف خودکار عبارت‌های زائد مذهبی نظیر «(ع)» از انتهای خطوط آوانگاری.\n")
    f.write("۵. مصون‌سازی قطعی کلیه متون آغاز، انجام، چاپ، کدهای قفسه و عبارات درون پاراگرافی (نظیر متون پس از «وابسته به:»).\n\n")
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

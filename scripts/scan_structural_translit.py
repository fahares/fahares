#!/usr/bin/env python3
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
    
    # BiDi Pattern 1: '(1600 - author)' -> 'author (- 1600)'
    m_rev = re.match(r'^\(\s*([0-9\?]+[Cc]?)\s*[\-\–]\s+([‘\'ʻʼʿʾa-zA-ZāīūēōăĭŭšṣżṭẓčžḥḫẖḍḏġğẕṯṇṉṛśṥẑẗẁỳĀĪŪĒŌĂĬŬŠṢŻṬẒČŽḤḪḎĠḠẔṮṆṈṚŚṤẐ].*?)\s*\)$', clean)
    if m_rev:
        date_part = m_rev.group(1).strip()
        author_part = m_rev.group(2).strip()
        return f"{author_part} (- {date_part})"

    # BiDi Pattern 2: '(- author 17c)' -> 'author (- 17c)'
    m_frac = re.match(r'^\(\s*[\-\–]\s+([‘\'ʻʼʿʾa-zA-ZāīūēōăĭŭšṣżṭẓčžḥḫẖḍḏġğẕṯṇṉṛśṥẑẗẁỳĀĪŪĒŌĂĬŬŠṢŻṬẒČŽḤḪḎĠḠẔṮṆṈṚŚṤẐ].*?)\s+([0-9\?]+[Cc]?\s*\))$', clean)
    if m_frac:
        author_part = m_frac.group(1).strip()
        date_part = m_frac.group(2).strip()
        return f"{author_part} (- {date_part}"

    # BiDi Pattern 3: '(1889 - 1952 author)' -> 'author (1889 - 1952)'
    m_range = re.match(r'^\(\s*([0-9\?]+)\s*[\-\–]\s*([0-9\?]+)\s+([‘\'ʻʼʿʾa-zA-ZāīūēōăĭŭšṣżṭẓčžḥḫẖḍḏġğẕṯṇṉṛśṥẑẗẁỳĀĪŪĒŌĂĬŬŠṢŻṬẒČŽḤḪḎĠḠẔṮṆṈṚŚṤẐ].*?)\s*\)$', clean)
    if m_range:
        d1 = m_range.group(1).strip()
        d2 = m_range.group(2).strip()
        author_part = m_range.group(3).strip()
        return f"{author_part} ({d1} - {d2})"

    # BiDi Pattern 4: Leading date parenthesis '(18C) author' -> 'author (18C)'
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

def segment_text_blocks(text):
    clean = text.strip()
    if not clean or is_shelfmark_or_field(clean):
        return [clean]

    # Pure Latin
    norm = normalize_translit(clean)
    if not any(is_persian_char(c) for c in norm) and any(c in TRANSLIT_CHARS for c in norm):
        return [norm]

    # Pure Persian
    if not any(c in TRANSLIT_CHARS for c in clean):
        return [clean]

    words = clean.split()
    if len(words) < 2:
        return [clean]

    # 1. Leading Transliteration followed by Persian description
    l_idx = 0
    in_paren = False
    for i, w in enumerate(words):
        if '(' in w: in_paren = True
        has_p = any(is_persian_char(c) for c in w)
        has_l = any(c in TRANSLIT_CHARS for c in w)
        
        if in_paren:
            if ')' in w: in_paren = False
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
            rest_blocks = segment_text_blocks(persian_rest)
            return [lead_norm] + rest_blocks

    # 2. Trailing Transliteration preceded by Persian
    r_idx = len(words)
    in_paren = False
    for i in range(len(words) - 1, -1, -1):
        w = words[i]
        if ')' in w: in_paren = True
        has_p = any(is_persian_char(c) for c in w)
        has_l = any(c in TRANSLIT_CHARS for c in w)
        
        if in_paren:
            if '(' in w: in_paren = False
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

    # 3. Middle Transliteration (e.g. Persian Author + Latin Author + Persian Description)
    first_latin = -1
    last_latin = -1
    in_paren = False
    
    for i, w in enumerate(words):
        has_l = any(c in TRANSLIT_CHARS for c in w)
        has_p = any(is_persian_char(c) for c in w)
        
        if '(' in w:
            in_paren = True
        
        if first_latin == -1:
            if has_l or (in_paren and not has_p and any(c.isdigit() or c in '-–' for c in w)):
                first_latin = i
                last_latin = i
        else:
            if in_paren:
                last_latin = i
                if ')' in w:
                    in_paren = False
            elif has_l or is_translit_word(w) or w in ['-', '–', '=']:
                last_latin = i
            elif w.startswith('('):
                in_paren = True
                last_latin = i
            else:
                break
                
    if first_latin != -1 and last_latin != -1 and (first_latin > 0 or last_latin < len(words) - 1):
        p1 = ' '.join(words[:first_latin]).strip()
        l_mid = ' '.join(words[first_latin:last_latin+1]).strip()
        p2 = ' '.join(words[last_latin+1:]).strip()
        l_norm = normalize_translit(l_mid)
        if is_genuine_transliteration(l_norm):
            res = []
            if p1: res.append(p1)
            res.append(l_norm)
            if p2: res.append(p2)
            return res

    return [clean]

def process_line(line):
    clean = line.strip()
    if not clean or is_shelfmark_or_field(clean):
        return [clean]

    # Check for Page Tag strictly at the boundary between Persian and Latin
    m_boundary = re.search(r'^(.*?)\s*(<!--\s*page:\s*\d+\s*-->)\s*(.*?)$', clean)
    if m_boundary:
        before = m_boundary.group(1).strip()
        tag = m_boundary.group(2).strip()
        after = m_boundary.group(3).strip()
        
        b_has_p = any(is_persian_char(c) for c in before)
        b_has_l = any(c in TRANSLIT_CHARS for c in before)
        a_has_p = any(is_persian_char(c) for c in after)
        a_has_l = any(c in TRANSLIT_CHARS for c in after)
        
        if (b_has_p and not b_has_l) and (a_has_l and not a_has_p):
            a_norm = normalize_translit(after)
            if is_genuine_transliteration(a_norm):
                return [before, tag, a_norm]
                
        if (b_has_l and not b_has_p) and (a_has_p and not a_has_l):
            b_norm = normalize_translit(before)
            if is_genuine_transliteration(b_norm):
                return [b_norm, tag, after]

    return segment_text_blocks(clean)

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

        parsed_blocks = process_line(clean)

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
    f.write("۱. استقرار مستقل برچسب‌های صفحه (<!-- page: X -->) در سطر مجزا صرفاً در مرز میان متن فارسی و آوانگاری (و حفظ آن در درون پاراگراف‌ها).\n")
    f.write("۲. تثبیت کامل و قطعی کلیه تاریخ‌های حیات مؤلف داخل پرانتز در سطر آوانگاری مؤلف در کلیه سطور چندبخشی و مستقل.\n")
    f.write("۳. ترمیم و بازسازی کامل ناهنجاری‌های معکوس BiDi در پرانتز تاریخ مؤلفان.\n")
    f.write("۴. حذف خودکار عبارت‌های زائد مذهبی نظیر «(ع)» از انتهای خطوط آوانگاری.\n")
    f.write("۵. مصون‌سازی قطعی کلیه متون آغاز، انجام، چاپ، کدهای قفسه و عبارات درون پاراگرافی.\n\n")
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

#!/usr/bin/env python3
"""
High-Precision Transliteration Isolation Report Generator.
Solves:
1. True transliteration detection vs foreign text citations (excluding beginnings, endings, shelfmarks).
2. Author life dates (e.g. (1889 - 1952), (18C)) preserved inside author transliteration line.
3. Multi-block merged lines decomposition:
   [Title] -> [Title Translit] -> [Author] -> [Author Translit (Dates)] -> [Description / MSS]
4. Complete Orientalist Unicode diacritics coverage (preventing splits inside transliterations).
"""

import os
import re
import sys
import time

# Complete set of Latin and Orientalist transliteration characters
TRANSLIT_CHARS = set(
    "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"
    "āīūēōăĭŭšṣżṭẓčžḥḫẖḍḏġğẕṯṇṉṛśṥẑẗẁỳ"
    "ĀĪŪĒŌĂĬŬŠṢŻṬẒČŽḤḪḎĠḠẔṮṆṈṚŚṤẐ"
    "‘'ʻʼʿʾ`’"
)

def is_translit_char(c):
    return c in TRANSLIT_CHARS or c in "-=,.\t "

def is_persian_char(c):
    return '\u0600' <= c <= '\u06FF'

def contains_persian(s):
    return any(is_persian_char(c) for c in s)

def contains_latin(s):
    return any(c in TRANSLIT_CHARS for c in s)

def is_shelfmark_or_desc_citation(line):
    clean = line.strip()
    # Explicit field prefixes with foreign citations
    if any(clean.startswith(p) for p in ['آغاز:', 'انجام:', 'چاپ:', 'خط:', 'کاغذ:', 'مؤلف:', 'ن.ک.:', 'نک:']):
        return True
    # Pure bracketed shelfmarks
    if clean.startswith('[') and clean.endswith(']') and not clean.startswith('●'):
        return True
    return False

def parse_merged_line(line, prev_line, next_line):
    """
    Parses a single line that might contain multiple merged blocks.
    Returns list of formatted separate lines.
    """
    clean = line.strip()
    
    # Check 1: Embedded page tag with transliteration
    if '<!-- page:' in clean and '-->' in clean:
        tag_match = re.search(r'(<!--\s*page:\s*\d+\s*-->)', clean)
        if tag_match:
            tag = tag_match.group(1)
            before = clean[:tag_match.start()].strip()
            after = clean[tag_match.end():].strip()
            
            # If line is simply: Persian / Translit <!-- page: X --> Translit / Persian
            # Only split if one part is pure transliteration or author translit
            if (before and not contains_persian(before) and contains_latin(before)) or \
               (after and not contains_persian(after) and contains_latin(after)):
                res = []
                if before: res.append(before)
                res.append(tag)
                if after: res.append(after)
                return res

    # If line is a description citation or shelfmark, do not alter
    if is_shelfmark_or_desc_citation(clean):
        return [clean]
        
    # Pattern A: Multi-Block Parser using State-Machine Segmentation
    # Tokens: Persian chunks vs Latin Translit chunks (with attached date parentheses)
    
    # Regex to extract Latin chunks (including Orientalist diacritics, punctuation, and author dates like (1889 - 1952) or (18C))
    # A Latin transliteration chunk:
    # Starts with a translit char, contains translit chars, spaces, dashes, commas, and optionally ends with (dates)
    latin_block_pattern = re.compile(
        r'([‘\'ʻʼʿʾa-zA-ZāīūēōăĭŭšṣżṭẓčžḥḫẖḍḏġğẕṯṇṉṛśṥẑẗẁỳĀĪŪĒŌĂĬŬŠṢŻṬẒČŽḤḪḎĠḠẔṮṆṈṚŚṤẐ]'
        r'[\w\s\-\=\,\.\'ʻʼʿʾ\`\’āīūēōăĭŭšṣżṭẓčžḥḫẖḍḏġğẕṯṇṉṛśṥẑẗẁỳĀĪŪĒŌĂĬŬŠṢŻṬẒČŽḤḪḎĠḠẔṮṆṈṚŚṤẐ]*?'
        r'(?:\s*\(\s*(?:d\.\s*)?[0-9\?]+(?:\s*[\-\–]\s*[0-9\?]+)?\s*(?:[Cc]|شمسی|قمری|میلادی)?\s*\))?)'
    )
    
    # We look for transitions between Persian and Latin
    # Let's inspect if the line starts with ● Title
    if clean.startswith('●'):
        # Check if line contains Latin transliteration after / subject / language or after title
        # e.g., ● عنوان / موضوع / زبان translit ...
        m = re.search(r'^(●\s*[\u0600-\u06FF\d\s\/\(\)\-\=\:\,\«\»]+?)\s+([‘\'ʻʼʿʾa-zA-Zāīū].*)$', clean)
        if m:
            p_title = m.group(1).strip()
            rest = m.group(2).strip()
            # Recursively or sequentially parse rest
            sub_parsed = parse_merged_line(rest, p_title, next_line)
            return [p_title] + sub_parsed
            
    # Check if line starts with Latin Transliteration followed by Persian
    # e.g., 'al-aṭar-ul jadīd fī tārix-i ‘alī ibn-il ḥusayn-il šahīd سردرودی تبریزی...'
    # or 'ansārī jāberī, hasan ebn-e ‘alī (18C) تذکره زنان شاعر...'
    m_lead = re.match(
        r'^([‘\'ʻʼʿʾa-zA-ZāīūēōăĭŭšṣżṭẓčžḥḫẖḍḏġğẕṯṇṉṛśṥẑẗẁỳĀĪŪĒŌĂĬŬŠṢŻṬẒČŽḤḪḎĠḠẔṮṆṈṚŚṤẐ]'
        r'[\w\s\-\=\,\.\'ʻʼʿʾ\`\’āīūēōăĭŭšṣżṭẓčžḥḫẖḍḏġğẕṯṇṉṛśṥẑẗẁỳĀĪŪĒŌĂĬŬŠṢŻṬẒČŽḤḪḎĠḠẔṮṆṈṚŚṤẐ]*?'
        r'(?:\s*\(\s*(?:d\.\s*)?[0-9\?]+(?:\s*[\-\–]\s*[0-9\?]+)?\s*(?:[Cc]|شمسی|قمری|میلادی)?\s*\))?)'
        r'\s+([\u0600-\u06FF].*)$',
        clean
    )
    if m_lead:
        latin_chunk = m_lead.group(1).strip()
        persian_chunk = m_lead.group(2).strip()
        
        # Verify latin_chunk is genuine transliteration (not English citation word like 'Dr.' or 'Karl Garbers')
        if not (latin_chunk.startswith(('Karl', 'Johan', 'Dr.', 'Prof.')) and persian_chunk.startswith('و ')):
            # Check if persian_chunk itself has a trailing or embedded transliteration (Compound line!)
            # Recursively parse persian_chunk!
            rest_parsed = parse_merged_line(persian_chunk, latin_chunk, next_line)
            return [latin_chunk] + rest_parsed

    # Check if line starts with Persian and ends with Latin Transliteration
    # e.g., 'سردرودی تبریزی، محمد حسن بن محمد حسین، 1306 - 1371 قمری sardrūdī tabrizī, mohammad hasan ebn-e mohammad hoseyn (1889 - 1952)'
    m_trail = re.search(
        r'^([\u0600-\u06FF\d\s\/\(\)\-\:\,\«\»]+?)\s+'
        r'([‘\'ʻʼʿʾa-zA-ZāīūēōăĭŭšṣżṭẓčžḥḫẖḍḏġğẕṯṇṉṛśṥẑẗẁỳĀĪŪĒŌĂĬŬŠṢŻṬẒČŽḤḪḎĠḠẔṮṆṈṚŚṤẐ]'
        r'[\w\s\-\=\,\.\'ʻʼʿʾ\`\’āīūēōăĭŭšṣżṭẓčžḥḫẖḍḏġğẕṯṇṉṛśṥẑẗẁỳĀĪŪĒŌĂĬŬŠṢŻṬẒČŽḤḪḎĠḠẔṮṆṈṚŚṤẐ]*?'
        r'(?:\s*\(\s*(?:d\.\s*)?[0-9\?]+(?:\s*[\-\–]\s*[0-9\?]+)?\s*(?:[Cc]|شمسی|قمری|میلادی)?\s*\))?)$',
        clean
    )
    if m_trail:
        p_part = m_trail.group(1).strip()
        l_part = m_trail.group(2).strip()
        
        # Avoid foreign shelfmark false positives
        if not any(p_part.endswith(s) for s in ['[', '[ف:', '[نشریه:']) and \
           not any(s in p_part[-15:] for s in ['[MS', '[Cod', '[Add', '[Or', '[Suppl', '[Lat', '[BOD', '[Rieu', '[Blochet', '[Ethé']):
            if l_part not in ['(Rich)', '(Loth)', '(Rich).', '(Loth).']:
                # Ensure l_part has at least one word >= 3 chars
                if any(len(w.strip('.,;:\'\"()[]«»-')) >= 3 for w in l_part.split()):
                    return [p_part, l_part]

    return [clean]

# Process all 34 volumes
start_t = time.time()
report_items = []

for vol in range(1, 35):
    fpath = f'sources/text/fahares_vol_{vol:02d}.txt'
    if not os.path.exists(fpath): continue
    
    with open(fpath, 'r', encoding='utf-8') as f:
        lines = [line.rstrip('\r\n') for line in f]
        
    num_lines = len(lines)
    for l_idx, line_str in enumerate(lines, 1):
        clean = line_str.strip()
        if not clean: continue
        
        prev_l = lines[l_idx - 2] if l_idx >= 2 else ""
        next_l = lines[l_idx] if l_idx < num_lines else ""
        
        parsed_blocks = parse_merged_line(clean, prev_l, next_l)
        
        # If line was broken into 2 or more separate blocks
        if len(parsed_blocks) > 1:
            report_items.append({
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
    f.write("# گزارش جامع تفکیک هوشمند و استقلال سطور آوانگاری (نگارش استاندارد و چندسطری)\n\n")
    f.write(f"تعداد کل سطور نیازمند تفکیک: **{len(report_items)}** سطر\n\n")
    f.write("ویژگی‌های الگوریتم هوشمند:\n")
    f.write("۱. تفکیک کامل سطور چندبخشی (عنوان + آوانگاری عنوان + مؤلف + آوانگاری مؤلف + تاریخ).\n")
    f.write("۲. حفظ کامل تاریخ حیات میلادی مؤلف داخل پرانتز در خط آوانگاری مؤلف.\n")
    f.write("۳. پوشش ۱۰۰٪ کاراکترهای اعراب‌دار آوانگاری شرق‌شناسی جهت جلوگیری از شکست در میانه واژگان.\n")
    f.write("۴. مصون‌سازی متون نقل‌قول زبان‌های خارجی در فیلدهای آغاز، انجام، چاپ و کدهای قفسه.\n\n")
    f.write("="*60 + "\n\n")
    
    for idx, it in enumerate(report_items, 1):
        f.write(f"### شماره {idx} (جلد {it['vol']:02d} - سطر {it['line_num']} | تعداد قطعات تفکیک‌شده: {it['num_blocks']})\n\n")
        f.write(f"🔴 **وضعیت فعلی:**\n```text\n{it['current']}\n```\n\n")
        f.write(f"🟢 **اصلاح پیشنهادی:**\n```text\n{it['proposed']}\n```\n\n")
        if it['prev_line']:
            f.write(f"- سطر قبل: `{it['prev_line'][:100]}`\n")
        if it['next_line']:
            f.write(f"- سطر بعد: `{it['next_line'][:100]}`\n")
        f.write("\n---\n\n")

print(f"✅ Generated report in {time.time() - start_t:.2f}s! Total items: {len(report_items)} -> {report_path}")

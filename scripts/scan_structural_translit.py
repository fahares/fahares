import os
import sys
import time
import re

start_t = time.time()

LATIN_LETTERS = set(
    "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"
    "āīūēōăĭŭšṣżṭẓčžḥḫẖḍḏġğẕṯṇṉṛśṥẑẗẁỳ"
    "ĀĪŪĒŌĂĬŬŠṢŻṬẒČŽḤḪḎĠḠẔṮṆṈṚŚṤẐ"
)

TRANSLIT_CHARS = LATIN_LETTERS.union(set(["‘", "'", "ʻ", "ʼ", "ʿ", "ʾ", "`", "’"]))

DIACRITICS_CHARS = set(
    "āīūēōăĭŭšṣżṭẓčžḥḫẖḍḏġğẕṯṇṉṛśṥẑẗẁỳ"
    "ĀĪŪĒŌĂĬŬŠṢŻṬẒČŽḤḪḎĠḠẔṮṆṈṚŚṤẐ"
    "‘ʻʼʿʾ`’"
)

SHELFMARK_PATTERNS = [
    r'\b(?:Or|Add|MS|Land Or|Ma VI|B\d+|Cod|Suppl|Cat|Ahlwardt|Sprenger|Rieu|Blochet|Bodleian|Huntington|Ethé|Flügel|Ivanow|Pertsch|Rypka|Sachau|Tornberg|Vollers|Voorhoeve)\b',
    r'\b(?:Or\.|Add\.|MS\.|Ms\.|Cod\.|Suppl\.|Cat\.)\b',
    r'^\s*Land\s+Or\b'
]

def is_persian_char(c):
    return '\u0600' <= c <= '\u06FF'

def is_shelfmark_or_non_entry_line(line):
    clean = line.strip()
    if not clean:
        return True
    if any(clean.startswith(p) for p in [
        'آغاز:', 'انجام:', 'چاپ:', 'خط:', 'کاغذ:', 'مؤلف:', 'ن.ک.:', 'نک:', 
        'نسخه:', 'نسخه اصل:', 'فهرست:', 'وابسته به:', 'مأخذ:', 'موضوع:', 'زبان:',
        'تاریخ تألیف:', 'تاریخ اجازه:', 'محل صدور:', 'شماره نسخه:'
    ]):
        return True
    if re.match(r'^\s*\d+[\.\-\)]', clean):
        return True
    if clean.startswith('[') and clean.endswith(']') and not clean.startswith('●'):
        return True
    for p in SHELFMARK_PATTERNS:
        if re.search(p, clean, re.IGNORECASE) and any(kw in clean for kw in ['خط:', 'کاغذ:', 'نسخه', 'شماره', 'کا:', 'تا:', 'سم', 'ص', 'برگ', 'گ']):
            return True
    return False

def is_persian_author_line(line):
    clean = line.strip()
    if not clean or len(clean) > 160 or not is_persian_char(clean[0]):
        return False
    if clean.startswith('●') or clean.startswith('[') or clean.startswith('('):
        return False
    if any(clean.startswith(p) for p in ['آغاز:', 'انجام:', 'چاپ:', 'خط:', 'کاغذ:', 'مؤلف:', 'ن.ک.:', 'نک:', 'نسخه:', 'نسخه اصل:', 'فهرست:', 'وابسته به:', 'مأخذ:', 'موضوع:', 'زبان:']):
        return False
    if any(w in clean for w in ['است', 'شد', 'نموده', 'دارد', 'رسانده', 'آورده', 'نوشته', 'گردیده', 'به کوشش', 'تحقیق', 'تعلیق', 'تصحیح', 'مؤلف در', 'این کتاب', 'نسخه', 'دار صادر', 'ص ', 'نشر ']):
        return False
    if '،' in clean and any(k in clean for k in [' بن ', ' ابن ', ' ق ', ' قرن ', ' قمری', ' شمسی', ' 1', ' 2', ' 3', ' 4', ' 5', ' 6', ' 7', ' 8', ' 9', ' - ', '-']):
        return True
    return False

def is_genuine_transliteration(text):
    without_tags = re.sub(r'__PAGE_TAG_\d+__', '', text)
    without_tags = re.sub(r'<!--\s*page:\s*\d+\s*-->', '', without_tags).strip('.,;:\'\"[]«»- ')
    
    if not any(c in LATIN_LETTERS for c in without_tags):
        return False
    
    latin_count = sum(1 for c in without_tags if c in LATIN_LETTERS)
    if latin_count < 2:
        return False

    for p in SHELFMARK_PATTERNS:
        if re.match(p, without_tags, re.IGNORECASE):
            return False

    if any(c in DIACRITICS_CHARS for c in without_tags):
        return True
        
    lower = without_tags.lower()
    markers = [
        'ebn-e', 'al-', '-ol-', '-or-', '-oz-', '-od-', '-ot-', '-on-', '-os-', '-oš-', '-ye', 
        '-ul', '-il', '-e ', '-i ', 'ibn-', 'abu ', 'abū ', 'b. ', '=', 'va-l-', 'wa-l-'
    ]
    if any(m in lower for m in markers):
        return True
    if ',' in without_tags and any(w in lower for w in ['ebn', 'ali', 'hasan', 'hoseyn', 'mohammad', 'ahmad', 'khan', 'shah', 'mirza']):
        return True
    if re.search(r'\(\s*(?:d\.\s*)?[\-\–]?[0-9\?]+(?:\s*[\-\–]\s*[0-9\?]+)?\s*(?:[Cc]|شمسی|قمری|میلادی)?\s*\)', without_tags):
        return bool(re.search(r'[a-zA-Zāīū].*?\(\s*(?:d\.\s*)?[\-\–]?[0-9\?]+', without_tags))
    
    words = [w for w in without_tags.split() if any(c in LATIN_LETTERS for c in w)]
    if len(words) >= 2:
        return True
        
    return False

def normalize_translit(text):
    clean = text.strip()
    clean = re.sub(r'\s*\([عصس]\)\s*$', '', clean)
    clean = re.sub(r'\s*\(عج\)\s*$', '', clean)
    
    m_rev = re.match(r'^\(\s*([0-9\?]+[Cc]?)\s*[\-\–]\s+([‘\'ʻʼʿʾa-zA-ZāīūēōăĭŭšṣżṭẓčžḥḫẖḍḏġğẕṯṇṉṛśṥẑẗẁỳĀĪŪĒŌĂĬŬŠṢŻṬẒČŽḤḪḎĠḠẔṮṆṈṚŚṤẐ].*?)\s*\)$', clean)
    if m_rev:
        date_part = m_rev.group(1).strip()
        author_part = m_rev.group(2).strip()
        return f"{author_part} (- {date_part})"

    m_frac = re.match(r'^\(\s*[\-\–]\s+([‘\'ʻʼʿʾa-zA-ZāīūēōăĭŭšṣżṭẓčžḥḫẖḍḏġğẕṯṇṉṛśṥẑẗẁỳĀĪŪĒŌĂĬŬŠṢŻṬẒČŽḤḪḎĠḠẔṮṆṈṚŚṤẐ].*?)\s+([0-9\?]+[Cc]?\s*\))$', clean)
    if m_frac:
        author_part = m_frac.group(1).strip()
        date_part = m_frac.group(2).strip()
        return f"{author_part} (- {date_part}"

    m_range = re.match(r'^\(\s*([0-9\?]+)\s*[\-\–]\s*([0-9\?]+)\s+([‘\'ʻʼʿʾa-zA-ZāīūēōăĭŭšṣżṭẓčžḥḫẖḍḏġğẕṯṇṉṛśṥẑẗẁỳĀĪŪĒŌĂĬŬŠṢŻṬẒČŽḤḪḎĠḠẔṮṆṈṚŚṤẐ].*?)\s*\)$', clean)
    if m_range:
        d1 = m_range.group(1).strip()
        d2 = m_range.group(2).strip()
        author_part = m_range.group(3).strip()
        return f"{author_part} ({d1} - {d2})"

    m_lead_date = re.match(r'^(\(\s*(?:d\.\s*)?[0-9\?]+(?:\s*[\-\–]\s*[0-9\?]+)?\s*(?:[Cc]|شمسی|قمری|میلادی)?\s*\))\s+([‘\'ʻʼʿʾa-zA-Zāīū].*)$', clean)
    if m_lead_date:
        date_part = m_lead_date.group(1).strip()
        author_part = m_lead_date.group(2).strip()
        return f"{author_part} {date_part}"

    return clean

def segment_text_blocks_core(text):
    clean = text.strip()
    if not clean:
        return [clean]

    without_tags = re.sub(r'__PAGE_TAG_\d+__', '', clean).strip()
    if not any(c in LATIN_LETTERS for c in without_tags):
        return [clean]

    norm = normalize_translit(clean)
    if not any(is_persian_char(c) for c in norm) and any(c in LATIN_LETTERS for c in norm):
        return [norm]

    if not any(c in LATIN_LETTERS for c in without_tags):
        return [clean]

    words = clean.split()
    if len(words) < 2:
        return [clean]

    l_idx = 0
    in_paren = False
    for i, w in enumerate(words):
        if w.startswith('__PAGE_TAG_'):
            break
        if '(' in w: in_paren = True
        has_p = any(is_persian_char(c) for c in w)
        has_l = any(c in LATIN_LETTERS for c in w)
        
        if in_paren:
            if ')' in w: in_paren = False
            l_idx = i + 1
            continue
            
        if has_p:
            if w in ['(ع)', '(ص)', '(س)', '(عج)']:
                l_idx = i + 1
                continue
            break
        elif has_l or w in ['-', '–', '=', '(', ')']:
            l_idx = i + 1
        else:
            break
            
    if l_idx > 0 and l_idx < len(words):
        lead_latin = ' '.join(words[:l_idx])
        persian_rest = ' '.join(words[l_idx:])
        lead_norm = normalize_translit(lead_latin)
        if is_genuine_transliteration(lead_norm):
            rest_blocks = segment_text_blocks_core(persian_rest)
            return [lead_norm] + rest_blocks

    r_idx = len(words)
    in_paren = False
    for i in range(len(words) - 1, -1, -1):
        w = words[i]
        if w.startswith('__PAGE_TAG_'):
            break
        if ')' in w: in_paren = True
        has_p = any(is_persian_char(c) for c in w)
        has_l = any(c in LATIN_LETTERS for c in w)
        
        if in_paren:
            if '(' in w: in_paren = False
            r_idx = i
            continue
            
        if has_p:
            break
        elif has_l or w in ['-', '–', '=', '(', ')']:
            r_idx = i
        else:
            break
            
    if r_idx < len(words) and r_idx > 0:
        persian_lead = ' '.join(words[:r_idx])
        trail_latin = ' '.join(words[r_idx:])
        trail_norm = normalize_translit(trail_latin)
        if is_genuine_transliteration(trail_norm):
            if not any(persian_lead.endswith(s) for s in ['[', '[ف:', '[نشریه:', 'آغاز:', 'انجام:', 'نسخه:']):
                return [persian_lead, trail_norm]

    first_latin = -1
    last_latin = -1
    in_paren = False
    
    for i, w in enumerate(words):
        if w.startswith('__PAGE_TAG_'):
            if first_latin != -1 and last_latin != -1:
                break
            continue
            
        has_l = any(c in LATIN_LETTERS for c in w)
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
            elif has_l or w in ['-', '–', '=']:
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
        if is_genuine_transliteration(l_norm) and not p1.endswith('آغاز:') and not p1.endswith('انجام:'):
            res = []
            if p1: res.append(p1)
            res.append(l_norm)
            if p2: res.append(p2)
            return res

    return [clean]

def is_valid_translit_context(clean, prev_line):
    if clean.startswith('●') or prev_line.strip().startswith('●'):
        return True
    if is_persian_author_line(clean) or is_persian_author_line(prev_line):
        return True
    words = clean.split()
    if words:
        first_word_clean = words[0].strip('.,;:\'\"()[]«»-')
        if any(c in LATIN_LETTERS for c in first_word_clean):
            lead_chunk = ' '.join(words[:6])
            if ',' in lead_chunk and any(c in DIACRITICS_CHARS for c in lead_chunk):
                return True
            if any(m in lead_chunk.lower() for m in ['ebn-e', 'al-', '-ol-', '-or-', '-oz-', '-od-', '-ot-', '-on-', '-os-', '-oš-', '-ye', '-ul', '-il', 'ibn-', 'abū ']):
                if any(k in clean for k in ['●', '،', ' بن ', ' ابن ']):
                    return True
    return False

def process_line(line, prev_line=""):
    clean = line.strip()
    if not clean or is_shelfmark_or_non_entry_line(clean):
        return [clean]

    if not is_valid_translit_context(clean, prev_line):
        return [clean]

    tag_map = {}
    def tag_repl(m):
        tok = f"__PAGE_TAG_{len(tag_map)}__"
        tag_map[tok] = m.group(0)
        return f" {tok} "

    masked = re.sub(r'<!--\s*page:\s*\d+\s*-->', tag_repl, clean)
    masked = ' '.join(masked.split())

    without_tags = re.sub(r'__PAGE_TAG_\d+__', '', masked).strip()
    if not any(c in LATIN_LETTERS for c in without_tags):
        return [clean]

    m_boundary = re.search(r'^(.*?)\s*(__PAGE_TAG_\d+__)\s*(.*?)$', masked)
    if m_boundary:
        before = m_boundary.group(1).strip()
        tag_tok = m_boundary.group(2).strip()
        after = m_boundary.group(3).strip()
        
        b_has_p = any(is_persian_char(c) for c in before)
        b_has_l = any(c in LATIN_LETTERS for c in before)
        a_has_p = any(is_persian_char(c) for c in after)
        a_has_l = any(c in LATIN_LETTERS for c in after)
        
        if (b_has_p and not b_has_l) and (a_has_l and not a_has_p):
            a_norm = normalize_translit(after)
            if is_genuine_transliteration(a_norm):
                return [tag_map.get(before, before), tag_map[tag_tok], tag_map.get(a_norm, a_norm)]
                
        if (b_has_l and not b_has_p) and (a_has_p and not a_has_l):
            b_norm = normalize_translit(before)
            if is_genuine_transliteration(b_norm):
                return [tag_map.get(b_norm, b_norm), tag_map[tag_tok], tag_map.get(after, after)]

    blocks = segment_text_blocks_core(masked)
    
    restored = []
    for b in blocks:
        for tok, original_tag in tag_map.items():
            b = b.replace(tok, original_tag)
        b = ' '.join(b.split())
        restored.append(b)
        
    return restored

def format_proposed_blocks(blocks, prev_line=""):
    if not blocks:
        return ""
    if len(blocks) == 1:
        return blocks[0]
        
    formatted = []
    i = 0
    while i < len(blocks):
        b = blocks[i]
        
        if i + 1 < len(blocks):
            next_b = blocks[i+1]
            
            if i + 2 < len(blocks) and next_b.startswith('<!--') and next_b.endswith('-->') and is_genuine_transliteration(blocks[i+2]):
                formatted.append(f"{b}\n{next_b}\n{blocks[i+2]}")
                i += 3
                continue
                
            if is_genuine_transliteration(next_b) and (b.startswith('●') or any(is_persian_char(c) for c in b)):
                formatted.append(f"{b}\n{next_b}")
                i += 2
                continue

        formatted.append(b)
        i += 1
        
    return "\n\n".join(formatted)

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

        parsed_blocks = process_line(clean, prev_l)

        if len(parsed_blocks) > 1 or (len(parsed_blocks) == 1 and parsed_blocks[0] != clean):
            prop_str = format_proposed_blocks(parsed_blocks, prev_l)
            candidates.append({
                'vol': vol,
                'line_num': l_idx,
                'num_blocks': len(parsed_blocks),
                'current': clean,
                'proposed': prop_str,
                'prev_line': prev_l,
                'next_line': next_l
            })

report_path = 'reports/candidate_isolated_transliterations.md'
with open(report_path, 'w', encoding='utf-8') as f:
    f.write("# گزارش جامع تفکیک ساختاری و استقلال سطور آوانگاری (نگارش استاندارد، دقیق و بی‌نقص)\n\n")
    f.write(f"تعداد کل سطور نیازمند تفکیک و اصلاح: **{len(candidates)}** سطر\n\n")
    f.write("ویژگی‌های نگارش نهایی:\n")
    f.write("۱. انحصار قطعی تفکیک به سطور عنوان و مؤلف و مصونیت کامل متن آغاز/انجام، نسخه‌های خطی و توضیحات.\n")
    f.write("۲. اتصال مستقیم عنوان/مؤلف با آوانگاری مربوطه بدون سطر خالی اضافی.\n")
    f.write("۳. استقرار ۳ سطری متوالی (عنوان + شماره صفحه + آوانگاری) بدون سطر خالی در صورت قرارگیری برچسب صفحه در میان آن‌ها.\n")
    f.write("۴. تثبیت کامل و قطعی کلیه تاریخ‌های حیات مؤلف داخل پرانتز در سطر آوانگاری مؤلف.\n")
    f.write("۵. ترمیم و بازسازی کامل ناهنجاری‌های معکوس BiDi در پرانتز تاریخ مؤلفان.\n")
    f.write("۶. حذف خودکار عبارت‌های زائد مذهبی نظیر «(ع)» از انتهای خطوط آوانگاری.\n\n")
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

print(f"Finished in {time.time() - start_t:.2f}s! Total items: {len(candidates)} -> {report_path}")

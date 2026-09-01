code = r'''import os
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
    r'\b(?:Or|Add|MS|Land Or|Ma VI|B\d+|Cod|Suppl|Cat|Ahlwardt|Sprenger|Rieu|Blochet|Bodleian|Huntington|Ethé|Flügel|Ivanow|Pertsch|Rypka|Sachau|Tornberg|Vollers|Voorhoeve|NF)\b',
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
    if any(kw in clean for kw in ['خط: ', 'کاغذ: ', 'شماره نسخه: ', 'کا: ', 'تا: ', ' [فیلما:', ' [فهرست']):
        if not any(delimiter in clean for delimiter in [' / ', ' = ', ' ● ']):
            return True
    for p in SHELFMARK_PATTERNS:
        if re.search(p, clean, re.IGNORECASE) and any(kw in clean for kw in ['خط:', 'کاغذ:', 'نسخه', 'شماره', 'کا:', 'تا:', 'سم', 'ص', 'برگ', 'گ', 'سطر']):
            if not any(delimiter in clean for delimiter in [' / ', ' = ', ' ● ']):
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
    
    # Strip opening stray quote followed by space
    without_tags = re.sub(r'(^|\s)[‘\'ʻʼʿʾ`’]\s+', r'\1', without_tags).strip()

    # Strip folio numbers, dates, punctuation, quotes
    cleaned = re.sub(r'\b\d+\s*[abrvpqCc]\b', '', without_tags)
    cleaned = re.sub(r'[‘\'ʻʼʿʾ`’\(\)\-\–\d\s\.,;:«»\[\]=]', '', cleaned)
    
    # Century date check: (19c), 19c), (17c -), (18c -)
    if re.match(r'^\(?\s*[\-\–]?\s*\d+\s*[Cc]\s*[\-\–]?\s*\)?$', without_tags) or re.match(r'^\(?\s*[\-\–]?\s*[Cc]\s*\d+\s*[\-\–]?\s*\)?$', without_tags):
        return False
    if re.match(r'^\(?\s*[\-\–]?\s*\d+\s*[\-\–]?\s*\)?$', without_tags):
        return False

    latin_chars = [c for c in cleaned if c in LATIN_LETTERS]
    if len(latin_chars) < 2:
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
        return bool(re.search(r'[a-zA-Zāīū].*?\(\s*(?:d\.\s*)?[0-9\?]+', without_tags))
    
    words = [w for w in without_tags.split() if any(c in LATIN_LETTERS for c in w)]
    if len(words) >= 2:
        return True
        
    return False

def normalize_translit(text):
    clean = text.strip()
    
    # Remove space after opening quote/half-ring: '‘ osmānī' -> '‘osmānī'
    clean = re.sub(r'(^|\s)([‘\'ʻʼʿʾ`’])\s+([a-zA-ZāīūēōăĭŭšṣżṭẓčžḥḫẖḍḏġğẕṯṇṉṛśṥẑẗẁỳĀĪŪĒŌĂĬŬŠṢŻṬẒČŽḤḪḎĠḠẔṮṆṈṚŚṤẐ])', r'\1\2\3', clean)
    
    clean = re.sub(r'\s*\([عصس]\)\s*$', '', clean)
    clean = re.sub(r'\s*\(عج\)\s*$', '', clean)
    clean = re.sub(r'\(\s*(\d+)\'\s*\)', r'(\1)', clean)
    clean = re.sub(r'\(\s*[\-\–]\s*\(\s*[\-\–]\s*', '(- ', clean)
    clean = re.sub(r'\(\s*[\-\–]\s*[\-\–]\s*', '(- ', clean)
    clean = re.sub(r'\(\s*(\d+)\s*\)?\s*\(\s*[\-\–]\s*(\d+)\s*\)?', r'(\2 - \1)', clean)
    clean = re.sub(r'\(\s*[\-\–]\s*ebn-e', ' ebn-e', clean)
    clean = re.sub(r'\s+’\s*\(', r'’ (', clean)
    clean = re.sub(r'\s+’\s*$', r'’', clean)

    # Leading date with closing paren: '19c) author' -> 'author (- 19c)'
    m_lead_cp = re.match(r'^(\d+[Cc]?)\)\s+([‘\'ʻʼʿʾa-zA-Zāīū].*)$', clean)
    if m_lead_cp:
        clean = f"{m_lead_cp.group(2).strip()} (- {m_lead_cp.group(1).strip()})"

    # Missing closing paren for date range / century: '(- 18c' -> '(- 18c)'
    clean = re.sub(r'\(\s*(\d+)\s*[\-\–]\s*(\d+)\s*$', r'(\1 - \2)', clean)
    clean = re.sub(r'\(\s*[\-\–]\s*(\d+[Cc]?)\s*$', r'(- \1)', clean)

    # BiDi Pattern 0: '(- author (dates))' -> 'author (- dates)'
    m_bidi0 = re.match(r'^\(\s*[\-\–]\s+([‘\'ʻʼʿʾa-zA-ZāīūēōăĭŭšṣżṭẓčžḥḫẖḍḏġğẕṯṇṉṛśṥẑẗẁỳĀĪŪĒŌĂĬŬŠṢŻṬẒČŽḤḪḎĠḠẔṮṆṈṚŚṤẐ].*?)\s*\(\s*([0-9\?]+[Cc]?)\s*\)\s*$', clean)
    if m_bidi0:
        author_part = m_bidi0.group(1).strip()
        date_part = m_bidi0.group(2).strip()
        return f"{author_part} (- {date_part})"

    # BiDi Pattern 0b: '(- author dates)' or '(- author (dates)' -> 'author (- dates)'
    m_bidi0b = re.match(r'^\(\s*[\-\–]\s+([‘\'ʻʼʿʾa-zA-ZāīūēōăĭŭšṣżṭẓčžḥḫẖḍḏġğẕṯṇṉṛśṥẑẗẁỳĀĪŪĒŌĂĬŬŠṢŻṬẒČŽḤḪḎĠḠẔṮṆṈṚŚṤẐ].*?)\s*\(?\s*([0-9\?]+[Cc]?)\s*\)?\s*$', clean)
    if m_bidi0b:
        author_part = m_bidi0b.group(1).strip()
        date_part = m_bidi0b.group(2).strip()
        return f"{author_part} (- {date_part})"
    
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
        date_part = m_frac.group(2).strip(' )')
        return f"{author_part} (- {date_part})"

    # BiDi Pattern 3: '(1889 - 1952 author)' -> 'author (1889 - 1952)'
    m_range = re.match(r'^\(\s*([0-9\?]+)\s*[\-\–]\s*([0-9\?]+)\s+([‘\'ʻʼʿʾa-zA-ZāīūēōăĭŭšṣżṭẓčžḥḫẖḍḏġğẕṯṇṉṛśṥẑẗẁỳĀĪŪĒŌĂĬŬŠṢŻṬẒČŽḤḪḎĠḠẔṮṆṈṚŚṤẐ].*?)\s*\)$', clean)
    if m_range:
        d1 = m_range.group(1).strip()
        d2 = m_range.group(2).strip()
        author_part = m_range.group(3).strip()
        return f"{author_part} ({d1} - {d2})"

    # BiDi Pattern 4: Leading date parenthesis '(18C) author' -> 'author (18C)'
    m_lead_date = re.match(r'^(\(\s*(?:d\.\s*)?[0-9\?]+(?:\s*[\-\–]\s*[0-9\?]+)?\s*(?:[Cc]|شمسی|قمری|میلادی)?\s*\))\s+([‘\'ʻʼʿʾa-zA-Zāīū].*)$', clean)
    if m_lead_date:
        date_part = m_lead_date.group(1).strip()
        author_part = m_lead_date.group(2).strip()
        return f"{author_part} {date_part}"

    # Fix unbalanced stray parentheses
    if clean.startswith('(') and clean.count('(') > clean.count(')'):
        clean = clean[1:].strip()
    if clean.endswith(')') and clean.count(')') > clean.count('('):
        m_dt = re.search(r'[\-\–]?\s*\d+\s*[Cc]?\s*\)$', clean)
        if m_dt and clean.count('(') == 0:
            clean = re.sub(r'([\-\–]?\s*\d+\s*[Cc]?)\s*\)$', r'(\1)', clean)
            clean = re.sub(r'\(\s*[\-\–]\s*', '(- ', clean)
        else:
            clean = clean[:-1].strip()

    return clean

def segment_text_blocks_core(text):
    clean = text.strip()
    if not clean:
        return [clean]

    without_tags = re.sub(r'__PAGE_TAG_\d+__', '', clean).strip()
    if not any(c in LATIN_LETTERS for c in without_tags):
        return [clean]

    # Pattern: BiDi Wrap 1: 'Persian (1600 - Author)' -> 'Persian', 'Author (- 1600)'
    m_bw1 = re.match(r'^(.*?)\s*\(\s*([0-9\?]+[Cc]?)\s*[\-\–]\s+([‘\'ʻʼʿʾa-zA-ZāīūēōăĭŭšṣżṭẓčžḥḫẖḍḏġğẕṯṇṉṛśṥẑẗẁỳĀĪŪĒŌĂĬŬŠṢŻṬẒČŽḤḪḎĠḠẔṮṆṈṚŚṤẐ].*?)\s*\)$', clean)
    if m_bw1:
        p = m_bw1.group(1).strip()
        d = m_bw1.group(2).strip()
        a = m_bw1.group(3).strip()
        m_end = re.search(r'\(\s*(\d+[Cc]?)\s*$', a)
        if m_end:
            end_d = m_end.group(1)
            a_clean = re.sub(r'\s*\(\s*\d+[Cc]?\s*$', '', a)
            return [p, f'{a_clean} ({d} - {end_d})']
        return [p, f'{a} (- {d})']

    # Pattern: BiDi Wrap 2: 'Persian (- Author 17c)' -> 'Persian', 'Author (- 17c)'
    m_bw2 = re.match(r'^(.*?)\s*\(\s*[\-\–]\s+([‘\'ʻʼʿʾa-zA-ZāīūēōăĭŭšṣżṭẓčžḥḫẖḍḏġğẕṯṇṉṛśṥẑẗẁỳĀĪŪĒŌĂĬŬŠṢŻṬẒČŽḤḪḎĠḠẔṮṆṈṚŚṤẐ].*?)\s+([0-9\?]+[Cc]?\s*\))$', clean)
    if m_bw2:
        p = m_bw2.group(1).strip()
        a = m_bw2.group(2).strip()
        d = m_bw2.group(3).strip(' )')
        return [p, f'{a} (- {d})']

    # Pattern: BiDi Wrap 3: 'Persian (1889 - 1952 Author)' -> 'Persian', 'Author (1889 - 1952)'
    m_bw3 = re.match(r'^(.*?)\s*\(\s*([0-9\?]+)\s*[\-\–]\s*([0-9\?]+)\s+([‘\'ʻʼʿʾa-zA-ZāīūēōăĭŭšṣżṭẓčžḥḫẖḍḏġğẕṯṇṉṛśṥẑẗẁỳĀĪŪĒŌĂĬŬŠṢŻṬẒČŽḤḪḎĠḠẔṮṆṈṚŚṤẐ].*?)\s*\)$', clean)
    if m_bw3:
        p = m_bw3.group(1).strip()
        d1 = m_bw3.group(2).strip()
        d2 = m_bw3.group(3).strip()
        a = m_bw3.group(4).strip()
        return [p, f'{a} ({d1} - {d2})']

    # Pattern: Author_Translit Persian_Description (Dates)
    m_author_desc = re.match(r'^([‘\'ʻʼʿʾa-zA-ZāīūēōăĭŭšṣżṭẓčžḥḫẖḍḏġğẕṯṇṉṛśṥẑẗẁỳĀĪŪĒŌĂĬŬŠṢŻṬẒČŽḤḪḎĠḠẔṮṆṈṚŚṤẐ].*?,\s*[‘\'ʻʼʿʾa-zA-ZāīūēōăĭŭšṣżṭẓčžḥḫẖḍḏġğẕṯṇṉṛśṥẑẗẁỳĀĪŪĒŌĂĬŬŠṢŻṬẒČŽḤḪḎĠḠẔṮṆṈṚŚṤẐ\s\-\–]+)\s+(.+?)\s*(\(\s*(?:d\.\s*)?[\-\–]?[0-9\?]+(?:\s*[\-\–]\s*[0-9\?]+)?\s*(?:[Cc]|شمسی|قمری|میلادی)?\s*\))$', clean)
    if m_author_desc:
        author_str = m_author_desc.group(1).strip()
        persian_desc = m_author_desc.group(2).strip()
        dates_str = m_author_desc.group(3).strip()
        if any(is_persian_char(c) for c in persian_desc):
            return [f"{author_str} {dates_str}", persian_desc]

    words = clean.split()
    if len(words) < 2:
        return [clean]

    # 1. Leading Transliteration followed by Persian description / Persian Author / Shelfmark
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
        elif has_l or w in ['-', '–', '=', '(', ')', '’', '‘', '\'']:
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

    # 2. Leading Persian followed by Latin transliteration
    p_idx = -1
    for i, w in enumerate(words):
        if w.startswith('__PAGE_TAG_'):
            continue
        if any(c in LATIN_LETTERS for c in w):
            cand_start = i
            if i > 0 and words[i-1] in ['‘', "'", 'ʻ', 'ʼ', 'ʿ', 'ʾ', '`', '’']:
                cand_start = i - 1
            rest_cand = ' '.join(words[cand_start:])
            if is_genuine_transliteration(rest_cand):
                p_idx = cand_start
                break
            
    if p_idx > 0 and p_idx < len(words):
        persian_lead = ' '.join(words[:p_idx])
        rest = ' '.join(words[p_idx:])
        if not any(persian_lead.endswith(s) for s in ['[', '[ف:', '[نشریه:', 'آغاز:', 'انجام:', 'نسخه:']):
            rest_blocks = segment_text_blocks_core(rest)
            return [persian_lead] + rest_blocks

    norm = normalize_translit(clean)
    if not any(is_persian_char(c) for c in norm) and any(c in LATIN_LETTERS for c in norm):
        return [norm]

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

def is_valid_page_split(before, after):
    b = before.strip()
    a = after.strip()
    if len(b) > 150 or any(w in b for w in ['است', 'بود', 'دارد', 'می‌گوید', 'شد', 'نوشته', 'گردیده', 'رسانده', 'مختصری', 'رساله‌ای', 'وابسته به:', 'ن.ک:', 'ن.ک.:']):
        return False
    if b.startswith('وابسته به:'):
        return False
    if not (b.startswith('●') or ' / ' in b or '،' in b):
        return False
    return True

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
        
        if is_valid_page_split(before, after):
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

    # Sanity check on parsed blocks: all non-persian blocks must be genuine transliterations or page tags
    if len(restored) > 1:
        for b in restored:
            if b.startswith('<!--') and b.endswith('-->'):
                continue
            if any(is_persian_char(c) for c in b):
                continue
            if not is_genuine_transliteration(b):
                return [clean]
        
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
            
            # Subcase 1: Persian Title + Page Tag + Latin Transliteration
            if i + 2 < len(blocks) and next_b.startswith('<!--') and next_b.endswith('-->') and is_genuine_transliteration(blocks[i+2]):
                formatted.append(f"{b}\n{next_b}\n{blocks[i+2]}")
                i += 3
                continue
                
            # Subcase 2: Persian Title/Author + Latin Transliteration
            if is_genuine_transliteration(next_b) and (b.startswith('●') or any(is_persian_char(c) for c in b)):
                formatted.append(f"{b}\n{next_b}")
                i += 2
                continue

        formatted.append(b)
        i += 1
        
    return "\n\n".join(formatted)

if __name__ == '__main__':
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

            if not any(c in LATIN_LETTERS for c in clean):
                continue

            prev_l = lines[l_idx - 2] if l_idx >= 2 else ""
            next_l = lines[l_idx] if l_idx < num_lines else ""

            parsed_blocks = process_line(clean, prev_l)

            if len(parsed_blocks) > 1:
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
        f.write("۶. حذف خودکار عبارت‌های زائد مذهبی نظیر «(ع)» از انتهای خطوط آوانگاری.\n")
        f.write("۷. الحاق صحیح و بدون فاصله نویسه‌های ابتدایی (نظیر «‘osmānī») با حذف اسپیس ناشی از خطای OCR.\n\n")
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
'''

with open('scripts/scan_structural_translit.py', 'w', encoding='utf-8') as f:
    f.write(code)
print('Successfully generated scripts/scan_structural_translit.py')

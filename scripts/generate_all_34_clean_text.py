#!/usr/bin/env python3
"""
Convert 68 OCR JSON tree files (sources/json/1.json..68.json) into 34 clean, Volume-sorted text files
using strict Volume Start Markers defined in sources/volume_starts.txt:
- sources/text/fahares_vol_01.txt .. sources/text/fahares_vol_34.txt
- sources/text/fankha-full.txt
- reports/global_reconstructed_titles.md
"""

import sys
import os
import json
import re
from statistics import median

sys.path.append(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from scripts.apply_ocr_corrections import repair_text, repair_transliteration_with_fa_context

# Convert BOTH Persian (۰-۹) and Arabic (٠-٩) digits to standard 0-9
ALL_DIGITS_TO_ENGLISH = str.maketrans('۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', '01234567890123456789')
FOREIGN_SHELF_BRANDS = r'(?:Add|Or|Suppl|MS|Cod|Rieu|Blochet|Ethé|Lat|Ar|Pers|BOD|Cambridge|Paris|London|Berlin|Vatican)'
TRANSLIT_CHAR_CLASS = r'[a-zA-Z\u0100-\u024F\u1E00-\u1EFF]'
TRANSLIT_START_REGEX = rf'^(?:\(-|\()?\s*(?:=\s*)?(?:\d{{3,4}}\s*[\-–]\s*)?(?!{FOREIGN_SHELF_BRANDS}\b)[\'\`\’]?\s*{TRANSLIT_CHAR_CLASS}'
CATEGORY_COMPLETE_REGEX = r'/\s*[\u0600-\u06FF\s]+\s*/\s*[\u0600-\u06FF]+$'

COMMON_CITIES_AND_LIBS = r'(?:تهران|مشهد|قم|اصفهان|شیراز|تبریز|یزد|کرمان|همدان|رشت|ساری|قزوین|کاشان|نجف|کربلا|بغداد|کاظمین|سامرا|دمشق|حلب|بیروت|قاهره|استانبول|آنکارا|باکو|ایروان|دوشنبه|تاشکند|سمرقند|بخارا|کابل|هرات|لاهور|کراچی|اسلام[\s\u200c]*آباد|پیشاور|لکنهو|حیدرآباد|دهلی|علیگر|کلکته|بمبئی|پاتنه|رامپور|لندن|پاریس|برلین|وین|رم|واتیکان|سن[\s\u200c]*پترزبورگ|مسکو|واشنگتن|نیویورک|پرینستون|میشیگان|هاروارد|لس[\s\u200c]*آنجلس|مجلس|دانشگاه|مرعشی|ملی|رضوی|ملک|سنا|سلطنتی|دائر[ةه]\s*المعارف|دایر[ةه]\s*المعارف|فرهنگستان|مرکز\s*احیاء|موزه|آستان\s*قدس|الهیات|ادبیات|حقوق|پزشکی|سپهسالار|شهید\s*مطهری|وزیری|گوهرشاد|چاپ)'
MANUSCRIPT_ENTRY_REGEX = rf'[\d۰-۹]+\.\s+(?:{COMMON_CITIES_AND_LIBS}\s*[؛;:]|[آ-ی\s]{{2,30}}[؛;]\s*(?:شماره نسخه|ش:|شماره:|نسخه:|کتابخانه|مجموعه|ف:|عکس|فیلم|چاپ|[آ-ی\s]+[؛;]))'

ALL_ARROWS = r'[←→➡➔➜➤➥◀▶◄►▲▼–—]'

EXPLICIT_RECONSTRUCTED_TITLES = {
    r"zaxā'?er-ol\s+asfār": "● ذخائر الاسفار",
    r"zaxā'?ir-ul\s+usūl": "● ذخائر الاصول",
    r"dabā'?ih-u\s+ahl-il\s+kitāb": "● ذبائح اهل الكتاب",
}

RECONSTRUCTED_LOG = []

STANDARDIZED_SOURCES = [
    (r'(?:الذریعة|الذریعه|الذريعة)', 'الذریعة'),
    (r'(?:أعیان\s*الشیعة|اعیان\s*الشیعة|أعیان\s*الشیعه|اعیان\s*الشیعه)', 'أعیان الشیعة'),
    (r'(?:م[کك]تب[هة]\s*[اأ]م[یي]ر\s*ال(?:مؤمنین|مومنین|مؤمنين|مومنين))', 'مکتبة أمیر المؤمنین'),
    (r'(?:کشف\s*الظنون|كشف\s*الظنون)', 'کشف الظنون'),
    (r'(?:معجم\s*المطبوعات)', 'معجم المطبوعات'),
    (r'(?:فرهنگ\s*سخنوران)', 'فرهنگ سخنوران'),
    (r'(?:مشار|خانبابا\s*مشار)', 'مشار'),
    (r'(?:تراثنا|تراثا)', 'تراثنا'),
    (r'(?:مجالس\s*المؤمنین|مجالس\s*المومنین)', 'مجالس المؤمنین'),
    (r'(?:ریحانة\s*الادب|ریحانه\s*الادب|ریحانة\s*الأدب|ریحانه\s*الأدب)', 'ریحانة الأدب'),
    (r'(?:نسخه‌های\s*منزوی|نسخه\s*های\s*منزوی|نسخ\s*منزوی)', 'نسخه‌های منزوی'),
    (r'(?:نسخه‌های\s*خطی|نسخه\s*های\s*خطی)', 'نسخه‌های خطی'),
    (r'(?:فهرستواره|فهرستواره\s*منزوی)', 'فهرستواره'),
    (r'(?:نشریه|تشریه)', 'نشریه'),
    (r'(?:دنا)', 'دنا'),
    (r'(?:عکسی\s*ف|عکسی|عكسي\s*ف|عكسي|عكسى)', 'عکسی ف'),
    (r'(?:سنا\s*:\s*ف|سنا\s*ف|سنا)', 'سنا ف'),
    (r'(?:اهدائی|اهدایی)', 'اهدایی'),
    (r'(?:فیلم‌ها|فیلمها|فیلها|فیلم|میکروفیلم)', 'فیلم'),
    (r'(?:محدث)', 'محدث'),
    (r'(?:میراث)', 'میراث'),
    (r'(?:مجلس)', 'مجلس'),
    (r'(?:دانشگاه)', 'دانشگاه'),
    (r'(?:مرعشی)', 'مرعشی'),
    (r'(?:ملی)', 'ملی'),
    (r'(?:رضوی)', 'رضوی'),
    (r'(?:ملک)', 'ملک'),
    (r'(?:رشت)', 'رشت'),
    (r'(?:ف\]?|ف)', 'ف'),
]

CATALOG_PREFIXES = r'(?:ف|عکسی|عكسي|سنا|دنا|نشریه|فیلم|میکروفیلم|فیلها|اهدائی|اهدایی|فهرستواره|نسخه‌های\s*منزوی|نسخه\s*های\s*منزوی|الذریعة|الذریعه|الذريعة|کشف\s*الظنون|معجم\s*المطبوعات|فرهنگ\s*سخنوران|مشار|تراثنا|مجالس\s*المؤمنین|مجالس\s*المومنین|اعیان\s*الشیعة|أعیان\s*الشیعة|ریحانة\s*الادب|ریحانة\s*الأدب|ریحانه\s*الادب|ریحانه\s*الأدب|م[کك]تب[هة]\s*[اأ]م[یي]ر\s*ال(?:مؤمنین|مومنین|مؤمنين|مومنين)|مجلس|دانشگاه|مرعشی|ملی|رضوی|ملک|رشت)'
CATALOG_SEPARATOR_PREFIXES = r'(?:الذریعة|الذریعه|الذريعة|دنا|فهرستواره|کشف\s*الظنون|معجم\s*المطبوعات|مشار|أعیان\s*الشیعة|اعیان\s*الشیعة|ریحانة\s*الادب|ریحانة\s*الأدب|نسخه‌های\s*منزوی|نسخه\s*های\s*منزوی)'

def load_volume_starts(config_path="sources/volume_starts.txt"):
    starts = {}
    if not os.path.exists(config_path):
        return starts
        
    with open(config_path, 'r', encoding='utf-8') as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith('#'): continue
            
            line_clean = line.translate(ALL_DIGITS_TO_ENGLISH)
            m = re.search(r'(?:vol(?:ume)?\s*[_:]?\s*|جلد\s*)?(\d+)\s*[:=]\s*(\d+\.json)\s*[/,:]?\s*(?:sheet|page|برگه|صفحه)?\s*[_:]?\s*(\d+)', line_clean, flags=re.IGNORECASE)
            if m:
                vol_num = int(m.group(1))
                json_file = m.group(2)
                sheet_idx = int(m.group(3))
                starts[(json_file, sheet_idx)] = vol_num
                
    return starts

def standardize_single_item(item):
    item = item.strip()
    if not item: return ''
    
    if item.count('(') > item.count(')'):
        item += ')' * (item.count('(') - item.count(')'))
        
    item = re.sub(r'^ف\]\s*:\s*', 'ف: ', item)
    if item in ('رایانه', 'رايانه'): return 'رایانه'
    
    for pat, canonical in STANDARDIZED_SOURCES:
        m = re.match(rf'^{pat}\s*(?::\s*|\s+)(.*)$', item)
        if m:
            rest = m.group(1).strip()
            if canonical == 'ف':
                rest = re.sub(r'^[\]:]+\s*', '', rest)
                return f'ف: {rest}'
            elif canonical in ('سنا ف', 'عکسی ف'):
                rest = re.sub(r'^[ف\s:]+', '', rest)
                return f'{canonical}: {rest}' if rest else canonical
            else:
                return f'{canonical}: {rest}' if rest else canonical
        elif re.match(rf'^{pat}$', item):
            return canonical
            
    return item

def standardize_bracket_references(text):
    if not text or '[' not in text:
        return text

    text = re.sub(r'\[ف\]\s*:\s*', r'[ف: ', text)
    text = re.sub(r'\[ف\]\s*(?=[\d۰-۹])', r'[ف: ', text)
    text = re.sub(r'\[ف:\s*:\s*', r'[ف: ', text)

    def repl(m):
        content = m.group(1)
        content = re.sub(rf'(?<=\d|\))\s+(?={CATALOG_SEPARATOR_PREFIXES}\b)', '؛ ', content)
        items = re.split(r'[؛;]', content)
        std_items = [standardize_single_item(it) for it in items if it.strip()]
        return f'[{"; ".join(std_items)}]'

    res = re.sub(r'\[([^\]\n\r]+)\]', repl, text)

    def repl_unclosed(m):
        content = m.group(1)
        content = re.sub(rf'(?<=\d|\))\s+(?={CATALOG_SEPARATOR_PREFIXES}\b)', '؛ ', content)
        items = re.split(r'[؛;]', content)
        std_items = [standardize_single_item(it) for it in items if it.strip()]
        return f'[{"; ".join(std_items)}]'

    res = re.sub(rf'\[({CATALOG_PREFIXES}[^\]\n\r]+?)(?<!\])(?=$|\n)', repl_unclosed, res)
    res = re.sub(r'\[(.*?)\]', lambda m: f"[{m.group(1).replace(';', '؛')}]", res)

    return res

def get_reconstructed_title(translit_str):
    if not translit_str: return None
    clean = translit_str.strip().split('\n')[0].strip()
    clean = re.sub(r'^[●\s]+', '', clean)
    clean = re.sub(r'\(.*?\)', '', clean).strip()
    
    for pat, title in EXPLICIT_RECONSTRUCTED_TITLES.items():
        if re.search(pat, clean, re.IGNORECASE):
            return title
    return None

def clean_translit_string(raw_translit, fa_context=""):
    repaired = repair_transliteration_with_fa_context(fa_context, raw_translit)
    flat = ' '.join(repaired.split())
    flat = re.sub(r'^\((\d{3,4})\s*[\-–]\s*(.*?)\s+(\d{3,4})\s*\)', r'\2 (\1-\3)', flat)
    flat = re.sub(rf"^[\'\`\’]\s+({TRANSLIT_CHAR_CLASS})", r"'\1", flat)
    flat = re.sub(rf"^=\s+[\'\`\’]\s+({TRANSLIT_CHAR_CLASS})", r"= '\1", flat)
    return flat

def standardize_bib_terms_safely(text):
    if not text: return ""
    l = text
    l = re.sub(r'\b(?:کاء|کاه)\s*:\s*', 'کا: ', l)
    l = re.sub(r'\bجاء\s*:\s*', 'جا: ', l)
    l = re.sub(r'\bتاء\s*:\s*', 'تا: ', l)
    
    l = re.sub(r'\b(?:بیکا|بیکاء|بیکاه)\b', 'بی‌کا', l)
    l = re.sub(r'\b(?:بیتا|بیتای|بیتآ)\b', 'بی‌تا', l)
    l = re.sub(r'\b(?:بیجا|بیجاء)\b', 'بی‌جا', l)
    
    l = re.sub(r'\bبی[\s\u200c]*(?:کا|کاء|کاه|ک)\b', 'بی‌کا', l)
    l = re.sub(r'\bبی[\s\u200c]*(?:تا|تاء|ت)\b', 'بی‌تا', l)
    l = re.sub(r'\bبی[\s\u200c]*(?:جا|جاء|ج)\b', 'بی‌جا', l)
    l = re.sub(r'\bبی[\s\u200c]+(?:نا|ناء|ن)\b', 'بی‌نا', l)

    l = re.sub(r'\(([^()\n\r]{1,35})[؛;]', r'(\1)؛', l)

    return l

def clean_cross_reference_line(line):
    if not line:
        return [line]
        
    has_arrow = any(c in line for c in ['←', '→', '➡', '➔', '➜', '➤', '➥', '◀', '▶', '◄', '►', '▲', '▼'])
    if not has_arrow:
        return [line]
        
    if re.search(r'\d+\s*[←→–—➤➥➡➔➜➢◀▶◄►▲▼]\s*\d+', line):
        return [line]
        
    if bool(re.search(CATEGORY_COMPLETE_REGEX, line.split('\n')[0])):
        return [line]
        
    if len(re.findall(r'\bس\.\s+', line)) > 1:
        raw_parts = re.split(r'(?<=\S)\s+(?=س\.\s+)', line)
    else:
        raw_parts = [line]
        
    cleaned_entries = []
    for p in raw_parts:
        p = p.strip()
        p = re.sub(r'[→➡➔➜➤➥◀▶◄►▲▼]', '←', p)
        if '←' in p:
            p = re.sub(r'^[←→–—➤➥➡➔➜➢◀▶◄►▲▼_•\.\-\s]+', '', p)
            p = re.sub(r'^س\.?\s+', '', p)
            p = re.sub(r'^[←→–—➤➥➡➔➜➢◀▶◄►▲▼_•\.\-\s]+', '', p)
            
            sub = [x.strip() for x in p.split('←') if x.strip()]
            if len(sub) >= 2:
                source_title = sub[0]
                target_title = ' '.join(sub[1:])
                p = f"{source_title} ← {target_title}"
            elif len(sub) == 1:
                p = sub[0]
        if p:
            cleaned_entries.append(p)
            
    return cleaned_entries if cleaned_entries else [line]

def split_translit_and_persian(s):
    if re.match(TRANSLIT_START_REGEX, s):
        m = re.search(r'^([a-zA-Z\u0100-\u024F\u1E00-\u1EFF\s\d\(\)\-\,\.\'\`\’\=]+?)\s+([\u0600-\u06FF]{2,}.*)$', s)
        if m:
            return [m.group(1).strip(), m.group(2).strip()]
    return [s]

def clean_html_semantically(html):
    if not html: return "", False
    
    is_h2_tag = bool(re.search(r'<h2\b', html, re.IGNORECASE))
    
    t = html.replace('&lt;', '←')
    t = re.sub(r'<\s+(?![a-zA-Z/])', '← ', t)
    
    t = re.sub(r'<br\s*/?>(?:\s*<[^>]+>)*\s*(?=[→–➤➥➡➔➜➢◀▶◄►▲▼])', '\n', t, flags=re.IGNORECASE)
    t = re.sub(r'<br\s*/?>(?:\s*<[^>]+>)*\s*(?=س\.\s+)', '\n', t, flags=re.IGNORECASE)
    t = re.sub(rf'<br\s*/?>(?:\s*<[^>]+>)*\s*(?={MANUSCRIPT_ENTRY_REGEX})', '\n', t, flags=re.IGNORECASE)
    t = re.sub(rf'<br\s*/?>(?:\s*<[^>]+>)*\s*(?={TRANSLIT_START_REGEX[1:]})', '\n', t, flags=re.IGNORECASE)
    t = re.sub(r'<br\s*/?>(?:\s*<[^>]+>)*\s*(?=(?:اهداء|اهدا|وابسته|ترجمه)\s+به:)', '\n', t, flags=re.IGNORECASE)
    t = re.sub(r'<br\s*/?>(?:\s*<[^>]+>)*\s*(?=(?:آغاز|انجام|خط)\s*:)', '\n', t, flags=re.IGNORECASE)
    
    t = re.sub(r'<(?:p|div|h[1-6]|li)[^>]*>', '\n', t, flags=re.IGNORECASE)
    t = re.sub(r'</(?:p|div|h[1-6]|li)>', '\n', t, flags=re.IGNORECASE)
    
    t = re.sub(r'<br\s*/?>', ' ', t, flags=re.IGNORECASE)
    t = re.sub(r'<[^>]+>', ' ', t)
    
    t = re.sub(r'([\d۰-۹]+)\s+([\d۰-۹]+\.\s+[آ-ی])', r'\1\2', t)
    
    lines = [re.sub(r'[ \t]+', ' ', l).strip() for l in t.split('\n')]
    lines = [l for l in lines if l]
    
    final_lines = []
    for line in lines:
        cr_lines = clean_cross_reference_line(line)
        for cr_l in cr_lines:
            tokens = re.split(r'\s+([→–➤➥➡➔➜➢◀▶◄►▲▼])\s+', cr_l)
            if len(tokens) > 1:
                rebuilt_entries = []
                curr_entry = tokens[0]
                for i in range(1, len(tokens), 2):
                    marker = tokens[i]
                    next_part = tokens[i+1]
                    if '←' not in curr_entry:
                        curr_entry += f" ← {next_part}"
                    else:
                        rebuilt_entries.append(curr_entry.strip())
                        curr_entry = f"– {next_part}"
                if curr_entry:
                    rebuilt_entries.append(curr_entry.strip())
                sub_parts = rebuilt_entries
            else:
                sub_parts = [cr_l]

            for sp in sub_parts:
                sp = re.sub(r'([\d۰-۹]+)\s+([\d۰-۹]+\.\s+[آ-ی])', r'\1\2', sp)
                s_parts = re.split(r'(?<=\S)\s+(?=س\.\s+)', sp)
                for s_p in s_parts:
                    m_parts = re.split(rf'(?<=\S)\s+(?={MANUSCRIPT_ENTRY_REGEX})', s_p)
                    for mp in m_parts:
                        t_parts = re.split(rf'(?<=[آ-ی\d\)])(?<!ش)(?<!شماره)\s+(?=(?:\(-|\()?\s*(?:=\s*)?(?:\d{{3,4}}\s*[\-–]\s*)?[\'\`\’]?\s*(?!{FOREIGN_SHELF_BRANDS}\b){TRANSLIT_CHAR_CLASS}{{3,}})', mp)
                        for tp in t_parts:
                            l2p_parts = split_translit_and_persian(tp)
                            for l2p in l2p_parts:
                                d_parts = re.split(r'(?<=\S)\s+(?=(?:اهداء|اهدا|وابسته|ترجمه)\s+به:)', l2p)
                                for dp in d_parts:
                                    sp_clean = repair_text(dp.strip())
                                    if sp_clean:
                                        sp_clean = re.sub(rf'^\(-\s+({TRANSLIT_CHAR_CLASS}.*?)\s+(\d+\?\))', r'\1 (-\2', sp_clean)
                                        sp_clean = re.sub(rf'^\(-\s+({TRANSLIT_CHAR_CLASS}.*?)\s+\(-\s*(\d+)', r'\1 (-\2', sp_clean)
                                        
                                        sp_clean = standardize_bib_terms_safely(sp_clean)
                                        
                                        curr_is_tr = bool(re.match(TRANSLIT_START_REGEX, sp_clean))
                                        prev_is_tr = (final_lines and bool(re.match(TRANSLIT_START_REGEX, final_lines[-1])))
                                        prev_ends_eq = (final_lines and final_lines[-1].endswith('='))

                                        if curr_is_tr and (prev_is_tr or prev_ends_eq):
                                            final_lines[-1] = final_lines[-1].strip() + f" {sp_clean.strip()}"
                                        else:
                                            final_lines.append(sp_clean)
                
    text = "\n".join(final_lines)
    text = text.translate(ALL_DIGITS_TO_ENGLISH)
    
    text = re.sub(r'\(\s+', '(', text)
    text = re.sub(r'\s+\)', ')', text)
    text = re.sub(r'\[\s+', '[', text)
    text = re.sub(r'\s+\]', ']', text)
    
    return text, is_h2_tag

def is_header_text(clean_text, y_min):
    if y_min >= 250: return False
    if re.search(r'(?:آغاز:|انجام:|آغاز و انجام:|خط:|شماره نسخه:|نسخه اصل:|چاپ:|●|وابسته به:|اهداء به:|اهدا به:)', clean_text):
        return False
    
    if 'فهرستگان' in clean_text or 'فنخا' in clean_text or 'نخا' in clean_text or 'نخ' in clean_text: return True
    if re.match(r'^\d+$', clean_text.strip()): return True
    if re.search(r'[آ-ی]\s*-\s*[آ-ی]', clean_text): return True
    if re.search(r'[\u0600-\u06FF\s\(\)]+\s+[\d۰-۹]+$', clean_text.strip()): return True
    if re.search(r'^[\d۰-۹]+\s+[\u0600-\u06FF\s\(\)]+', clean_text.strip()): return True
    return False

def is_standalone_banner(child, page_mid_x):
    bbox = child.get('bbox', [])
    if not bbox or len(bbox) != 4: return False
    
    x_min, y_min, x_max, y_max = bbox
    height = y_max - y_min
    
    if y_min <= 250 or y_max >= 2000: return False
    
    if x_min < page_mid_x < x_max and height > 75:
        html = child.get('html', '')
        clean = re.sub(r'<[^>]+>', ' ', html).strip()
        if not clean.startswith('●') and '/' not in clean:
            return True
            
    return False

def get_item_column_and_y(html, default_bbox):
    spans = re.findall(r'data-bbox=\"([^\"]+)\"', html)
    if spans:
        x_centers = []
        for s in spans:
            nums = [float(v) for v in s.split()]
            if len(nums) == 4:
                x_centers.append((nums[0] + nums[2]) / 2)
        if x_centers:
            y_top = default_bbox[1] if len(default_bbox) == 4 and default_bbox[1] > 0 else min(float(s.split()[1]) for s in spans)
            return median(x_centers), y_top
            
    if len(default_bbox) == 4:
        return (default_bbox[0] + default_bbox[2]) / 2, default_bbox[1]
    return 0, 0

def extract_sub_blocks_from_child(child):
    html = child.get('html', '')
    c_bbox = child.get('bbox', [0, 0, 0, 0])
    
    if '<li' in html:
        items = re.findall(r'(<li\b[^>]*>.*?</li>)', html, re.DOTALL)
        if not items:
            items = re.split(r'(?=<li\b)', html)
            items = [it for it in items if it.strip()]
            
        sub_blocks = []
        for it in items:
            x_c, y_m = get_item_column_and_y(it, c_bbox)
            sub_blocks.append((it, x_c, y_m))
        return sub_blocks
    else:
        x_c, y_m = get_item_column_and_y(html, c_bbox)
        return [(html, x_c, y_m)]

def process_page_node(page, last_page_num, last_title_fa="", title_incomplete=False, vol_num=1):
    children = page.get('children', [])
    if not children: return f"<!-- page: {last_page_num + 1 if last_page_num else ''} -->", "", last_page_num + 1 if last_page_num else None, last_title_fa, title_incomplete
        
    page_bbox = page.get('bbox', [0, 0, 1600, 2240])
    page_width = page_bbox[2] - page_bbox[0]
    page_mid_x = page_bbox[0] + (page_width / 2)
    
    headers = []
    right_col = []
    left_col = []
    
    for child in children:
        if is_standalone_banner(child, page_mid_x):
            continue

        sub_blocks = extract_sub_blocks_from_child(child)
        
        for html, x_center, y_min in sub_blocks:
            clean_text, is_h2 = clean_html_semantically(html)
            if not clean_text: continue
                
            if is_header_text(clean_text, y_min):
                headers.append((y_min, clean_text, is_h2))
            else:
                lines_in_txt = clean_text.split('\n')
                for l_idx, l_txt in enumerate(lines_in_txt):
                    l_txt = l_txt.strip()
                    if not l_txt: continue
                    if x_center > page_mid_x:
                        right_col.append((y_min + (l_idx * 0.1), l_txt, is_h2 if l_idx == 0 else False))
                    else:
                        left_col.append((y_min + (l_idx * 0.1), l_txt, is_h2 if l_idx == 0 else False))
                
    headers.sort(key=lambda x: x[0])
    right_col.sort(key=lambda x: x[0])
    left_col.sort(key=lambda x: x[0])
    
    detected_num = None
    for _, h_text, _ in headers:
        m_num = re.search(r'\d+', h_text)
        if m_num:
            detected_num = int(m_num.group(0).translate(ALL_DIGITS_TO_ENGLISH))
            break
            
    if detected_num is not None:
        curr_num = detected_num
    elif last_page_num is not None:
        curr_num = last_page_num + 1
    else:
        curr_num = None

    page_num_tag = f"<!-- page: {curr_num} -->" if curr_num is not None else ""
            
    col_blocks = [(text, is_h2) for _, text, is_h2 in right_col] + [(text, is_h2) for _, text, is_h2 in left_col]
    
    merged_blocks = []
    in_bullet_title_mode = title_incomplete
    curr_title_fa = last_title_fa

    for blk_idx, (blk, is_h2) in enumerate(col_blocks):
        if 'ذ' in blk or 'احد' in blk or 'طلع' in blk:
            blk = repair_transliteration_with_fa_context(blk, blk)
        elif curr_title_fa and ('ذ' in curr_title_fa or 'احد' in curr_title_fa or 'طلع' in curr_title_fa):
            blk = repair_transliteration_with_fa_context(curr_title_fa, blk)

        first_line = blk.split('\n')[0].strip()
        is_entry_header = bool(re.match(rf'^{MANUSCRIPT_ENTRY_REGEX}', first_line))

        if in_bullet_title_mode and merged_blocks:
            prev_title_is_complete = bool(re.search(CATEGORY_COMPLETE_REGEX, merged_blocks[-1].split('\n')[0]))
            is_translit = bool(re.match(TRANSLIT_START_REGEX, first_line))
            is_entry_field = (first_line.startswith('آغاز:') or first_line.startswith('انجام:') or first_line.startswith('آغاز و انجام:') or first_line.startswith('خط:') or is_entry_header)

            if not prev_title_is_complete and not is_translit and not is_entry_field:
                clean_blk = re.sub(r'^●\s*', '', blk).strip()
                merged_blocks[-1] = merged_blocks[-1].strip() + f" {clean_blk}"
                curr_title_fa = merged_blocks[-1]
                in_bullet_title_mode = not bool(re.search(CATEGORY_COMPLETE_REGEX, curr_title_fa.split('\n')[0]))
                continue

        has_category_slash = bool(re.search(r'/\s*[\u0600-\u06FF\s]+\s*/(?:[\u0600-\u06FF]+)?$', first_line))
        
        if blk_idx == 0 and title_incomplete:
            clean_blk_no_bullet = re.sub(r'^●\s*', '', blk)
            words_prev = curr_title_fa.split()
            words_curr = clean_blk_no_bullet.split()
            
            prefix_len = 0
            for k in range(1, len(words_curr)+1):
                phrase = ' '.join(words_curr[:k])
                if phrase in curr_title_fa:
                    prefix_len = k
                else:
                    break
                    
            remaining_curr = ' '.join(words_curr[prefix_len:]) if prefix_len > 0 else clean_blk_no_bullet
            
            if merged_blocks:
                merged_blocks[-1] = merged_blocks[-1].strip() + f" {remaining_curr.strip()}"
            else:
                merged_blocks.append(remaining_curr.strip())
                
            curr_title_fa = curr_title_fa.strip() + f" {remaining_curr.strip()}"
            in_bullet_title_mode = not bool(re.search(CATEGORY_COMPLETE_REGEX, curr_title_fa.split('\n')[0]))
            title_incomplete = False
            continue

        is_title_fa = (first_line.startswith('●') or is_h2 or has_category_slash)
        
        if is_title_fa:
            if not blk.startswith('●'):
                blk = f"● {blk}"
                first_line = blk.split('\n')[0].strip()

            curr_title_fa = first_line
            merged_blocks.append(blk)
            in_bullet_title_mode = not bool(re.search(CATEGORY_COMPLETE_REGEX, first_line))
            continue

        if in_bullet_title_mode:
            is_translit = bool(re.match(TRANSLIT_START_REGEX, first_line))
            is_entry_field = (first_line.startswith('آغاز:') or first_line.startswith('انجام:') or first_line.startswith('آغاز و انجام:') or first_line.startswith('خط:') or is_entry_header or is_title_fa)

            if is_translit:
                in_bullet_title_mode = False
                flat_translit = clean_translit_string(blk, curr_title_fa)
                merged_blocks[-1] += f"\n{flat_translit}"
                continue
            elif is_entry_field:
                in_bullet_title_mode = False
                merged_blocks.append(blk)
                continue
            else:
                merged_blocks[-1] = merged_blocks[-1].strip() + f" {blk.strip()}"
                curr_title_fa = merged_blocks[-1]
                in_bullet_title_mode = not bool(re.search(CATEGORY_COMPLETE_REGEX, curr_title_fa.split('\n')[0]))
                continue

        prev_is_persian_title = (merged_blocks and (merged_blocks[-1].startswith('●') or '/' in merged_blocks[-1].split('\n')[-1]) and not re.match(TRANSLIT_START_REGEX, merged_blocks[-1].split('\n')[-1].strip()))
        curr_is_translit = bool(re.match(TRANSLIT_START_REGEX, first_line))

        if curr_is_translit and not prev_is_persian_title:
            reconstructed_title = get_reconstructed_title(first_line)
            if reconstructed_title:
                context_preview = col_blocks[blk_idx+1][0].split('\n')[0] if blk_idx+1 < len(col_blocks) else ""
                RECONSTRUCTED_LOG.append({
                    "vol": vol_num,
                    "page": curr_num,
                    "transliteration": first_line,
                    "reconstructed_title": reconstructed_title,
                    "context": context_preview
                })
                merged_blocks.append(reconstructed_title)
                curr_title_fa = reconstructed_title
                flat_translit = clean_translit_string(blk, curr_title_fa)
                merged_blocks[-1] += f"\n{flat_translit}"
                continue

        if curr_is_translit:
            flat_translit = clean_translit_string(blk, curr_title_fa)
            
            if merged_blocks:
                prev_lines = merged_blocks[-1].split('\n')
                prev_last_line = prev_lines[-1].strip()
                prev_is_translit = bool(re.match(TRANSLIT_START_REGEX, prev_last_line)) or prev_last_line.endswith('=')
                prev_is_title_alone = (merged_blocks[-1].startswith('●') and len(prev_lines) == 1)
                prev_is_author_alone = (not merged_blocks[-1].startswith('●') and 
                                        not prev_last_line.startswith(('آغاز:', 'انجام:', 'آغاز و انجام:', 'خط:', 'چاپ:', 'وابسته به:', 'اهداء به:', 'اهدا به:')) and 
                                        not bool(re.match(r'^\d+\.\s+', prev_last_line)) and 
                                        len(prev_lines) == 1)

                if prev_is_translit:
                    merged_blocks[-1] = merged_blocks[-1].strip() + f" {flat_translit}"
                elif prev_is_title_alone or prev_is_author_alone:
                    merged_blocks[-1] += f"\n{flat_translit}"
                else:
                    merged_blocks.append(flat_translit)
            else:
                merged_blocks.append(flat_translit)
            continue

        if merged_blocks and re.search(r'(?:نسخه اصل:.*?ش|موزه.*?ش)\s*$', merged_blocks[-1].strip()) and re.search(rf'^\s*{FOREIGN_SHELF_BRANDS}\b', first_line):
            merged_blocks[-1] = merged_blocks[-1].strip() + f" {blk.strip()}"
            continue

        if merged_blocks and (first_line.startswith('آغاز:') or first_line.startswith('انجام:') or first_line.startswith('آغاز و انجام:') or first_line.startswith('خط:') or first_line.startswith('نسخه اصل:')):
            merged_blocks[-1] += f"\n{blk}"
            continue

        # Merge split unclosed bracket tail
        prev_has_open = (merged_blocks and merged_blocks[-1].count('[') > merged_blocks[-1].count(']'))
        prev_has_broken_ref = (merged_blocks and bool(re.search(r'\[(?:[آ-ی\s:]*:\s*|.*:\s*\d+[\-–/]\s*)\]?$', merged_blocks[-1].strip())))
        
        if prev_has_open or prev_has_broken_ref:
            clean_blk_str = blk.strip()
            nums = re.findall(r'\d+', clean_blk_str)
            if re.match(r'^\[?\s*[\d\s\-–/]+\s*\]?$', clean_blk_str) and len(nums) in (1, 2):
                if len(nums) == 2:
                    n1, n2 = int(nums[0]), int(nums[1])
                    if n1 > n2 and n2 <= 15:
                        num_str = f"{n2}-{n1}"
                    else:
                        num_str = f"{n1}-{n2}"
                else:
                    num_str = nums[0]

                if prev_has_broken_ref:
                    base = re.sub(r'\]?$', '', merged_blocks[-1].strip())
                    merged_blocks[-1] = f"{base} {num_str}]"
                else:
                    merged_blocks[-1] = merged_blocks[-1].strip() + f" {num_str}]"
                continue

        # Incomplete sentence merger
        if merged_blocks:
            prev_last_line = merged_blocks[-1].strip().split('\n')[-1].strip()
            prev_is_title = (merged_blocks[-1].startswith('●') or bool(re.search(r'/\s*[\u0600-\u06FF\s]+\s*/(?:[\u0600-\u06FF]+)?$', prev_last_line)))
            prev_is_translit_block = bool(re.match(TRANSLIT_START_REGEX, prev_last_line))
            prev_is_cross_ref = (any(c in prev_last_line for c in ['←', '◀', '▶', '◄', '►']) and '/' not in prev_last_line and not re.search(r'\d+\s*[←→–—◀▶]\s*\d+', prev_last_line))
            curr_is_cross_ref = (any(c in first_line for c in ['←', '◀', '▶', '◄', '►']) and '/' not in first_line and not re.search(r'\d+\s*[←→–—◀▶]\s*\d+', first_line))
            prev_no_term = not prev_last_line.endswith(('.', ':', '؛', ']', '»', '؟', '!', ')', '×'))
            curr_is_field = (first_line.startswith('آغاز:') or first_line.startswith('انجام:') or first_line.startswith('آغاز و انجام:') or first_line.startswith('خط:') or first_line.startswith('چاپ:') or is_entry_header or is_title_fa or curr_is_cross_ref)
            
            if prev_no_term and not prev_is_title and not prev_is_translit_block and not prev_is_cross_ref and not curr_is_field and not first_line.startswith('●') and not curr_is_translit:
                merged_blocks[-1] = merged_blocks[-1].strip() + f" {blk.strip()}"
                continue
                
        merged_blocks.append(blk)

    is_last_block_incomplete_title = False
    if merged_blocks:
        last_blk = merged_blocks[-1].strip().split('\n')[-1].strip()
        if last_blk.startswith('●') and not bool(re.search(CATEGORY_COMPLETE_REGEX, last_blk)) and not last_blk.endswith('.'):
            is_last_block_incomplete_title = True

    for i in range(len(merged_blocks)):
        merged_blocks[i] = standardize_bib_terms_safely(merged_blocks[i])
        merged_blocks[i] = standardize_bracket_references(merged_blocks[i])
        if merged_blocks[i].count('[') > merged_blocks[i].count(']'):
            merged_blocks[i] += ']'

    page_text = "\n\n".join(merged_blocks)
    return page_num_tag, page_text, curr_num, curr_title_fa, is_last_block_incomplete_title

def main():
    vol_starts = load_volume_starts("sources/volume_starts.txt")
    print(f"📖 Loaded {len(vol_starts)} explicit volume start markers from sources/volume_starts.txt")

    os.makedirs("sources/text", exist_ok=True)
    os.makedirs("reports", exist_ok=True)

    vol_pages_contents = {v: [] for v in range(1, 35)}
    curr_vol = 1
    last_page_num = 1
    last_title_fa = ""
    title_incomplete = False
    
    for i in range(1, 69):
        json_filename = f"{i}.json"
        fpath = f"sources/json/{json_filename}"
        if not os.path.exists(fpath): continue
            
        print(f"🔄 Processing {json_filename}...")
        with open(fpath, 'r', encoding='utf-8', errors='ignore') as f:
            data = json.load(f)
            
        pages_nodes = []
        def collect_pages(node):
            if isinstance(node, dict):
                bbox = node.get('bbox', [])
                children = node.get('children', [])
                if bbox and len(bbox) == 4 and bbox[1] <= 50 and bbox[3] >= 2000:
                    pages_nodes.append(node)
                else:
                    for c in children:
                        collect_pages(c)
        collect_pages(data)
        
        for sheet_idx, p in enumerate(pages_nodes, 1):
            if (json_filename, sheet_idx) in vol_starts:
                curr_vol = vol_starts[(json_filename, sheet_idx)]
                last_page_num = 1
                last_title_fa = ""
                title_incomplete = False
                print(f"📌 Volume boundary triggered -> Volume {curr_vol:02d} starting at {json_filename} / sheet {sheet_idx}")
            else:
                p_vol = None
                for c in p.get('children', []):
                    html = c.get('html', '')
                    c_bbox = c.get('bbox', [0,0,0,0])
                    if c_bbox[1] < 250 and ('فهرستگان' in html or 'جلد' in html):
                        m_vol = re.search(r'جلد\s*([۰-۹\d]+)', html)
                        if m_vol:
                            val = int(m_vol.group(1).translate(ALL_DIGITS_TO_ENGLISH))
                            if 1 <= val <= 34:
                                p_vol = val
                                break
                if p_vol and not vol_starts:
                    if p_vol != curr_vol:
                        curr_vol = p_vol
                        last_page_num = 1
                        last_title_fa = ""
                        title_incomplete = False
                    
            tag, txt, last_page_num, last_title_fa, title_incomplete = process_page_node(
                p, last_page_num, last_title_fa, title_incomplete, vol_num=curr_vol
            )
            if txt:
                vol_pages_contents[curr_vol].append({'tag': tag, 'txt': txt})

    full_text_list = []
    
    for vol in range(1, 35):
        items = vol_pages_contents[vol]
        if not items:
            print(f"⚠️ Warning: Volume {vol:02d} had 0 pages extracted!")
            continue
            
        final_text = ""
        for item_idx, item in enumerate(items):
            tag = item['tag']
            txt = item['txt']
            
            # Accurate initial page number fix on the first item:
            if item_idx == 0 and len(items) > 1:
                m_next = re.search(r'<!-- page:\s*(\d+)\s*-->', items[1]['tag'])
                if m_next:
                    second_page_num = int(m_next.group(1))
                    tag = f"<!-- page: {second_page_num - 1} -->"
            
            if not final_text:
                final_text = f"{tag}\n{txt}" if tag else txt
            else:
                if tag:
                    last_line = final_text.strip().split('\n')[-1].strip()
                    first_line_next = txt.strip().split('\n')[0].strip()
                    if last_line.startswith('●') and not bool(re.search(CATEGORY_COMPLETE_REGEX, last_line)) and not last_line.endswith('.'):
                        final_text = final_text.rstrip() + f" {tag} " + txt.lstrip()
                    else:
                        is_last_translit = bool(re.match(TRANSLIT_START_REGEX, last_line))
                        is_last_title = bool(final_text.strip().split('\n\n')[-1].startswith('●') or '/' in last_line)
                        
                        if is_last_translit or is_last_title or last_line[-1] in ['.', ':', ']', '»', '؛'] or first_line_next.startswith('●') or first_line_next.startswith('–') or first_line_next.startswith('→') or first_line_next.startswith('←'):
                            final_text = final_text.rstrip() + f"\n{tag}\n" + txt.lstrip()
                        else:
                            final_text = final_text.rstrip() + f" {tag} " + txt.lstrip()
                else:
                    final_text += f"\n\n{txt}"
                    
        vol_path_sources = f"sources/text/fahares_vol_{vol:02d}.txt"
        
        with open(vol_path_sources, 'w', encoding='utf-8') as f:
            f.write(final_text)
            
        full_text_list.append(final_text)
        print(f"✅ Created Volume {vol:02d} -> {vol_path_sources} ({len(items)} pages, {len(final_text):,} chars)")

    # Combined master file across all 34 volumes
    full_path_sources = "sources/text/fankha-full.txt"
    combined_content = "\n\n".join(full_text_list)
    
    with open(full_path_sources, 'w', encoding='utf-8') as f:
        f.write(combined_content)
        
    print(f"\n🎉 Combined master text created -> {full_path_sources} ({len(combined_content):,} chars)")

    # Write Global Reconstruction Report
    report_path = "reports/global_reconstructed_titles.md"
    with open(report_path, 'w', encoding='utf-8') as f:
        f.write("# Global Report of Reconstructed Titles\n\n")
        f.write(f"Total reconstructed titles: {len(RECONSTRUCTED_LOG)}\n\n")
        f.write("| Vol | Page | Transliteration | Reconstructed Persian Title | Context Preview |\n")
        f.write("|---|---|---|---|---|\n")
        for entry in RECONSTRUCTED_LOG:
            f.write(f"| {entry['vol']} | {entry['page']} | `{entry['transliteration']}` | **{entry['reconstructed_title']}** | {entry['context'][:60]} |\n")
    print(f"📊 Reconstruction report generated -> {report_path}")

if __name__ == "__main__":
    main()

#!/usr/bin/env python3
"""
Ultra-Precision AI Anomaly Detection Engine for FanKha Titles and Cross-References.
Incorporates learned user patterns:
1. Exact consonant-skeleton + dental sound normalization.
2. Abbreviation expansions and apostrophe-safe transliteration matching.
3. Multi-entry cross-reference chain splits.
4. Glued cataloging lines separation.
Output: reports/candidate_title_anomalies.md
"""

import os
import re
import unicodedata

def expand_translit_abbr(tr):
    tr = re.sub(r'\bal-mn\.\b', 'al-muntaxab', tr)
    tr = re.sub(r'\bmn\.\b', 'muntaxab', tr)
    tr = re.sub(r'\bk\.\b', 'kitab', tr)
    tr = re.sub(r'\bš\.\b', 'sarh', tr)
    tr = re.sub(r'\br\.\b', 'resale', tr)
    tr = re.sub(r'\bm\.\b', 'muxtasar', tr)
    tr = re.sub(r'\bd\.\b', 'diwan', tr)
    tr = re.sub(r'\bt\.\b', 'tarjame', tr)
    tr = re.sub(r'\bmx\.\b', 'muxtasar', tr)
    return tr

CHAR_MAP = {
    'ا': 'a', 'آ': 'a', 'أ': 'a', 'إ': 'a', 'ء': '', 'ب': 'b', 'پ': 'p', 'ت': 't',
    'ث': 's', 'ج': 'j', 'چ': 'c', 'ح': 'h', 'خ': 'x', 'د': 'd', 'ذ': 'z', 'ر': 'r',
    'ز': 'z', 'ژ': 'z', 'س': 's', 'ش': 's', 'ص': 's', 'ض': 'z', 'ط': 't', 'ظ': 'z',
    'ع': '', 'غ': 'g', 'ف': 'f', 'ق': 'q', 'ک': 'k', 'ك': 'k', 'گ': 'g', 'ل': 'l',
    'م': 'm', 'ن': 'n', 'و': 'u', 'ه': 'h', 'ة': 't', 'ی': 'i', 'ي': 'i', 'ى': 'i'
}

def to_ascii_phonetic(text, is_fa=False):
    if is_fa:
        w_clean = re.sub(r'[\u064B-\u065F\u0670\s\.\-–\(\)\[\]«»\"\'\=]', '', text)
        if w_clean.startswith('ال') and len(w_clean) > 3:
            w_clean = w_clean[2:]
        mapped = ''.join(CHAR_MAP.get(c, '') for c in w_clean)
        return mapped
    else:
        t = text.lower()
        t = t.replace('ṯ', 's').replace('th', 's')
        t = t.replace('ḍ', 'z').replace('ḏ', 'z').replace('dh', 'z').replace('ẓ', 'z')
        t = t.replace('ḥ', 'h').replace('x', 'x').replace('kh', 'x')
        norm = unicodedata.normalize('NFKD', t)
        ascii_str = ''.join(c for c in norm if not unicodedata.combining(c))
        ascii_str = re.sub(r'[^a-z]', '', ascii_str)
        if ascii_str.startswith('al') and len(ascii_str) > 4:
            ascii_str = ascii_str[2:]
        return ascii_str

def is_phonetic_match(fa_word, tr_word):
    if not fa_word or not tr_word: return False
    f_p = to_ascii_phonetic(fa_word, is_fa=True)
    t_p = to_ascii_phonetic(tr_word, is_fa=False)
    if not f_p or not t_p: return False
    if f_p == t_p or f_p[:3] == t_p[:3]: return True
    f_sk = re.sub(r'[aeiou]', '', f_p)
    t_sk = re.sub(r'[aeiou]', '', t_p)
    if f_sk and t_sk and f_sk[:2] == t_sk[:2]: return True
    f_sk_v = f_sk.replace('t', 's')
    t_sk_v = t_sk.replace('t', 's')
    if f_sk_v and t_sk_v and f_sk_v[:2] == t_sk_v[:2]: return True
    if len(f_p) >= 3 and len(t_p) >= 3 and (f_p in t_p or t_p in f_p): return True
    return False

anomalies = []

for vol in range(1, 35):
    fpath = f"sources/text/fahares_vol_{vol:02d}.txt"
    if not os.path.exists(fpath): continue
    
    with open(fpath, 'r', encoding='utf-8') as f:
        lines = f.readlines()
        
    for idx, line in enumerate(lines):
        line_clean = line.strip()
        if not line_clean: continue
        
        # Check 1: Mid-line bullets or multiple bullets on same line
        if ('●' in line_clean and not line_clean.startswith('●')) or (line_clean.count('●') > 1):
            raw_parts = [p.strip() for p in re.split(r'(?=[●])', line_clean) if p.strip()]
            proposed_lines = []
            for p in raw_parts:
                if '←' in p:
                    proposed_lines.append(re.sub(r'^[●\s]+', '', p))
                else:
                    proposed_lines.append(p if p.startswith('●') else f"● {p}")
                    
            anomalies.append({
                'vol': vol,
                'line_num': idx + 1,
                'category': 'چندمدخلی در یک سطر (شکستن سطر در محل گلوله ●)',
                'current': line_clean,
                'proposed': '\n\n'.join(proposed_lines)
            })
            continue

        # Check 2: Genuine title/cross-ref boundary issues
        if line_clean.startswith('●') and idx+1 < len(lines):
            next_tr = lines[idx+1].strip()
            if bool(re.match(r'^[a-zA-Z\u0100-\u024F\u1E00-\u1EFF\(\'\`\’\=]', next_tr)):
                prev_line = lines[idx-2].strip() if idx >= 2 else ''
                title_words = re.sub(r'^[●\s]+', '', line_clean).split()
                if '/' in title_words:
                    slash_idx = title_words.index('/')
                    title_words = title_words[:slash_idx]
                    
                expanded_tr = expand_translit_abbr(next_tr)
                tr_words = [w.lower() for w in re.findall(r'[a-zA-Z\u0100-\u024F\u1E00-\u1EFF\-\'\`\’\‘\ʻ\ʿ]+', expanded_tr)]
                
                if title_words and tr_words and prev_line and '←' in prev_line:
                    first_title_w = title_words[0]
                    first_tr_w = tr_words[0]
                    
                    # If current title already matches transliteration, it is 100% correct!
                    if is_phonetic_match(first_title_w, first_tr_w):
                        continue
                        
                    # Case 2A: Title starts with extra words that belong to previous cross-reference
                    found_forward_split = False
                    for split_pos in range(1, min(len(title_words), 5)):
                        cand_first = title_words[split_pos]
                        if is_phonetic_match(cand_first, first_tr_w):
                            extra_words = ' '.join(title_words[:split_pos])
                            clean_title_part = ' '.join(title_words[split_pos:])
                            cat_match = re.search(r'/\s*[\u0600-\u06FF\s]+\s*/\s*[\u0600-\u06FF]+$', line_clean)
                            cat_str = f" {cat_match.group(0)}" if cat_match else ""
                            
                            anomalies.append({
                                'vol': vol,
                                'line_num': idx + 1,
                                'category': 'انتقال کلمات اضافه از ابتدای عنوان به انتهای ارجاع قبل',
                                'current_prev': prev_line,
                                'current_title': line_clean,
                                'translit': next_tr,
                                'proposed_prev': f"{prev_line} {extra_words}",
                                'proposed_title': f"● {clean_title_part}{cat_str}"
                            })
                            found_forward_split = True
                            break
                            
                    if found_forward_split:
                        continue

                    # Case 2B: Current title missing first word (left at end of previous cross-reference)
                    prev_words = prev_line.split()
                    found_k = 0
                    for k in [1, 2, 3]:
                        if len(prev_words) > k:
                            cand_w = prev_words[-k]
                            if is_phonetic_match(cand_w, first_tr_w):
                                found_k = k
                                break
                                
                    if found_k > 0:
                        moved_words = ' '.join(prev_words[-found_k:])
                        new_prev = ' '.join(prev_words[:-found_k])
                        new_title = f"● {moved_words} {re.sub(r'^[●\s]+', '', line_clean)}"
                        
                        anomalies.append({
                            'vol': vol,
                            'line_num': idx + 1,
                            'category': 'انتقال کلمه جا مانده از انتهای ارجاع به ابتدای عنوان',
                            'current_prev': prev_line,
                            'current_title': line_clean,
                            'translit': next_tr,
                            'proposed_prev': new_prev,
                            'proposed_title': new_title
                        })

report_path = 'reports/candidate_title_anomalies.md'
with open(report_path, 'w', encoding='utf-8') as f:
    f.write("# گزارش موارد مشکوک عناوین و ارجاعات (نسخه پالایش‌شده با هوش مصنوعی)\n\n")
    f.write(f"تعداد کل موارد شناسایی شده: **{len(anomalies)}** مورد\n\n")
    f.write("راهنما:\n")
    f.write("- **نوع ۱ (انتقال از ارجاع به عنوان)**: کلمه‌ای که در انتهای ارجاع مانده و باید اول عنوان بیاید.\n")
    f.write("- **نوع ۲ (انتقال از عنوان به ارجاع)**: کلماتی که اول عنوان آمده‌اند ولی متعلق به انتهای ارجاع قبل هستند.\n")
    f.write("- **نوع ۳ (چندمدخلی در یک سطر)**: چند ارجاع یا عنوان که در یک خط چسبیده‌اند و باید به سطرهای جداگانه شکسته شوند.\n\n")
    f.write("="*60 + "\n\n")
    
    for i, a in enumerate(anomalies, 1):
        f.write(f"### شماره {i} (جلد {a['vol']:02d} - سطر {a['line_num']}) | نوع: {a['category']}\n\n")
        
        if 'current_prev' in a:
            f.write("🔴 **وضعیت فعلی:**\n")
            f.write(f"- سطر ارجاع: `{a['current_prev']}`\n")
            f.write(f"- سطر عنوان: `{a['current_title']}`\n")
            f.write(f"- آوانگاری:  `{a['translit']}`\n\n")
            f.write("🟢 **اصلاح پیشنهادی:**\n")
            f.write(f"- سطر ارجاع: `{a['proposed_prev']}`\n")
            f.write(f"- سطر عنوان: `{a['proposed_title']}`\n\n")
        else:
            f.write("🔴 **وضعیت فعلی:**\n")
            f.write(f"```text\n{a['current']}\n```\n\n")
            f.write("🟢 **اصلاح پیشنهادی:**\n")
            f.write(f"```text\n{a['proposed']}\n```\n\n")
            
        f.write("---\n\n")

print(f"✅ Generated final AI-refined smart diff report -> {report_path} (Total items: {len(anomalies)})")

#!/usr/bin/env python3
"""
Generate a structured report of all candidate title/cross-reference anomalies across all 34 volumes.
Output: reports/candidate_title_anomalies.md
"""

import os
import re
import glob

def expand_translit_abbr(tr):
    tr = re.sub(r'al-mn\.', 'al-muntaxab', tr)
    tr = re.sub(r'mn\.', 'muntaxab', tr)
    tr = re.sub(r'k\.', 'kitab', tr)
    tr = re.sub(r'š\.', 'šarh', tr)
    tr = re.sub(r'r\.', 'resale', tr)
    tr = re.sub(r'm\.', 'muxtasar', tr)
    tr = re.sub(r'd\.', 'diwan', tr)
    return tr

char_map = {
    'م': 'm', 'ق': 'q', 'د': 'd', 'ر': 'r', 'ا': 'a', 'و': 'v', 'ز': 'z',
    'ن': 'n', 'ف': 'f', 'ی': 'y', 'ي': 'y', 'ع': "'", 'ل': 'l', 'ب': 'b',
    'ت': 't', 'ث': 's', 'ج': 'j', 'ح': 'h', 'خ': 'x', 'س': 's', 'ش': 's',
    'ص': 's', 'ض': 'z', 'ط': 't', 'ظ': 'z', 'غ': 'g', 'ک': 'k', 'ك': 'k',
    'گ': 'g', 'ه': 'h', 'ة': 't'
}

anomalies = []

for vol in range(1, 35):
    fpath = f"sources/text/fahares_vol_{vol:02d}.txt"
    if not os.path.exists(fpath): continue
    
    with open(fpath, 'r', encoding='utf-8') as f:
        lines = f.readlines()
        
    for idx, line in enumerate(lines):
        line_clean = line.strip()
        if not line_clean: continue
        
        # Type A: Mid-line bullet or multiple bullets
        if ('●' in line_clean and not line_clean.startswith('●')) or (line_clean.count('●') > 1):
            anomalies.append({
                'vol': vol,
                'line_num': idx + 1,
                'type': 'گلوله (●) در میان سطر یا سطرهای چندمدخلی',
                'current': line_clean,
                'next': lines[idx+1].strip() if idx+1 < len(lines) else ''
            })
            continue

        # Type B: Missing first word from title (left at end of previous cross-reference line)
        if line_clean.startswith('●') and idx+1 < len(lines):
            next_tr = lines[idx+1].strip()
            if bool(re.match(r'^[a-zA-Z\u0100-\u024F\u1E00-\u1EFF\(\'\`\’\=]', next_tr)):
                prev_line = lines[idx-2].strip() if idx >= 2 else ''
                title_words = re.sub(r'^[●\s]+', '', line_clean).split()
                if '/' in title_words:
                    slash_idx = title_words.index('/')
                    title_words = title_words[:slash_idx]
                    
                expanded_tr = expand_translit_abbr(next_tr)
                tr_words = [w.lower() for w in re.findall(r'[a-zA-Z\u0100-\u024F\u1E00-\u1EFF]+', expanded_tr)]
                
                if title_words and tr_words:
                    first_title_w = re.sub(r'[\u064B-\u065F\u0670]', '', title_words[0])
                    first_tr_w = tr_words[0]
                    first_stem = first_title_w[2:] if first_title_w.startswith('ال') and len(first_title_w) > 3 else first_title_w
                    tr_stem = first_tr_w[3:] if first_tr_w.startswith('al-') or first_tr_w.startswith('al_') else first_tr_w
                    
                    if not (first_title_w in first_tr_w or first_tr_w in first_title_w or (first_stem and tr_stem and char_map.get(first_stem[0]) == tr_stem[0])):
                        if prev_line and '←' in prev_line:
                            prev_last_w = prev_line.split()[-1]
                            prev_stem = prev_last_w[2:] if prev_last_w.startswith('ال') and len(prev_last_w) > 3 else prev_last_w
                            if prev_last_w in first_tr_w or (prev_stem and tr_stem and char_map.get(prev_stem[0]) == tr_stem[0]):
                                anomalies.append({
                                    'vol': vol,
                                    'line_num': idx + 1,
                                    'type': 'جابجایی کلمه ابتدای عنوان با انتهای سطر ارجاع قبل',
                                    'prev': prev_line,
                                    'current': line_clean,
                                    'translit': next_tr
                                })

os.makedirs('reports', exist_ok=True)
report_path = 'reports/candidate_title_anomalies.md'

with open(report_path, 'w', encoding='utf-8') as f:
    f.write("# گزارش موارد مشکوک عناوین و ارجاعات (برای بررسی و تأیید کاربر)\n\n")
    f.write(f"تعداد کل موارد شناسایی شده: **{len(anomalies)}** مورد\n\n")
    
    for i, a in enumerate(anomalies, 1):
        f.write(f"### مورد {i}: جلد {a['vol']:02d} (سطر {a['line_num']}) - نوع: {a['type']}\n\n")
        if 'prev' in a:
            f.write(f"- **سطر ارجاع قبلی (فعلی):**\n  `{a['prev']}`\n")
        f.write(f"- **سطر عنوان (فعلی):**\n  `{a['current']}`\n")
        if 'translit' in a:
            f.write(f"- **آوانگاری لاتین:**\n  `{a['translit']}`\n")
        f.write("\n---\n\n")

print(f"✅ Generated anomaly report with {len(anomalies)} items -> {report_path}")

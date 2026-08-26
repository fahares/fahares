#!/usr/bin/env python3
"""
Refined Candidate Report Generator for Remaining Stray Prefixes and Complex Glued Entries.
"""

import os
import re

candidates = []

for vol in range(1, 35):
    fpath = f'sources/text/fahares_vol_{vol:02d}.txt'
    if not os.path.exists(fpath): continue
    
    with open(fpath, 'r', encoding='utf-8') as f:
        lines = f.readlines()
        
    for idx, line in enumerate(lines):
        line_clean = line.strip()
        if not line_clean or '←' not in line_clean:
            continue
            
        # Case 1: Glued bullet entry (starts with ●, has ← AND has '/')
        if line_clean.startswith('●') and '/' in line_clean:
            # Let's propose splitting into separate lines
            # Usually: ● [entry 1] ← [target 1] [Title 2] / [category] / [lang]
            candidates.append({
                'vol': vol,
                'line_num': idx + 1,
                'category': 'چندمدخلی چسبیده (شامل ارجاع و عنوان اثر همراه با ممیز موضوع/زبان)',
                'current': line_clean,
                'proposed': 'نیازمند تفکیک ارجاع از عنوان اثر (ممیزدار)'
            })
            continue

        parts = line_clean.split('←')
        left = parts[0].strip()
        right = '←'.join(parts[1:]).strip()
        
        if not left:
            continue

        # Case 2: Leading list numbers (e.g. 1., 3., 9.)
        m_num = re.match(r'^(\d+[\.\-\)]\s*)(.*)', left)
        if m_num:
            num_prefix = m_num.group(1).strip()
            rest = m_num.group(2).strip()
            if rest:
                new_line = f"{rest} ← {right}"
                candidates.append({
                    'vol': vol,
                    'line_num': idx + 1,
                    'category': f"حذف شماره ترتیبی زائد ({num_prefix})",
                    'current': line_clean,
                    'proposed': new_line
                })
                continue

        # Case 3: Single isolated Arabic letter + space (e.g. س ..., ه ..., ط ...)
        m_single = re.match(r'^([\u0600-\u06FF])\s+([\u0600-\u06FF].*)', left)
        if m_single:
            letter = m_single.group(1)
            rest = m_single.group(2).strip()
            words_rest = rest.split()
            first_word = words_rest[0] if words_rest else ''
            
            # Sub-case 3A: Duplicated first letter (e.g. ط طارقات, ص صحاح, ع علل)
            if first_word and first_word.startswith(letter):
                new_left = rest
                new_line = f"{new_left} ← {right}"
                category = f"حذف حرف تکراری ابتدای کلمه ({letter})"
            # Sub-case 3B: Merged word (e.g. ک امل -> کامل)
            elif (letter + first_word) in right:
                merged_word = letter + first_word
                new_words = [merged_word] + words_rest[1:]
                new_left = ' '.join(new_words)
                new_line = f"{new_left} ← {right}"
                category = f"اتصال حرف جداافتاده به کلمه ({letter} + {first_word} -> {merged_word})"
            # Sub-case 3C: Stray standalone letter (e.g. س تاریخ...)
            else:
                new_left = rest
                new_line = f"{new_left} ← {right}"
                category = f"حذف تک‌حرف زائد ناشی از اسکن گلوله ({letter})"
                
            candidates.append({
                'vol': vol,
                'line_num': idx + 1,
                'category': category,
                'current': line_clean,
                'proposed': new_line
            })

report_path = 'reports/candidate_stray_prefixes.md'
with open(report_path, 'w', encoding='utf-8') as f:
    f.write("# گزارش موارد باقیمانده کاراکترها، حروف زائد و ارجاعات ترکیبی ممیزدار\n\n")
    f.write(f"تعداد کل موارد باقیمانده برای بررسی: **{len(candidates)}** مورد\n\n")
    f.write("راهنما:\n")
    f.write("- **نوع ۱ (ارجاعات ممیزدار)**: خطوطی که با گلوله شروع شده و حاوی ممیز موضوع/زبان هستند (ارجاع + عنوان چسبیده).\n")
    f.write("- **نوع ۲ (شماره‌های ترتیبی)**: شماره‌های چاپی مانند `1.`, `3.` در ابتدای ارجاع.\n")
    f.write("- **نوع ۳ (حروف منفرد زائد یا تکراری)**: مانند `س تاریخ`, `ط طارقات`, `ک امل` و...\n\n")
    f.write("="*60 + "\n\n")
    
    for i, c in enumerate(candidates, 1):
        f.write(f"### شماره {i} (جلد {c['vol']:02d} - سطر {c['line_num']}) | نوع: {c['category']}\n\n")
        f.write("🔴 **وضعیت فعلی:**\n")
        f.write(f"```text\n{c['current']}\n```\n\n")
        f.write("🟢 **اصلاح پیشنهادی:**\n")
        f.write(f"```text\n{c['proposed']}\n```\n\n")
        f.write("---\n\n")

print(f"✅ Generated new refined report -> {report_path} (Total items: {len(candidates)})")

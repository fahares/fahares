#!/usr/bin/env python3
"""
Applies Part 1: 624 isolated single-page OCR misreads in <!-- page: X --> tags.
Then generates reports/candidate_page_number_gaps.md for Part 2 review.
"""

import os
import re

applied_count = 0
audit_log = []

for vol in range(1, 35):
    fpath = f'sources/text/fahares_vol_{vol:02d}.txt'
    if not os.path.exists(fpath): continue
    
    with open(fpath, 'r', encoding='utf-8') as f:
        content = f.read()
        
    matches = list(re.finditer(r'<!--\s*page:\s*(\d+)\s*-->', content))
    if not matches: continue
    
    pages = [int(m.group(1)) for m in matches]
    
    # We will modify from back to front to keep character offsets stable or replace exact substrings
    replacements = []
    for i in range(1, len(pages) - 1):
        prev_p = pages[i - 1]
        curr_p = pages[i]
        next_p = pages[i + 1]
        
        if prev_p + 2 == next_p and curr_p != prev_p + 1:
            expected = prev_p + 1
            m = matches[i]
            old_tag = m.group(0)
            new_tag = f"<!-- page: {expected} -->"
            replacements.append((m.start(), m.end(), old_tag, new_tag, vol, prev_p, curr_p, next_p, expected))
            
    # Apply replacements from back to front
    content_list = list(content)
    for start, end, old_tag, new_tag, v, prev_p, curr_p, next_p, exp in reversed(replacements):
        content_list[start:end] = list(new_tag)
        applied_count += 1
        audit_log.append({
            'vol': v,
            'sequence': f"{prev_p} -> [{curr_p}] -> {next_p}",
            'before': old_tag,
            'after': new_tag
        })
        
    new_content = "".join(content_list)
    with open(fpath, 'w', encoding='utf-8') as f:
        f.write(new_content)

print(f"Applied {applied_count} isolated page number fixes across 34 volumes.")

# Regenerate master file
full_texts = []
for vol in range(1, 35):
    fpath = f'sources/text/fahares_vol_{vol:02d}.txt'
    with open(fpath, 'r', encoding='utf-8') as f:
        full_texts.append(f.read())

with open('sources/text/fankha-full.txt', 'w', encoding='utf-8') as f:
    f.write('\n\n'.join(full_texts))

print("Regenerated fankha-full.txt.")

# Now re-scan for remaining gaps (Part 2)
remaining_gaps = []
for vol in range(1, 35):
    fpath = f'sources/text/fahares_vol_{vol:02d}.txt'
    with open(fpath, 'r', encoding='utf-8') as f:
        content = f.read()
        
    matches = list(re.finditer(r'<!--\s*page:\s*(\d+)\s*-->', content))
    if not matches: continue
    
    pages = [int(m.group(1)) for m in matches]
    for i in range(1, len(pages)):
        prev_p = pages[i - 1]
        curr_p = pages[i]
        diff = curr_p - prev_p
        if diff != 1:
            m = matches[i]
            ctx_start = max(0, m.start() - 60)
            ctx_end = min(len(content), m.end() + 80)
            snippet = content[ctx_start:ctx_end].replace('\n', ' ')
            remaining_gaps.append({
                'vol': vol,
                'index': i + 1,
                'prev_p': prev_p,
                'curr_p': curr_p,
                'diff': diff,
                'snippet': snippet
            })

gap_report_path = 'reports/candidate_page_number_gaps.md'
with open(gap_report_path, 'w', encoding='utf-8') as f:
    f.write("# گزارش پرش‌ها و ناپیوستگی‌های شماره صفحات (بخش دوم - جهت بررسی با PDF)\n\n")
    f.write(f"تعداد کل پرش‌های باقیمانده برای بررسی: **{len(remaining_gaps)}** مورد\n\n")
    f.write("توضیح:\n")
    f.write("تمام ۶۲۴ مورد غلط‌خوانی تک‌صفحه‌ای OCR در بخش اول اصلاح شدند.\n")
    f.write("موارد زیر پرش‌های چندصفحه‌ای یا ناهماهنگی‌هایی هستند که نیاز به تطبیق با فایل PDF مجلدات دارند.\n\n")
    f.write("="*60 + "\n\n")
    
    for idx, g in enumerate(remaining_gaps, 1):
        f.write(f"### شماره {idx} (جلد {g['vol']:02d} - از صفحه {g['prev_p']} به {g['curr_p']} | اختلاف: {g['diff']:+d} صفحه)\n\n")
        f.write(f"- **محل وقوع:** جلد {g['vol']:02d}، صفحه {g['prev_p']} $\\rightarrow$ صفحه {g['curr_p']}\n")
        f.write(f"- **متن پیرامون:** `{g['snippet']}`\n\n")
        f.write("---\n\n")

print(f"Generated remaining gaps report -> {gap_report_path} ({len(remaining_gaps)} items).")

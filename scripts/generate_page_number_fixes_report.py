#!/usr/bin/env python3
"""
Generate Precision Two-Part Page Number Discrepancy Report:
Part 1: Isolated OCR Misreads (where prev+2 == next, e.g. 156 -> [152] -> 158 => proposed 157).
Part 2: Real Multi-page Jumps / Gaps (for user PDF verification).
Output: reports/candidate_page_number_fixes.md
"""

import os
import re

isolated_fixes = []
structural_gaps = []

for vol in range(1, 35):
    fpath = f'sources/text/fahares_vol_{vol:02d}.txt'
    if not os.path.exists(fpath): continue
    
    with open(fpath, 'r', encoding='utf-8') as f:
        content = f.read()
        
    matches = list(re.finditer(r'<!--\s*page:\s*(\d+)\s*-->', content))
    if not matches: continue
    
    pages = [int(m.group(1)) for m in matches]
    
    # 1. Detect isolated single-page misreads
    for i in range(1, len(pages) - 1):
        prev_p = pages[i - 1]
        curr_p = pages[i]
        next_p = pages[i + 1]
        
        if prev_p + 2 == next_p and curr_p != prev_p + 1:
            expected = prev_p + 1
            m = matches[i]
            ctx_start = max(0, m.start() - 50)
            ctx_end = min(len(content), m.end() + 60)
            snippet = content[ctx_start:ctx_end].replace('\n', ' ')
            
            isolated_fixes.append({
                'vol': vol,
                'page_index': i + 1,
                'prev_p': prev_p,
                'curr_p': curr_p,
                'next_p': next_p,
                'expected': expected,
                'curr_tag': f"<!-- page: {curr_p} -->",
                'prop_tag': f"<!-- page: {expected} -->",
                'snippet': snippet
            })
            
    # 2. Detect non-isolated gaps
    for i in range(1, len(pages)):
        prev_p = pages[i - 1]
        curr_p = pages[i]
        diff = curr_p - prev_p
        
        if diff != 1:
            # Check if part of an isolated misread
            is_isolated = False
            if i < len(pages) - 1 and pages[i - 1] + 2 == pages[i + 1]:
                is_isolated = True
            elif i > 1 and pages[i - 2] + 2 == pages[i]:
                is_isolated = True
                
            if not is_isolated:
                structural_gaps.append({
                    'vol': vol,
                    'prev_p': prev_p,
                    'curr_p': curr_p,
                    'diff': diff
                })

report_path = 'reports/candidate_page_number_fixes.md'
with open(report_path, 'w', encoding='utf-8') as f:
    f.write("# گزارش موارد اصلاح شماره صفحات (برچسب‌های <!-- page: X -->)\n\n")
    f.write(f"- تعداد غلط‌خوانی‌های تک‌صفحه‌ای OCR (بخش اول): **{len(isolated_fixes)}** مورد\n")
    f.write(f"- تعداد پرش‌ها و ناپیوستگی‌های چندصفحه‌ای (بخش دوم): **{len(structural_gaps)}** مورد\n\n")
    f.write("="*60 + "\n\n")
    
    f.write("## بخش اول: غلط‌خوانی‌های تک‌صفحه‌ای OCR (کاملاً قطعی و مشخص)\n")
    f.write("در این موارد، صفحه قبل و بعد کاملاً متوالی هستند (مثلاً ۱۵۶ و ۱۵۸) و تنها عدد صفحه میانی به دلیل شباهت ارقام اشتباه خوانده شده است.\n\n")
    
    for idx, c in enumerate(isolated_fixes, 1):
        f.write(f"### شماره {idx} (جلد {c['vol']:02d} - توالی: {c['prev_p']} $\\rightarrow$ **[{c['curr_p']}]** $\\rightarrow$ {c['next_p']})\n\n")
        f.write(f"- **برچسب فعلی:** `{c['curr_tag']}`\n")
        f.write(f"- **اصلاح پیشنهادی:** `{c['prop_tag']}`\n")
        f.write(f"- **متن پیرامون:** `{c['snippet']}`\n\n")
        f.write("---\n\n")
        
    f.write("\n" + "="*60 + "\n\n")
    f.write("## بخش دوم: پرش‌ها و ناپیوستگی‌های چندصفحه‌ای (جهت بررسی با PDF)\n\n")
    for idx, g in enumerate(structural_gaps, 1):
        f.write(f"{idx}. **جلد {g['vol']:02d}:** پرش از صفحه `{g['prev_p']}` به `{g['curr_p']}` (اختلاف: `{g['diff']:+d}` صفحه)\n")

print(f"✅ Generated two-part report -> {report_path} (Isolated: {len(isolated_fixes)}, Gaps: {len(structural_gaps)})")

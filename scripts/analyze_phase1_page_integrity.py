#!/usr/bin/env python3
"""
Phase 1 Analyzer: Page-Level Integrity, Missing/Empty Pages, and Length Outliers.
Scans all 34 volumes and outputs summary report.
"""

import os
import re
from statistics import mean, median

print("=== Phase 1: Macro & Page-Level Integrity Scan ===")

all_volume_stats = []
empty_pages = []
short_pages = []
page_sequence_anomalies = []

for vol in range(1, 35):
    fpath = f'sources/text/fahares_vol_{vol:02d}.txt'
    if not os.path.exists(fpath): continue
    
    with open(fpath, 'r', encoding='utf-8') as f:
        content = f.read()
        
    # Find all page markers <!-- page: X -->
    matches = list(re.finditer(r'<!--\s*page:\s*(\d+)\s*-->', content))
    
    if not matches:
        page_sequence_anomalies.append({
            'vol': vol,
            'issue': 'هیچ برچسب صفحه‌ای در این جلد یافت نشد!'
        })
        continue
        
    page_numbers = [int(m.group(1)) for m in matches]
    
    # Check sequence
    start_p = page_numbers[0]
    end_p = page_numbers[-1]
    
    # Check for duplicates or non-monotonic sequences
    seen = set()
    duplicates = []
    gaps = []
    
    for i, p in enumerate(page_numbers):
        if p in seen:
            duplicates.append(p)
        seen.add(p)
        if i > 0 and p != page_numbers[i-1] + 1:
            gaps.append((page_numbers[i-1], p))
            
    # Page text length extraction
    page_lengths = []
    for i in range(len(matches)):
        start_idx = matches[i].end()
        end_idx = matches[i+1].start() if i+1 < len(matches) else len(content)
        page_txt = content[start_idx:end_idx].strip()
        char_len = len(page_txt)
        lines_cnt = len(page_txt.split('\n')) if page_txt else 0
        p_num = page_numbers[i]
        
        page_lengths.append(char_len)
        
        if char_len == 0:
            empty_pages.append({
                'vol': vol,
                'page': p_num
            })
        elif char_len < 250:
            # Abnormally short (less than 250 chars, typical is 1500-2500)
            # Exclude last page of volume if it's naturally short
            if i < len(matches) - 1:
                short_pages.append({
                    'vol': vol,
                    'page': p_num,
                    'char_len': char_len,
                    'lines_cnt': lines_cnt,
                    'sample': page_txt[:100].replace('\n', ' ')
                })
                
    med_len = median(page_lengths) if page_lengths else 0
    all_volume_stats.append({
        'vol': vol,
        'page_count': len(page_numbers),
        'start_page': start_p,
        'end_page': end_p,
        'duplicates': duplicates,
        'gaps': gaps,
        'median_char_len': med_len
    })

print("\n--- 1. بررسی توالی و شماره صفحات در ۳۴ جلد ---")
total_gaps = 0
total_dups = 0
for v in all_volume_stats:
    gap_str = f"شکاف/پرش: {v['gaps']}" if v['gaps'] else "توالی بدون پرش"
    dup_str = f"تکراری: {v['duplicates']}" if v['duplicates'] else "بدون تکرار"
    if v['gaps'] or v['duplicates']:
        print(f"جلد {v['vol']:02d}: صفحات {v['start_page']} تا {v['end_page']} ({v['page_count']} صفحه) | {gap_str} | {dup_str}")
        total_gaps += len(v['gaps'])
        total_dups += len(v['duplicates'])

if total_gaps == 0 and total_dups == 0:
    print("✅ توالی شماره صفحات در تمام ۳۴ جلد کاملاً یکنواخت، صعودی و بدون هیچ تکرار یا پرشی است.")

print(f"\n--- 2. صفحات خالی (Empty Pages): {len(empty_pages)} مورد ---")
for ep in empty_pages:
    print(f"جلد {ep['vol']:02d} - صفحه {ep['page']}")

print(f"\n--- 3. صفحات با حجم متن بسیار اندک (زیر ۲۵۰ کاراکتر - به جز صفحه آخر): {len(short_pages)} مورد ---")
for sp in short_pages[:20]:
    print(f"جلد {sp['vol']:02d} - صفحه {sp['page']} (طول: {sp['char_len']} کاراکتر، {sp['lines_cnt']} سطر) | نمونه: {sp['sample']}")


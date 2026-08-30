#!/usr/bin/env python3
"""
Scans all 34 volumes to find ANY remaining page number sequence anomalies,
duplicates, gaps, or misordered pages.
"""

import os
import re

total_anomalies = []

for vol in range(1, 35):
    fpath = f'sources/text/fahares_vol_{vol:02d}.txt'
    if not os.path.exists(fpath): continue
    
    with open(fpath, 'r', encoding='utf-8') as f:
        content = f.read()
        
    matches = list(re.finditer(r'<!--\s*page:\s*(\d+)\s*-->', content))
    if not matches:
        total_anomalies.append({
            'vol': vol,
            'type': 'بدون برچسب صفحه',
            'desc': 'هیچ برچسب صفحه‌ای در این جلد یافت نشد.'
        })
        continue
        
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
            
            total_anomalies.append({
                'vol': vol,
                'index': i + 1,
                'prev_p': prev_p,
                'curr_p': curr_p,
                'diff': diff,
                'snippet': snippet
            })

print(f"=== Remaining Page Sequence Anomalies Scan ===")
print(f"Total anomalies found: {len(total_anomalies)}")

for idx, a in enumerate(total_anomalies, 1):
    diff_sign = f"+{a['diff']}" if a['diff'] > 0 else f"{a['diff']}"
    print(f"{idx}. **جلد {a['vol']:02d}:** پرش از صفحه `{a['prev_p']}` به `{a['curr_p']}` (اختلاف: `{diff_sign}` صفحه)")
    print(f"   متن: {a['snippet'][:120]}")

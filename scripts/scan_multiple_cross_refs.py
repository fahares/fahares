#!/usr/bin/env python3
"""
Scan for lines containing more than one '←' across all 34 volumes.
"""

import os
import re

multi_refs = []

for vol in range(1, 35):
    fpath = f'sources/text/fahares_vol_{vol:02d}.txt'
    if not os.path.exists(fpath): continue
    
    with open(fpath, 'r', encoding='utf-8') as f:
        lines = f.readlines()
        
    for l_idx, line in enumerate(lines, 1):
        clean_line = line.strip()
        count = clean_line.count('←')
        if count > 1:
            multi_refs.append({
                'vol': vol,
                'line_num': l_idx,
                'count': count,
                'text': clean_line
            })

print(f"=== Multi-Cross-Reference Scan ===")
print(f"Total lines with >1 '←': {len(multi_refs)}")

for idx, m in enumerate(multi_refs, 1):
    print(f"{idx}. جلد {m['vol']:02d} (سطر {m['line_num']}) [{m['count']} ارجاع]:")
    print(f"   {m['text']}")

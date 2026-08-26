#!/usr/bin/env python3
"""
Automated Cleanup of Pure Stray OCR Symbols and Bullets without Slashes.
Preserves all lines where bullet (●) might belong to a glued main entry (lines containing '/').
Regenerates candidate report at reports/candidate_stray_prefixes.md.
"""

import os
import re

PURE_SYMBOLS_REGEX = r'^[↪☞✦✍↵↔■❦♣✎ـ«»=\"\'`‘*~^><_•\u200c\u200f\ufeff]+\s*'

removed_count = 0
audit_log = []

for vol in range(1, 35):
    fpath = f'sources/text/fahares_vol_{vol:02d}.txt'
    if not os.path.exists(fpath): continue
    
    with open(fpath, 'r', encoding='utf-8') as f:
        lines = f.readlines()
        
    new_lines = []
    for idx, line in enumerate(lines):
        line_clean = line.strip()
        
        # Check if cross-reference line
        if '←' in line_clean:
            # Case 1: Non-bullet symbols (always safe to remove)
            if re.match(PURE_SYMBOLS_REGEX, line_clean):
                cleaned = re.sub(PURE_SYMBOLS_REGEX, '', line_clean).strip()
                audit_log.append({
                    'vol': vol,
                    'line_num': idx + 1,
                    'type': 'pure_symbol',
                    'before': line_clean,
                    'after': cleaned
                })
                removed_count += 1
                new_lines.append(cleaned + '\n')
                continue
                
            # Case 2: Bullet (●) in cross-reference WITHOUT slash '/'
            if line_clean.startswith('●') and '/' not in line_clean:
                cleaned = re.sub(r'^[●\s]+', '', line_clean).strip()
                audit_log.append({
                    'vol': vol,
                    'line_num': idx + 1,
                    'type': 'pure_bullet_no_slash',
                    'before': line_clean,
                    'after': cleaned
                })
                removed_count += 1
                new_lines.append(cleaned + '\n')
                continue

        new_lines.append(line)
        
    with open(fpath, 'w', encoding='utf-8') as f:
        f.writelines(new_lines)

# Regenerate master full text
full_texts = []
for vol in range(1, 35):
    with open(f'sources/text/fahares_vol_{vol:02d}.txt', 'r', encoding='utf-8') as f:
        full_texts.append(f.read())

with open('sources/text/fankha-full.txt', 'w', encoding='utf-8') as f:
    f.write('\n\n'.join(full_texts))

print(f"✅ Successfully cleaned {removed_count} pure stray symbols and unglued bullets across all volumes.")

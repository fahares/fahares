#!/usr/bin/env python3
import os
import re

total_fixed = 0
audit_log = []

for vol in range(1, 35):
    fpath = f'sources/text/fahares_vol_{vol:02d}.txt'
    if not os.path.exists(fpath): continue
    
    with open(fpath, 'r', encoding='utf-8') as f:
        content = f.read()
        
    matches = list(re.finditer(r'<!--\s*page:\s*(\d+)\s*-->', content))
    if not matches: continue
    pages = [int(m.group(1)) for m in matches]
    
    replacements = []
    i = 0
    while i < len(pages):
        if i + 1 < len(pages) and pages[i + 1] != pages[i] + 1:
            start_p = pages[i]
            recovered = False
            for span_len in range(2, 20):
                j = i + span_len
                if j < len(pages):
                    end_p = pages[j]
                    if end_p - start_p == span_len:
                        print(f"Vol {vol:02d}: Anchor match index {i} (p:{start_p}) to {j} (p:{end_p}) of len {span_len}")
                        for k in range(1, span_len):
                            target_idx = i + k
                            expected_p = start_p + k
                            actual_p = pages[target_idx]
                            m = matches[target_idx]
                            old_tag = m.group(0)
                            new_tag = f"<!-- page: {expected_p} -->"
                            replacements.append((m.start(), m.end(), old_tag, new_tag, vol, actual_p, expected_p))
                        i = j
                        recovered = True
                        break
            if not recovered:
                i += 1
        else:
            i += 1
            
    if replacements:
        content_list = list(content)
        for start, end, old_tag, new_tag, v, act_p, exp_p in reversed(replacements):
            content_list[start:end] = list(new_tag)
            total_fixed += 1
            audit_log.append((v, act_p, exp_p))
        new_content = "".join(content_list)
        with open(fpath, 'w', encoding='utf-8') as f:
            f.write(new_content)

print(f"\nApplied {total_fixed} clear page number interpolations.")

# Regenerate master
full_texts = []
for vol in range(1, 35):
    fpath = f'sources/text/fahares_vol_{vol:02d}.txt'
    with open(fpath, 'r', encoding='utf-8') as f:
        full_texts.append(f.read())

with open('sources/text/fankha-full.txt', 'w', encoding='utf-8') as f:
    f.write('\n\n'.join(full_texts))

print("Regenerated fankha-full.txt.")

import sys
import os
import re

sys.path.append('.')
from scripts.batch_3_definitions import PAGE_RECONSTRUCTIONS

target_file = 'sources/text/fahares_vol_01.txt'

with open(target_file, 'r', encoding='utf-8') as f:
    text = f.read()

orig_tags = re.findall(r'<!--\s*page:\s*(\d+)\s*-->', text)
print(f"Original file has {len(orig_tags)} page tags.")

applied_count = 0
for p in sorted(PAGE_RECONSTRUCTIONS.keys()):
    rec_content = PAGE_RECONSTRUCTIONS[p].strip()
    pat = rf'(<!--\s*page:\s*{p}\s*-->)([\s\S]*?)(<!--\s*page:\s*{p+1}\s*-->)'
    m = re.search(pat, text)
    if not m:
        print(f"WARNING: Could not find exact boundaries for Page {p}!")
    else:
        replacement = r'\1' + '\n' + rec_content + '\n\n' + r'\3'
        text = text[:m.start()] + re.sub(pat, replacement, text[m.start():m.end()]) + text[m.end():]
        applied_count += 1
        print(f"Applied reconstruction for Page {p}.")

new_tags = re.findall(r'<!--\s*page:\s*(\d+)\s*-->', text)
print(f"After replacement, file has {len(new_tags)} page tags.")

if orig_tags == new_tags:
    print(f"INTEGRITY CHECK PASSED: All {len(new_tags)} page tags perfectly preserved!")
    with open(target_file, 'w', encoding='utf-8') as f:
        f.write(text)
    print(f"Successfully written changes to {target_file} for {applied_count} pages in Batch 3.")
else:
    print("FATAL ERROR: Page tags mismatch! Aborting write.")
    sys.exit(1)

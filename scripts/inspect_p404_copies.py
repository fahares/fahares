import json
import re

with open('reports/batch_15_extracted_raw.json') as f:
    d = json.load(f)

for b in d['404']['pdf_blocks']:
    txt = " ".join(b['text'].split())
    # Match numbers followed by dot at beginning of block
    m = re.match(r'^([۰-۹\d]+)\s*\.\s*(.*)', txt)
    if m:
        print(f"B{b['block_id']:02d} [{b['bbox'][0]:.1f}, {b['bbox'][1]:.1f}]: num={m.group(1)} text={m.group(2)[:60]}")

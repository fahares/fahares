import json

with open('reports/batch_15_extracted_raw.json') as f:
    d = json.load(f)

for b in d['398']['pdf_blocks'][:15]:
    txt = " ".join(b['text'].split())
    print(f"B{b['block_id']:02d} {b['bbox']}: {txt}")

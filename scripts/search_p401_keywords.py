import json

with open('reports/batch_15_extracted_raw.json') as f:
    d = json.load(f)

for b in d['401']['pdf_blocks']:
    if 'رساله' in b['text'] or 'همانند' in b['text'] or 'موضوع' in b['text']:
        print(f"B{b['block_id']:02d} {b['bbox']}: {b['text']}")

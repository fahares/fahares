import json

with open('reports/batch_15_extracted_raw.json') as f:
    d = json.load(f)

for b in d['397']['pdf_blocks']:
    print(f"B{b['block_id']:02d} {b['bbox']}: {b['text']}\n---")

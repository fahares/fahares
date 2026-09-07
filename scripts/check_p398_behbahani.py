import json

with open('reports/batch_15_extracted_raw.json') as f:
    d = json.load(f)

for b in d['398']['pdf_blocks'][16:21]:
    print(f"B{b['block_id']}: {repr(b['text'])}")

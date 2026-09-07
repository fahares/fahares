import json

with open('reports/batch_15_extracted_raw.json') as f:
    d = json.load(f)

for b in d['405']['pdf_blocks'][32:49]:
    print(f"B{b['block_id']}: {repr(b['text'])}")

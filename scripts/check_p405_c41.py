import json

with open('reports/batch_15_extracted_raw.json') as f:
    d = json.load(f)

for b in d['405']['pdf_blocks']:
    txt = " ".join(b['text'].split())
    if "۴۱" in txt or "1279" in txt or "۶۵۸۸" in txt or "۴۲" in txt:
        print(f"B{b['block_id']}: {txt}")

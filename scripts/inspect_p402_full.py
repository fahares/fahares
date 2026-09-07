import json

with open('reports/batch_15_extracted_raw.json') as f:
    d = json.load(f)

print("=== PAGE 402 CURRENT TEXT ===")
print(d['402']['current_text'])

print("\n=== PAGE 402 PDF BLOCKS ===")
for b in d['402']['pdf_blocks']:
    txt = " ".join(b['text'].split())
    if txt:
        print(f"B{b['block_id']:02d} {b['bbox']}: {txt}")

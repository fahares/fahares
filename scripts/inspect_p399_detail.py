import json

with open('reports/batch_15_extracted_raw.json') as f:
    d = json.load(f)

print("=== PAGE 399 CURRENT TEXT ===")
print(d['399']['current_text'])

print("\n=== PAGE 399 PDF BLOCKS ===")
for b in d['399']['pdf_blocks']:
    txt = " ".join(b['text'].split())
    if txt:
        print(f"B{b['block_id']:02d} {b['bbox']}: {txt[:100]}")

import json

with open('reports/batch_15_extracted_raw.json') as f:
    d = json.load(f)

print("=== PAGE 405 PDF BLOCKS ===")
for b in d['405']['pdf_blocks']:
    txt = " ".join(b['text'].split())
    if txt and not (b['bbox'][1] < 45 or b['bbox'][3] > 555):
        print(f"B{b['block_id']:02d} [{b['bbox'][0]:.1f}, {b['bbox'][1]:.1f}, {b['bbox'][2]:.1f}, {b['bbox'][3]:.1f}]: {txt}")

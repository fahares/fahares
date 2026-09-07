import json

with open('reports/batch_14_extracted_raw.json') as f:
    data = json.load(f)

p396 = data['396']
print("=== PAGE 396 ALL BLOCKS ===")
for b in p396['pdf_blocks']:
    txt = b['text'].strip()
    if txt:
        # replace internal newlines with space
        one_line = " ".join(txt.split())
        print(f"B{b['block_id']:02d} [{b['bbox'][0]:.1f}, {b['bbox'][1]:.1f}, {b['bbox'][2]:.1f}, {b['bbox'][3]:.1f}]: {one_line[:120]}")

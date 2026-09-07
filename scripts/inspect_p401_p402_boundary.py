import json

with open('reports/batch_15_extracted_raw.json') as f:
    d = json.load(f)

print("=== P401 BLOCKS 75 TO END ===")
for b in d['401']['pdf_blocks'][75:]:
    txt = " ".join(b['text'].split())
    print(f"B{b['block_id']:02d} {b['bbox']}: {txt}")

print("\n=== P402 BLOCKS 0 TO 15 ===")
for b in d['402']['pdf_blocks'][:15]:
    txt = " ".join(b['text'].split())
    print(f"B{b['block_id']:02d} {b['bbox']}: {txt}")

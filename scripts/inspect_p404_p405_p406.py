import json

with open('reports/batch_15_extracted_raw.json') as f:
    d = json.load(f)

for p in [404, 405, 406]:
    print(f"==================================================")
    print(f"PAGE {p} CURRENT TEXT")
    print(f"==================================================")
    print(d[str(p)]['current_text'])

    print(f"\nPAGE {p} PDF BLOCKS:")
    for b in d[str(p)]['pdf_blocks']:
        txt = " ".join(b['text'].split())
        if txt:
            print(f"B{b['block_id']:02d} {b['bbox']}: {txt[:100]}")

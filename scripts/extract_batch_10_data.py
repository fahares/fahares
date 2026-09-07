import fitz
import json

doc = fitz.open('sources/pdf/parts/3.pdf')

pages_to_extract = [292, 294, 299, 305, 309, 318]
results = {}

for p in pages_to_extract:
    pdf_idx = p - 58
    page = doc[pdf_idx]
    
    blocks = page.get_text("blocks")
    
    right_blocks = [b for b in blocks if b[0] > 275 and 85 < b[1] < 770]
    left_blocks = [b for b in blocks if b[0] <= 275 and 85 < b[1] < 770]
    
    right_blocks.sort(key=lambda b: b[1])
    left_blocks.sort(key=lambda b: b[1])
    
    results[p] = {
        'right': [b[4] for b in right_blocks],
        'left': [b[4] for b in left_blocks],
        'raw_text': page.get_text("text")
    }

with open('reports/batch_10_extracted_raw.json', 'w', encoding='utf-8') as f:
    json.dump(results, f, ensure_ascii=False, indent=2)

print("Batch 10 raw extraction complete.")

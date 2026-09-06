import pymupdf
import json
import re

doc = pymupdf.open('sources/pdf/parts/3.pdf')

with open('sources/text/fahares_vol_02.txt', 'r', encoding='utf-8') as f:
    full_text = f.read()

pages_raw = re.split(r'(<!-- page: \d+ -->)', full_text)
text_pages = {}
for i in range(1, len(pages_raw), 2):
    p_num = int(re.search(r'\d+', pages_raw[i]).group(0))
    text_pages[p_num] = pages_raw[i+1]

target_pages = [390, 393, 394, 395, 396]

extracted_data = {}

for p in target_pages:
    pdf_idx = p - 58
    page = doc[pdf_idx]
    blocks = page.get_text("blocks")
    
    # Sort blocks: 2-column layout. Typically right column first (x > midpoint), then left column (x < midpoint)
    # Page width is typically around 595 pt, midpoint around 280-300 pt.
    # In PyMuPDF, x0 is left, y0 is top.
    # Right column in RTL page is usually x0 > 280. Left column is x0 < 280.
    
    blocks_data = []
    for idx, b in enumerate(blocks):
        blocks_data.append({
            'block_id': idx,
            'bbox': [round(v, 2) for v in b[:4]],
            'text': b[4].strip()
        })
    
    extracted_data[str(p)] = {
        'page': p,
        'pdf_idx': pdf_idx,
        'current_text': text_pages.get(p, ''),
        'pdf_blocks': blocks_data
    }

with open('reports/batch_14_extracted_raw.json', 'w', encoding='utf-8') as f:
    json.dump(extracted_data, f, ensure_ascii=False, indent=2)

print("Saved raw extraction to reports/batch_14_extracted_raw.json")

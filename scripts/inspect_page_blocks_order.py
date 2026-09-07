import json

with open('reports/batch_15_extracted_raw.json', 'r', encoding='utf-8') as f:
    data = json.load(f)

def inspect_page(p):
    p_str = str(p)
    p_data = data[p_str]
    blocks = p_data['pdf_blocks']
    
    # Filter out header / footer
    # Book page headers are at y < 55, page footers at y > 550
    content_blocks = []
    for b in blocks:
        bbox = b['bbox']
        # check if page number or running header
        txt = b['text'].strip()
        if not txt:
            continue
        if bbox[1] < 45 or bbox[3] > 555:
            # Header or footer
            continue
        if bbox[3] < 55 and (txt.isdigit() or 'فهرستگان' in txt or 'اختیارات' in txt):
            continue
        if bbox[1] > 540 and txt.isdigit():
            continue
        content_blocks.append(b)
        
    # Split into right column and left column
    # Page width is ~400..420. The column divide is around x = 205..215.
    # In Persian RTL: Right column is read FIRST (x > 210), then Left column (x < 210).
    # Wait, let's verify column divider for this page.
    right_col = [b for b in content_blocks if b['bbox'][0] >= 200]
    left_col = [b for b in content_blocks if b['bbox'][0] < 200]
    
    # Sort each column by y0 (top to bottom)
    right_col.sort(key=lambda b: (b['bbox'][1], b['bbox'][0]))
    left_col.sort(key=lambda b: (b['bbox'][1], b['bbox'][0]))
    
    print(f"================================================================")
    print(f"PAGE {p} (PDF index: {p_data['pdf_idx']})")
    print(f"================================================================")
    print(f"RIGHT COLUMN ({len(right_col)} blocks):")
    for b in right_col:
        print(f"  [{b['bbox'][0]:.1f}, {b['bbox'][1]:.1f}, {b['bbox'][2]:.1f}, {b['bbox'][3]:.1f}] B{b['block_id']:02d}: {b['text'][:100]!r}")
        
    print(f"\nLEFT COLUMN ({len(left_col)} blocks):")
    for b in left_col:
        print(f"  [{b['bbox'][0]:.1f}, {b['bbox'][1]:.1f}, {b['bbox'][2]:.1f}, {b['bbox'][3]:.1f}] B{b['block_id']:02d}: {b['text'][:100]!r}")

if __name__ == '__main__':
    import sys
    page_num = int(sys.argv[1]) if len(sys.argv) > 1 else 397
    inspect_page(page_num)

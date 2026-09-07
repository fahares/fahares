import re
import fitz

def get_current_page(page_num):
    with open('sources/text/fahares_vol_02.txt', 'r', encoding='utf-8') as f:
        content = f.read()
    
    pattern = rf'<!--\s*page:\s*{page_num}\s*-->(.*?)(?=<!--\s*page:\s*\d+\s*-->|$)'
    m = re.search(pattern, content, re.DOTALL)
    if m:
        return m.group(1).strip()
    return None

def inspect_pdf_page(book_page):
    pdf_idx = book_page + 442
    doc = fitz.open('sources/pdf/parts/2.pdf')
    page = doc[pdf_idx]
    
    print(f"==================================================")
    print(f"=== BOOK PAGE {book_page} (PDF INDEX {pdf_idx}) ===")
    print(f"==================================================")
    
    # Get raw text
    print("\n--- RAW TEXT FROM PDF ---")
    print(page.get_text('text'))
    
    # Get blocks
    print("\n--- TEXT BLOCKS (x0, y0, x1, y1, text, block_no) ---")
    blocks = page.get_text('blocks')
    for b in blocks:
        # b is (x0, y0, x1, y1, text, block_no, block_type)
        if b[6] == 0: # text block
            txt = b[4].strip().replace('\n', ' / ')
            print(f"Block {b[5]} [{b[0]:.1f}, {b[1]:.1f}, {b[2]:.1f}, {b[3]:.1f}]: {txt[:100]}...")

if __name__ == '__main__':
    for p in [7, 8, 38, 51]:
        print(f"\n##################################################")
        print(f"CURRENT IN TEXT: PAGE {p}")
        print(f"##################################################")
        cur = get_current_page(p)
        print(cur)
        inspect_pdf_page(p)

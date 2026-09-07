import fitz
import re

doc = fitz.open('sources/pdf/parts/2.pdf')

with open('sources/text/fahares_vol_02.txt', 'r', encoding='utf-8') as f:
    text = f.read()

pages = re.split(r'<!--\s*page:\s*(\d+)\s*-->', text)
txt_pages = {int(pages[i]): pages[i+1] for i in range(1, len(pages), 2)}

for p in [54, 56, 57]:
    print(f"\n==================================================")
    print(f"=== PAGE {p} (PDF idx {p + 442}) ===")
    print(f"==================================================")
    p_text = txt_pages.get(p, '')
    lines = [l for l in p_text.splitlines() if l.strip()]
    print("--- TXT SNIPPET (first 10 and last 10 lines) ---")
    for l in lines[:10]:
        print("  ", l[:80])
    print("   ...")
    for l in lines[-10:]:
        print("  ", l[:80])
    
    pdf_text = doc[p + 442].get_text('text')
    pdf_lines = [l.strip() for l in pdf_text.splitlines() if l.strip()]
    print("--- PDF SNIPPET ---")
    for l in pdf_lines[:15]:
        print("  ", l[:80])


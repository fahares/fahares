import fitz
import re

doc = fitz.open('sources/pdf/parts/2.pdf')

with open('sources/text/fahares_vol_02.txt', 'r', encoding='utf-8') as f:
    text = f.read()

pages = re.split(r'<!--\s*page:\s*(\d+)\s*-->', text)
txt_pages = {int(pages[i]): pages[i+1] for i in range(1, len(pages), 2)}

print("=== DEEP AUDIT OF PART 2 (PAGES 7 TO 57) ===")

already_fixed = {7, 8, 12, 13, 16, 19, 21, 24, 26, 29, 30, 38, 51, 53}

for p in range(7, 58):
    if p in already_fixed:
        continue
    p_text = txt_pages.get(p, '')
    pdf_text = doc[p + 442].get_text('text')
    
    issues = []
    
    # Check 1: Bullet count
    pdf_b = len(re.findall(r'[•●]', pdf_text))
    txt_b = len(re.findall(r'[•●]', p_text))
    if pdf_b != txt_b:
        issues.append(f"Bullet mismatch: PDF={pdf_b}, TXT={txt_b}")
        
    # Check 2: Empty shelfmarks
    lines = [l.strip() for l in p_text.splitlines() if l.strip()]
    empty_sm = []
    for idx, l in enumerate(lines[:-1]):
        next_l = lines[idx+1]
        if 'شماره نسخه:' in l and (re.match(r'^\d+\.', next_l) or next_l.startswith('●')):
            after_sn = l.split('شماره نسخه:', 1)[1].strip()
            if len(after_sn.split()) <= 2:
                empty_sm.append(l[:40])
    if empty_sm:
        issues.append(f"Empty shelfmarks ({len(empty_sm)}): {empty_sm}")
        
    # Check 3: Clustered 'آغاز:' lines (more than 1 آغاز before the next numbered entry or under single entry)
    aqaaz_count = len(re.findall(r'آغاز\s*:', p_text))
    entries_count = len(re.findall(r'(?m)^\d+\.', p_text))
    if aqaaz_count > 0 and entries_count > 0:
        # Check if any entry block has multiple آغاز
        parts = re.split(r'(?m)^\d+\.', p_text)
        for part in parts:
            if len(re.findall(r'آغاز\s*:', part)) > 1:
                issues.append(f"Multiple آغاز in single block! (Clustered descriptions)")
                break

    if issues:
        print(f"\n[PAGE {p}] DEFECTIVE:")
        for iss in issues:
            print(f"  - {iss}")
    else:
        # print clean
        pass


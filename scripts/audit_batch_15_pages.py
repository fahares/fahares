import json
import re

with open('reports/batch_15_extracted_raw.json') as f:
    data = json.load(f)

print("=== BATCH 15 (PAGES 397-406) COMPREHENSIVE AUDIT ===")

for p in range(397, 407):
    p_data = data[str(p)]
    txt = p_data['current_text']
    blocks = p_data['pdf_blocks']
    
    # Body blocks (exclude top page header)
    body = [b for b in blocks if b['bbox'][1] >= 95 and b['bbox'][3] <= 760 and b['text'].strip()]
    right_b = [b for b in body if b['bbox'][0] >= 275]
    left_b = [b for b in body if b['bbox'][0] < 275]
    
    # Titles in PDF
    pdf_titles = []
    pdf_shelfs = []
    for b in body:
        for line in b['text'].split('\n'):
            line_s = line.strip()
            if line_s.startswith('•') or ' / ' in line_s or ' = ' in line_s:
                pdf_titles.append(" ".join(line_s.split()))
            m_s = re.match(r'^(\d+|[۰-۹]+)\.\s*', line_s)
            if m_s:
                pdf_shelfs.append(m_s.group(1))
                
    txt_bullets = re.findall(r'^[ \t]*●[ \t]*(.*)', txt, re.M)
    txt_shelfs = re.findall(r'^(\d+)\.', txt, re.M)
    txt_referrals = re.findall(r'^[ \t]*([^●\n]+←[^\n]+)', txt, re.M)
    
    # Check if shelfmarks are clustered before descriptions (interleaving signature)
    lines = [l.strip() for l in txt.split('\n') if l.strip()]
    shelf_line_indices = [idx for idx, l in enumerate(lines) if re.match(r'^\d+\.', l)]
    consecutive_shelfs = 0
    max_consecutive_shelfs = 0
    for i in range(len(shelf_line_indices)-1):
        if shelf_line_indices[i+1] == shelf_line_indices[i] + 1:
            consecutive_shelfs += 1
            max_consecutive_shelfs = max(max_consecutive_shelfs, consecutive_shelfs)
        else:
            consecutive_shelfs = 0
            
    print(f"\n---------------- PAGE {p} ----------------")
    print(f"Right col blocks: {len(right_b)}, Left col blocks: {len(left_b)}")
    print(f"Text chars: {len(txt)}, lines: {len(lines)}")
    print(f"TXT Bullets: {len(txt_bullets)}, Shelfs: {len(txt_shelfs)}, Referrals: {len(txt_referrals)}")
    print(f"Max consecutive shelfmarks in text: {max_consecutive_shelfs} {'[WARNING: POSSIBLE INTERLEAVING!]' if max_consecutive_shelfs > 4 else ''}")
    print("TXT Bullets:")
    for b in txt_bullets:
        print(f"   ● {b}")
    print("PDF Titles:")
    for t in pdf_titles[:5]:
        print(f"   • {t}")
    if len(pdf_titles) > 5:
        print(f"   ... and {len(pdf_titles)-5} more")
    if txt_referrals:
        print("TXT Referrals:")
        for r in txt_referrals:
            print(f"   ← {r}")

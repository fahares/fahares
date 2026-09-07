import pymupdf
import re
import json

doc = pymupdf.open('sources/pdf/parts/3.pdf')

with open('sources/text/fahares_vol_02.txt', 'r', encoding='utf-8') as f:
    text = f.read()

pages_raw = re.split(r'(<!-- page: \d+ -->)', text)
text_pages = {}
for i in range(1, len(pages_raw), 2):
    p_num = int(re.search(r'\d+', pages_raw[i]).group(0))
    text_pages[p_num] = pages_raw[i+1]

report = []

for p in range(387, 406):
    pdf_idx = p - 58
    if pdf_idx < 0 or pdf_idx >= len(doc):
        continue
    page = doc[pdf_idx]
    blocks = page.get_text("blocks")
    
    txt = text_pages.get(p, '')
    
    # Check bullets in txt vs potential titles in PDF
    txt_bullets = re.findall(r'^[ \t]*●[ \t]*(.*)', txt, re.M)
    
    # Find titles in blocks: blocks often have ' / ' for subject/language
    pdf_titles = []
    pdf_shelfmarks = []
    
    for b in blocks:
        b_text = b[4].strip()
        lines = b_text.split('\n')
        for l in lines:
            l_s = l.strip()
            # Title pattern
            if (' / ' in l_s or l_s.startswith('●')) and not re.match(r'^\d+\.', l_s):
                pdf_titles.append(l_s)
            m_shelf = re.match(r'^(\d+)\.\s*([^؛\n]+[؛:])', l_s)
            if m_shelf:
                pdf_shelfmarks.append(m_shelf.group(1))
    
    txt_shelfmarks = re.findall(r'^(\d+)\.', txt, re.M)
    
    report.append({
        'page': p,
        'txt_len': len(txt),
        'txt_bullets': len(txt_bullets),
        'txt_titles': txt_bullets,
        'pdf_titles_found': len(pdf_titles),
        'pdf_titles': pdf_titles,
        'txt_shelfmarks_count': len(txt_shelfmarks),
        'pdf_shelfmarks_count': len(pdf_shelfmarks),
        'txt_shelfmarks': txt_shelfmarks[:5] + (['...'] if len(txt_shelfmarks)>5 else []),
        'pdf_shelfmarks': pdf_shelfmarks[:5] + (['...'] if len(pdf_shelfmarks)>5 else [])
    })

print(json.dumps(report, ensure_ascii=False, indent=2))

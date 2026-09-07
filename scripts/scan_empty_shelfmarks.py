import re

with open('sources/text/fahares_vol_02.txt', 'r', encoding='utf-8') as f:
    text = f.read()

pages = re.split(r'<!--\s*page:\s*(\d+)\s*-->', text)
txt_pages = {int(pages[i]): pages[i+1] for i in range(1, len(pages), 2)}

for p in range(7, 58):
    p_text = txt_pages.get(p, '')
    lines = [l.strip() for l in p_text.splitlines() if l.strip()]
    for idx, l in enumerate(lines[:-1]):
        next_l = lines[idx+1]
        # Check if line has شماره نسخه: but no description on that line, and next line starts with a number or bullet
        if 'شماره نسخه:' in l and (re.match(r'^\d+\.', next_l) or next_l.startswith('●')):
            # check if there's any description after شماره نسخه:
            after_sn = l.split('شماره نسخه:', 1)[1].strip()
            # if after_sn only contains the shelfmark number (digits, slashes, dash, letters, spaces) but no description words
            if len(after_sn.split()) <= 2:
                print(f"Page {p}: Empty shelfmark: {l}  ==>  Next: {next_l[:30]}")


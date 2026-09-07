import json
import re

with open('sources/text/fahares_vol_02.txt', 'r', encoding='utf-8') as f:
    full_text = f.read()

pages_raw = re.split(r'(<!-- page: \d+ -->)', full_text)
text_pages = {}
for i in range(1, len(pages_raw), 2):
    p_num = int(re.search(r'\d+', pages_raw[i]).group(0))
    text_pages[p_num] = pages_raw[i+1]

for p in [390, 393, 394, 395, 396]:
    print(f"============================== PAGE {p} ==============================")
    lines = [l.strip() for l in text_pages[p].split('\n') if l.strip()]
    for idx, l in enumerate(lines):
        print(f"{idx+1:02d}: {l}")

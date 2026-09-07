import json
import re

with open("sources/text/fahares_vol_02.txt", "r", encoding="utf-8") as f:
    text = f.read()

pages_raw = re.split(r"(<!-- page: \d+ -->)", text)
pages = {}
for i in range(1, len(pages_raw), 2):
    tag = pages_raw[i]
    pnum = int(re.search(r"\d+", tag).group())
    pcontent = pages_raw[i+1]
    pages[pnum] = pcontent

batch_16_data = {}
for p in range(407, 417):
    if p in pages:
        batch_16_data[p] = pages[p]

with open("reports/batch_16_extracted_raw.json", "w", encoding="utf-8") as f:
    json.dump(batch_16_data, f, ensure_ascii=False, indent=2)

print("Saved reports/batch_16_extracted_raw.json")

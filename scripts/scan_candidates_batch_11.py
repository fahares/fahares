import re

with open("sources/text/fahares_vol_02.txt") as f:
    text = f.read()

pages = re.split(r"(<!-- page: \d+ -->)", text)
page_dict = {}
for i in range(1, len(pages), 2):
    tag = pages[i]
    pnum = int(re.search(r"\d+", tag).group())
    pcontent = pages[i+1]
    page_dict[pnum] = pcontent

# Let us check for entries where transliteration is a title, not author
# Title transliteration usually has dashes, lowercase, no birth-death years at end, etc.
# Or better: check against vol_02_comprehensive_audit.md!
with open("reports/vol_02_comprehensive_audit.md") as f:
    audit_md = f.read()

import re
sec3 = audit_md.split("## ۳.")[1].split("## ۴.")[0]
sec3_pages = {}
for m in re.finditer(r"\*\s*\*\*صفحه\s*(\d+)\*\*:\s*([^
]+)", sec3):
    p = int(m.group(1))
    info = m.group(2)
    sec3_pages[p] = info

print("Pages in Section 3 between 320 and 400:")
for p in sorted(sec3_pages.keys()):
    if 320 <= p <= 400:
        print(f"Page {p}: {sec3_pages[p]}")

# Also check Section 2 (interleaved)
sec2 = audit_md.split("## ۲.")[1].split("## ۳.")[0]
sec2_pages = [int(x) for x in re.findall(r"\d+", sec2)]
print("
Pages in Section 2 between 320 and 400:")
for p in sorted(sec2_pages):
    if 320 <= p <= 400:
        print(f"Page {p}")

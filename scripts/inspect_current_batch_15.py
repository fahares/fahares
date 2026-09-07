import re

with open("sources/text/fahares_vol_02.txt", "r", encoding="utf-8") as f:
    content = f.read()

for p in range(397, 407):
    tag = f"<!-- page: {p} -->"
    next_tag = f"<!-- page: {p+1} -->"
    start = content.find(tag)
    end = content.find(next_tag)
    page_text = content[start:end]
    print(f"=== PAGE {p} (length: {len(page_text)}) ===")
    lines = [l for l in page_text.split("\n") if l.strip()]
    for l in lines[:5]:
        print("  ", l[:80])
    print("   ...")
    for l in lines[-3:]:
        print("  ", l[:80])

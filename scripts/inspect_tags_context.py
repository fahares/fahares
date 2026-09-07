import re

with open("sources/text/fahares_vol_02.txt", "r", encoding="utf-8") as f:
    text = f.read()

for p in range(417, 428):
    tag = f"<!-- page: {p} -->"
    idx = text.find(tag)
    if idx != -1:
        before = text[max(0, idx-80):idx]
        after = text[idx+len(tag):idx+len(tag)+80]
        print(f"\n--- Tag {p} ---")
        print("BEFORE:", repr(before))
        print("AFTER :", repr(after))

import re

with open("sources/text/fahares_vol_02.txt", "r", encoding="utf-8") as f:
    text = f.read()

lines = text.split('\n')

for p in range(417, 428):
    tag = f"<!-- page: {p} -->"
    for i, line in enumerate(lines):
        if tag in line:
            is_standalone = (line.strip() == tag)
            print(f"Tag {p} on line {i+1}: {'STANDALONE' if is_standalone else 'INLINE'}")
            if not is_standalone:
                print(f"   Context: {repr(line[:100])}")

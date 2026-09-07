import re

with open("sources/text/fahares_vol_02.txt", "r", encoding="utf-8") as f:
    text = f.read()

m556 = re.search(r'<!-- page: 556 -->(.*?)(<!-- page: 557 -->)', text, re.DOTALL)
if m556:
    lines = m556.group(1).strip().split('\n')
    for i in range(min(45, len(lines))):
        print(f"556:{i+1}: {lines[i][:90]}")

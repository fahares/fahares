import re

with open("sources/text/fahares_vol_02.txt", "r", encoding="utf-8") as f:
    text = f.read()

m = re.search(r'<!-- page: 425 -->(.*?)(<!-- page:|\Z)', text, re.DOTALL)
if m:
    lines = m.group(1).strip().split('\n')
    for i, l in enumerate(lines[50:]):
        print(f"425:{i+51}: {l}")

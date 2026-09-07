import re

with open("sources/text/fahares_vol_02.txt", "r", encoding="utf-8") as f:
    text = f.read()

m = re.search(r'<!-- page: 425 -->(.*?)<!-- page: 427 -->', text, re.DOTALL)
if m:
    print(m.group(1))

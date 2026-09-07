import re

with open("sources/text/fahares_vol_02.txt", "r", encoding="utf-8") as f:
    text = f.read()

m89 = re.search(r'<!-- page: 89 -->(.*?)(<!-- page: 90 -->)', text, re.DOTALL)
if m89:
    print("Bottom of 89:")
    print("\n".join(m89.group(1).strip().split('\n')[-5:]))

m90 = re.search(r'<!-- page: 90 -->(.*?)(<!-- page: 91 -->)', text, re.DOTALL)
if m90:
    print("Top of 90:")
    print("\n".join(m90.group(1).strip().split('\n')[:5]))

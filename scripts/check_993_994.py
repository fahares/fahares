import re

with open("sources/text/fahares_vol_02.txt", "r", encoding="utf-8") as f:
    text = f.read()

# Let's check page 993 bottom and 994 top
m993 = re.search(r'<!-- page: 993 -->(.*?)(<!-- page: 994 -->)', text, re.DOTALL)
if m993:
    print("Bottom of 993:")
    print("\n".join(m993.group(1).strip().split('\n')[-5:]))

m994 = re.search(r'<!-- page: 994 -->(.*?)(<!-- page: 995 -->)', text, re.DOTALL)
if m994:
    print("Top of 994:")
    print("\n".join(m994.group(1).strip().split('\n')[:5]))

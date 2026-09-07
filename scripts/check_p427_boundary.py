import re

with open("sources/text/fahares_vol_02.txt", "r", encoding="utf-8") as f:
    text = f.read()

m = re.search(r'(.{0,150})(<!-- page: 427 -->)(.{0,150})', text, re.DOTALL)
if m:
    print("Before tag 427:")
    print(repr(m.group(1)))
    print("Tag:", m.group(2))
    print("After tag 427:")
    print(repr(m.group(3)))

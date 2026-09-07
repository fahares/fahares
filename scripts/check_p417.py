with open("sources/text/fahares_vol_02.txt", "r", encoding="utf-8") as f:
    text = f.read()

p417_idx = text.find("<!-- page: 417 -->")
print(text[p417_idx:p417_idx+200])

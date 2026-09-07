with open("sources/text/fahares_vol_02.txt", "r", encoding="utf-8") as f:
    text = f.read()

start = text.find("<!-- page: 413 -->")
end = text.find("<!-- page: 414 -->")
print("=== CURRENT PAGE 413 TEXT ===")
print(text[start:end])

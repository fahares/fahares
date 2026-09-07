with open("sources/text/fahares_vol_02.txt", "r", encoding="utf-8") as f:
    text = f.read()

start = text.find("<!-- page: 397 -->")
end = text.find("<!-- page: 398 -->")
print("=== PAGE 397 TEXT ===")
print(text[start:end])

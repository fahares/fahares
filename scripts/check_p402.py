with open("sources/text/fahares_vol_02.txt", "r", encoding="utf-8") as f:
    text = f.read()

start = text.find("<!-- page: 402 -->")
end = text.find("<!-- page: 403 -->")
print("=== PAGE 402 TEXT ===")
print(text[start:end])

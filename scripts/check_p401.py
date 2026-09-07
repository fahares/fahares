with open("sources/text/fahares_vol_02.txt", "r", encoding="utf-8") as f:
    text = f.read()

start = text.find("<!-- page: 401 -->")
end = text.find("<!-- page: 402 -->")
print("=== PAGE 401 TEXT ===")
print(text[start:end])

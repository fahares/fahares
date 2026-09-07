with open("sources/text/fahares_vol_02.txt", "r", encoding="utf-8") as f:
    text = f.read()

start = text.find("<!-- page: 400 -->")
end = text.find("<!-- page: 401 -->")
print("=== PAGE 400 TEXT ===")
print(text[start:end])

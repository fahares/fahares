with open('sources/text/fahares_vol_02.txt', 'r', encoding='utf-8') as f:
    text = f.read()

for p in [397, 398, 399, 400, 401, 402]:
    print(f"==================== PAGE {p} ====================")
    c = text.split(f'<!-- page: {p} -->')[1].split(f'<!-- page: {p+1} -->')[0]
    lines = [l.strip() for l in c.strip().split('\n') if l.strip()]
    for idx, l in enumerate(lines):
        print(f"{idx+1:02d}: {l[:100]}")

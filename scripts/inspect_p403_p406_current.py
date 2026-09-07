with open('sources/text/fahares_vol_02.txt', 'r', encoding='utf-8') as f:
    text = f.read()

for p in range(403, 407):
    print(f"==================== PAGE {p} ====================")
    if f'<!-- page: {p} -->' in text:
        next_tag = f'<!-- page: {p+1} -->'
        c = text.split(f'<!-- page: {p} -->')[1].split(next_tag)[0]
        lines = [l.strip() for l in c.strip().split('\n') if l.strip()]
        for idx, l in enumerate(lines[:15]):
            print(f"{idx+1:02d}: {l[:100]}")
        if len(lines) > 15:
            print(f"... ({len(lines)-15} more lines)")
            for idx, l in enumerate(lines[-3:]):
                print(f"{len(lines)-2+idx:02d}: {l[:100]}")

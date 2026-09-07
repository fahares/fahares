with open('sources/text/fahares_vol_02.txt', 'r', encoding='utf-8') as f:
    text = f.read()

p391_txt = text.split('<!-- page: 391 -->')[1].split('<!-- page: 392 -->')[0]

lines = [l.strip() for l in p391_txt.split('\n') if l.strip()]
for idx, l in enumerate(lines):
    print(f"{idx+1:02d}: {l}")

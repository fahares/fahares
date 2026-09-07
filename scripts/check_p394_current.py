with open('sources/text/fahares_vol_02.txt', 'r', encoding='utf-8') as f:
    text = f.read()

p394 = text.split('<!-- page: 394 -->')[1].split('<!-- page: 395 -->')[0]
print(p394)

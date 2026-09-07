with open('sources/text/fahares_vol_02.txt', 'r', encoding='utf-8') as f:
    text = f.read()

p402_txt = text.split('<!-- page: 402 -->')[1].split('<!-- page: 403 -->')[0]
print(p402_txt)

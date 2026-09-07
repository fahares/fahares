import re
import json

# Test on Block 1, Block 2, Block 3, Block 4
with open('sources/text/fahares_vol_01.txt', 'r', encoding='utf-8') as f:
    text = f.read()
lines = text.split('\n')

# Block 3: lines 3693 to 3708 (Page 67)
shs_b3 = [
    '1. مشهد؛ رضوی؛ شماره نسخه: 982 آغاز و انجام: برابر',
    '2. تهران؛ دانشگاه؛ شماره نسخه: 195/130-ف نسخه اصل: حمدیه ش 1447؛ بی‌کا، تا: رمضان 754؛ در هامش است [فیلم: ها - ف: 1-430]',
    '3. تهران؛ مجلس؛ شماره نسخه: 630/1',
    '4. قم؛ مرعشی؛ شماره نسخه: 12376/1 آغاز و انجام: برابر',
    '5. تهران؛ ادبیات؛ شماره نسخه: 357/8',
    '7. تهران؛ ملک؛ شماره نسخه: 796',
    '8. تهران؛ ملک؛ شماره نسخه: 1603/3'
]

descs_b3 = [
    'خط: نسخ؛ کا: محمد بن احمد بن صقر بن سلام التدمری الغسانی الشافعی، تا: 29 جمادی الاول 742، جا: مکه؛ با رساله «جمل» ش 981 در یک مجلد می باشد، معرب و معجم؛ واقف: ابن خاتون، تاریخ وقف: 1067؛ 4 گ، 23 سطر، اندازه: 19×13 سم [ف: 1-258]',
    'آغاز: برابر بی‌کا، تا: قرن 8؛ قطع: وزیر، اندازه: 22×12 سم [ف: 2-388]',
    'خط: نستعلیق؛ بی‌کا ا ، تا: قرن 8؛ 4 گ (5-5)، 27 سطر ، اندازه: 17×5 سم [ف: 31-281]',
    'خط: نسخ؛ بی‌کا ا، تا: قرن 9 [ف: 3-58]',
    'خط: تعلیق؛ کا: علی بن عبدالقادر، تا: 882؛ 72 گ، 9 سطر، اندازه: 7/10×4/2 سم [ف: 1-11]',
    'خط: نسخ؛ کا: ص مد بن خلیفه محمد، تا: رمضان 894؛ 10 سطر [ف: 5-303]'
]

def format_reassembled(sh_raw, desc_text):
    # Check if sh_raw contains incipit
    m_inc = re.search(r'(?:؛\s*|\s+)(آغاز(?:\s+و\s+انجام)?:\s*[^؛\n]+(?:؛|$)|آغاز:\s*.*$)', sh_raw)
    inc_part = None
    clean_sh = sh_raw
    if m_inc:
        inc_part = m_inc.group(1).strip().rstrip('؛')
        clean_sh = sh_raw[:m_inc.start()].strip()
        
    if not desc_text:
        if inc_part:
            return f"{clean_sh}\n{inc_part}"
        return clean_sh
        
    # Check if desc_text has incipit at beginning
    m_desc_inc = re.search(r'^(آغاز(?:\s+و\s+انجام)?:\s*[^;\n]+(?:؛|$)|آغاز:\s*.*?)(?:\s+خط:|\s+بی‌کا|\s+بی کا|$)', desc_text)
    if m_desc_inc:
        desc_inc = m_desc_inc.group(1).strip().rstrip('؛')
        desc_body = desc_text[len(m_desc_inc.group(1)):].strip()
        final_inc = inc_part if inc_part else desc_inc
        if desc_body:
            return f"{clean_sh}\n{final_inc}\n{desc_body}"
        else:
            return f"{clean_sh}\n{final_inc}"
            
    if inc_part:
        return f"{clean_sh}\n{inc_part}\n{desc_text}"
    return f"{clean_sh}\n{desc_text}"

print("=== REASSEMBLED BLOCK 3 ===")
d_idx = 0
for sh in shs_b3:
    if '[فیلم:' in sh or '[ف:' in sh:
        # Already complete
        print(sh)
    else:
        d = descs_b3[d_idx]
        d_idx += 1
        print(format_reassembled(sh, d))
    print()

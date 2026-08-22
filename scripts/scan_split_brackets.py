import glob
import re

txt_files = sorted(glob.glob('sources/text/*.txt'))

split_cases = []

for fpath in txt_files:
    with open(fpath, 'r', encoding='utf-8', errors='ignore') as f:
        text = f.read()
        
    lines = text.split('\n')
    for i in range(len(lines) - 1):
        l1 = lines[i].strip()
        l2 = lines[i+1].strip()
        
        # Check if l1 ends with [ or [ف]: or [ف: and l2 starts/ends with numbers and ]
        if (l1.endswith('[ف]:') or l1.endswith('[ف:') or l1.endswith('[')) and (re.match(r'^\d+[\d\-–\s]*\]', l2) or ']' in l2):
            split_cases.append({
                'file': fpath,
                'line_num': i+1,
                'l1': l1,
                'l2': l2
            })

print(f"=== 🔍 مجموع حالات شکسته کروشه کشف شده: {len(split_cases)} مورد ===")
for c in split_cases[:25]:
    print(f"فایل: {c['file']} (سطر {c['line_num']}):")
    print(f"   سطر ۱: {c['l1']}")
    print(f"   سطر ۲: {c['l2']}\n")


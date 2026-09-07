import re

with open("sources/text/fahares_vol_02.txt", "r", encoding="utf-8") as f:
    text = f.read()

for p in [419, 425]:
    m = re.search(r'<!-- page: ' + str(p) + r' -->(.*?)(<!-- page:|\Z)', text, re.DOTALL)
    if m:
        content = m.group(1).strip()
        lines = content.split('\n')
        print(f"=== Page {p} (Total lines: {len(lines)}) ===")
        for i, l in enumerate(lines[:20]):
            print(f"  {i+1}: {l[:90]}")
        # Look for numbers with dots
        num_dots = re.findall(r'(?:^|\s)(\d+)\.\s+([^؛\n]+)', content)
        print(f"Numbers found in page {p}:", [n[0] for n in num_dots[:15]])

import json
import re

with open("reports/batch_17_extracted_raw.json", "r", encoding="utf-8") as f:
    raw_pages = json.load(f)

for p in range(417, 427):
    content = raw_pages[str(p)]
    lines = content.strip().split('\n')
    print(f"\n==================== Page {p} (Lines: {len(lines)}) ====================")
    
    # 1. Check copies in page
    copies = re.findall(r'(?:^|\n)\s*(\d+)\.\s+([^؛\n]+)；([^؛\n]+)；شماره نسخه:\s*([^\n]+)', content)
    print(f"Copies found ({len(copies)}): {[c[0] for c in copies]}")
    
    # 2. Check squashed copies (where number and details are on same line)
    squashed = []
    for l_idx, l in enumerate(lines):
        if re.search(r'^\s*\d+\.\s+[^؛]+；.*?خط:', l):
            squashed.append((l_idx+1, l[:80]))
    if squashed:
        print(f"Squashed copy lines ({len(squashed)}): {[s[0] for s in squashed]}")

    # 3. Check for specific known typos or OCR artifacts
    typos = []
    if 'گلیپایگانی' in content or 'گلبایگانی' in content or 'گلیانگانی' in content:
        typos.append("Golpayegani typo")
    if 'بی کاف' in content or 'بی ک،' in content:
        typos.append("Bi-ka typo (بی کاف / بی ک،)")
    if 'مدرسه غروب' in content:
        typos.append("Madrese Gharb typo")
    if 'فرنکی' in content:
        typos.append("Farangi typo")
    if 'گک' in content:
        typos.append("Gak typo (گک)")
    if re.search(r'[\u0600-\u06FF]\s+[\u0600-\u06FF]', content):
        pass
    if typos:
        print(f"Typos found: {typos}")
    
    # 4. First and last 2 lines
    print("Start:", repr(lines[0][:80]))
    print("End  :", repr(lines[-1][:80]))

import sys, re
sys.path.insert(0, 'scripts')
from vol_02_reconstruction_definitions import PAGE_RECONSTRUCTIONS

targets = [12, 19, 21, 24, 29, 38, 65, 80, 136, 205, 207, 263, 268, 289, 311, 358, 390, 413, 419, 425, 442, 526, 530, 537, 540, 555, 625, 644, 761, 769, 789, 792, 899, 915, 932, 965, 972, 981, 982, 993, 998, 999, 1000, 1002, 1003, 1005, 1013, 1014, 1020]

assert len(PAGE_RECONSTRUCTIONS) == len(targets), f"Expected {len(targets)} pages, found {len(PAGE_RECONSTRUCTIONS)}"

# ONLY the 4 physical printed book numbering anomalies verified against images:
# Page 19: 392 between 103 and 104
# Page 24: 4 between 160 and 161
# Page 29: 3 between 222 and 223
# Page 419: 117 between 11 and 12
KNOWN_AUTHENTIC_JUMPS = {
    19: [(392, 104)],
    24: [(160, 4)],
    29: [(222, 3)],
    419: [(117, 12)]
}

fa_digits = '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩'
errors = []

for p in targets:
    t = PAGE_RECONSTRUCTIONS[p]
    # 1. Check non-empty
    if len(t.strip()) < 100:
        errors.append(f"Page {p}: abnormally short ({len(t)} chars)")
    
    # 2. Check Persian digits
    found_fa = [c for c in t if c in fa_digits]
    if found_fa:
        errors.append(f"Page {p}: contains non-English digits: {found_fa[:5]}")
        
    # 3. Check bracket balance
    b_open = t.count('[')
    b_close = t.count(']')
    if abs(b_open - b_close) > 2:
        errors.append(f"Page {p}: bracket imbalance: '['={b_open}, ']'={b_close}")
        
    # 4. Check shelfmark monotonicity
    sh_nums = re.findall(r'(?:^|\n)\s*(\d+)\.\s*', t)
    if len(sh_nums) > 1:
        ints = [int(x) for x in sh_nums]
        # Allow entry-level resets (e.g. 1..5 under entry A, then 1..4 under entry B)
        # But detect backward jumps within the same block
        for i in range(len(ints)-1):
            if ints[i+1] < ints[i] and ints[i+1] != 1:
                if p in KNOWN_AUTHENTIC_JUMPS and (ints[i], ints[i+1]) in KNOWN_AUTHENTIC_JUMPS[p]:
                    continue
                errors.append(f"Page {p}: non-monotonic jump: {ints[i]} -> {ints[i+1]}")
                break

    # 5. Check isolated transliterations without bullet
    lines = t.splitlines()
    for idx, l in enumerate(lines):
        if re.match(r'^[a-zA-Zāīūṭṣḍẓḥṯš\s\-\'\,\.\(\)]+$', l) and len(l) > 4:
            # check previous non-empty line
            prev = ''
            for k in range(idx-1, -1, -1):
                if lines[k].strip():
                    prev = lines[k].strip()
                    break
            # if prev is a shelfmark or description and not a heading or author or ghayr, warn
            if prev.startswith(('1.', '2.', '3.', '4.', '5.', '6.', '7.', '8.', '9.', '0.', 'خط:', 'کاغذ:', 'اندازه:')):
                errors.append(f"Page {p}: transliteration '{l[:20]}' immediately follows shelfmark/description '{prev[:20]}'")

print(f"Total verification errors: {len(errors)}")
for e in errors[:20]:
    print("  ERROR:", e)

if not errors:
    print("ALL 49 RECONSTRUCTED PAGES PASSED ALL STRUCTURAL INTEGRITY AUDITS!")

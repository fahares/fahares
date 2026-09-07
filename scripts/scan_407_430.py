import re

with open("sources/text/fahares_vol_02.txt", "r", encoding="utf-8") as f:
    text = f.read()

pages_raw = re.split(r"(<!-- page: \d+ -->)", text)
pages = {}
for i in range(1, len(pages_raw), 2):
    tag = pages_raw[i]
    pnum = int(re.search(r"\d+", tag).group())
    pcontent = pages_raw[i+1]
    pages[pnum] = pcontent

print("Scanning pages 407 to 430 for anomalies...")

anomalous_pages = []

for p in range(407, 431):
    if p not in pages:
        continue
    c = pages[p]
    issues = []
    
    # 1. Check isolated transliteration (line of latin without title/author above it)
    lines = [l.strip() for l in c.split("\n") if l.strip()]
    for idx, l in enumerate(lines):
        if re.match(r"^[a-zA-Zāīūšžčṭḍṣẓḥ‘'-]+(\s+[a-zA-Zāīūšžčṭḍṣẓḥ‘'-]+)*$", l):
            prev = lines[idx-1] if idx > 0 else ""
            if not (prev.startswith("●") or "قمری" in prev or "–" in prev or "-" in prev):
                issues.append(f"Isolated translit: {l}")

    # 2. Numbering sequence check in copies
    num_matches = re.findall(r"(?:^|\n)\s*(\d+)\.\s*", c)
    if num_matches:
        nums = [int(n) for n in num_matches]
        # check if disordered
        for k in range(len(nums)-1):
            if nums[k+1] < nums[k]:
                issues.append(f"Disordered numbering: {nums[k]} -> {nums[k+1]}")
                break

    # 3. Stray slashes
    for l in lines:
        if l.startswith("/") or l.endswith("/"):
            issues.append(f"Stray slash line: {l[:40]}")
        if l.startswith("●"):
            parts = l.split("/")
            if len(parts) != 3:
                issues.append(f"Malformed title header (parts={len(parts)}): {l}")

    # 4. Unbalanced brackets
    if c.count("[") != c.count("]"):
        issues.append(f"Unbalanced brackets: [={c.count('[')}, ]={c.count(']')}")

    # 5. Internal word-splits
    splits = re.findall(r"(?:ت\s+هران|مر\s+عشی|گلپای\s+گانی|اخ\s+تیارات)", c)
    if splits:
        issues.append(f"Word splits: {set(splits)}")

    if issues:
        anomalous_pages.append((p, issues))

print(f"Total problematic pages found between 407 and 430: {len(anomalous_pages)}")
for p, iss in anomalous_pages:
    print(f"Page {p}:")
    for is_item in iss:
        print(f"   - {is_item}")

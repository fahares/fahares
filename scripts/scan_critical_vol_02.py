import re
import json

def scan_critical_vol_02():
    with open("sources/text/fahares_vol_02.txt", "r", encoding="utf-8") as f:
        text = f.read()

    # Split into pages
    page_splits = re.split(r'(<!-- page: \d+ -->)', text)
    
    pages = {}
    current_p = 7
    # Note: text before the first tag is intro/empty
    for i in range(1, len(page_splits), 2):
        tag = page_splits[i]
        p_num = int(re.search(r'\d+', tag).group(0))
        content = page_splits[i+1] if i+1 < len(page_splits) else ""
        pages[p_num] = content

    critical_pages = {}

    for p_num in sorted(pages.keys()):
        p_text = pages[p_num]
        lines = p_text.split('\n')
        issues = []

        # 1. Check for severe copy number inversions within the page
        # Match lines like: 12. تهران؛ ... or 12. مشهد؛ ...
        copy_matches = []
        for l_idx, line in enumerate(lines):
            # Check for title reset
            if line.strip().startswith('●'):
                copy_matches.append(('TITLE', 0, l_idx))
            else:
                m = re.match(r'^\s*(\d+)\.\s+([^؛]+)；([^؛]+)；شماره نسخه:', line)
                if m:
                    copy_num = int(m.group(1))
                    copy_matches.append(('COPY', copy_num, l_idx))

        # Check sequence
        last_num = None
        for typ, val, l_idx in copy_matches:
            if typ == 'TITLE':
                last_num = None
            elif typ == 'COPY':
                if last_num is not None:
                    # If it drops by more than 3, likely column interleaving or reordering
                    if val < last_num:
                        issues.append(f"Sequence inversion: copy {last_num} followed by copy {val} (line {l_idx+1})")
                last_num = val

        # 2. Check for isolated transliteration (Latin lines without nearby title)
        for l_idx, line in enumerate(lines):
            stripped = line.strip()
            # If line is mostly Latin letters
            if stripped and re.match(r'^[a-zA-Zāīūḍṣṭẓḥśẕḻḡōēčšž\s\(\)\.\,\-\:\'\"\?\/0-9]+$', stripped):
                # Is it preceded by a title or author line in current or prev lines?
                has_title_context = False
                for prev_i in range(max(0, l_idx-3), l_idx):
                    if '●' in lines[prev_i] or '،' in lines[prev_i] or any('\u0600' <= c <= '\u06FF' for c in lines[prev_i]):
                        has_title_context = True
                        break
                if not has_title_context:
                    # check if it's just a bracket reference or page marker
                    if not stripped.startswith('[') and not stripped.startswith('('):
                        issues.append(f"Isolated transliteration without Persian title context: '{stripped[:40]}' (line {l_idx+1})")

        # 3. Check for severe OCR corruption / Gibberish
        # e.g., strange non-persian scripts or repeated garbage tokens
        for l_idx, line in enumerate(lines):
            # Check for high concentration of non-standard chars in non-transliteration lines
            persian_chars = len(re.findall(r'[\u0600-\u06FF]', line))
            latin_chars = len(re.findall(r'[a-zA-Z]', line))
            # If line has mixed weird characters or foreign text where Persian expected
            if len(line) > 30 and persian_chars > 0 and latin_chars > 15:
                # Exclude standard bibliographical or shelfmark lines
                if not any(k in line for k in ['شماره نسخه:', 'اندازه:', 'ISBN', 'http', '[ف:', 'ص (', 'فهرست']):
                    if not re.search(r'^[a-zA-Z\s\.\,\-]+$', line):
                        # check ratio
                        issues.append(f"Possible OCR corruption / mixed text: '{line[:50]}...' (line {l_idx+1})")

        # 4. Check for clustered copy headers (3+ consecutive headers without body)
        consec_headers = 0
        max_consec = 0
        for line in lines:
            if re.match(r'^\s*\d+\.\s+[^؛]+；[^؛]+；شماره نسخه:', line):
                consec_headers += 1
                if consec_headers > max_consec:
                    max_consec = consec_headers
            elif line.strip() and not line.strip().startswith('●'):
                consec_headers = 0
        if max_consec >= 4:
            issues.append(f"Header clustering: {max_consec} consecutive copy headers without body text")

        if issues:
            critical_pages[p_num] = issues

    print(f"Total pages scanned: {len(pages)}")
    print(f"Critical anomaly candidate pages found: {len(critical_pages)}")
    
    with open("reports/critical_anomalies_vol_02.json", "w", encoding="utf-8") as f:
        json.dump(critical_pages, f, ensure_ascii=False, indent=2)

    for p in sorted(critical_pages.keys()):
        print(f"Page {p} ({len(critical_pages[p])} issues):")
        for iss in critical_pages[p]:
            print(f"  - {iss}")

if __name__ == "__main__":
    scan_critical_vol_02()

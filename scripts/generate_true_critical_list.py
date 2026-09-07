import re
import json

def generate_true_critical_list():
    with open("sources/text/fahares_vol_02.txt", "r", encoding="utf-8") as f:
        text = f.read()

    # Split into pages
    page_splits = re.split(r'(<!-- page: \d+ -->)', text)
    
    pages = {}
    for i in range(1, len(page_splits), 2):
        tag = page_splits[i]
        p_num = int(re.search(r'\d+', tag).group(0))
        content = page_splits[i+1] if i+1 < len(page_splits) else ""
        pages[p_num] = content

    critical_pages = {}

    for p_num in sorted(pages.keys()):
        p_text = pages[p_num]
        lines = p_text.split('\n')
        page_issues = []

        # 1. Missing Title detection
        # Check every line that is an author line or transliteration
        for l_idx, line in enumerate(lines):
            stripped = line.strip()
            # If line is author date line: "نام، ... قمری"
            if re.search(r'[\u0600-\u06FF]+،.*?\d+\s*قمری', stripped):
                # Check if preceded by title within 4 lines
                has_title = False
                for prev_i in range(max(0, l_idx-4), l_idx):
                    if lines[prev_i].strip().startswith('●') or '←' in lines[prev_i]:
                        has_title = True
                        break
                # If at top of page, check bottom of previous page
                if not has_title and l_idx <= 3 and p_num - 1 in pages:
                    prev_lines = pages[p_num - 1].strip().split('\n')
                    for pl in prev_lines[-4:]:
                        if pl.strip().startswith('●') or '←' in pl:
                            has_title = True
                            break
                if not has_title:
                    # make sure not inside a copy description
                    if not any(k in stripped for k in ['خط:', 'کا:', 'تا:', 'جا:', 'آغاز:', 'انجام:', 'شماره نسخه:', 'وقف:']):
                        page_issues.append(('MISSING_TITLE', f"Missing Persian title before author: '{stripped[:50]}' (line {l_idx+1})"))

        # 2. Check for isolated transliteration (not preceded by title)
        for l_idx, line in enumerate(lines):
            stripped = line.strip()
            # If line is transliteration
            if stripped and re.match(r'^[a-zāīūḍṣṭẓḥśẕḻḡōēčšž\s\(\)\.\,\-\:\'\"\?\/0-9]+$', stripped, re.I):
                if len(stripped) > 5 and not stripped.startswith('[') and not stripped.startswith('('):
                    # check if preceded by title or author
                    has_ctx = False
                    for prev_i in range(max(0, l_idx-3), l_idx):
                        if '●' in lines[prev_i] or '،' in lines[prev_i] or any('\u0600' <= c <= '\u06FF' for c in lines[prev_i]):
                            has_ctx = True
                            break
                    # if at top of page, check prev page bottom
                    if not has_ctx and l_idx <= 2 and p_num - 1 in pages:
                        prev_lines = pages[p_num - 1].strip().split('\n')
                        for pl in prev_lines[-3:]:
                            if '●' in pl or '،' in pl or any('\u0600' <= c <= '\u06FF' for c in pl):
                                has_ctx = True
                                break
                    if not has_ctx:
                        page_issues.append(('ORPHAN_TRANSLIT', f"Orphan transliteration: '{stripped[:40]}' (line {l_idx+1})"))

        # 3. Check for Copy Number Sequence Inversions / Jumps
        copy_nums = []
        for l_idx, line in enumerate(lines):
            stripped = line.strip()
            if stripped.startswith('●'):
                copy_nums.append(('TITLE', 0, l_idx))
            else:
                m = re.match(r'^(\d+)\.\s+', stripped)
                if m:
                    copy_nums.append(('COPY', int(m.group(1)), l_idx))

        last_c = None
        for typ, c_num, l_idx in copy_nums:
            if typ == 'TITLE':
                last_c = None
            elif typ == 'COPY':
                if last_c is not None:
                    if c_num < last_c:
                        page_issues.append(('NUM_INVERSION', f"Copy number drop: {last_c} -> {c_num} (line {l_idx+1})"))
                    elif c_num > last_c + 15:
                        page_issues.append(('NUM_JUMP', f"Copy number leap: {last_c} -> {c_num} (line {l_idx+1})"))
                last_c = c_num

        # 4. Check for OCR Corruption / Broken symbols
        for l_idx, line in enumerate(lines):
            if any(sym in line for sym in ['✍', '◄', '►']):
                page_issues.append(('CORRUPT_SYMBOL', f"Corrupt cross-ref symbol: '{line.strip()[:40]}' (line {l_idx+1})"))
            if '<' in line and not line.strip().startswith('<!--'):
                page_issues.append(('CORRUPT_CHAR', f"Angle bracket '<' in text: '{line.strip()[:40]}' (line {l_idx+1})"))
            if re.search(r'[a-zA-Z].*?[\u0600-\u06FF].*?[a-zA-Z]', line):
                if not any(k in line for k in ['http', 'www', 'ISBN']):
                    page_issues.append(('INTERLEAVED_TEXT', f"Interleaved Persian/Latin: '{line.strip()[:50]}' (line {l_idx+1})"))

        if page_issues:
            critical_pages[p_num] = page_issues

    print(f"Total True Level 1 Critical Pages: {len(critical_pages)}")
    
    with open("reports/true_critical_pages_vol_02.json", "w", encoding="utf-8") as f:
        # Convert to serializable format
        out = {p: [list(iss) for iss in issues] for p, issues in critical_pages.items()}
        json.dump(out, f, ensure_ascii=False, indent=2)

    return critical_pages

if __name__ == "__main__":
    pages = generate_true_critical_list()
    # Categorize by issue type
    cats = {}
    for p, issues in pages.items():
        types = set(iss[0] for iss in issues)
        for t in types:
            cats[t] = cats.get(t, []) + [p]
    
    print("\nBreakdown by Anomaly Type:")
    for t, plist in sorted(cats.items()):
        print(f"  {t} ({len(plist)} pages): {plist}")

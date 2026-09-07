import re
import json

def audit_vol_02_critical():
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

    results = {}

    for p_num in sorted(pages.keys()):
        p_text = pages[p_num]
        lines = p_text.split('\n')
        issues = []

        # Check 1: Missing Title before Author/Transliteration
        # Pattern: author line like "نام، ق X قمری" or "نام، سال - سال قمری"
        # followed by latin transliteration, BUT without a preceding "●" title line
        for l_idx, line in enumerate(lines):
            stripped = line.strip()
            # If line looks like author date line: e.g. "باخرزی، ... قمری"
            if re.search(r'[\u0600-\u06FF]+،.*?\d+\s*قمری', stripped):
                # Look back up to 3 non-empty lines
                has_bullet = False
                for prev_i in range(max(0, l_idx-3), l_idx):
                    if lines[prev_i].strip().startswith('●') or '←' in lines[prev_i]:
                        has_bullet = True
                        break
                # Also check if it's top of page, could title be at bottom of prev page?
                if not has_bullet:
                    # check previous page bottom
                    if l_idx <= 2 and p_num - 1 in pages:
                        prev_lines = pages[p_num - 1].strip().split('\n')
                        for pl in prev_lines[-3:]:
                            if pl.strip().startswith('●') or '←' in pl:
                                has_bullet = True
                                break
                if not has_bullet:
                    # make sure this isn't inside a copy description (like کاتب: فلانی، تا: ۱۲۰۰ق)
                    if not any(k in stripped for k in ['خط:', 'کا:', 'تا:', 'جا:', 'آغاز:', 'انجام:', 'شماره نسخه:']):
                        issues.append(f"Missing title before author: '{stripped}' (line {l_idx+1})")

        # Check 2: Strange symbols (✍, < instead of ←, etc.)
        for l_idx, line in enumerate(lines):
            if any(sym in line for sym in ['✍', '◄', '►']):
                issues.append(f"Non-standard symbol found: '{line.strip()[:50]}' (line {l_idx+1})")
            if '<' in line and not line.strip().startswith('<!--'):
                issues.append(f"Angle bracket '<' in text: '{line.strip()[:50]}' (line {l_idx+1})")

        # Check 3: Sequence anomalies in copy numbers
        # Match any line starting with digits and dot: "117. " or " 12. "
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
                    # Anomaly if jump downwards, or jump upwards by more than 15 without title
                    if c_num < last_c:
                        issues.append(f"Numbering drop: copy {last_c} -> {c_num} (line {l_idx+1})")
                    elif c_num > last_c + 15:
                        issues.append(f"Suspicious number leap: copy {last_c} -> {c_num} (line {l_idx+1})")
                last_c = c_num

        # Check 4: Latin transliteration with broken Persian / author interleaving
        for l_idx, line in enumerate(lines):
            if re.search(r'[a-zA-Z].*?[\u0600-\u06FF].*?[a-zA-Z]', line):
                # Ignore URLs or standard footnotes
                if not any(k in line for k in ['http', 'www', 'ISBN']):
                    issues.append(f"Interleaved Persian and Latin in one line: '{line.strip()[:60]}' (line {l_idx+1})")

        # Check 5: Isolated transliteration
        for l_idx, line in enumerate(lines):
            stripped = line.strip()
            if stripped and re.match(r'^[a-zāīūḍṣṭẓḥśẕḻḡōēčšž\s\(\)\.\,\-\:\'\"\?\/0-9]+$', stripped, re.I):
                if len(stripped) > 5 and not stripped.startswith('[') and not stripped.startswith('('):
                    # Check context
                    has_ctx = False
                    # check 2 lines above
                    for prev_i in range(max(0, l_idx-2), l_idx):
                        if '●' in lines[prev_i] or '،' in lines[prev_i] or any('\u0600' <= c <= '\u06FF' for c in lines[prev_i]):
                            has_ctx = True
                            break
                    if not has_ctx and l_idx == 0 and p_num - 1 in pages:
                        prev_lines = pages[p_num - 1].strip().split('\n')
                        for pl in prev_lines[-2:]:
                            if '●' in pl or any('\u0600' <= c <= '\u06FF' for c in pl):
                                has_ctx = True
                                break
                    if not has_ctx:
                        issues.append(f"Isolated transliteration without context: '{stripped[:40]}' (line {l_idx+1})")

        if issues:
            results[p_num] = issues

    print(f"Total pages scanned: {len(pages)}")
    print(f"Total Level 1 Critical candidate pages: {len(results)}")
    
    with open("reports/critical_anomalies_summary_v2.json", "w", encoding="utf-8") as f:
        json.dump(results, f, ensure_ascii=False, indent=2)

    # Print summary of first 20 pages
    for p in sorted(results.keys()):
        print(f"Page {p} ({len(results[p])} issues):")
        for iss in results[p]:
            print(f"   * {iss}")

if __name__ == "__main__":
    audit_vol_02_critical()

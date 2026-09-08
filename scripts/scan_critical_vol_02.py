import re
import json
from collections import defaultdict

def scan_critical_vol_02():
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

    def normalize_text(t):
        return t.replace('\uff1b', '\u061b').replace(';', '\u061b')

    results = defaultdict(list)

    for p_num in sorted(pages.keys()):
        p_text = normalize_text(pages[p_num])
        lines = [l.strip() for l in p_text.split('\n') if l.strip()]

        # 1. Number sequence checks (inversions and large unexpected leaps without title reset)
        copy_items = []
        for l_idx, line in enumerate(lines):
            if line.startswith('●'):
                copy_items.append(('TITLE', 0, l_idx))
            elif re.match(r'^\d+\.\s+[^؛]+؛', line) and 'شماره نسخه:' in line:
                c_num = int(re.match(r'^(\d+)\.', line).group(1))
                copy_items.append(('COPY', c_num, l_idx))

        last_c = None
        for typ, val, l_idx in copy_items:
            if typ == 'TITLE':
                last_c = None
            elif typ == 'COPY':
                if last_c is not None:
                    if val < last_c:
                        results[p_num].append(f'Inversion: copy {last_c} -> {val} (line {l_idx+1})')
                    elif val > last_c + 3:
                        results[p_num].append(f'Gap: copy {last_c} -> {val} (line {l_idx+1})')
                last_c = val

        # 2. True Column Interleaving:
        # A sequence of multiple bare headers (no description on line)
        # where the page later has stacked body blocks separated from their headers.
        consecutive_bare_headers = 0
        max_consecutive_bare_headers = 0
        consecutive_bodies = 0
        max_consecutive_bodies = 0

        for line in lines:
            is_header = bool(re.match(r'^(?:\d+\.\s+)?[^؛]+؛[^؛]+؛\s*شماره نسخه:', line))
            has_body_on_line = any(k in line for k in ['خط:', 'آغاز:', 'انجام:', 'کاغذ:', 'اندازه:', 'جلد:'])
            is_pure_body = any(line.startswith(k) for k in ['خط:', 'آغاز:', 'انجام:'])

            if is_header:
                if not has_body_on_line:
                    consecutive_bare_headers += 1
                    max_consecutive_bare_headers = max(max_consecutive_bare_headers, consecutive_bare_headers)
                else:
                    consecutive_bare_headers = 0
                consecutive_bodies = 0
            elif is_pure_body:
                consecutive_bodies += 1
                max_consecutive_bodies = max(max_consecutive_bodies, consecutive_bodies)
                consecutive_bare_headers = 0
            elif line.startswith('●'):
                consecutive_bare_headers = 0
                consecutive_bodies = 0

        if max_consecutive_bare_headers >= 4 and max_consecutive_bodies >= 4:
            results[p_num].append(f'Column Interleaving: {max_consecutive_bare_headers} bare headers with {max_consecutive_bodies} stacked body blocks')

        # 3. Multiple orphaned copies at top (2 or more khat or aghaz before first header or title)
        initial_khat = 0
        initial_aghaz = 0
        for line in lines:
            if bool(re.match(r'^(?:\d+\.\s+)?[^؛]+؛[^؛]+؛\s*شماره نسخه:', line)) or line.startswith('●'):
                break
            if line.startswith('خط:'):
                initial_khat += 1
            if line.startswith('آغاز:'):
                initial_aghaz += 1

        if initial_khat >= 2 or initial_aghaz >= 2:
            results[p_num].append(f'Multiple Orphan Copies at Top: {initial_khat} khat and {initial_aghaz} aghaz blocks before any header')

        # 4. Isolated transliteration without any Persian title or context
        for l_idx, line in enumerate(lines):
            if re.match(r'^[a-zāīūḍṣṭẓḥśẕḻḡōēčšž\s\(\)\.\,\-\:\'\"\?\/0-9]+$', line, re.I):
                if len(line) > 6 and not line.startswith('[') and not line.startswith('('):
                    has_ctx = False
                    for prev_i in range(max(0, l_idx-3), l_idx):
                        if '●' in lines[prev_i] or any('\u0600' <= c <= '\u06FF' for c in lines[prev_i]):
                            has_ctx = True
                            break
                    if not has_ctx and l_idx <= 1 and p_num - 1 in pages:
                        prev_lines = [pl.strip() for pl in pages[p_num - 1].split('\n') if pl.strip()]
                        for pl in prev_lines[-3:]:
                            if '●' in pl or any('\u0600' <= c <= '\u06FF' for c in pl):
                                has_ctx = True
                                break
                    if not has_ctx:
                        results[p_num].append(f'Isolated transliteration: {line[:40]}')

        # 5. Corrupt symbols
        for l_idx, line in enumerate(lines):
            if any(s in line for s in ['✍', '◄', '►']) or ('<' in line and not line.startswith('<!--')):
                results[p_num].append(f'Corrupt symbol: {line[:40]}')

    print(f"Total pages scanned: {len(pages)}")
    print(f"Truly Critical Pages Found: {len(results)}")

    with open("reports/true_critical_pages_vol_02.json", "w", encoding="utf-8") as f:
        json.dump(dict(results), f, ensure_ascii=False, indent=2)

    print("\nSummary of all critical pages:")
    for p in sorted(results.keys()):
        print(f"Page {p} ({len(results[p])} issues): {', '.join(results[p])}")

if __name__ == "__main__":
    scan_critical_vol_02()

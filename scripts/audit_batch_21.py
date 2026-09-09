#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Audit script for Batch 21 (pages 12, 19, 24, 29, 131, 205, 267, 388, 419, 522).
"""

import re
import sys

def audit_batch_21():
    filepath = 'sources/text/fahares_vol_02.txt'
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()

    pages = [12, 19, 24, 29, 131, 205, 267, 388, 419, 522]
    all_ok = True

    # 1. Total tag count
    tags = re.findall(r'<!-- page: \d+ -->', content)
    print(f'Total tags count: {len(tags)}')
    if len(tags) != 1015:
        print(f'FAIL: Total tags count is {len(tags)}, expected 1015')
        all_ok = False
    else:
        print('PASS: Total tags count is 1015')

    # 2. Check each page
    for p in pages:
        m = re.search(rf'<!-- page: {p} -->.*?(?=<!-- page: {p+1} -->)', content, re.DOTALL)
        if not m:
            print(f'FAIL: Page {p} not found in text!')
            all_ok = False
            continue
        page_text = m.group(0)
        lines = [l.strip() for l in page_text.split('\n') if l.strip()]

        # Check consecutive bare copy headers (e.g. numbered lines without body)
        bare_header_count = 0
        for i in range(len(lines) - 1):
            l1 = lines[i]
            l2 = lines[i+1]
            if re.match(r'^\d+\.\s+[^؛]+؛\s*[^؛]+؛\s*شماره\s*نسخه', l1) and re.match(r'^\d+\.\s+[^؛]+؛\s*[^؛]+؛\s*شماره\s*نسخه', l2):
                print(f'FAIL on Page {p}: Consecutive bare headers:\n  {l1}\n  {l2}')
                all_ok = False
                bare_header_count += 1
        if bare_header_count == 0:
            print(f'PASS on Page {p}: Zero consecutive bare headers')

        # Check transliterations on own line
        for i, l in enumerate(lines):
            if re.search(r'^[a-zA-Zāīūšžčṭẓṣḍḥ]', l):
                # Must not contain Persian characters
                if re.search(r'[\u0600-\u06FF]', l):
                    print(f'FAIL on Page {p}: Mixed Persian/Latin transliteration line: {l}')
                    all_ok = False

    # 3. Check transitions
    transitions = [
        (18, 19), (19, 20), (23, 24), (28, 29),
        (130, 131), (131, 132), (204, 205), (205, 206),
        (266, 267), (267, 268), (387, 388), (388, 389),
        (418, 419), (419, 420), (521, 522), (522, 523)
    ]
    for p1, p2 in transitions:
        pattern = rf'<!-- page: {p1} -->.*?(?=<!-- page: {p2+1} -->)'
        m = re.search(pattern, content, re.DOTALL)
        if not m:
            print(f'FAIL: Transition {p1}->{p2} not found')
            all_ok = False
        else:
            # Check around <!-- page: p2 -->
            sub = m.group(0)
            tag = f'<!-- page: {p2} -->'
            idx = sub.find(tag)
            before = sub[max(0, idx-80):idx].strip()
            after = sub[idx+len(tag):min(len(sub), idx+len(tag)+80)].strip()
            print(f'Transition {p1}->{p2}: ...{before[-30:]} | TAG | {after[:30]}...')

    return all_ok

if __name__ == '__main__':
    ok = audit_batch_21()
    sys.exit(0 if ok else 1)

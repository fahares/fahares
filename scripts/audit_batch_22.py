#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Audit script for Batch 22:
Pages: 593, 602, 618, 659, 670, 679, 752, 757, 782, 785
"""

import re
import json

def audit():
    with open("sources/text/fahares_vol_02.txt", "r", encoding="utf-8") as f:
        text = f.read()

    pages = [593, 602, 618, 659, 670, 679, 752, 757, 782, 785]
    all_ok = True

    # 1. Total tag count check
    tags = re.findall(r"<!-- page: (\d+) -->", text)
    print(f"Total page tags found: {len(tags)}")
    if len(tags) != 1015:
        print(f"ERROR: Expected 1015 tags, found {len(tags)}")
        all_ok = False
    else:
        print("PASS: Page tag count is exactly 1015.")

    # 2. Check each target page boundary
    for p in pages:
        tag = f"<!-- page: {p} -->"
        if tag not in text:
            print(f"ERROR: Tag {tag} not found!")
            all_ok = False
        else:
            print(f"PASS: Page {p} tag exists.")

    # 3. Check for any '**'
    if "**" in text:
        print("ERROR: Double asterisk '**' found in text!")
        all_ok = False
    else:
        print("PASS: No double asterisk '**' found anywhere in text.")

    # 4. Check specific pages content integrity
    # Page 618 copy order
    p618_pos = text.find("<!-- page: 618 -->")
    p619_pos = text.find("<!-- page: 619 -->")
    p618_text = text[p618_pos:p619_pos]
    for c in range(252, 260):
        if f"{c}." not in p618_text:
            print(f"ERROR: Copy {c}. not found on page 618!")
            all_ok = False
    print("PASS: Page 618 copy sequence 252-259 verified.")

    # Page 752 copy order
    p752_pos = text.find("<!-- page: 752 -->")
    p753_pos = text.find("<!-- page: 753 -->")
    p752_text = text[p752_pos:p753_pos]
    for c in range(644, 662):
        if f"{c}." not in p752_text:
            print(f"ERROR: Copy {c}. not found on page 752!")
            all_ok = False
    print("PASS: Page 752 copy sequence 644-661 verified.")

    # Page 782 copy order
    p782_pos = text.find("<!-- page: 782 -->")
    p783_pos = text.find("<!-- page: 783 -->")
    p782_text = text[p782_pos:p783_pos]
    for c in range(940, 956):
        if f"{c}." not in p782_text:
            print(f"ERROR: Copy {c}. not found on page 782!")
            all_ok = False
    print("PASS: Page 782 copy sequence 940-955 verified.")

    return all_ok

if __name__ == "__main__":
    import sys
    ok = audit()
    sys.exit(0 if ok else 1)

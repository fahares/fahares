#!/usr/bin/env python3
"""
Fix merged header lines in FanKha text files.
In some OCR outputs, the author line or description was merged onto the work header line:
● عنوان / موضوع / زبان [آوانگاری] نام مؤلف، تاریخ
This script splits them into:
Line 1: ● عنوان / موضوع / زبان [آوانگاری]
Line 2: نام مؤلف، تاریخ
"""

import sys
import glob
import re
import argparse

KNOWN_LANGS = [
    'فارسی', 'عربی', 'ترکی', 'اردو', 'عبری', 'سریانی', 'پهلوی', 'اوستایی', 'کردی', 'پشتو', 
    'فرانسوی', 'انگلیسی', 'لاتین', 'لری'
]
LANG_JOIN = r'(?:\s*(?:و|به|-|،|,|/)\s*(?:' + '|'.join(KNOWN_LANGS) + r'))*'
LANG_PATTERN = r'(?:' + '|'.join(KNOWN_LANGS) + r')' + LANG_JOIN

TRANS_CHAR_RE = r'(?:<!--.*?-->|[a-zA-Zāīūšžčḍṭẓṣḥ‘ʻ\-\s\?0-9\(\)\'\’\:\/\.]|[\u02BE\u02BF\u02BD\u02BC])*'

def analyze_line(line_str):
    s = line_str.strip()
    if not s.startswith('●'):
        return None
    
    pattern = r'^(●\s*[^/]+(?:/[^/]+)?/\s*' + LANG_PATTERN + r')\s*(.*)$'
    m = re.match(pattern, s)
    if not m:
        pattern_single = r'^(●\s*[^/]+/\s*' + LANG_PATTERN + r')\s*(.*)$'
        m = re.match(pattern_single, s)
    
    if not m:
        return None
    
    head = m.group(1).strip()
    rest = m.group(2).strip()
    if not rest:
        return None
    
    m_lead = re.match(r'^(' + TRANS_CHAR_RE + r')(.*)$', rest)
    if not m_lead:
        return None
    
    lead_part = m_lead.group(1).strip()
    tail_part = m_lead.group(2).strip()
    
    if re.search(r'[\u0600-\u06FF]', tail_part):
        header_line = (head + (' ' + lead_part if lead_part else '')).strip()
        next_line = tail_part
        return header_line, next_line
    
    return None

def process_files(file_patterns, apply_changes=False):
    files = sorted(glob.glob(file_patterns))
    total_found = 0
    
    for fpath in files:
        with open(fpath, 'r', encoding='utf-8') as f:
            lines = f.readlines()
        
        new_lines = []
        file_modified = False
        
        for idx, line in enumerate(lines, 1):
            res = analyze_line(line)
            if res:
                total_found += 1
                header_line, next_line = res
                print(f"{fpath}:{idx}")
                print(f"  [-] {line.strip()}")
                print(f"  [+] {header_line}")
                print(f"  [+] {next_line}")
                new_lines.append(header_line + '\n')
                new_lines.append(next_line + '\n')
                file_modified = True
            else:
                new_lines.append(line)
        
        if apply_changes and file_modified:
            with open(fpath, 'w', encoding='utf-8') as f:
                f.writelines(new_lines)
            print(f"-> Applied changes to {fpath}")
            
    print(f"\nTotal merged header lines found: {total_found}")
    if not apply_changes:
        print("Dry-run complete. Run with --apply to write changes.")

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description="Fix merged header lines in FanKha")
    parser.add_argument('pattern', nargs='?', default='sources/text/fahares_vol_0[1-6].txt')
    parser.add_argument('--apply', action='store_true', help="Apply changes directly to files")
    args = parser.parse_args()
    
    process_files(args.pattern, args.apply)

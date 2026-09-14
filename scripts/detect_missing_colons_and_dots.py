#!/usr/bin/env python3
"""
Scanner and auditor for dropped colons (:) and dots (.) in manuscript blocks.
Detects:
1. Missing dot after manuscript sequence number (e.g., '2 تهران؛' -> '2. تهران؛')
2. Missing colon after 'خط' before script names (e.g., 'خط نستعلیق' -> 'خط: نستعلیق')
3. Missing colon after 'کا' before scribe name (e.g., 'کا محمد باقر' -> 'کا: محمد باقر')
4. Missing colon after 'جا' before place name (e.g., 'جا کرمان' -> 'جا: کرمان')
5. Missing colon after 'تا' before dates (e.g., 'تا 1234', 'تا قرن 10', 'تا بی‌تا' -> 'تا: ...')
6. Missing colon after 'آغاز' or 'انجام' before 'برابر' (e.g., 'آغاز برابر' -> 'آغاز: برابر')
"""

import re
import sys
from pathlib import Path

# Known script types for confident 'خط' detection
KNOWN_SCRIPTS = r'(?:نستعلیق|نسخ|شکسته|تعلیق|رقعه|کوفی|ثلث|ریحان|محقق|طومار|لاتین)'

# Known date markers for confident 'تا' detection
DATE_MARKERS = r'(?:\d+|قرن|با تاریخ|اوایل|اواخر|نیمه|بی‌تا|بی تا|غره|سلخ|جمادی|ربیع|شوال|رمضان|صفر|محرم|شعبان|ذوالقعده|ذیقعده|ذوالحجه|ذیحجه|سنه)'

# Content scope terms that distinguish manuscript completeness notes from dates
CONTENT_WORDS_RE = re.compile(r'(?:فصل|باب|مقاله|میمر|جزء|قسم|مطلب|کتاب|مقصد|حدیث|ثمره|شعبه|پایان|بیت|شعر)')

def audit_volume(file_path, apply_fixes=False):
    with open(file_path, 'r', encoding='utf-8') as f:
        lines = f.readlines()
        
    findings = []
    modified_lines = []
    
    for line_num, line in enumerate(lines, 1):
        original = line
        modified = line
        fixes = []
        
        # 1. Missing dot after leading number at manuscript line:
        # e.g., '2 تهران؛ مجلس؛ شماره نسخه:' -> '2. تهران؛ مجلس؛ شماره نسخه:'
        num_match = re.match(r'^(\d+)\s+([^.\d\s؛\n][^؛\n]*；.*شماره نسخه:)', modified)
        if num_match:
            fixed_num = f"{num_match.group(1)}. {num_match.group(2)}"
            modified = fixed_num + modified[num_match.end():]
            fixes.append(('missing_num_dot', num_match.group(1), f"{num_match.group(1)}."))
            
        # 2. Missing colon after 'خط':
        # Must be preceded by semicolon, comma, or start of line (NEVER 'به خط')
        # e.g., '؛ خط نستعلیق' -> '؛ خط: نستعلیق'
        script_pattern = re.compile(rf'(^|[؛،])\s*خط\s+({KNOWN_SCRIPTS})')
        if script_pattern.search(modified):
            modified = script_pattern.sub(r'\1 خط: \2', modified)
            fixes.append(('missing_script_colon', 'خط ...', 'خط: ...'))
            
        # 3. Missing colon after 'آغاز' / 'انجام' before 'برابر':
        # Avoid creating double colons like 'آغاز: برابر: برابر'
        inc_exp_pattern = re.compile(r'(^|[؛،\n])\s*(آغاز|انجام)\s+برابر(?!\s*:)')
        if inc_exp_pattern.search(modified):
            modified = inc_exp_pattern.sub(r'\1 \2: برابر', modified)
            fixes.append(('missing_inc_exp_colon', 'آغاز/انجام برابر', 'آغاز/انجام: برابر'))
            
        # 4. Missing colon after 'کا' before scribe:
        scribe_pattern = re.compile(r'(^|[؛،])\s*کا\s+([^:\s،؛][^،؛\n]*?)(?=(?:،\s*تا[:\s]|،\s*جا[:\s]|؛|\n|$))')
        if scribe_pattern.search(modified):
            modified = scribe_pattern.sub(r'\1 کا: \2', modified)
            fixes.append(('missing_scribe_colon', 'کا ...', 'کا: ...'))
            
        # 5. Missing colon after 'تا' before date markers:
        # Exclude volume/chapter content notes (e.g. "تا اوایل مقصد دوم", "تا اواخر باب پانزدهم")
        # and only match if the line doesn't already contain a date colon or بی‌تا
        if not re.search(r'(?:تا:|بی‌تا|بی تا)', modified):
            date_pattern = re.compile(rf'(^|[؛،])\s*تا\s+({DATE_MARKERS}[^،؛\n]*?)(?=(?:،\s*جا[:\s]|؛|\n|$))')
            m = date_pattern.search(modified)
            if m:
                matched_text = m.group(2)
                if not CONTENT_WORDS_RE.search(matched_text) and not any(w in matched_text for w in ['دارد', 'داراست', 'در بردارد']):
                    modified = date_pattern.sub(r'\1 تا: \2', modified)
                    fixes.append(('missing_date_colon', 'تا ...', 'تا: ...'))
            
        # 6. Missing colon after 'جا' before place:
        place_pattern = re.compile(r'(^|[؛،])\s*جا\s+([^:\s،؛][^؛\n]*?)(?=(?:؛|\n|$))')
        if place_pattern.search(modified):
            modified = place_pattern.sub(r'\1 جا: \2', modified)
            fixes.append(('missing_place_colon', 'جا ...', 'جا: ...'))
            
        if fixes and modified != original:
            findings.append({
                'line_num': line_num,
                'fixes': fixes,
                'original': original.strip(),
                'modified': modified.strip()
            })
            
        modified_lines.append(modified)
        
    if apply_fixes and findings:
        with open(file_path, 'w', encoding='utf-8') as f:
            f.writelines(modified_lines)
            
    return findings

def main():
    apply_fixes = '--apply' in sys.argv
    volumes = ['sources/text/fahares_vol_01.txt', 'sources/text/fahares_vol_02.txt', 'sources/text/fahares_vol_03.txt']
    
    total_findings = 0
    all_findings_by_type = {}
    
    for vol in volumes:
        path = Path(vol)
        if not path.exists():
            continue
            
        findings = audit_volume(path, apply_fixes=apply_fixes)
        total_findings += len(findings)
        print(f"\n=======================================================")
        print(f"AUDIT REPORT FOR: {path.name} {'[APPLIED]' if apply_fixes else '[DRY-RUN]'}")
        print(f"=======================================================")
        print(f"Total lines with detected dropped colons/dots: {len(findings)}")
        
        type_counts = {}
        for f in findings:
            for fix_type, _, _ in f['fixes']:
                type_counts[fix_type] = type_counts.get(fix_type, 0) + 1
                all_findings_by_type[fix_type] = all_findings_by_type.get(fix_type, 0) + 1
                
        for t, count in sorted(type_counts.items(), key=lambda x: x[1], reverse=True):
            print(f"  {t:25} -> {count} instances")
            
        print("\n--- Sample Candidates (First 5 in this volume) ---")
        for f in findings[:5]:
            print(f"Line {f['line_num']}:")
            print(f"  [-] {f['original']}")
            print(f"  [+] {f['modified']}")
            print()
            
    print(f"\n=======================================================")
    print(f"TOTAL DETECTED ACROSS ALL VOLUMES: {total_findings} lines {'(UPDATED IN PLACE)' if apply_fixes else ''}")
    print(f"=======================================================")
    for t, c in sorted(all_findings_by_type.items(), key=lambda x: x[1], reverse=True):
        print(f"  {t:25} -> {c} instances")

if __name__ == '__main__':
    main()


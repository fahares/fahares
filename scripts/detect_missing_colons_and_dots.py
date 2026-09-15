#!/usr/bin/env python3
"""
Scanner and auditor for punctuation standardization and missing colons/dots in FanKha text files.

Capabilities:
1. Missing punctuation detection & fix:
   - Missing dot after manuscript sequence number (e.g., '2 تهران؛' -> '2. تهران؛')
   - Missing colon after 'خط' before script names (e.g., 'خط نستعلیق' -> 'خط: نستعلیق')
   - Missing colon after 'کا' before scribe name (e.g., 'کا محمد باقر' -> 'کا: محمد باقر')
   - Missing colon after 'جا' before place name (e.g., 'جا کرمان' -> 'جا: کرمان')
   - Missing colon after 'تا' before dates (e.g., 'تا 1234', 'تا قرن 10' -> 'تا: ...')
   - Missing colon after 'آغاز' or 'انجام' before 'برابر' (e.g., 'آغاز برابر' -> 'آغاز: برابر')
   - Scrambled OCR typos (e.g. 'بی ،کا تا' -> 'بی‌کا، تا:', ' :کا' -> '، کا:')

2. Colon & Semicolon spacing standardization:
   - Colon (:) must attach to preceding word without space, and have exactly one space after.
   - Semicolon (؛) must attach to preceding word without space, and have exactly one space after.

3. Question mark standardization:
   - All ASCII English question marks '?' converted to Persian '؟'.
   - Initial '?نام' converted to '؟ نام'.
"""

import re
import sys
from pathlib import Path
from typing import List, Dict, Tuple, Any

KNOWN_SCRIPTS = r'(?:نستعلیق|نسخ|شکسته|تعلیق|رقعه|کوفی|ثلث|ریحان|محقق|طومار|لاتین)'
DATE_MARKERS = r'(?:\d+|قرن|با تاریخ|اوایل|اواخر|نیمه|بی‌تا|بی تا|غره|سلخ|جمادی|ربیع|شوال|رمضان|صفر|محرم|شعبان|ذوالقعده|ذیقعده|ذوالحجه|ذیحجه|سنه)'
CONTENT_WORDS_RE = re.compile(r'(?:فصل|باب|مقاله|میمر|جزء|قسم|مطلب|کتاب|مقصد|حدیث|ثمره|شعبه|پایان|بیت|شعر)')

def standardize_punctuation(line: str) -> Tuple[str, List[Tuple[str, str, str]]]:
    fixes = []
    original = line

    # 1. Question marks:
    s = line.strip()
    is_latin_line = (re.search(r'[a-zA-Z]', s) and not re.search(r'[\u0620-\u064A\u0671-\u06D3]', s)) or bool(re.fullmatch(r'\([0-9\?؟\s\-–\.\/A-Za-z]+\)', s))
    if is_latin_line:
        if '؟' in line:
            line = line.replace('؟', '?')
            fixes.append(('question_mark_latin', '؟', '?'))
    else:
        if '?' in line:
            fixed = re.sub(r'(^|[؛،\n\s])\?(?=[\u0600-\u06FF])', r'\1؟ ', line)
            fixed = fixed.replace('?', '؟')
            if fixed != line:
                fixes.append(('question_mark_persian', '?', '؟'))
                line = fixed

    # 2. Semicolon spacing:
    # Remove comma before semicolon: ،؛ or ، ؛ -> ؛
    if re.search(r'[،,][ \t]*؛', line):
        line = re.sub(r'[،,][ \t]*؛', '؛', line)
        fixes.append(('semicolon_cleanup_comma_before', '،؛', '؛'))

    # Remove comma after semicolon: ؛، or ؛ ، -> ؛
    if re.search(r'؛[ \t]*[،,]', line):
        line = re.sub(r'؛[ \t]*[،,]', '؛', line)
        fixes.append(('semicolon_cleanup_comma_after', '؛،', '؛'))

    # Remove whitespace before semicolon: [ \t]+؛ -> ؛
    if re.search(r'[ \t]+؛', line):
        line = re.sub(r'[ \t]+؛', '؛', line)
        fixes.append(('semicolon_attach_prev', ' ؛', '؛'))

    # Ensure space after semicolon when followed by word / opening punct
    if re.search(r'؛(?=[\u0600-\u06FFA-Za-z0-9«\(\[\"\'\-–])', line):
        line = re.sub(r'؛(?=[\u0600-\u06FFA-Za-z0-9«\(\[\"\'\-–])', '؛ ', line)
        fixes.append(('semicolon_space_after', '؛', '؛ '))

    # Normalize multiple spaces after semicolon
    if re.search(r'؛[ \t]{2,}', line):
        line = re.sub(r'؛[ \t]+', '؛ ', line)
        fixes.append(('semicolon_normalize_spaces', '؛  ', '؛ '))

    # 3. Colon spacing:
    # Remove whitespace before colon: (\S)[ \t]+: -> \1:
    # (Exclude HTML comments <!-- page: ... --> which already have no space before colon)
    if re.search(r'(\S)[ \t]+:', line):
        line = re.sub(r'(\S)[ \t]+:', r'\1:', line)
        fixes.append(('colon_attach_prev', ' :', ':'))

    # Ensure space after colon when followed by word / punct / digits / quote / bracket / dash
    # Avoid ::
    if re.search(r'(?<!:):(?=[\u0600-\u06FFA-Za-z0-9«\(\[\"\'\-–])', line):
        line = re.sub(r'(?<!:):(?=[\u0600-\u06FFA-Za-z0-9«\(\[\"\'\-–])', ': ', line)
        fixes.append(('colon_space_after', ':', ': '))

    # Normalize multiple spaces after colon
    if re.search(r':(?<!::)[ \t]{2,}', line):
        line = re.sub(r':(?<!::)[ \t]+', ': ', line)
        fixes.append(('colon_normalize_spaces', ':  ', ': '))

    return line, fixes

def fix_missing_structural_punctuation(line: str) -> Tuple[str, List[Tuple[str, str, str]]]:
    fixes = []
    modified = line

    # 0. OCR scrambled typos:
    if re.search(r'بی[ \t]*،[ \t]*کا[ \t]+تا[ \t]*:?', modified):
        modified = re.sub(r'بی[ \t]*،[ \t]*کا[ \t]+تا[ \t]*:?', 'بی‌کا، تا: ', modified)
        fixes.append(('ocr_fix_scribe_date', 'بی ،کا تا', 'بی‌کا، تا:'))

    if re.search(r'[ \t]+:کا[ \t]+', modified):
        modified = re.sub(r'[ \t]+:کا[ \t]+', '، کا: ', modified)
        fixes.append(('ocr_fix_isolated_colon_scribe', ' :کا ', '، کا: '))

    # 1. Missing dot after leading number at manuscript line:
    num_match = re.match(r'^(\d+)\s+([^.\d\s؛\n][^؛\n]*；.*شماره نسخه:)', modified)
    if num_match:
        fixed_num = f"{num_match.group(1)}. {num_match.group(2)}"
        modified = fixed_num + modified[num_match.end():]
        fixes.append(('missing_num_dot', num_match.group(1), f"{num_match.group(1)}."))

    # 2. Missing colon after 'خط':
    script_pattern = re.compile(rf'(^|[؛،\n])\s*خط\s+({KNOWN_SCRIPTS})')
    if script_pattern.search(modified):
        modified = script_pattern.sub(r'\1 خط: \2', modified)
        fixes.append(('missing_script_colon', 'خط ...', 'خط: ...'))

    # 3. Missing colon after 'آغاز' / 'انجام' before 'برابر':
    inc_exp_pattern = re.compile(r'(^|[؛،\n])\s*(آغاز|انجام)\s+برابر(?!\s*:)')
    if inc_exp_pattern.search(modified):
        modified = inc_exp_pattern.sub(r'\1 \2: برابر', modified)
        fixes.append(('missing_inc_exp_colon', 'آغاز/انجام برابر', 'آغاز/انجام: برابر'))

    # 4. Missing colon after 'کا' before scribe (exclude = مؤلف):
    scribe_pattern = re.compile(r'(^|[؛،])\s*کا\s+([^:\s،؛=][^،؛\n]*?)(?=(?:،\s*تا[:\s]|،\s*جا[:\s]|؛|\n|$))')
    if scribe_pattern.search(modified):
        modified = scribe_pattern.sub(r'\1 کا: \2', modified)
        fixes.append(('missing_scribe_colon', 'کا ...', 'کا: ...'))

    # 5. Missing colon after 'تا' before date markers:
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

    return modified, fixes

def audit_and_standardize_volume(file_path: Path, apply_fixes: bool = False, include_structural_fixes: bool = True) -> List[Dict[str, Any]]:
    with open(file_path, 'r', encoding='utf-8') as f:
        lines = f.readlines()

    findings = []
    modified_lines = []

    for line_num, line in enumerate(lines, 1):
        original = line
        modified = line
        line_fixes = []

        # Step 1: Structural missing colons and dots
        if include_structural_fixes:
            modified, struct_fixes = fix_missing_structural_punctuation(modified)
            line_fixes.extend(struct_fixes)

        # Step 2: Spacing around colons & semicolons, and question marks
        modified, punct_fixes = standardize_punctuation(modified)
        line_fixes.extend(punct_fixes)

        if line_fixes and modified != original:
            findings.append({
                'line_num': line_num,
                'fixes': line_fixes,
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

    # Check for specific volume numbers specified in command line
    target_vols = []
    for arg in sys.argv[1:]:
        if arg.isdigit():
            target_vols.append(int(arg))

    if not target_vols:
        target_vols = list(range(1, 7))

    total_findings = 0
    all_findings_by_type: Dict[str, int] = {}

    for vol_num in sorted(target_vols):
        path = Path(f'sources/text/fahares_vol_{vol_num:02d}.txt')
        if not path.exists():
            continue

        # Volumes 1-3 already had structural fixes applied earlier, but will run both safely.
        findings = audit_and_standardize_volume(path, apply_fixes=apply_fixes, include_structural_fixes=True)
        total_findings += len(findings)

        print(f"\n=======================================================")
        print(f"AUDIT REPORT FOR: {path.name} {'[APPLIED]' if apply_fixes else '[DRY-RUN]'}")
        print(f"=======================================================")
        print(f"Total lines modified: {len(findings)}")

        type_counts: Dict[str, int] = {}
        for f in findings:
            for fix_type, _, _ in f['fixes']:
                type_counts[fix_type] = type_counts.get(fix_type, 0) + 1
                all_findings_by_type[fix_type] = all_findings_by_type.get(fix_type, 0) + 1

        for t, count in sorted(type_counts.items(), key=lambda x: x[1], reverse=True):
            print(f"  {t:32} -> {count} instances")

        print("\n--- Sample Candidates (First 5 in this volume) ---")
        for f in findings[:5]:
            print(f"Line {f['line_num']}:")
            print(f"  [-] {f['original']}")
            print(f"  [+] {f['modified']}")
            print()

    print(f"\n=======================================================")
    print(f"TOTAL LINES MODIFIED ACROSS ALL VOLUMES: {total_findings} {'(APPLIED IN PLACE)' if apply_fixes else '(DRY RUN)'}")
    print(f"=======================================================")
    for t, c in sorted(all_findings_by_type.items(), key=lambda x: x[1], reverse=True):
        print(f"  {t:32} -> {c} instances")

if __name__ == '__main__':
    main()

# -*- coding: utf-8 -*-
"""
Single-pass Reconstruction and Rectification for Volume 2 (sources/text/fahares_vol_02.txt).
Reconstructs all 49 candidate interleaved pages and fixes referral and layout anomalies.
"""

import sys
import os
import re

sys.path.insert(0, 'scripts')
from vol_02_reconstruction_definitions import PAGE_RECONSTRUCTIONS

targets = [12, 19, 21, 24, 29, 38, 65, 80, 136, 205, 207, 263, 268, 289, 311, 358, 390, 413, 419, 425, 442, 526, 530, 537, 540, 555, 625, 644, 761, 769, 789, 792, 899, 915, 932, 965, 972, 981, 982, 993, 998, 999, 1000, 1002, 1003, 1005, 1013, 1014, 1020]

target_file = 'sources/text/fahares_vol_02.txt'

with open(target_file, 'r', encoding='utf-8') as f:
    text = f.read()

orig_tags = re.findall(r'<!--\s*page:\s*(\d+)\s*-->', text)
print(f"Original file has {len(orig_tags)} page tags (from {orig_tags[0]} to {orig_tags[-1]}).")
assert len(orig_tags) == 1015, f"Expected 1015 tags, found {len(orig_tags)}"

# 1. Apply all 49 page reconstructions
applied_pages = 0
for p in sorted(PAGE_RECONSTRUCTIONS.keys()):
    rec_content = PAGE_RECONSTRUCTIONS[p].strip()
    pat = rf'(<!--\s*page:\s*{p}\s*-->)([\s\S]*?)(<!--\s*page:\s*{p+1}\s*-->)'
    m = re.search(pat, text)
    if not m:
        print(f"FATAL ERROR: Could not find exact boundaries for Page {p}!")
        sys.exit(1)
    else:
        replacement = r'\1' + '\n' + rec_content + '\n\n' + r'\3'
        text = text[:m.start()] + re.sub(pat, replacement, text[m.start():m.end()]) + text[m.end():]
        applied_pages += 1
        print(f"Applied reconstruction for Page {p}.")

print(f"Successfully applied reconstructions for all {applied_pages}/{len(targets)} pages.")

# 2. Page 85 referral & heading fix (remove '=' from referral and restore entry title prefix)
p85_old = """احادیث قدسی (ترجمه) ← الاحادیث القدسیه احادیث کتاب الاربعین =

● صلوة المريدین فی فضائل ذکر رب العالمین / حدیث / عربی"""

p85_new = """احادیث قدسی (ترجمه) ← الاحادیث القدسیة

● احادیث کتاب الاربعین = صلوة المریدین فی فضائل ذکر رب العالمین / حدیث / عربی"""

if p85_old in text:
    text = text.replace(p85_old, p85_new)
    print("Successfully applied Page 85 referral and title rectification.")
else:
    print("FATAL ERROR: Page 85 target pattern not found!")
    sys.exit(1)

# 3. Page 281 self-referral removal (احوالات خاقان ← احوالات خاقان)
p281_old = "احوالات خاقان ← احوالات خاقان\n\n"
if p281_old in text:
    text = text.replace(p281_old, "")
    print("Successfully removed Page 281 self-referral.")
else:
    if "احوالات خاقان ← احوالات خاقان" in text:
        text = text.replace("احوالات خاقان ← احوالات خاقان\n", "")
        print("Successfully removed Page 281 self-referral (single newline).")
    else:
        print("FATAL ERROR: Page 281 self-referral target not found!")
        sys.exit(1)

# 4. Safe dictionary replacements for broken words with internal spaces
KNOWN_BROKEN_WORDS = [
    ('ت هران', 'تهران'),
    ('مر عشی', 'مرعشی'),
    ('مج لس', 'مجلس'),
    ('اصف هان', 'اصفهان'),
    ('دانش گاه', 'دانشگاه'),
    ('سپه سالار', 'سپهسالار'),
    ('گلپای گانی', 'گلپایگانی'),
    ('گوهر شاد', 'گوهرشاد'),
    ('فیض یه', 'فیضیه'),
    ('مل ک', 'ملک'),
    ('مل ی', 'ملی'),
    ('نس خ', 'نسخ'),
    ('نست علیق', 'نستعلیق'),
    ('شما ره', 'شماره'),
    ('نس خه', 'نسخه'),
    ('آغ از', 'آغاز'),
    ('انج ام', 'انجام'),
    ('بر ابر', 'برابر'),
    ('بی‌ک ا', 'بی‌کا'),
    ('بی‌ت ا', 'بی‌تا'),
    ('اندا زه', 'اندازه'),
    ('تی ماج', 'تیماج'),
    ('مق وایی', 'مقوایی'),
    ('فرن گی', 'فرنگی'),
    ('مصح ح', 'مصحح'),
    ('رقع ی', 'رقعی'),
    ('وزیر ی', 'وزیری'),
    ('رحل ی', 'رحلی'),
    ('س لاریه', 'سلاریه'),
]

broken_replacements = 0
for broken, correct in KNOWN_BROKEN_WORDS:
    cnt = text.count(broken)
    if cnt > 0:
        text = text.replace(broken, correct)
        broken_replacements += cnt

print(f"Applied {broken_replacements} broken word dictionary replacements.")

# 5. Verify Tag Integrity
new_tags = re.findall(r'<!--\s*page:\s*(\d+)\s*-->', text)
print(f"After all modifications, file has {len(new_tags)} page tags.")

if orig_tags == new_tags:
    print(f"INTEGRITY CHECK PASSED: All {len(new_tags)} page tags perfectly preserved!")
    with open(target_file, 'w', encoding='utf-8') as f:
        f.write(text)
    print(f"SUCCESS: Written all modifications to {target_file} in ONE SINGLE INTEGRATED PASS.")
else:
    print("FATAL ERROR: Page tags mismatch! Aborting write.")
    sys.exit(1)

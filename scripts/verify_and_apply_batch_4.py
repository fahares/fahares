# -*- coding: utf-8 -*-
import sys
import re
sys.path.insert(0, ".")
from scripts.batch_4_definitions import REPLACEMENTS

persian_digits = re.compile(r'[\u06f0-\u06f9]')
stray_star = re.compile(r'\*')

errors = []
for p, text in REPLACEMENTS.items():
    pd = persian_digits.findall(text)
    if pd:
        errors.append(f"Page {p} has Persian digits: {set(pd)}")
    stars = stray_star.findall(text)
    if stars:
        errors.append(f"Page {p} has stray *: {len(stars)}")
    open_b = text.count('[')
    close_b = text.count(']')
    if p == 156:
        # Page 156 closes a bracket opened on page 155
        if open_b + 1 != close_b:
            errors.append(f"Page {p} bracket mismatch: [ = {open_b}, ] = {close_b}")
    else:
        if open_b != close_b:
            errors.append(f"Page {p} bracket mismatch: [ = {open_b}, ] = {close_b}")

if errors:
    print("ERRORS FOUND IN DEFINITIONS:")
    for e in errors:
        print(" -", e)
    sys.exit(1)
else:
    print("Definitions verified: 0 Persian digits, 0 stray *, all brackets verified!")

# Now test replacing in fahares_vol_01.txt
file_path = "sources/text/fahares_vol_01.txt"
with open(file_path, "r", encoding="utf-8") as f:
    content = f.read()

initial_tags = len(re.findall(r'<!-- page: \d+ -->', content))
print(f"Initial page tag count: {initial_tags}")

modified_content = content

# Verification of current page presence
for p in sorted(REPLACEMENTS.keys()):
    tag = f"<!-- page: {p} -->"
    next_tag = f"<!-- page: {p+1} -->"
    pos = modified_content.find(tag)
    pos_next = modified_content.find(next_tag)
    if pos == -1:
        print(f"ERROR: tag {tag} not found!")
        sys.exit(1)
    if pos_next == -1:
        print(f"ERROR: next tag {next_tag} not found!")
        sys.exit(1)
    if pos >= pos_next:
        print(f"ERROR: tag order invalid for page {p}")
        sys.exit(1)

# Now apply replacements
for p in sorted(REPLACEMENTS.keys()):
    tag = f"<!-- page: {p} -->"
    next_tag = f"<!-- page: {p+1} -->"
    pos = modified_content.find(tag)
    pos_next = modified_content.find(next_tag)
    
    rep_text = REPLACEMENTS[p].rstrip() + "\n\n"
    
    # Replace the slice [pos:pos_next]
    modified_content = modified_content[:pos] + rep_text + modified_content[pos_next:]
    print(f"Page {p} successfully replaced.")

final_tags = len(re.findall(r'<!-- page: \d+ -->', modified_content))
print(f"Final page tag count: {final_tags}")
assert final_tags == 949, f"Expected 949 tags, got {final_tags}!"

# Write back
with open(file_path, "w", encoding="utf-8") as f:
    f.write(modified_content)

print("ALL 14 PAGES OF BATCH 4 APPLIED AND VERIFIED SUCCESSFULLY!")

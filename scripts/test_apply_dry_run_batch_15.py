import json
import re
import difflib

with open('sources/text/fahares_vol_02.txt', 'r', encoding='utf-8') as f:
    orig_full = f.read()

with open('reports/batch_15_reconstructed.json', 'r', encoding='utf-8') as f:
    reconstructed = json.load(f)

# Split original text by page tags
pattern = re.compile(r'(<!-- page: \d+ -->)')
parts = pattern.split(orig_full)

# Verify page count
page_tags = [parts[i] for i in range(1, len(parts), 2)]
page_contents = [parts[i+1] for i in range(1, len(parts), 2)]

print(f"Total page tags found: {len(page_tags)}")
assert len(page_tags) == 1015, f"Expected 1015 page tags, got {len(page_tags)}"

# Map page number to index
page_map = {}
for idx, tag in enumerate(page_tags):
    p_num = int(re.search(r'\d+', tag).group(0))
    page_map[p_num] = idx

# Prepare modified text
new_contents = list(page_contents)
for p_num in range(397, 407):
    idx = page_map[p_num]
    new_contents[idx] = "\n" + reconstructed[str(p_num)].strip() + "\n\n"

# Assemble new full text
new_full_parts = [parts[0]]
for i in range(len(page_tags)):
    new_full_parts.append(page_tags[i])
    new_full_parts.append(new_contents[i])
new_full = "".join(new_full_parts)

# Verify page tags in new_full
new_tags = pattern.findall(new_full)
print(f"Total page tags after replacement: {len(new_tags)}")
assert len(new_tags) == 1015, f"Expected 1015 page tags after replacement, got {len(new_tags)}"
assert new_tags[0] == '<!-- page: 7 -->'
assert new_tags[-1] == '<!-- page: 1021 -->'

# Generate diff
diff_lines = list(difflib.unified_diff(
    orig_full.splitlines(keepends=True),
    new_full.splitlines(keepends=True),
    fromfile='sources/text/fahares_vol_02.txt (original)',
    tofile='sources/text/fahares_vol_02.txt (batch 15 reconstructed)'
))

with open('reports/vol_02_batch_15_diff.txt', 'w', encoding='utf-8') as f:
    f.writelines(diff_lines)

print(f"Diff written to reports/vol_02_batch_15_diff.txt ({len(diff_lines)} lines).")

# Check continuity between pages
print("\n--- CONTINUITY CHECKS ---")
for p in range(396, 407):
    idx = page_map[p]
    tail = [line for line in new_contents[idx].strip().splitlines() if line][-1]
    next_idx = page_map[p+1]
    head = [line for line in new_contents[next_idx].strip().splitlines() if line][0]
    print(f"Page {p} tail: {tail[:80]!r}")
    print(f"Page {p+1} head: {head[:80]!r}")
    print("---")

# Application script for Batch 15 (Pages 397 to 406)
import json
import re

TARGET_FILE = 'sources/text/fahares_vol_02.txt'

with open(TARGET_FILE, 'r', encoding='utf-8') as f:
    orig_full = f.read()

with open('reports/batch_15_reconstructed.json', 'r', encoding='utf-8') as f:
    reconstructed = json.load(f)

pattern = re.compile(r'(<!-- page: \d+ -->)')
parts = pattern.split(orig_full)

page_tags = [parts[i] for i in range(1, len(parts), 2)]
page_contents = [parts[i+1] for i in range(1, len(parts), 2)]

assert len(page_tags) == 1015, f"Expected 1015 page tags, got {len(page_tags)}"

page_map = {}
for idx, tag in enumerate(page_tags):
    p_num = int(re.search(r'\d+', tag).group(0))
    page_map[p_num] = idx

new_contents = list(page_contents)
for p_num in range(397, 407):
    idx = page_map[p_num]
    new_contents[idx] = "\n" + reconstructed[str(p_num)].strip() + "\n\n"

new_full_parts = [parts[0]]
for i in range(len(page_tags)):
    new_full_parts.append(page_tags[i])
    new_full_parts.append(new_contents[i])
new_full = "".join(new_full_parts)

new_tags = pattern.findall(new_full)
assert len(new_tags) == 1015, f"Expected 1015 page tags after replacement, got {len(new_tags)}"
assert new_tags[0] == '<!-- page: 7 -->'
assert new_tags[-1] == '<!-- page: 1021 -->'

with open(TARGET_FILE, 'w', encoding='utf-8') as f:
    f.write(new_full)

print("Successfully applied Batch 15 (pages 397..406) to sources/text/fahares_vol_02.txt")

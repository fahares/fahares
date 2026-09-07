import re
import json

with open('sources/text/fahares_vol_02.txt', 'r', encoding='utf-8') as f:
    orig_text = f.read()

from test_reconstruct_batch_14 import pages_content

# Build replacement
text = orig_text
for p_num in [390, 393, 394, 395, 396]:
    tag = f"<!-- page: {p_num} -->"
    next_tag = f"<!-- page: {p_num + 1} -->"
    
    # find section
    pattern = re.compile(rf"{re.escape(tag)}.*?(?={re.escape(next_tag)})", re.DOTALL)
    m = pattern.search(text)
    assert m is not None, f"Could not find section for page {p_num}"
    
    new_page_str = pages_content[p_num] + "\n\n"
    text = text[:m.start()] + new_page_str + text[m.end():]

# Verify all tags in new text
tags = re.findall(r'<!-- page: \d+ -->', text)
nums = [int(re.search(r'\d+', t).group(0)) for t in tags]

print(f"Total page tags: {len(nums)}")
print(f"Min: {min(nums)}, Max: {max(nums)}")
expected = list(range(7, 1022))
if nums == expected:
    print("ALL 1,015 PAGE TAGS MATCH EXPECTED PERFECTLY!")
else:
    print("PAGE TAG MISMATCH!")

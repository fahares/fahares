import re

with open('sources/text/fahares_vol_02.txt', 'r', encoding='utf-8') as f:
    text = f.read()

tags = re.findall(r'<!-- page: (\d+) -->', text)
print(f"Total tags: {len(tags)}")
nums = [int(t) for t in tags]
expected = list(range(7, 1022))
if nums == expected:
    print("SUCCESS: Exact 1,015 continuous pages from 7 to 1021!")
else:
    print("MISMATCH! Missing or duplicate tags:")
    s_nums = set(nums)
    s_exp = set(expected)
    print("Missing:", sorted(list(s_exp - s_nums)))
    print("Extra/Duplicate:", len(nums) - len(s_nums))

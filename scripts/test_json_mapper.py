import os
import sys
import json
import re
import time

start_t = time.time()

# Load volume starts
with open('sources/volume_starts.txt', 'r', encoding='utf-8') as f:
    v_lines = f.readlines()

vol_starts = {}
for line in v_lines:
    m = re.match(r'vol_(\d+):\s*(\d+)\.json,\s*sheet\s*(\d+)', line)
    if m:
        vol = int(m.group(1))
        j_num = int(m.group(2))
        sheet = int(m.group(3))
        vol_starts[vol] = (j_num, sheet)

def get_json_files_for_vol(vol):
    j_start, _ = vol_starts.get(vol, (1, 1))
    next_vol = vol + 1
    if next_vol in vol_starts:
        j_end, _ = vol_starts[next_vol]
    else:
        j_end = 68
    # return list of json files covering this volume
    return list(range(j_start, min(j_end + 2, 69)))

print("Vol 1 JSON files:", get_json_files_for_vol(1))
print("Vol 12 JSON files:", get_json_files_for_vol(12))

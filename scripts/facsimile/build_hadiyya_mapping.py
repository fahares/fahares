"""Build the printed column -> PDF leaf table for the Hadiyyat al-Arifin scans (see build_kashf_mapping.py).

Output: hadiyya_page_mapping.json  {"01": {"402": [401, "L"], ...}, "02": {...}}
"""
from build_kashf_mapping import write
from hadiyya_readings import BOUNDS, R, STEP

if __name__ == '__main__':
    write('hadiyya', R, BOUNDS, STEP)

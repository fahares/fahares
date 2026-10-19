"""Build the printed column -> PDF leaf table for the Idah al-Maknun scans (see build_kashf_mapping.py).

Output: idah_page_mapping.json  {"01": {"302": [301, "L"], ...}, "02": {...}}
"""
from build_kashf_mapping import write
from idah_readings import BOUNDS, R, STEP

if __name__ == '__main__':
    write('idah', R, BOUNDS, STEP)

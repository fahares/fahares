import os
import sys
import time

sys.path.insert(0, os.path.dirname(__file__))
from scan_stray_bullets import is_main_entry_title

def apply_all(dry_run=False):
    total_modified_lines = 0
    total_files_modified = 0
    start_t = time.time()

    for vol in range(1, 35):
        fpath = f'sources/text/fahares_vol_{vol:02d}.txt'
        if not os.path.exists(fpath):
            continue

        with open(fpath, 'r', encoding='utf-8') as f:
            lines = [l.rstrip('\r\n') for l in f]

        num_lines = len(lines)
        new_lines = []
        file_mod_count = 0

        for l_idx, line_str in enumerate(lines, 1):
            clean = line_str.strip()
            if '●' not in clean:
                new_lines.append(line_str)
                continue

            if is_main_entry_title(clean):
                new_lines.append(line_str)
                continue

            # Strip bullet
            import re
            if clean.startswith('●'):
                mod = re.sub(r'^●\s*', '', clean)
            else:
                mod = re.sub(r'\s*●\s*', ' ', clean)

            new_lines.append(mod)
            file_mod_count += 1

        if file_mod_count > 0:
            total_files_modified += 1
            total_modified_lines += file_mod_count
            if not dry_run:
                with open(fpath, 'w', encoding='utf-8') as f:
                    f.write('\n'.join(new_lines) + '\n')
            print(f"Vol {vol:02d}: {'[DRY-RUN] would modify' if dry_run else 'modified'} {file_mod_count} lines")

    print(f"DONE in {time.time() - start_t:.2f}s! Total files: {total_files_modified}, total lines: {total_modified_lines}")

if __name__ == '__main__':
    dry_run_mode = '--dry-run' in sys.argv
    apply_all(dry_run=dry_run_mode)

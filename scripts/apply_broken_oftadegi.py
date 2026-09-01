import os
import sys
import re
import time

def apply_all(dry_run=False):
    total_modified_cases = 0
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
        i = 0
        file_mod_count = 0

        while i < num_lines:
            line_str = lines[i]
            clean = line_str.strip()

            # Check if line ends with 'افتادگی:' or 'افتادگی'
            if clean and re.search(r'(?:؛|\s|^)افتادگی\s*:?\s*$', clean):
                # Look ahead for page tags and next non-empty line
                k = i + 1
                page_tags = []
                next_l_idx = -1
                while k < min(i + 6, num_lines):
                    nxt = lines[k].strip()
                    if not nxt:
                        k += 1
                        continue
                    if nxt.startswith('<!--') and nxt.endswith('-->'):
                        page_tags.append(nxt)
                        k += 1
                        continue
                    next_l_idx = k
                    break

                if next_l_idx != -1:
                    next_l = lines[next_l_idx].strip()
                    clean_norm = re.sub(r'(?:؛|\s|^)افتادگی\s*:?\s*$', ' افتادگی:', clean).strip()
                    if clean.endswith('؛ افتادگی:') or clean.endswith('؛ افتادگی'):
                        clean_norm = re.sub(r'؛\s*افتادگی\s*:?\s*$', '؛ افتادگی:', clean).strip()

                    if page_tags:
                        pt_str = ' '.join(page_tags)
                        merged = f"{clean_norm} {pt_str} {next_l}"
                    else:
                        merged = f"{clean_norm} {next_l}"

                    new_lines.append(merged)
                    file_mod_count += 1
                    i = next_l_idx + 1
                    continue

            new_lines.append(line_str)
            i += 1

        if file_mod_count > 0:
            total_files_modified += 1
            total_modified_cases += file_mod_count
            if not dry_run:
                with open(fpath, 'w', encoding='utf-8') as f:
                    f.write('\n'.join(new_lines) + '\n')
            print(f"Vol {vol:02d}: {'[DRY-RUN] would merge' if dry_run else 'merged'} {file_mod_count} cases")

    print(f"DONE in {time.time() - start_t:.2f}s! Total files: {total_files_modified}, total merged cases: {total_modified_cases}")

if __name__ == '__main__':
    dry_run_mode = '--dry-run' in sys.argv
    apply_all(dry_run=dry_run_mode)

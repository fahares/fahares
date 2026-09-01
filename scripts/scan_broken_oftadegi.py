import os
import sys
import re
import time

start_t = time.time()

DEFECT_STARTS = [
    'آغاز', 'انجام', 'آغاز و انجام', 'انجام و آغاز', 'از آغاز', 'از انجام', 'از آغاز و انجام',
    'از اول', 'از آخر', 'از میانه', 'از میان', 'از وسط', 'میانه', 'میان', 'وسط', 'متن',
    'اول', 'آخر', 'پایان', 'چند برگ', 'یک برگ', 'دو برگ', 'سه برگ', 'چندین برگ', 'برگ اول',
    'برگ آخر', 'برگهای اول', 'صفحه اول', 'صفحه آخر', 'برگی', 'اوراق', 'نسخه', 'در آغاز', 'در انجام'
]

def analyze():
    candidates = []
    
    for vol in range(1, 35):
        fpath = f'sources/text/fahares_vol_{vol:02d}.txt'
        if not os.path.exists(fpath):
            continue

        with open(fpath, 'r', encoding='utf-8') as f:
            lines = [l.rstrip('\r\n') for l in f]

        num_lines = len(lines)
        for l_idx, line_str in enumerate(lines, 1):
            clean = line_str.strip()
            if not clean:
                continue

            # Check if line ends with 'افتادگی:' or 'افتادگی'
            m_oft = re.search(r'(?:؛|\s|^)افتادگی\s*:?\s*$', clean)
            if m_oft:
                # Find next non-empty line (and track page tags)
                next_l_idx = -1
                page_tags = []
                for k in range(l_idx, min(l_idx + 5, num_lines)):
                    nxt = lines[k].strip()
                    if not nxt:
                        continue
                    if nxt.startswith('<!--') and nxt.endswith('-->'):
                        page_tags.append(nxt)
                        continue
                    next_l_idx = k
                    break

                if next_l_idx != -1:
                    next_l = lines[next_l_idx].strip()
                    
                    # Check if next_l starts with a defect continuation
                    starts_with_defect = any(next_l.startswith(ds) for ds in DEFECT_STARTS)
                    
                    # Normalize 'افتادگی' at end of line to 'افتادگی:'
                    clean_norm = re.sub(r'(?:؛|\s|^)افتادگی\s*:?\s*$', ' افتادگی:', clean).strip()
                    if clean.endswith('؛ افتادگی:') or clean.endswith('؛ افتادگی'):
                        clean_norm = re.sub(r'؛\s*افتادگی\s*:?\s*$', '؛ افتادگی:', clean).strip()
                    
                    # Determine proposed fix
                    cat = "شکستگی فیلد افتادگی (پیوسته)"
                    if page_tags:
                        cat = "شکستگی فیلد افتادگی (همراه با برچسب صفحه)"
                        pt_str = ' '.join(page_tags)
                        proposed = f"{clean_norm} {pt_str} {next_l}"
                    else:
                        proposed = f"{clean_norm} {next_l}"

                    candidates.append({
                        'vol': vol,
                        'line_num': l_idx,
                        'next_line_num': next_l_idx + 1,
                        'category': cat,
                        'current_line1': clean,
                        'page_tags': page_tags,
                        'current_line2': next_l,
                        'proposed': proposed,
                        'starts_with_defect': starts_with_defect
                    })

    report_path = 'reports/candidate_broken_oftadegi_lines.md'
    os.makedirs('reports', exist_ok=True)
    with open(report_path, 'w', encoding='utf-8') as f:
        f.write("# گزارش جامع شناسایی و پیوستگی شکستگی‌های فیلد «افتادگی:»\n\n")
        f.write(f"تعداد کل موارد شناسایی‌شده: **{len(candidates):,}** مورد\n\n")

        cat_counts = {}
        for c in candidates:
            cat_counts[c['category']] = cat_counts.get(c['category'], 0) + 1

        f.write("### 📊 آمار موارد به تفکیک دسته‌بندی:\n\n")
        for cat, count in sorted(cat_counts.items(), key=lambda x: x[1], reverse=True):
            f.write(f"- **{cat}:** {count:,} مورد\n")
        f.write("\n" + "="*60 + "\n\n")

        for idx, c in enumerate(candidates, 1):
            f.write(f"### شماره {idx} (جلد {c['vol']:02d} - سطر {c['line_num']} تا {c['next_line_num']} | دسته‌بندی: {c['category']})\n\n")
            f.write(f"🔴 **وضعیت فعلی (شکسته در دو سطر):**\n```text\n")
            f.write(f"{c['current_line1']}\n")
            if c['page_tags']:
                for pt in c['page_tags']:
                    f.write(f"{pt}\n")
            f.write(f"{c['current_line2']}\n```\n\n")
            f.write(f"🟢 **اصلاح پیشنهادی (پیوستگی در یک سطر):**\n```text\n{c['proposed']}\n```\n\n")
            f.write("\n---\n\n")

    print(f"Finished in {time.time() - start_t:.2f}s! Total items: {len(candidates)} -> {report_path}")

if __name__ == '__main__':
    analyze()

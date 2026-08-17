import os
import glob
import re
import json

def scan_file(json_fpath):
    fname = os.path.basename(json_fpath)
    try:
        with open(json_fpath, 'r', encoding='utf-8', errors='ignore') as f:
            data = json.load(f)
    except Exception as e:
        return []

    pat_span = re.compile(r'<span[^>]*?data-confidence=["\']([0-9\.]+)["\'][^>]*?>(.*?)</span>', re.IGNORECASE | re.DOTALL)
    pat_mixed = re.compile(r'([آ-ی][a-zA-Z]|[a-zA-Z][آ-ی])')
    
    words_list = []
    anomalies_indices = []

    def walk(node):
        if isinstance(node, dict):
            html = node.get('html', '')
            if html:
                for m in pat_span.finditer(html):
                    try:
                        conf = float(m.group(1))
                    except:
                        conf = 1.0
                    raw_w = re.sub(r'<[^>]+>', '', m.group(2)).strip()
                    if raw_w:
                        idx = len(words_list)
                        words_list.append(raw_w)
                        is_mixed = bool(pat_mixed.search(raw_w))
                        # Filter mixed Persian-English OR low confidence (< 0.10)
                        if is_mixed or conf < 0.10 or 'فوqe' in raw_w:
                            anomalies_indices.append((idx, raw_w, conf, is_mixed))
                            
            for c in node.get('children', []):
                walk(c)

    walk(data)

    file_anomalies = []
    for idx, word, conf, is_mixed in anomalies_indices:
        start = max(0, idx - 5)
        end = min(len(words_list), idx + 6)
        snippet_words = []
        for i in range(start, end):
            if i == idx:
                snippet_words.append(f"👉 [{words_list[i]}] 👈")
            else:
                snippet_words.append(words_list[i])
                
        clean_snippet = " ".join(snippet_words)
        file_anomalies.append({
            'word': word,
            'conf': conf,
            'is_mixed': is_mixed,
            'file': fname,
            'snippet': clean_snippet
        })

    return file_anomalies

def main():
    json_files = sorted(glob.glob('sources/json/*.json'))
    print(f"در حال استخراج ناهنجاری‌ها با بافت ۱۰۰٪ خالص از درخت DOM...")
    
    all_anomalies = []
    mixed_freq = {}

    for fpath in json_files:
        items = scan_file(fpath)
        all_anomalies.extend(items)
        for it in items:
            if it['is_mixed']:
                w = it['word']
                mixed_freq[w] = mixed_freq.get(w, 0) + 1
                
    print(f"✅ اسکن تمام شد! مجموعاً {len(all_anomalies)} مورد ناهنجاری کشف شد.")
    print(f"تعداد کل واژه‌های ترکیبی (فارسی-لاتین): {len(mixed_freq)}")

    anomalies_sorted = sorted(all_anomalies, key=lambda x: (0 if x['is_mixed'] else 1, x['conf'], x['word']))

    os.makedirs('scratch', exist_ok=True)
    report = []
    report.append('# 📊 گزارش ناهنجاری‌های OCR با بافت متنی ۱۰۰٪ خالص (استخراج از درخت DOM)\n')
    report.append(f'این گزارش شامل **{len(all_anomalies)} مورد** واژه با ضریب اطمینان پایین یا ترکیبی است که بافت متنی آن‌ها ۱۰۰٪ خالص (بدون هیچ تگ یا ویژگی HTML) با ۵ کلمه قبل و بعد استخراج شده است.\n')
    
    report.append('## ⚠️ ۱. پر تکرارترین واژه‌های ترکیبی فارسی-لاتین:\n')
    for w, cnt in sorted(mixed_freq.items(), key=lambda x: x[1], reverse=True)[:80]:
        report.append(f'- **`{w}`**: {cnt} بار تکرار')

    report.append('\n---\n')
    report.append('## 🔍 ۲. نمونه‌های واقعی ناهنجاری با بافت متنی خالص (۵ کلمه قبل و بعد):\n')

    seen_words = set()
    for item in anomalies_sorted:
        w = item['word']
        if w not in seen_words:
            seen_words.add(w)
            conf_pct = item['conf'] * 100
            mixed_flag = " ⚠️ [حروف ترکیبی فارسی-انگلیسی]" if item['is_mixed'] else ""
            report.append(f'\n### واژه مشکوک: `{w}` (اطمینان OCR: {conf_pct:.1f}%){mixed_flag}')
            report.append(f'- **فایل منبع**: `{item["file"]}`')
            report.append(f'- **بافت متنی خالص (۵ کلمه قبل و بعد)**:\n  > ... {item["snippet"]} ...')
            if len(seen_words) >= 200:
                break

    with open('scratch/low_confidence_anomalies_report.md', 'w', encoding='utf-8') as f:
        f.write('\n'.join(report))

    print("✅ گزارش جدید با بافت ۱۰۰٪ خالص در scratch/low_confidence_anomalies_report.md ذخیره شد.")

if __name__ == '__main__':
    main()

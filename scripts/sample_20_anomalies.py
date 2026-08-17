import os
import json
import glob
import re

pat_span = re.compile(r'<span[^>]*?data-confidence=["\']([0-9\.]+)["\'][^>]*?>(.*?)</span>', re.IGNORECASE | re.DOTALL)
pat_mixed = re.compile(r'([آ-ی][a-zA-Z]|[a-zA-Z][آ-ی])')

json_files = sorted(glob.glob('sources/json/*.json'))

sample_anomalies = []
seen_words = set()

for fpath in json_files:
    try:
        with open(fpath, 'r', encoding='utf-8', errors='ignore') as f:
            data = json.load(f)
    except:
        continue
        
    words_list = []
    anomalies_local = []
    
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
                        if is_mixed:
                            anomalies_local.append((idx, raw_w, conf))
                            
            for c in node.get('children', []):
                walk(c)

    walk(data)
    
    for idx, word, conf in anomalies_local:
        if word not in seen_words:
            seen_words.add(word)
            start = max(0, idx - 7)
            end = min(len(words_list), idx + 8)
            
            words_before = words_list[start:idx]
            words_after = words_list[idx+1:end]
            
            context_str = f"{' '.join(words_before)} 👉 [{word}] 👈 {' '.join(words_after)}"
            
            sample_anomalies.append({
                'word': word,
                'conf': conf,
                'file': os.path.basename(fpath),
                'context': context_str
            })
            
            if len(sample_anomalies) >= 20:
                break
    if len(sample_anomalies) >= 20:
        break

print("=== 🔍 ۲۰ نمونه ناهنجاری استخراج شده ===")
for i, item in enumerate(sample_anomalies, 1):
    print(f"{i:02d}. کلمه: {item['word']:<15} | فایل: {item['file']}")
    print(f"    بافت: ... {item['context']} ...\n")


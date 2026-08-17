import json
import re

with open('sources/json/20.json', 'r', encoding='utf-8') as f:
    data = json.load(f)

pat_span = re.compile(r'data-confidence=["\']([0-9\.]+)["\'][^>]*?>(.*?)</span>', re.IGNORECASE)
pat_mixed = re.compile(r'([آ-ی][a-zA-Z]|[a-zA-Z][آ-ی])')

# Collect all text spans in order from HTML blocks
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
                    if is_mixed or conf < 0.10 or 'حiale' in raw_w:
                        anomalies_indices.append((idx, raw_w, conf, is_mixed))
                        
        for c in node.get('children', []):
            walk(c)

walk(data)

print(f"Total words collected: {len(words_list)}")
print(f"Total anomalies found: {len(anomalies_indices)}")

for idx, word, conf, is_mixed in anomalies_indices[:15]:
    start = max(0, idx - 5)
    end = min(len(words_list), idx + 6)
    snippet_words = []
    for i in range(start, end):
        if i == idx:
            snippet_words.append(f"👉 [{words_list[i]}] 👈")
        else:
            snippet_words.append(words_list[i])
            
    print(f"\nWord: {word:<15} | Conf: {conf*100:5.1f}%")
    print("Snippet:", " ".join(snippet_words))


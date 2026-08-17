import json
import re

with open('sources/json/1.json', 'r', encoding='utf-8') as f:
    text = f.read()

spans = text.split('data-confidence=')
print("Total data-confidence occurrences in 1.json:", len(spans)-1)

pat_mixed = re.compile(r'([آ-ی][a-zA-Z]|[a-zA-Z][آ-ی])')

anomalies = []
for chunk in spans[1:]:
    # chunk starts like: \"0.998\">–</span> ...
    m_conf = re.search(r'^[\\"]*([0-9\.]+)', chunk)
    if not m_conf: continue
    
    conf = float(m_conf.group(1))
    
    gt_idx = chunk.find('>')
    lt_idx = chunk.find('<', gt_idx)
    if gt_idx != -1 and lt_idx != -1:
        word = chunk[gt_idx+1:lt_idx].strip()
        word = word.replace('\\', '').strip()
        if not word or word.isnumeric(): continue
        
        is_mixed = bool(pat_mixed.search(word))
        if conf < 0.50 or is_mixed or 'فوqe' in word:
            anomalies.append((word, conf, is_mixed))

print(f"✅ Total anomalies extracted from 1.json: {len(anomalies)}")
print("Sample anomalies:")
for w, conf, is_mixed in anomalies[:35]:
    print(f" - Word: {w:<25} | Conf: {conf*100:5.1f}% | Mixed: {is_mixed}")


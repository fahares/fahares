"""Cuts the blocker's tasks of the untied entries into batches of 50 for the judging agents.
usage: batches.py <dir> <source-code>   (reads <dir>/tasks_<code>.jsonl, writes <dir>/full/)"""
import json, re, random, sys, os
D, CODE = sys.argv[1], sys.argv[2]
os.makedirs(D + '/full', exist_ok=True)
T = [json.loads(l) for l in open(f'{D}/tasks_{CODE}.jsonl', encoding='utf8')]
# an entry that only points to another one is no life
ptr = re.compile('يأتي|یأتی|ياتي|راجع|مر بعنوان|مرّ بعنوان|تقدم بعنوان|المذكور بعنوان|خواهد آمد|رجوع شود|رجوع نمایند|مذکور شد|نگارش یافت')
keep = [t for t in T if not t['known'] and not (len(t['text']) < 170 and ptr.search(t['text']))]
random.seed(1)
random.shuffle(keep)
json.dump(keep, open(D + '/full/full_tasks.json', 'w', encoding='utf8'), ensure_ascii=False)
for n, b in enumerate(range(0, len(keep), 50), 1):
    out = [dict(id=t['item_id'], volume=t['volume'], heading=t['title'], text=t['text'],
                candidates=[dict(n=c['n'], name=c['name'], other_names=c['aliases'], dates=c['dates'], works=c['works'], works_count=c['works_count']) for c in t['candidates']])
           for t in keep[b:b + 50]]
    json.dump(out, open(f'{D}/full/batch_{n:03d}.json', 'w', encoding='utf8'), ensure_ascii=False, indent=1)
print(len(keep), 'entries in', n, 'batches')

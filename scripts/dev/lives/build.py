"""Checks the judged batches beside it and builds the data file of ties.
usage: build.py <source-code> <step> [<out.json>]"""
import json, glob, os, re, sys, collections
# a short entry that only points to another one is no life, whatever the model answered
POINTER = re.compile('يأتي|یأتی|ياتي|راجع|ذكر في|مر في|مرّ في|مر بعنوان|مرّ بعنوان|تقدم|المذكور بعنوان')
D = os.path.dirname(os.path.abspath(__file__))
CODE, STEP = sys.argv[1], sys.argv[2]
tasks = {t['item_id']: t for t in json.load(open(D + '/full_tasks.json', encoding='utf8'))}
c = collections.Counter()
rows = {}
for f in sorted(glob.glob(D + '/out_*.json')):
    try:
        data = json.load(open(f, encoding='utf8'))
    except Exception as e:
        print('BAD FILE', f, e); c['bad_file'] += 1
        continue
    for r in data:
        try:
            iid = int(r['id'])
        except Exception:
            c['bad_row'] += 1; continue
        if iid not in tasks:
            c['unknown_id'] += 1; continue
        if iid in rows:
            c['twice'] += 1; continue
        rows[iid] = r
print('judged', len(rows), 'of', len(tasks), 'missing', len(set(tasks) - set(rows)))
ties = []
for iid, r in rows.items():
    t = tasks[iid]
    try:
        n = int(r.get('choice') or 0)
        conf = int(r.get('confidence') or 0)
    except Exception:
        c['bad_row'] += 1; continue
    if n == 0:
        c['none'] += 1; continue
    if len(t['text']) < 170 and POINTER.search(t['text']):
        c['pointer_entry'] += 1; continue
    cand = next((x for x in t['candidates'] if x['n'] == n), None)
    named = [x for x in t['candidates'] if x['name'].strip() == (r.get('chosen_name') or '').strip()]
    if not cand:
        c['bad_choice'] += 1; continue
    if cand not in named:
        # the number and the name disagree: a slip. Take neither.
        c['slip'] += 1
        print('  slip', iid, n, '|', r.get('chosen_name'), '| candidate', cand['name'])
        continue
    if len(named) > 1:
        c['same_name_candidates'] += 1
    ev = (r.get('evidence') or '').strip()
    quoted = bool(ev) and ev in (t['title'] + ' ' + t['text'])
    c['tie'] += 1
    c['tie_quoted'] += quoted
    ties.append(dict(person_slug=cand['slug'], person_name=cand['name'], source=CODE, item_key=t['item_key'], volume=t['volume'],
                     method='name_llm', confidence=conf if quoted else min(conf, 85), judged_by='sonnet', evidence=ev[:200], reason=(r.get('reason') or '')[:200],
                     heading=t['title']))
# one life a person and source: the surest stays
by = collections.defaultdict(list)
for x in ties:
    by[x['person_slug']].append(x)
out = []
for slug, xs in by.items():
    xs.sort(key=lambda x: -x['confidence'])
    out.append(xs[0])
    if len(xs) > 1:
        # two entries chose one person: the book has him twice, or they are namesakes. The surest stays, for reviewers only
        xs[0]['confidence'] = min(xs[0]['confidence'], 89)
        xs[0]['other_entries'] = [x['item_key'] + ' v' + str(x['volume']) for x in xs[1:]]
        c['person_twice'] += 1
        c['dropped_second_entry'] += len(xs) - 1
out.sort(key=lambda x: (x['volume'], x['item_key']))
bands = collections.Counter('90+' if x['confidence'] >= 90 else ('80-89' if x['confidence'] >= 80 else '<80') for x in out)
print(dict(c)); print('ties kept', len(out), dict(bands))
if len(sys.argv) > 3:
    note = ("Lives of the book of lives «%s» tied to people with works: candidates by the name of the entry's subject with a nisba, "
            "a death year or a work title (scripts/dev/lives/blocker.py), each entry judged by a model (Sonnet) among at most four "
            "candidates or none (backlog item 86). Keyed by person slug, source code and item key. A tie under confidence 90 is "
            "shown to reviewers only.") % CODE
    json.dump({'step': STEP, 'note': note, 'ties': out}, open(sys.argv[3], 'w', encoding='utf8'), ensure_ascii=False, indent=0)
    print('written', sys.argv[3])

"""Candidate people for the entries of a book of lives (prototype of the blocker; read-only).
usage: blocker.py <dir> <source-code>  -> prints recall on the existing ties, writes tasks_<code>.jsonl"""
import sys, re, json, math, collections

S, CODE = sys.argv[1], sys.argv[2]
TOP = 4
HON = set('سید شیخ میرزا ملا مولی مولا حاج حاجی اقا خان بیگ بیک قاضی مولانا مولوی میر شاه خواجه دکتر امیر شریف فقیه حکیم علامه استاد اخوند شهید مفتی حافظ امام مرحوم'.split())
COMMON = set('محمد علی حسن حسین احمد عبدالله ابراهیم اسماعیل محمود جعفر مهدی هادی باقر تقی رضا صادق کاظم یوسف یحیی موسی عیسی عبد الله دین'.split())
LINK = {'بن', 'ابن', 'فرزند', 'ولد', 'پسر'}

def norm(s):
    s = re.sub('[ً-ٰٟـ‌‍‎‏]', '', s)
    s = s.translate(str.maketrans({'ي': 'ی', 'ى': 'ی', 'ك': 'ک', 'ة': 'ه', 'أ': 'ا', 'إ': 'ا', 'آ': 'ا', 'ٱ': 'ا', 'ؤ': 'و', 'ئ': 'ی', 'ۀ': 'ه'}))
    s = re.sub('[^؀-ۿ0-9 ]', ' ', s)
    return s

SECOND = set('باقر تقی رضا صادق کاظم حسین حسن علی مهدی هادی جعفر طاهر امین شریف صالح اکبر اصغر نقی قلی محمد اشرف اسماعیل ابراهیم یوسف قاسم هاشم زمان سعید مومن معصوم رفیع شفیع نصیر کریم رحیم'.split())

def toks(s):
    raw = []
    for t in norm(s).split():
        if t.startswith('ال') and len(t) > 4:
            t = t[2:]
        if t in ('ابی', 'ابا'):
            t = 'ابو'
        # «عبدالملک», «ابوالحسن», «محمدباقر», «علینقی»: the parts apart, joined again below in one way
        for pre in ('عبدال', 'ابوال'):
            if t.startswith(pre) and len(t) > len(pre) + 1:
                raw += [pre[:3], t[len(pre):]]
                break
        else:
            for pre in ('محمد', 'علی', 'غلام'):
                if t.startswith(pre) and t[len(pre):] in SECOND:
                    raw += [pre, t[len(pre):]]
                    break
            else:
                raw.append(t)
    out = []
    for t in raw:
        if out and out[-1] in ('عبد', 'ابو') and t not in LINK:
            out[-1] += t[2:] if t.startswith('ال') and len(t) > 3 else t
        elif out and t == 'الله' and out[-1] not in LINK:   # «نعمة الله», «حمد الله»
            out[-1] += t
        else:
            out.append(t)
    return out

def core(ts):
    return [t for t in ts if t not in HON and not t.isdigit()]

def years(s):
    return sorted({int(y) for y in re.findall(r'(?<!\d)(\d{3,4})(?!\d)', s.translate(str.maketrans('۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', '01234567890123456789'))) if 100 <= int(y) <= 1420})

# ---- people
people = {}
by_ism = collections.defaultdict(set)      # last token of the ism -> people
by_pair = collections.defaultdict(set)   # (last ism token, a father token) -> people
by_part = collections.defaultdict(set)     # distinctive name token -> people
df = collections.Counter()
works = collections.defaultdict(list)
for l in open(S + '/works.tsv', encoding='utf8'):
    f = l.rstrip('\n').split('\t')
    if len(f) == 2:
        works[int(f[0])].append(f[1])

def forms(name):
    """(ism tokens, father tokens, the other tokens) of one written form of a name."""
    name = re.sub(r'\([^)]*\)|\[[^\]]*\]', ' ', name)
    if '،' in name:
        head, tail = name.split('،', 1)
    else:
        head, tail = '', name
    t = core(toks(tail))
    ism, father = t, []
    for i, x in enumerate(t):
        if x in LINK:
            ism, father = t[:i], [y for y in t[i + 1:i + 4] if y not in LINK][:2]
            break
    if not head and len(ism) > 2:  # «کلیم کاشانی»، «محمد یوسف طالقانی»: no comma, the tail is the nisba
        extra = ism[2:] if ism[0] in COMMON else ism[1:]
        ism = ism[:2] if ism[0] in COMMON else ism[:1]
    else:
        extra = []
    return ism, father, core(toks(head)) + extra

for l in open(S + '/authors.tsv', encoding='utf8'):
    f = l.rstrip('\n').split('\t')
    if len(f) < 10:
        continue
    pid = int(f[0])
    names = [f[2]]
    try:
        names += [a if isinstance(a, str) else a.get('name', '') for a in (json.loads(f[3]) if f[3] else [])]
    except Exception:
        pass
    fs = [forms(n) for n in names if n.strip()]
    parts = set()
    others = set()
    for ism, father, other in fs:
        others.update(x for x in other if len(x) > 2 and x not in COMMON and x not in LINK)
        if ism:
            by_ism[ism[-1]].add(pid)
            for y in father:
                by_pair[(ism[-1], y)].add(pid)
        parts.update(x for x in other + ism + father if len(x) > 2 and x not in COMMON and x not in LINK)
    for x in parts:
        by_part[x].add(pid)
        df[x] += 1
    people[pid] = dict(id=pid, slug=f[1], name=f[2], aliases=names[1:], birth=f[4], death=f[5], century=f[6], dates=f[7],
                       works_count=int(f[8]), forms=fs, parts=parts, others=others,
                       titles=[norm(w) for w in works.get(pid, [])])
N = len(people)
idf = lambda x: math.log(N / (1 + df[x]))

def name_hit(form, chain, first, tset):
    """2 = the ism stands before the first «بن» of the subject's name and the father after it; 1 = the ism stands in
    the heading and nothing tells the father; 0 = another name, or another father."""
    ism, father, _ = form
    if not ism:
        return 0
    ic = [x for x in ism if x != 'محمد'] or ism
    fc = [x for x in father if x != 'محمد'] or father
    if first is None:
        return 1 if all(x in tset for x in ic) else 0
    left, right = chain[max(0, first - 6):first], chain[first + 1:first + 4]
    if not all(x in left for x in ic):
        return 0
    if not father:
        return 1
    # «جعفر بن ابی اسحاق» / «جعفر بن اسحاق»
    bare = lambda x: x[3:] if x.startswith('ابو') and len(x) > 5 else x
    return 2 if all(x in right or bare(x) in [bare(y) for y in right] for x in fc) else 0

def candidates(title, text, prior=None):
    tt = core(toks(title))
    xt = core(toks(text[:320]))
    # the heading may be repeated at the head of the text, with a number before it
    chains = []
    k = next((i for i, x in enumerate(tt) if x in LINK), None)
    if k is not None and k > 0:
        chains.append((tt, k))
    # the full name at the head of the text, after the heading repeated, a number or the years
    k = next((i for i, x in enumerate(xt[:len(tt) + 16]) if x in LINK and i > 0), None)
    if k is not None:
        chains.append((tt + xt[:k + 4], len(tt) + k))
    if not chains:
        chains.append((tt, None))
    tset = set(tt)
    zs = tset | set(core(toks(text[:200])))
    ntext = norm(title + ' ' + text)
    ys = years(title + ' ' + text[:700])
    if not ys and prior:
        ys = [prior - 60, prior, prior + 60]
    pool = set()
    for x in zs:
        if x in by_part and df[x] <= 150:
            pool |= by_part[x]
    for chain, first in chains:
        if first is not None:
            for x in chain[max(0, first - 6):first]:
                for f in chain[first + 1:first + 4]:
                    pool |= by_pair.get((x, f), set())
                    if f.startswith('ابو') and len(f) > 5:
                        pool |= by_pair.get((x, f[3:]), set())
    out = []
    for pid in pool:
        p = people[pid]
        hit = max((name_hit(f, chain, first, tset) for f in p['forms'] for chain, first in chains), default=0)
        shared = [x for x in p['parts'] if x in zs]
        extra = [x for x in p['others'] if x in zs]
        part_score = sum(idf(x) for x in shared)
        title_hit = [w for w in p['titles'] if len(w) >= 12 and w in ntext][:3]
        if hit == 0:
            continue
        date = 0
        if ys:
            if p['death']:
                d = int(p['death'])
                date = 2 if any(abs(y - d) <= 1 for y in ys) else (-2 if all(abs(y - d) > 80 for y in ys) else 0)
            elif p['century']:
                c = int(p['century'])
                lo, hi = (c - 1) * 100 - 40, c * 100 + 40
                date = 1 if any(lo <= y <= hi for y in ys) else -2
        if date == -2 and not title_hit:
            continue
        # «محمد بن علی» alone is nobody: a nisba, a laqab, a work or a year must agree
        ok = bool(title_hit) or (hit == 2 and (extra or date == 2)) or (hit == 1 and extra and date == 2) or (hit == 1 and len(extra) >= 2 and sum(idf(x) for x in extra) >= 9)
        if not ok:
            continue
        score = 6 * hit + part_score + 8 * len(title_hit) + 5 * date
        out.append((score, pid, hit, len(shared), len(title_hit), date))
    out.sort(reverse=True)
    return out

gold = collections.defaultdict(set)
for l in open(S + '/gold.tsv'):
    a, b = l.split()
    gold[int(a)].add(int(b))

rec = collections.defaultdict(collections.Counter)
tasks = []
stats = collections.Counter()
rows = []
volyears = collections.defaultdict(list)
for l in open(S + '/items.tsv', encoding='utf8'):
    f = l.rstrip('\n').split('\t')
    if len(f) < 7:
        continue
    rows.append(f)
    ys = years(f[4] + ' ' + f[5][:400])
    if ys and f[1] == CODE:
        volyears[(f[1], f[2])].append(ys[-1])
prior = {}
for k, v in volyears.items():
    v.sort()
    if len(v) >= 50 and v[len(v) * 3 // 4] - v[len(v) // 4] <= 90:
        prior[k] = v[len(v) // 2]
print('volume priors', sorted((int(k[1]), y) for k, y in prior.items()))
for f in rows:
    iid, code, vol, key, title, text, ln = int(f[0]), f[1], f[2], f[3], f[4], f[5], int(f[6])
    c = candidates(title, text, prior.get((code, vol)))
    ids = [x[1] for x in c]
    if iid in gold:
        for g in gold[iid]:
            if g not in people:
                continue
            r = rec[code]
            r['gold'] += 1
            r['found'] += g in ids
            r['top%d' % TOP] += g in ids[:TOP]
            r['top1'] += ids[:1] == [g]
    if code != CODE or (ln < 60 and iid not in gold):
        stats['skipped'] += code == CODE
        continue
    stats['tied' if iid in gold else 'untied'] += 1
    stats['n%d' % min(len(c), TOP)] += 1
    if c:
        tasks.append(dict(item_id=iid, source=code, volume=int(vol), item_key=key, title=title, text=text[:800],
                          known=[people[g]['slug'] for g in gold.get(iid, ()) if g in people],
                          candidates=[dict(n=i + 1, slug=people[x[1]]['slug'], name=people[x[1]]['name'], aliases=people[x[1]]['aliases'],
                                           dates=people[x[1]]['dates'] or (('د. ' + people[x[1]]['death']) if people[x[1]]['death'] else ('سده ' + people[x[1]]['century'] if people[x[1]]['century'] else '')),
                                           works=works.get(x[1], [])[:8], works_count=people[x[1]]['works_count'],
                                           signals=dict(name=x[2], parts=x[3], titles=x[4], date=x[5], score=round(x[0], 1))) for i, x in enumerate(c[:TOP])]))
tot = collections.Counter()
for code, r in sorted(rec.items()):
    print(code, dict(r)); tot.update(r)
print('gold total', dict(tot), 'recall top%d = %.1f%%' % (TOP, 100 * tot['top%d' % TOP] / max(1, tot['gold'])))
print(CODE, dict(stats), 'tasks', len(tasks))
with open(S + '/tasks_%s.jsonl' % CODE, 'w', encoding='utf8') as o:
    for t in tasks:
        o.write(json.dumps(t, ensure_ascii=False) + '\n')

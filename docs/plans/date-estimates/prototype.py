"""Prototype of the date-estimate engine (docs/plans/date-estimates.md): interval bounds propagation over
difference constraints. Measurement only: it reads TSV exports and writes nothing to any database.

  python3 prototype.py <dir-with-tsv> [--quarantine]

--quarantine leaves every value that is in a direct contradiction out of the seeds (and out of the hold-out pool).
The TSV files (mariadb -B -N, NULL as the word NULL) are:
  people.tsv  id, birth_year_hijri, death_from, death_to, death_certainty
  works.tsv   id, composition_date_from, composition_date_to, composition_date_certainty
  mss.tsv     id, work_id, copy_date_from, copy_date_to, copy_date_certainty, is_autograph, is_facsimile,
              1 when copy_date_hedge_raw names the author's time («مؤلف», «تألیف»), else 0
  wp.tsv      work_people: work_id, person_id, role, certainty (person_id set; author, dedicatee, translator, commissioner)
  mp.tsv      manuscript_people: manuscript_id, person_id, role (person_id set)
  wr.tsv      work_relations: work_id, related_work_id, type, confidence (all types but related and part)
"""
import csv, random, statistics, sys
from collections import Counter, defaultdict

ARGS = [a for a in sys.argv[1:] if not a.startswith('--')]
D = ARGS[0] if ARGS else 'd'
QUARANTINE = '--quarantine' in sys.argv
LIFE, AUTHOR_AGE, SCRIBE_AGE, MAXDEPTH = 100, 15, 10, 3
INF = 10**6


def rows(name):
    with open(f'{D}/{name}.tsv', encoding='utf-8') as f:
        for r in csv.reader(f, delimiter='\t', quoting=csv.QUOTE_NONE):
            yield [None if c == 'NULL' else c for c in r]


def num(v):
    return None if v is None else int(v)


# ---- variables: (kind, id) -> index;  kinds: B birth, D death, C composition, K copy
idx, names = {}, []
def var(kind, i):
    k = (kind, i)
    if k not in idx:
        idx[k] = len(names); names.append(k)
    return idx[k]

seed_lo, seed_hi = {}, {}
cons = []  # (x, y, c, label): x <= y + c


def con(x, y, c, label):
    cons.append((x, y, c, label))


for pid, b, df, dt, cert in rows('people'):
    pid = int(pid); B, Dd = var('B', pid), var('D', pid)
    if b is not None: seed_lo[B] = seed_hi[B] = int(b)
    if df is not None: seed_lo[Dd] = int(df)
    if dt is not None: seed_hi[Dd] = int(dt)
    con(B, Dd, 0, 'life'); con(Dd, B, LIFE, 'life')

for wid, cf, ct, cert in rows('works'):
    C = var('C', int(wid))
    if cf is not None: seed_lo[C] = int(cf)
    if ct is not None: seed_hi[C] = int(ct)

authors, translated = defaultdict(list), set()
wp = list(rows('wp'))
for wid, pid, role, cert in wp:
    if role == 'translator' and cert is None: translated.add(int(wid))
skipped_translated = 0
for wid, pid, role, cert in wp:
    wid, pid = int(wid), int(pid)
    if cert is not None: continue  # doubtful / probable attributions stay out
    C, B, Dd = var('C', wid), var('B', pid), var('D', pid)
    if role == 'author':
        if wid in translated: skipped_translated += 1; continue
        authors[wid].append(pid)
        con(C, Dd, 0, 'author'); con(B, C, -AUTHOR_AGE, 'author')
    elif role == 'translator':
        con(C, Dd, 0, 'translator'); con(B, C, -AUTHOR_AGE, 'translator')
    elif role in ('dedicatee', 'commissioner'):
        con(C, Dd, 0, 'dedicatee'); con(B, C, 0, 'dedicatee')

ms_work = {}
for mid, wid, kf, kt, cert, autog, facs, relative in rows('mss'):
    mid = int(mid); K = var('K', mid)
    if relative != '1':
        if kf is not None: seed_lo[K] = int(kf)
        if kt is not None: seed_hi[K] = int(kt)
    if wid is not None:
        wid = int(wid); ms_work[mid] = wid
        con(var('C', wid), K, 0, 'copy>=composition')
        if autog == '1' and len(authors.get(wid, [])) == 1:
            con(K, var('D', authors[wid][0]), 0, 'autograph')

for mid, pid, role in rows('mp'):
    mid, pid = int(mid), int(pid); K = var('K', mid)
    if role == 'scribe':
        con(K, var('D', pid), 0, 'scribe'); con(var('B', pid), K, -SCRIBE_AGE, 'scribe')
    elif role == 'patron':
        con(K, var('D', pid), 0, 'patron'); con(var('B', pid), K, 0, 'patron')

for wid, rid, typ, conf in rows('wr'):
    con(var('C', int(rid)), var('C', int(wid)), 0, 'derived-work')

N = len(names)
print(f'variables {N}, constraints {len(cons)}, authored works on translated records skipped {skipped_translated}')


def run(hidden=frozenset()):
    """Returns lo, hi as lists of (value, depth, label)."""
    lo = [(-INF, 0, None)] * N; hi = [(INF, 0, None)] * N
    for v, x in seed_lo.items():
        if v not in hidden: lo[v] = (x, 0, 'seed')
    for v, x in seed_hi.items():
        if v not in hidden: hi[v] = (x, 0, 'seed')
    for depth in range(1, MAXDEPTH + 1):
        nlo, nhi = lo[:], hi[:]
        for x, y, c, label in cons:
            # x <= y + c  =>  hi[x] <= hi[y] + c ;  lo[y] >= lo[x] - c
            hy = hi[y]
            if hy[0] < INF and hy[1] == depth - 1 and hy[0] + c < nhi[x][0]:
                nhi[x] = (hy[0] + c, depth, label)
            lx = lo[x]
            if lx[0] > -INF and lx[1] == depth - 1 and lx[0] - c > nlo[y][0]:
                nlo[y] = (lx[0] - c, depth, label)
        lo, hi = nlo, nhi
    return lo, hi


KINDS = {'B': 'تولد شخص', 'D': 'وفات شخص', 'C': 'تألیف اثر', 'K': 'کتابت نسخه'}
by_kind = defaultdict(list)
for i, (k, _) in enumerate(names): by_kind[k].append(i)

# ---- 1. what is missing today
print('\n== 1. documented today')
for k, label in KINDS.items():
    vs = by_kind[k]
    both = sum(1 for v in vs if v in seed_lo and v in seed_hi)
    one = sum(1 for v in vs if (v in seed_lo) != (v in seed_hi))
    print(f'{k} {len(vs):>7}  both bounds {both:>7} ({both/len(vs):.1%})  one bound {one:>6}  none {len(vs)-both-one:>7} ({(len(vs)-both-one)/len(vs):.1%})')

# ---- 2. direct contradictions between two documented values
print('\n== 2. direct contradictions (documented vs documented)')
viol = Counter(); viol_ex = defaultdict(list)
for x, y, c, label in cons:
    if x in seed_lo and y in seed_hi and seed_lo[x] > seed_hi[y] + c:
        key = (label, names[x][0], names[y][0])
        viol[key] += 1
        if len(viol_ex[key]) < 4: viol_ex[key].append((names[x], seed_lo[x], names[y], seed_hi[y], c))
for key, n in viol.most_common():
    print(n, key, viol_ex[key])
print('total', sum(viol.values()))

# ---- 3. estimates for what is undocumented
if QUARANTINE:
    QUAR = set()
    for x, y, c, label in cons:
        if x in seed_lo and y in seed_hi and seed_lo[x] > seed_hi[y] + c: QUAR.add(x); QUAR.add(y)
    print('quarantined values', len(QUAR), dict(Counter(names[v][0] for v in QUAR)))
    for v in QUAR: seed_lo.pop(v, None); seed_hi.pop(v, None)
lo, hi = run()
print('\n== 3. estimates for values with no documented bound at all')
def bucket(w):
    return '<=25' if w <= 25 else '<=50' if w <= 50 else '<=100' if w <= 100 else '<=150' if w <= 150 else '>150'
for k in KINDS:
    vs = [v for v in by_kind[k] if v not in seed_lo and v not in seed_hi]
    c = Counter(); rules_lo = Counter(); rules_hi = Counter()
    for v in vs:
        l, h = lo[v][0] > -INF, hi[v][0] < INF
        if l: rules_lo[lo[v][2]] += 1
        if h: rules_hi[hi[v][2]] += 1
        if l and h:
            w = hi[v][0] - lo[v][0]
            c['empty(contradiction)' if w < 0 else 'both ' + bucket(w)] += 1
        elif l: c['only after'] += 1
        elif h: c['only before'] += 1
        else: c['nothing'] += 1
    print(k, 'undocumented', len(vs))
    for name in ['both <=25', 'both <=50', 'both <=100', 'both <=150', 'both >150', 'only after', 'only before', 'empty(contradiction)', 'nothing']:
        if c[name]: print(f'    {name:<22}{c[name]:>7} {c[name]/len(vs):.1%}')
    print('    lower bound by rule:', dict(rules_lo.most_common()))
    print('    upper bound by rule:', dict(rules_hi.most_common()))

# people with any role, undocumented death: the population that matters on the site
linked = set()
for x, y, c, label in cons:
    if label != 'life':
        for v in (x, y):
            if names[v][0] in 'BD': linked.add(names[v][1])
vs = [v for v in by_kind['D'] if v not in seed_lo and v not in seed_hi]
print('undocumented-death people with at least one work/copy link:', sum(1 for v in vs if names[v][1] in linked), 'of', len(vs))
alive = sum(1 for v in vs if lo[v][0] > -INF and lo[v][1] == 1)
print('  of them, a direct «alive in year X» (depth 1):', alive)

# empty intervals after propagation (any depth)
empty = Counter()
for v in range(N):
    if lo[v][0] > -INF and hi[v][0] < INF and lo[v][0] > hi[v][0]: empty[names[v][0]] += 1
print('empty intervals after propagation:', dict(empty))

# ---- 4. validation: hide 20% of the precisely documented values of one kind, estimate them back
print('\n== 4. hold-out validation (20% of values documented within 10 years)')
random.seed(7)
for k in ['D', 'C', 'K']:
    pool = [v for v in by_kind[k] if v in seed_lo and v in seed_hi and 0 <= seed_hi[v] - seed_lo[v] <= 10]
    hidden = frozenset(random.sample(pool, len(pool) // 5))
    l2, h2 = run(hidden)
    both = inside = 0; widths = []; lo_only = lo_ok = hi_only = hi_ok = none = 0; wb = Counter(); inside_b = Counter()
    for v in hidden:
        a, b = seed_lo[v], seed_hi[v]
        l, h = l2[v][0], h2[v][0]
        if l > -INF and h < INF:
            both += 1; w = h - l; widths.append(w)
            ok = l <= a and b <= h
            inside += ok; bk = 'empty' if w < 0 else bucket(w); wb[bk] += 1; inside_b[bk] += ok
        elif l > -INF: lo_only += 1; lo_ok += l <= a
        elif h < INF: hi_only += 1; hi_ok += b <= h
        else: none += 1
    n = len(hidden)
    print(f'{k}: hidden {n}; two-sided {both} ({both/n:.1%}), truth inside {inside} ({inside/max(both,1):.1%}), median width {statistics.median(widths) if widths else None}')
    for bk in ['<=25', '<=50', '<=100', '<=150', '>150', 'empty']:
        if wb[bk]: print(f'     width {bk:<6} {wb[bk]:>6}  truth inside {inside_b[bk]/wb[bk]:.1%}')
    print(f'   only-after {lo_only} (correct {lo_ok/max(lo_only,1):.1%}), only-before {hi_only} (correct {hi_ok/max(hi_only,1):.1%}), nothing {none} ({none/n:.1%})')

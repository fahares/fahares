import json

with open("reports/true_critical_pages_vol_02.json", "r", encoding="utf-8") as f:
    data = json.load(f)

upcoming = [int(p) for p in data.keys() if int(p) >= 417]
print(f"Total upcoming critical pages (>= 417): {len(upcoming)}")
print("Upcoming critical pages:", sorted(upcoming))

# Group into clusters
clusters = []
for p in sorted(upcoming):
    if not clusters or p > clusters[-1][-1] + 3:
        clusters.append([p])
    else:
        clusters.append(clusters.pop() + [p])

print(f"\nGrouped into {len(clusters)} tightly-focused clusters:")
for c in clusters:
    print(f"  Cluster {c[0]}..{c[-1]} ({len(c)} pages): {c}")

import subprocess

res = subprocess.run(["git", "diff", "sources/text/fahares_vol_02.txt"], capture_output=True, text=True)
with open("reports/vol_02_batch_15_diff.txt", "w", encoding="utf-8") as f:
    f.write(res.stdout)

print("Updated reports/vol_02_batch_15_diff.txt")
print("Diff stats:")
for line in res.stdout.split("\n"):
    if line.startswith("@@"):
        print(line)

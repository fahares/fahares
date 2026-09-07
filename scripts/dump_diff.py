import subprocess

res = subprocess.run(["git", "diff", "sources/text/fahares_vol_02.txt"], capture_output=True, text=True)
with open("reports/vol_02_batch_14_diff.txt", "w", encoding="utf-8") as f:
    f.write(res.stdout)

print("Diff size:", len(res.stdout))

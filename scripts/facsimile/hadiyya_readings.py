"""Printed column numbers of Hadiyyat al-Arifin scan leaves, read by eye from footer strips (19 Oct 2026).

Edition: Istanbul 1951-55 (Bilge & Inal), two volumes, each numbered by column from 1. Each page carries two
numbered columns, the odd one on the right. Scans are archive.org/details/a_kashf c_hdya_1_text.pdf and
c_hdya_2_text.pdf, kept as v1_text.pdf and v2_text.pdf. The scans hold every page twice (leaves 1 and 2,
3 and 4, ... are the same image), so only the odd leaves are read and mapped (STEP = 2).

R[volume][leaf] = printed number of the RIGHT column; the left column is that number + 1.
Odd leaves were read one by one at the start and the end and every tenth in between; the column on the
right is the leaf number throughout.
"""
STEP = 2
R = {
 '01': {l: l for l in list(range(1, 41, 2)) + list(range(41, 832, 10)) + list(range(833, 842, 2))},
 '02': {l: l for l in list(range(1, 41, 2)) + list(range(41, 572, 10)) + [573]},
}

# first and last text leaf: no front matter in either scan. Vol. 1 ends at leaf 841 (841-842, "end of the
# first volume, the second begins with mim"); vol. 2 at leaf 573 (573-574). After the text both volumes
# have an index of authors with its own column numbers, which is not mapped.
BOUNDS = {'01': (1, 841), '02': (1, 573)}

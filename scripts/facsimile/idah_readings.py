"""Printed column numbers of Idah al-Maknun scan leaves, read by eye from footer strips (19 Oct 2026).

Edition: Istanbul 1945-47 (Yaltkaya & Bilge), two volumes, each numbered by column from 1. Each page carries
two numbered columns, the odd one on the right. Scans are archive.org/details/a_kashf b_maknon_1_text.pdf and
b_maknon_2_text.pdf, kept as v1_text.pdf and v2_text.pdf. The scans hold every page twice (leaves 1 and 2,
3 and 4, ... are the same image), so only the odd leaves are read and mapped (STEP = 2).

R[volume][leaf] = printed number of the RIGHT column; the left column is that number + 1.
Odd leaves were read one by one at the start and the end and every tenth in between; the column on the
right is the leaf number throughout.
"""
STEP = 2
R = {
 '01': {l: l for l in list(range(1, 41, 2)) + list(range(41, 612, 10)) + list(range(613, 620, 2))},
 '02': {l: l for l in list(range(1, 16, 2)) + list(range(21, 722, 10)) + list(range(723, 732, 2))},
}
# vol. 1 leaf 621 is the last text page (end of letter za), its columns unnumbered above the closing line;
# it follows leaf 619 (619-620), so it is 621-622. Leaf 623 is the errata page.
R['01'][621] = 621

# first and last text leaf: no front matter in either scan; after the text, vol. 1 has errata and the
# index, vol. 2 errata and the printer's colophon.
BOUNDS = {'01': (1, 621), '02': (1, 731)}

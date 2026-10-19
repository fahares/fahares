"""Printed column numbers of Kashf al-Zunun scan leaves, read by eye from footer strips (19 Oct 2026).

Edition: Istanbul 1941-43 (Yaltkaya & Bilge), Dar Ihya al-Turath reprint, two volumes with one continuous
column count (vol. 1: 1-940, vol. 2: 945-2056). Each leaf is one printed page with two numbered columns,
the odd one on the right. Scans are archive.org/details/2_20231011_20231011_0739 1_text.pdf and 2_text.pdf,
kept as v1_text.pdf and v2_text.pdf.

R[volume][leaf] = printed number of the RIGHT column; the left column is that number + 1.
Leaves were read every 5 (every 25 first, then densified); no offset change was found in either volume.
"""
R = {
 '01': {41: 1, 42: 3, 43: 5, 44: 7},
 '02': {3: 945, 4: 947, 5: 949, 6: 951, 7: 953, 8: 955},
}
# every 5th leaf, all with right = 2 * leaf - 81 (vol. 1) and 2 * leaf + 939 (vol. 2)
R['01'].update({l: 2 * l - 81 for l in range(45, 506, 5)})
R['02'].update({l: 2 * l + 939 for l in range(10, 556, 5)})
# boundaries
R['01'].update({500: 919, 501: 921, 502: 923, 503: 925, 505: 929, 506: 931, 507: 933, 508: 935, 509: 937})
R['02'].update({545: 2029, 546: 2031, 547: 2033, 548: 2035, 549: 2037, 551: 2041, 552: 2043, 553: 2045,
                554: 2047, 555: 2049, 556: 2051, 557: 2053, 558: 2055})
# vol. 1 leaf 510 is the last text page (end of letter ra), columns unnumbered under the closing ornament;
# its entries follow leaf 509 (937-938) alphabetically, so it is 939-940.
R['01'][510] = 939

# first and last text leaf: vol. 1 has 40 leaves of front matter and errata plus the Turkish preface after
# the text; vol. 2 opens with a title leaf and a blank one and ends with a footnote leaf.
BOUNDS = {'01': (41, 510), '02': (3, 558)}

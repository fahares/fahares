"""Printed page numbers of Mu'jam al-Mu'allifin scan leaves, read by eye from page-number footers (20 Oct 2026).

Scan: archive.org/details/kah1957arar_202408, Kahhala's Mu'jam al-Mu'allifin, Damascus 1957-61 (al-Maktaba al-Arabiyya,
Maktabat al-Muthanna, Dar Ihya' al-Turath al-Arabi), parts 1-13 (*_text.pdf, saved as v01_text.pdf .. v13_text.pdf);
parts 14-15 are the indexes, which Fankha does not cite.

LEAVES[volume] = leaves in the PDF. R[volume][leaf] = printed page; leaves are 1-based PDF pages.
Leaves 3-8, every 25th and the last six of each volume were read first; the offset never changed inside a
volume (volume 1 has four unnumbered leaves before page 1, the others start with the title as page 1).
The footer of every 5th leaf was then checked against this table (see TECHNICAL_APPENDIX.md).
No printed page is missing from the scan.
"""
LEAVES = {'01': 324, '02': 320, '03': 320, '04': 320, '05': 320, '06': 295, '07': 320, '08': 320, '09': 320,
          '10': 320, '11': 320, '12': 320, '13': 421}


def _sampled(volume, offset):
    n = LEAVES[volume]
    return {l: l - offset for l in [*range(3 + offset, 9 + offset), *range(25, n - 5, 25), *range(n - 5, n + 1)]}


R = {v: _sampled(v, 4 if v == '01' else 0) for v in LEAVES}

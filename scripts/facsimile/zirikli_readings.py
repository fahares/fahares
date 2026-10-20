"""Printed page numbers of al-A'lam scan leaves, read by eye from header-strip montages (20 Oct 2026).

Scan: archive.org/details/al_a_laam, Khayr al-Din al-Zirikli's al-A'lam, Dar al-'Ilm li-l-Malayin, 15th printing 2002
(eight volumes, the paging of the 1979-80 fourth edition that Fankha cites), alam1.pdf .. alam8.pdf, saved as
v01.pdf .. v08.pdf. Each is an image-only PDF of 300 dpi JBIG2 leaves, 2550 x 3350 px.

LEAVES[volume] = leaves in the PDF. R[volume][leaf] = printed page; leaves are 1-based PDF pages.
The header of leaf 26, every 25th leaf and the last six leaves of each volume were read first, then every 5th leaf
from 30 on: in every volume the printed page is the leaf number. The leaves before 26 (title and introduction)
carry no header; the source lists at the end of volume 8 are numbered in the footer, read at 268-373.
Blank strips (full-page plates of handwriting) sit between readings that agree. No printed page is missing.
"""
LEAVES = {'01': 336, '02': 341, '03': 355, '04': 334, '05': 336, '06': 335, '07': 352, '08': 373}

R = {v: {leaf: leaf for leaf in [26, *range(30, n + 1, 5), *range(n - 5, n + 1)]} for v, n in LEAVES.items()}

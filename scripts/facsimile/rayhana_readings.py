"""Printed page numbers of Rayhanat al-Adab scan leaves, read by eye from header strips (19 Oct 2026).

Scan: archive.org/details/rayhana_201410, Khayyam (Tehran) fourth printing 1374 SH, an offset of the Khayyam
edition that Fankha cites. Each PDF holds two volumes; the file name does not give their order
(4-3.pdf holds volume 3 first, then volume 4).

PARTS[volume] = (pdf, first leaf, last leaf) of the volume inside its PDF.
R[volume][leaf] = printed page. Leaves are 1-based PDF pages. Leaves were sampled every 25 and at
every volume boundary. The offset never changed inside a volume; the header of every 5th mapped page
was then checked against this table (see RAYHANA_NOTES.md).

Pages not in the scan: volume 2 page 456 (last text page, after which the original has only the
table of contents), volume 6 page 404 (likewise) and pages 3-4 of volume 6 (the symbol list; the
scan goes from the title leaf and its blank back, pages 1-2, straight to the unnumbered page 5,
"خداوندا در توفیق بگشا", as in the other volumes where the title is page 1 and the list pages 3-4).
"""
PARTS = {
    '01': ('2-1', 1, 452),
    '02': ('2-1', 453, 907),
    '03': ('4-3', 1, 484),
    '04': ('4-3', 485, 985),
    '05': ('5-6', 1, 398),
    '06': ('5-6', 399, 799),
    '07': ('8-7', 1, 590),
    '08': ('8-7', 593, 1078),
}

R = {
    '01': {7: 7, 10: 10, 14: 14, 20: 20, **{l: l for l in range(25, 451, 25)}, 446: 446, 447: 447, 448: 448,
           450: 450, 451: 451, 452: 452},
    '02': {456: 4, 458: 6, 459: 7, 460: 8, 461: 9, 462: 10, 464: 12, 465: 13, 466: 14, 467: 15, 468: 16,
           469: 17, 470: 18, **{l: l - 452 for l in range(500, 901, 25)}, 901: 449, 902: 450, 903: 451, 904: 452,
           905: 453, 906: 454, 907: 455},
    '03': {7: 7, 9: 9, 10: 10, 11: 11, 12: 12, 15: 15, 20: 20, **{l: l for l in range(25, 476, 25)},
           **{l: l for l in range(476, 485)}},
    '04': {488: 4, 490: 6, **{l: l - 484 for l in range(500, 976, 25)}, 978: 494, 979: 495, 980: 496,
           981: 497, 982: 498, 984: 500, 985: 501},
    '05': {7: 7, 9: 9, 12: 12, **{l: l for l in range(25, 376, 25)}, **{l: l for l in range(380, 399)}},
    '06': {399: 1, 400: 2, 401: 5, **{l: l - 396 for l in range(402, 421)}, **{l: l - 396 for l in range(425, 776, 25)},
           **{l: l - 396 for l in range(780, 800)}},
    '07': {7: 7, 9: 9, **{l: l for l in range(25, 576, 25)}, **{l: l for l in range(580, 591)}},
    '08': {597: 5, 599: 7, **{l: l - 592 for l in range(625, 1051, 25)}, 1055: 463,
           **{l: l - 592 for l in range(1056, 1079)}},
}

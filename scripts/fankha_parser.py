#!/usr/bin/env python3
"""
Fankha Production Parser & Structured JSON Generator.
Extracts work headers, referral links, and granular manuscript metadata from
the 34-volume Fankha text corpus into rich, relational-ready JSON.
"""

import re
import json
import sys
from pathlib import Path
from dataclasses import dataclass, field, asdict
from typing import List, Dict, Optional, Any, Tuple

PAGE_TAG_PATTERN = re.compile(r'<!--\s*page:\s*(\d+)\s*-->')
KNOWN_SCRIPTS = r'(?:نستعلیق|نسخ|شکسته|تعلیق|رقعه|کوفی|ثلث|ریحان|محقق|طومار|لاتین)'
DATE_MARKERS = r'(?:\d+|قرن|با تاریخ|اوایل|اواخر|نیمه|بی‌تا|بی تا|غره|سلخ|جمادی|ربیع|شوال|رمضان|صفر|محرم|شعبان|ذوالقعده|ذیقعده|ذوالحجه|ذیحجه|سنه)'
CONTENT_WORDS_RE = re.compile(r'(?:فصل|باب|مقاله|میمر|جزء|قسم|مطلب|کتاب|مقصد|حدیث|ثمره|شعبه|پایان|شعر|بیت)')

@dataclass
class Manuscript:
    sequence_number: Optional[int] = None
    city: Optional[str] = None
    library: Optional[str] = None
    shelfmark: Optional[str] = None
    page_start: Optional[int] = None
    page_end: Optional[int] = None
    scribe: Optional[str] = None
    is_bika: bool = False
    is_autograph: bool = False
    copy_date_raw: Optional[str] = None
    copy_place: Optional[str] = None
    script: Optional[str] = None
    folios: Optional[str] = None
    lines: Optional[str] = None
    dimensions: Optional[str] = None
    paper: Optional[str] = None
    binding: Optional[str] = None
    format: Optional[str] = None
    incipit_text: Optional[str] = None
    explicit_text: Optional[str] = None
    incipit_matches_work: bool = False
    explicit_matches_work: bool = False
    defects: Optional[str] = None
    print_info: Optional[str] = None
    contents_note: Optional[str] = None
    donor: Optional[str] = None
    colophon: Optional[str] = None
    composition_date: Optional[str] = None
    original_copy_ref: Optional[str] = None
    catalog_citation: Optional[str] = None
    editorial_notes: List[str] = field(default_factory=list)
    annex_notes: List[str] = field(default_factory=list)
    ownership_and_seals: List[str] = field(default_factory=list)
    residual_notes: Optional[str] = None
    is_corrected: bool = False
    has_marginal_notes: bool = False
    is_ruled: bool = False
    has_catchwords: bool = False
    is_facsimile: bool = False
    is_distinct_work: bool = False
    raw_text: str = ""

@dataclass
class WorkEntry:
    primary_title: str
    alternative_titles: List[str] = field(default_factory=list)
    subject: Optional[str] = None
    language: Optional[str] = None
    transliteration: Optional[str] = None
    author_name: Optional[str] = None
    author_transliteration: Optional[str] = None
    author_death_date_hijri: Optional[str] = None
    author_death_date_gregorian: Optional[str] = None
    related_work: Optional[str] = None
    description: Optional[str] = None
    print_info: Optional[str] = None
    sample_incipit: Optional[str] = None
    sample_explicit: Optional[str] = None
    commentaries_and_glosses: List[str] = field(default_factory=list)
    bibliography: List[str] = field(default_factory=list)
    page: Optional[int] = None
    manuscripts: List[Manuscript] = field(default_factory=list)

@dataclass
class ReferralEntry:
    source_title: str
    target_title: str
    page: Optional[int] = None

AUTHOR_DATE_PATTERN = re.compile(
    r'(?:'
    r'\d{3,4}\s*[\-–]\s*\d{3,4}\s*\??\s*ق?'
    r'|[\-–]\s*\d{3,4}\s*\??\s*ق?'
    r'|ق\s*\d{1,2}\s*ق'
    r'|قرن\s*\d{1,2}\s*ق?'
    r'|زنده در\s*\d{3,4}'
    r'|متوفای\s*\d{3,4}'
    r'|\d{3,4}\s*ق'
    r')$'
)

DESC_WORDS = [
    'است', 'بود', 'می‌شود', 'میگردد', 'می‌باشد', 'میباشد', 'گردیده', 'آمده',
    'دارد', 'شامل', 'مشتمل', 'مجموعه', 'رساله', 'کتاب', 'منظومه', 'گزارش',
    'بندی', 'سرگذشت', 'مطالب', 'عبارتند', 'یکی از', 'چند ', 'درباره', 'پیرامون',
    '«باب»', '«فصل»', '«اصل»', '«مقدمه»', '«مقصد»', 'باشد', 'محتملاً', 'احتمالاً'
]

def is_author_line(line: str, next_line: Optional[str] = None) -> bool:
    line = line.strip()
    if not line or len(line) > 120:
        return False
    first_word = line.split()[0] if line.split() else ''
    if any(first_word.startswith(w) for w in ['رساله', 'کتاب', 'منظومه', 'شرح', 'ترجمه', 'تفسیر', 'یکی', 'این', 'در', 'از', 'گویا', 'سرگذشت', 'مجموعه', 'مطالب', 'گزارش', 'آمار', 'وابسته']):
        return False
    if any(w in line for w in DESC_WORDS):
        return False
    if any(line.startswith(w) for w in ['آغاز:', 'انجام:', 'چاپ:', 'وابسته به:', 'تاریخ تألیف:', 'تألیف:', 'اهداء به:', 'موضوع:']):
        return False

    if next_line:
        nl = next_line.strip()
        if any(c.isascii() and c.isalpha() for c in nl) and re.search(r'\([0-9\?\-–CDc\s\.]+\)', nl):
            return True

    if AUTHOR_DATE_PATTERN.search(line):
        return True

    if '،' in line:
        parts = [p.strip() for p in line.split('،')]
        last = parts[-1]
        if re.search(r'(?:\d|[\?؟]|قرن|ق\s*\d|\bق\b|قمری|شمسی|میلادی|قبل میلاد)', last):
            return True

    if '،' in line and any(w in line for w in ['بن', 'ابن', 'ابو', 'محمد', 'احمد', 'علی', 'حسن', 'حسین', 'میرزا', 'سید', 'شیخ', 'ملا']):
        return True

    if len(line.split()) <= 4 and any(w in line for w in ['میرزا', 'سید', 'شیخ', 'ملا', 'خان', 'شاه', 'پاشا', 'افندی']):
        return True

    return False

def extract_author_and_date(cand: str) -> Tuple[str, Optional[str]]:
    cand = cand.strip()

    # 1. Parenthesized date at end: e.g. (370-428ق) or (-1312ق) or (1820-1898)
    m_paren = re.search(r'\s*\(([\d\?؟\s\-–\.\/]*(?:ق(?:مر[یی])?|م(?:یلادی)?|ش(?:مسی)?|قبل میلاد)?)\)$', cand)
    if m_paren and any(c.isdigit() for c in m_paren.group(1)):
        return cand[:m_paren.start()].rstrip('، '), m_paren.group(1).strip()

    # 2. Comma-separated date: 'شهرت، نام، تاریخ' or 'نام، تاریخ'
    if '،' in cand:
        parts = [p.strip() for p in cand.split('،')]
        last = parts[-1]
        has_digit = bool(re.search(r'\d', last))
        has_era = bool(re.search(r'(?:قرن|قبل میلاد|متوف[یای]|زنده در|\bق\b|قمری|قمرى|شمسی|شمسى|میلادی|ميلادي|\bم\b)', last))

        # Check that it is not a patronymic name part like 'بن محمد'
        if (has_digit or has_era) and not re.search(r'(?:بن|ابن|بنت)\s+[\u0600-\u06FF]', last):
            author_part = '، '.join(parts[:-1]).strip()
            if author_part:
                return author_part, last

    # 3. Trailing date separated by space/dash: e.g. 'سلطان علی مشهدی 841؟ - 926 ؟ ق'
    m = re.search(r'[\s،]+((?:(?:ق|قرن|سده|متوفای|زنده در)\s*)?[\-–\s]*[\d\?؟]+[\d\?؟\s\-–\.\/]*(?:یا\s+[\d\?؟]+[\d\?؟\s\-–\.\/]*)?(?:ق(?:مر[یی])?|م(?:یلادی)?|ش(?:مسی)?|قبل میلاد)?)$', cand)
    if m and any(c.isdigit() for c in m.group(1)):
        author_part = cand[:m.start()].rstrip('، ')
        if author_part:
            return author_part, m.group(1).strip()

    return cand, None

class FankhaParser:
    def __init__(self, volume_number: int):
        self.volume_number = volume_number
        self.works: List[WorkEntry] = []
        self.referrals: List[ReferralEntry] = []

    def parse_file(self, file_path: str):
        with open(file_path, 'r', encoding='utf-8') as f:
            text = f.read()

        raw_entries = self._split_entries(text)
        for entry_lines, page in raw_entries:
            entry_text = "\n".join(entry_lines).strip()
            if not entry_text:
                continue

            first_line = entry_lines[0].strip()
            if '←' in first_line and not first_line.startswith('●'):
                # Referral entry
                self._parse_referral(first_line, page)
            elif first_line.startswith('●'):
                # Work entry
                work = self._parse_work(entry_lines, page)
                if work:
                    self.works.append(work)

    def _split_entries(self, text: str) -> List[Tuple[List[str], int]]:
        lines = text.split('\n')
        entries = []
        curr_entry_lines = []
        curr_page = 1

        for line in lines:
            page_matches = PAGE_TAG_PATTERN.findall(line)
            if page_matches:
                curr_page = int(page_matches[-1])

            stripped = line.strip()
            is_work_header = stripped.startswith('● ')
            is_referral = '←' in stripped and not is_work_header and not stripped.startswith('آغاز') and not stripped.startswith('انجام')

            if (is_work_header or is_referral) and curr_entry_lines:
                entries.append((curr_entry_lines, curr_page))
                curr_entry_lines = []

            curr_entry_lines.append(line)

        if curr_entry_lines:
            entries.append((curr_entry_lines, curr_page))

        return entries

    def _parse_referral(self, line: str, page: int):
        clean = PAGE_TAG_PATTERN.sub('', line).strip()
        parts = clean.split('←')
        if len(parts) >= 2:
            src = parts[0].strip()
            tgt = parts[1].strip()
            self.referrals.append(ReferralEntry(source_title=src, target_title=tgt, page=page))

    def _parse_work(self, lines: List[str], page: int) -> Optional[WorkEntry]:
        header_line = lines[0]
        raw_header = header_line.lstrip('● ').strip()
        clean_header = PAGE_TAG_PATTERN.sub('', raw_header).strip()

        parts = [p.strip() for p in clean_header.split('/')]
        titles_part = parts[0] if len(parts) > 0 else clean_header
        subject = parts[1] if len(parts) > 1 else None
        language = parts[2] if len(parts) > 2 else None

        titles = [t.strip() for t in titles_part.split('=')]
        primary_title = titles[0]
        alternative_titles = titles[1:]

        work = WorkEntry(
            primary_title=primary_title,
            alternative_titles=alternative_titles,
            subject=subject,
            language=language,
            page=page
        )

        # Segregate preamble (work meta) from manuscript blocks
        preamble_lines = []
        ms_blocks: List[List[str]] = []
        curr_ms_lines: List[str] = []
        in_ms_section = False

        for line in lines[1:]:
            clean_l = PAGE_TAG_PATTERN.sub('', line).strip()
            if ('شماره نسخه:' in clean_l or 'شماره نسخه :' in clean_l) and not in_ms_section:
                in_ms_section = True
                if curr_ms_lines:
                    ms_blocks.append(curr_ms_lines)
                    curr_ms_lines = []
            elif in_ms_section and ('شماره نسخه:' in clean_l or 'شماره نسخه :' in clean_l):
                if curr_ms_lines:
                    ms_blocks.append(curr_ms_lines)
                    curr_ms_lines = []

            if not in_ms_section:
                preamble_lines.append(line)
            else:
                curr_ms_lines.append(line)

        if curr_ms_lines:
            ms_blocks.append(curr_ms_lines)

        self._parse_work_preamble(work, preamble_lines)

        # Parse manuscripts
        for seq, ms_lines in enumerate(ms_blocks, 1):
            ms = self._parse_manuscript(ms_lines, page, seq)
            if ms:
                work.manuscripts.append(ms)

        return work

    def _parse_work_preamble(self, work: WorkEntry, preamble_lines: List[str]):
        if not preamble_lines:
            return

        idx = 0
        total_p = len(preamble_lines)

        # 1. Transliteration (line 1 after header if ASCII/Latin letters)
        while idx < total_p and not preamble_lines[idx].strip():
            idx += 1
        if idx < total_p:
            cand = PAGE_TAG_PATTERN.sub('', preamble_lines[idx]).strip()
            if cand and any(c.isascii() and c.isalpha() for c in cand):
                work.transliteration = cand
                idx += 1

        # 2. Author info
        while idx < total_p and not preamble_lines[idx].strip():
            idx += 1
        if idx < total_p:
            cand = PAGE_TAG_PATTERN.sub('', preamble_lines[idx]).strip()
            next_cand = PAGE_TAG_PATTERN.sub('', preamble_lines[idx+1]).strip() if idx+1 < total_p else None

            # Check if cand has both Persian author and Latin transliteration on same line
            cand_trans = None
            if re.search(r'[a-zA-Z\']', cand) and re.search(r'[\u0600-\u06FF]', cand):
                m_lat = re.search(r'([a-zA-Z\'].*)', cand)
                if m_lat:
                    cand_trans = m_lat.group(1).strip()
                    cand = cand[:m_lat.start()].strip()

            if is_author_line(cand, next_cand or cand_trans):
                author_name, date_part = extract_author_and_date(cand)
                work.author_name = author_name
                if date_part:
                    if re.search(r'(?:م\b|میلادی|قبل میلاد)', date_part) and not re.search(r'(?:ق\b|قمری)', date_part):
                        work.author_death_date_gregorian = date_part
                    else:
                        work.author_death_date_hijri = date_part
                idx += 1

                # If transliteration wasn't on the same line, check next line
                if not cand_trans and idx < total_p:
                    next_l = PAGE_TAG_PATTERN.sub('', preamble_lines[idx]).strip()
                    if next_l and any(c.isascii() and c.isalpha() for c in next_l):
                        cand_trans = next_l
                        idx += 1

                if cand_trans:
                    gm = re.search(r'\(([^)]+)\)$', cand_trans)
                    if gm:
                        work.author_transliteration = cand_trans[:gm.start()].strip()
                        work.author_death_date_gregorian = gm.group(1).strip()
                    else:
                        work.author_transliteration = cand_trans

        # 3. Remaining preamble: description, related_work, print, sample incipit/explicit, bibliography
        rem_text = "\n".join(preamble_lines[idx:]).strip()
        if not rem_text:
            return

        # Check for related work (وابسته به: ...)
        rel_m = re.search(r'(?:^|\n)\s*وابسته به:\s*([^\n]+)', rem_text)
        if rel_m:
            work.related_work = rel_m.group(1).strip()
            rem_text = rem_text.replace(rel_m.group(0), ' ').strip()

        # Print info at work level: چاپ: ...
        work_print_m = re.search(r'(?:^|\n)\s*چاپ:\s*([^\n]+)', rem_text)
        if work_print_m:
            work.print_info = work_print_m.group(1).strip()

        # Work level sample incipit / explicit
        inc_m = re.search(r'(?:^|\n)\s*آغاز:\s*([^\n]+)', rem_text)
        if inc_m:
            work.sample_incipit = inc_m.group(1).strip()
        exp_m = re.search(r'(?:^|\n)\s*انجام:\s*([^\n]+)', rem_text)
        if exp_m:
            work.sample_explicit = exp_m.group(1).strip()

        # Bibliography citations [ ... ]
        bib_matches = re.findall(r'\[([^\]]+)\]', rem_text)
        if bib_matches:
            work.bibliography = [b.strip() for b in bib_matches]

        # Description is everything before sample incipit, print, or bibliography
        desc_text = rem_text
        if work_print_m:
            desc_text = desc_text.split(work_print_m.group(0))[0]
        elif inc_m:
            desc_text = desc_text.split(inc_m.group(0))[0]
        elif bib_matches:
            first_bib = f"[{bib_matches[0]}]"
            desc_text = desc_text.split(first_bib)[0]

        clean_desc = PAGE_TAG_PATTERN.sub('', desc_text).strip()
        if clean_desc:
            work.description = clean_desc

    def _parse_manuscript(self, ms_lines: List[str], current_page: int, fallback_seq: int) -> Optional[Manuscript]:
        raw_text = "\n".join(ms_lines).strip()
        if not raw_text:
            return None

        ms = Manuscript(
            sequence_number=fallback_seq,
            page_start=current_page,
            page_end=current_page,
            raw_text=raw_text
        )

        header_line = ms_lines[0].strip()
        # Sequence number & header e.g. "1. تهران؛ مجلس؛ شماره نسخه: 118/1" or "تهران؛ دانشگاه؛ شماره نسخه: ..."
        clean_header = PAGE_TAG_PATTERN.sub('', header_line).strip()
        seq_m = re.match(r'^(\d+)[\.\s]\s*', clean_header)
        if seq_m:
            ms.sequence_number = int(seq_m.group(1))
            clean_header = clean_header[seq_m.end():].strip()

        header_match = re.match(r'([^؛]+)؛\s*([^؛]+)؛\s*شماره نسخه:\s*(.*)', clean_header)
        if header_match:
            ms.city = header_match.group(1).strip()
            ms.library = header_match.group(2).strip()
            ms.shelfmark = header_match.group(3).strip()

        rem_text = "\n".join(ms_lines[1:]).strip() if len(ms_lines) > 1 else ""

        # Page range calculation if page tags appear inside manuscript
        ms_pages = [int(p) for p in PAGE_TAG_PATTERN.findall(raw_text)]
        if ms_pages:
            ms.page_start = ms_pages[0]
            ms.page_end = ms_pages[-1]

        extracted_spans: List[str] = []

        # 1. Catalog citation [ ... ]
        for cm in re.finditer(r'\[([^\]]+)\]', rem_text):
            ms.catalog_citation = f"[{cm.group(1).strip()}]"
            extracted_spans.append(cm.group(0))

        # 2. Original copy reference: نسخه اصل: ...
        orig_m = re.search(r'نسخه اصل:\s*([^؛\n]+)', rem_text)
        if orig_m:
            ms.original_copy_ref = orig_m.group(1).strip()
            extracted_spans.append(orig_m.group(0))

        # 3. Print info: چاپ: ...
        print_m = re.search(r'(?:^|[؛،\n])\s*چاپ:\s*([^؛\n]+)', rem_text)
        if print_m:
            ms.print_info = print_m.group(1).strip()
            extracted_spans.append(print_m.group(0))

        # 4. Contents / Included works: شامل: ...
        contents_m = re.search(r'(?:^|[؛،\n])\s*شامل:\s*([^؛\n]+)', rem_text)
        if contents_m:
            ms.contents_note = contents_m.group(1).strip()
            extracted_spans.append(contents_m.group(0))

        # 5. Editorial notes: توضیح: / تذکر: / نقد فهرست
        for em in re.finditer(r'(?:^|[؛،\n])\s*(?:توضیح|تذکر):\s*([^\n]+)', rem_text):
            ms.editorial_notes.append(em.group(0).strip())
            extracted_spans.append(em.group(0))

        editorial_patterns = [
            r'[^؛\n]*به قرینه[^؛\n]*',
            r'در فهرست [^؛\n]*(?:تصحیح شد|دانسته شده[^؛\n]*)',
            r'نام مؤلف [^؛\n]*(?:ذکر شد|تصحیح شد|تعیین شد|به دست آمد)',
            r'نام کتاب [^؛\n]*(?:ذکر شد|تصحیح شد|تعیین شد|به دست آمد)',
            r'به استناد نسخه [^؛\n]*(?:ذکر شد|تصحیح شد)'
        ]
        for ep in editorial_patterns:
            for em in re.finditer(ep, rem_text):
                ms.editorial_notes.append(em.group(0).strip())
                extracted_spans.append(em.group(0))

        # 6. Colophon extract: ترقیمه: / انجامه: / خاتمه:
        colophon_m = re.search(r'(?:^|[؛،\n])\s*(?:ترقیمه|انجامه|خاتمه):\s*([^؛\n]+)', rem_text)
        if colophon_m:
            ms.colophon = colophon_m.group(1).strip()
            extracted_spans.append(colophon_m.group(0))

        # 7. Composition date: تألیف: ...
        comp_m = re.search(r'(?:^|[؛،\n])\s*تألیف:\s*([^؛\n]+)', rem_text)
        if comp_m:
            ms.composition_date = comp_m.group(1).strip()
            extracted_spans.append(comp_m.group(0))

        # 8. Donor: اهدایی: / اهدا:
        donor_m = re.search(r'(?:^|[؛،\n])\s*(?:اهدایی|اهدا):\s*([^؛\n]+)', rem_text)
        if donor_m:
            ms.donor = donor_m.group(1).strip()
            extracted_spans.append(donor_m.group(0))

        # 9. Incipit / Explicit
        if 'آغاز و انجام: برابر' in rem_text:
            ms.incipit_matches_work = True
            ms.explicit_matches_work = True
            extracted_spans.append('آغاز و انجام: برابر')
        else:
            if 'آغاز: برابر' in rem_text or 'آغاز برابر' in rem_text:
                ms.incipit_matches_work = True
                extracted_spans.extend(['آغاز: برابر', 'آغاز برابر'])
            else:
                inc_m = re.search(r'آغاز:\s*(?:موجود:)?\s*(.+?)(?=(?:[؛،\n]\s*انجام[:\s]|؛|\n|$))', rem_text)
                if inc_m:
                    ms.incipit_text = inc_m.group(1).strip()
                    extracted_spans.append(inc_m.group(0))

            if 'انجام: برابر' in rem_text or 'انجام برابر' in rem_text:
                ms.explicit_matches_work = True
                extracted_spans.extend(['انجام: برابر', 'انجام برابر'])
            else:
                exp_m = re.search(r'انجام:\s*(?:موجود:)?\s*(.+?)(?=(?:[؛،]\s*(?:خط|بی‌کا|کا|بی‌تا|تا|افتادگی|کاغذ|جلد)|؛|\n|$))', rem_text)
                if exp_m:
                    ms.explicit_text = exp_m.group(1).strip()
                    extracted_spans.append(exp_m.group(0))

        # 10. Scribe (کاتب = مؤلف, بی‌کا, کا:)
        if 'کاتب = مؤلف' in rem_text:
            ms.is_autograph = True
            extracted_spans.append('کاتب = مؤلف')
        elif 'بی‌کا' in rem_text or 'بی کا' in rem_text:
            ms.is_bika = True
            extracted_spans.extend(['بی‌کا', 'بی کا'])
        else:
            scribe_m = re.search(r'(?:^|[؛،\n])\s*(?:کا:|کاتب:|کا\s+)\s*(.+?)(?=(?:،\s*(?:تا[:\s]|جا[:\s]|بی‌تا|بی تا)|[؛\n]|$))', rem_text)
            if scribe_m:
                ms.scribe = scribe_m.group(1).strip()
                extracted_spans.append(scribe_m.group(0))

        # 11. Copy Date
        if 'بی‌تا' in rem_text or 'بی تا' in rem_text:
            ms.copy_date_raw = 'بی‌تا'
            extracted_spans.extend(['بی‌تا', 'بی تا'])
        else:
            date_m = re.search(r'(?:^|[؛،\n])\s*تا:\s*(.+?)(?=(?:،\s*جا[:\s]|[؛\n]|$))', rem_text)
            if not date_m:
                m_cand = re.search(rf'(?:^|[؛،\n])\s*تا\s+({DATE_MARKERS}[^،؛\n]*?)(?=(?:،\s*جا[:\s]|[؛\n]|$))', rem_text)
                if m_cand and not CONTENT_WORDS_RE.search(m_cand.group(1)):
                    date_m = m_cand
            if date_m:
                ms.copy_date_raw = date_m.group(1).strip() if date_m.lastindex else date_m.group(0).strip()
                extracted_spans.append(date_m.group(0))

        # 12. Copy Place: جا: ...
        place_m = re.search(r'(?:^|[؛،\n])\s*جا[:\s]\s*([^؛\n]+)', rem_text)
        if place_m:
            ms.copy_place = place_m.group(1).strip()
            extracted_spans.append(place_m.group(0))

        # 13. Script: خط: ...
        script_m = re.search(r'(?:^|[؛،\n])\s*خط:\s*([^؛\n]+)', rem_text)
        if not script_m:
            script_m = re.search(rf'(?:^|[؛،\n])\s*خط\s+({KNOWN_SCRIPTS}[^؛\n]*)', rem_text)
        if script_m:
            ms.script = script_m.group(1).strip() if script_m.lastindex else script_m.group(0).strip()
            extracted_spans.append(script_m.group(0))

        # 14. Dimensions, paper, binding, format, folios, lines
        dim_m = re.search(r'اندازه:\s*([^؛\n\[]+)', rem_text)
        if dim_m:
            ms.dimensions = dim_m.group(1).strip()
            extracted_spans.append(dim_m.group(0))
        paper_m = re.search(r'کاغذ:\s*([^،؛\n]+)', rem_text)
        if paper_m:
            ms.paper = paper_m.group(1).strip()
            extracted_spans.append(paper_m.group(0))
        binding_m = re.search(r'جلد:\s*([^،؛\n]+)', rem_text)
        if binding_m:
            ms.binding = binding_m.group(1).strip()
            extracted_spans.append(binding_m.group(0))
        format_m = re.search(r'قطع:\s*([^،؛\n]+)', rem_text)
        if format_m:
            ms.format = format_m.group(1).strip()
            extracted_spans.append(format_m.group(0))

        fol_m = re.search(r'(\d+[\d/]*\s*(?:گ|ص|برگ)(?:\s*\([^)]+\))?)', rem_text)
        if fol_m:
            ms.folios = fol_m.group(1).strip()
            extracted_spans.append(fol_m.group(0))

        lines_m = re.search(r'((?:مختلف السطر|مختلف|\d+(?:\s*تا\s*\d+|-\d+)?\s*سطر)(?:\s*(?:راسته و چلیپا|راسته|چلیپا|مورب))?(?:\s*\([^)]+\))?)', rem_text)
        if lines_m:
            ms.lines = lines_m.group(1).strip()
            extracted_spans.append(lines_m.group(0))

        # Defects: افتادگی: ...
        defect_m = re.search(r'(?:افتادگی:|افتادگی)\s*([^؛\n]+)', rem_text)
        if defect_m:
            ms.defects = defect_m.group(1).strip()
            extracted_spans.append(defect_m.group(0))

        # Ownership & seals
        ownership_m = re.search(r'(?:تملک|مالک):\s*([^؛\n]+)', rem_text)
        if ownership_m:
            ms.ownership_and_seals.append(ownership_m.group(0).strip())
            extracted_spans.append(ownership_m.group(0))
        waqf_m = re.search(r'(?:واقف|وقف|وقفنامه):\s*([^؛\n]+)', rem_text)
        if waqf_m:
            ms.ownership_and_seals.append(waqf_m.group(0).strip())
            extracted_spans.append(waqf_m.group(0))
        seals_m = re.search(r'(?:دارای\s*)?مهر(?:\s*های)?:\s*([^؛\n]+)', rem_text)
        if seals_m:
            ms.ownership_and_seals.append(seals_m.group(0).strip())
            extracted_spans.append(seals_m.group(0))

        # Annex & attachments
        for ap in [r'این رساله به نسخه [^؛\n]+ ضمیمه شده است', r'به نسخه [^؛\n]+ ضمیمه شده است', r'دنباله رساله [^؛\n]+', r'[^؛\n]+ ضمیمه دارد']:
            for am in re.finditer(ap, rem_text):
                ms.annex_notes.append(am.group(0).strip())
                extracted_spans.append(am.group(0))

        # Boolean flags
        if 'مصحح' in rem_text:
            ms.is_corrected = True
            extracted_spans.append('مصحح')
        if 'محشی' in rem_text or 'در حواشی' in rem_text or 'در حاشیه' in rem_text:
            ms.has_marginal_notes = True
            extracted_spans.extend(['محشی', 'در حواشی', 'در حاشیه'])
        if 'مجدول' in rem_text or 'با جدول' in rem_text:
            ms.is_ruled = True
            extracted_spans.extend(['مجدول', 'با جدول'])
        if 'رکابه‌دار' in rem_text:
            ms.has_catchwords = True
            extracted_spans.append('رکابه‌دار')
        if 'عکسی' in rem_text:
            ms.is_facsimile = True
            extracted_spans.append('عکسی')
        if 'غیر همانند' in rem_text or 'غیرهمانند' in rem_text:
            ms.is_distinct_work = True
            extracted_spans.extend(['غیر همانند', 'غیرهمانند'])

        # Residual notes calculation
        res_clean = rem_text
        for span in sorted(list(set(extracted_spans)), key=len, reverse=True):
            if span:
                res_clean = res_clean.replace(span, ' ')

        labels = ['اندازه:', 'کاغذ:', 'جلد:', 'قطع:', 'آغاز:', 'انجام:', 'نسخه اصل:', 'خط:', 'کا:', 'تا:', 'جا:', 'افتادگی:', 'چاپ:', 'شامل:', 'اهدایی:', 'اهدا:', 'ترقیمه:', 'انجامه:', 'خاتمه:', 'تألیف:', 'توضیح:', 'تذکر:']
        for lb in labels:
            res_clean = res_clean.replace(lb, ' ')

        res_clean = re.sub(r'<!--[^>]+-->', ' ', res_clean)
        res_clean = re.sub(r'^[؛،\s\n\-]+|[؛،\s\n\-]+$', ' ', res_clean)
        res_clean = re.sub(r'[؛،\n\-]{2,}', ' ', res_clean)
        res_clean = re.sub(r'\s+', ' ', res_clean).strip(' ؛،-')

        if len(re.findall(r'[\u0600-\u06FF]', res_clean)) >= 3:
            ms.residual_notes = res_clean

        return ms

    def to_dict(self) -> Dict[str, Any]:
        return {
            'volume_number': self.volume_number,
            'total_works': len(self.works),
            'total_referrals': len(self.referrals),
            'total_manuscripts': sum(len(w.manuscripts) for w in self.works),
            'referrals': [asdict(r) for r in self.referrals],
            'works': [asdict(w) for w in self.works]
        }

    def export_json(self, output_file: str, indent: int = 2):
        data = self.to_dict()
        out_path = Path(output_file)
        out_path.parent.mkdir(parents=True, exist_ok=True)
        with open(out_path, 'w', encoding='utf-8') as f:
            json.dump(data, f, ensure_ascii=False, indent=indent)
        print(f"Exported volume {self.volume_number} to {output_file}")
        print(f"  - Works: {data['total_works']}")
        print(f"  - Referrals: {data['total_referrals']}")
        print(f"  - Manuscripts: {data['total_manuscripts']}")

def main():
    if len(sys.argv) < 2:
        print("Usage: python3 fankha_parser.py <volume_num> [output_json_path]")
        sys.exit(1)

    vol_num = int(sys.argv[1])
    input_file = f"sources/text/fahares_vol_{vol_num:02d}.txt"
    output_file = sys.argv[2] if len(sys.argv) > 2 else f"sources/json/fahares_vol_{vol_num:02d}.json"

    parser = FankhaParser(vol_num)
    parser.parse_file(input_file)
    parser.export_json(output_file)

if __name__ == '__main__':
    main()

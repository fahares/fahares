#!/usr/bin/env python3
"""
Fankha Production Parser & Structured JSON Generator (v2 - Phase 1 Refactored).
Extracts work headers, referral links, and granular manuscript metadata from
the Fankha text corpus into rich, relational-ready JSON with multi-subject/language,
date triad sorting, volume/page ranges, and granular citations.
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

KNOWN_LANGUAGES = {
    'فارسی', 'عربی', 'ترکی', 'اردو', 'عبری', 'سریانی', 'پهلوی',
    'اوستایی', 'کردی', 'پشتو', 'فرانسوی', 'انگلیسی', 'لاتین', 'لری'
}

COMPOUND_SUBJECTS_WHITELIST = [
    'کلام و اعتقادات', 'کلام و عقاید', 'عرفان و تصوف', 'آداب و سنن',
    'تفسیر و علوم قرآن', 'علوم قرآن', 'حکومت و سیاست', 'فضایل و مناقب',
    'عروض و قافیه', 'ادیان و مذاهب'
]

@dataclass
class Manuscript:
    sequence_number: Optional[int] = None
    city: Optional[str] = None
    library: Optional[str] = None
    shelfmark: Optional[str] = None
    page_start: Optional[int] = None
    page_end: Optional[int] = None
    scribe: Optional[str] = None
    scribe_name: Optional[str] = None
    is_bika: bool = False
    is_autograph: bool = False
    copy_date_raw: Optional[str] = None
    copy_place: Optional[str] = None
    script: Optional[str] = None
    scripts: List[str] = field(default_factory=list)
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
    parent_manuscript_seq: Optional[int] = None
    catalog_citation: Optional[str] = None
    editorial_notes: List[str] = field(default_factory=list)
    annex_notes: List[str] = field(default_factory=list)
    ownership_and_seals: List[str] = field(default_factory=list)
    seals: List[Dict[str, Optional[str]]] = field(default_factory=list)
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
    subject_raw: Optional[str] = None
    subjects: List[str] = field(default_factory=list)
    language: Optional[str] = None
    language_raw: Optional[str] = None
    languages: List[str] = field(default_factory=list)
    transliteration: Optional[str] = None
    alternative_transliterations: List[str] = field(default_factory=list)
    author_name: Optional[str] = None
    author_transliteration: Optional[str] = None
    author_death_date_raw: Optional[str] = None
    author_death_date_hijri: Optional[str] = None
    author_death_date_century: Optional[int] = None
    author_death_date_sort_year: Optional[int] = None
    author_death_date_gregorian: Optional[str] = None
    author_death_date_gregorian_calculated: Optional[int] = None
    composition_date: Optional[str] = None
    dedication: Optional[str] = None
    related_work: Optional[str] = None
    description: Optional[str] = None
    print_info: Optional[str] = None
    incipit: Optional[str] = None
    explicit: Optional[str] = None
    commentaries_and_glosses: List[str] = field(default_factory=list)
    bibliography: List[str] = field(default_factory=list)
    volume_number: Optional[int] = None
    page_start: Optional[int] = None
    page_end: Optional[int] = None
    manuscripts: List[Manuscript] = field(default_factory=list)

@dataclass
class ReferralEntry:
    source_title: str
    target_title: str
    volume_number: Optional[int] = None
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

def is_language_str(s: str) -> bool:
    if not s:
        return False
    words = re.split(r'[\s،,و/\-]+', s.strip())
    words = [w for w in words if w and w not in ('و', 'به', 'زبان')]
    if not words:
        return False
    return all(w in KNOWN_LANGUAGES for w in words)

def parse_languages(lang_str: Optional[str]) -> List[str]:
    if not lang_str:
        return []
    words = re.split(r'[\s،,و/\-]+', lang_str.strip())
    res = []
    for w in words:
        if w in KNOWN_LANGUAGES and w not in res:
            res.append(w)
    return res if res else [lang_str.strip()]

def parse_subjects(subj_str: Optional[str]) -> List[str]:
    if not subj_str:
        return []
    s = subj_str.strip()
    for cs in COMPOUND_SUBJECTS_WHITELIST:
        if s == cs:
            return [cs]
    
    parts = re.split(r'[،,]+', s)
    res = []
    for part in parts:
        p = part.strip()
        if not p:
            continue
        found_wl = False
        for cs in COMPOUND_SUBJECTS_WHITELIST:
            if p == cs:
                res.append(cs)
                found_wl = True
                break
        if not found_wl:
            res.append(p)
    return res

def parse_scripts(script_str: Optional[str]) -> List[str]:
    if not script_str:
        return []
    s = script_str
    known = ['شکسته نستعلیق', 'نستعلیق', 'شکسته', 'تعلیق', 'رقعه', 'کوفی', 'ثلث', 'ریحان', 'محقق', 'طومار', 'مغربی', 'لاتین', 'نسخ']
    found = []
    for sc in known:
        if sc in s:
            found.append(sc)
            s = s.replace(sc, ' ')
    return found if found else [script_str.strip()]

def parse_seals(seal_texts: List[str]) -> List[Dict[str, Optional[str]]]:
    res = []
    for st in seal_texts:
        clean = re.sub(r'^(?:دارای\s*)?مهر(?:ها)?:\s*', '', st).strip()
        items = re.split(r'[,،؛]\s*|\s+و\s+', clean)
        for it in items:
            it = re.sub(r'^(?:و\s+)', '', it).strip(' «»')
            if not it:
                continue
            shape_m = re.search(r'\((مربع|بیضی|بادامی|مدور|دایره|هشت\s*ضلعی|مستطیل)\)', it)
            shape = shape_m.group(1).strip() if shape_m else None
            insc = re.sub(r'\([^)]+\)', '', it).strip(' «»')
            if insc:
                res.append({'inscription': insc, 'shape': shape})
    return res

def parse_header_line(clean_header: str) -> Tuple[List[str], Optional[str], Optional[str]]:
    parts = [p.strip() for p in clean_header.split('/') if p.strip()]
    if not parts:
        return [clean_header], None, None
    
    titles_part = parts[0]
    titles = [t.strip() for t in titles_part.split('=') if t.strip()]
    
    if len(parts) == 1:
        return titles, None, None
    elif len(parts) == 2:
        part2 = parts[1]
        if '-' in part2:
            sub_parts = [sp.strip() for sp in part2.split('-') if sp.strip()]
            if len(sub_parts) == 2 and is_language_str(sub_parts[1]):
                return titles, sub_parts[0], sub_parts[1]
        
        if is_language_str(part2):
            return titles, None, part2
        else:
            return titles, part2, None
    else:
        subject = parts[1]
        language = parts[2]
        if '-' in subject and not language:
            sub_parts = [sp.strip() for sp in subject.split('-') if sp.strip()]
            if len(sub_parts) == 2 and is_language_str(sub_parts[1]):
                subject = sub_parts[0]
                language = sub_parts[1]
        return titles, subject, language

def parse_date_triad(date_raw: Optional[str]) -> Tuple[Optional[int], Optional[int], Optional[int]]:
    if not date_raw:
        return None, None, None
    
    s = date_raw.strip()
    century = None
    sort_year = None
    
    # 1. Century textual patterns: e.g. "قرن 7", "قرن 11", "سده 8", "ق 11", "اواخر قرن 7", "اوایل قرن 10"
    m_cent = re.search(r'(?:(اوایل|اواخر|نیمه\s*اول|نیمه\s*دوم)\s+)?(?:قرن|سده|ق\s*)\s*(\d{1,2})', s)
    if m_cent:
        mod = m_cent.group(1) or ''
        c_num = int(m_cent.group(2))
        if 1 <= c_num <= 15:
            century = c_num
            base = (c_num - 1) * 100
            if 'اوایل' in mod:
                sort_year = base + 20
            elif 'اواخر' in mod:
                sort_year = base + 80
            elif 'نیمه دوم' in mod:
                sort_year = base + 75
            elif 'نیمه اول' in mod:
                sort_year = base + 25
            else:
                sort_year = base + 50
    
    # 2. Number extraction (Hijri years 100..1450)
    nums = [int(n) for n in re.findall(r'(?<!\d)(\d{3,4})(?!\d)', s) if 100 <= int(n) <= 1450]
    if nums:
        death_y = nums[-1]
        sort_year = death_y
        century = (death_y - 1) // 100 + 1
    
    # 3. Gregorian calculated equivalent from Hijri
    greg_calc = None
    if sort_year and 100 <= sort_year <= 1450:
        greg_calc = round(sort_year * 0.970229 + 621.57)
        
    return century, sort_year, greg_calc

def is_author_line(line: str, next_line: Optional[str] = None) -> bool:
    line = line.strip()
    if not line or len(line) > 120:
        return False
    first_word = line.split()[0] if line.split() else ''
    if any(first_word.startswith(w) for w in ['رساله', 'کتاب', 'منظومه', 'شرح', 'ترجمه', 'تفسیر', 'یکی', 'این', 'در', 'از', 'گویا', 'سرگذشت', 'مجموعه', 'مطالب', 'گزارش', 'آمار', 'وابسته']):
        return False
    if any(w in line for w in DESC_WORDS):
        return False
    if any(line.startswith(w) for w in ['آغاز:', 'انجام:', 'چاپ:', 'وابسته به:', 'تاریخ تألیف:', 'تألیف:', 'تاریخ اجازه:', 'اجازه:', 'اهداء به:', 'اهدا به:', 'اهدایی به:', 'موضوع:']):
        return False

    if next_line:
        nl = next_line.strip()
        if any(c.isascii() and c.isalpha() for c in nl) and re.search(r'\([0-9\?؟\-–CDc\s\.]+\)', nl):
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

    m_paren = re.search(r'\s*\(([\d\?؟\s\-–\.\/]*(?:ق(?:مر[یی])?|م(?:یلادی)?|ش(?:مسی)?|قبل میلاد)?)\)$', cand)
    if m_paren and any(c.isdigit() for c in m_paren.group(1)):
        return cand[:m_paren.start()].rstrip('، '), m_paren.group(1).strip()

    if '،' in cand:
        parts = [p.strip() for p in cand.split('،')]
        last = parts[-1]
        has_digit = bool(re.search(r'\d', last))
        has_era = bool(re.search(r'(?:قرن|قبل میلاد|متوف[یای]|زنده در|\bق\b|قمری|قمرى|شمسی|شمسى|میلادی|ميلادي|\bم\b)', last))

        if (has_digit or has_era) and not re.search(r'(?:بن|ابن|بنت)\s+[\u0600-\u06FF]', last):
            author_part = '، '.join(parts[:-1]).strip()
            if author_part:
                return author_part, last

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
        for entry_lines, start_page, end_page in raw_entries:
            entry_text = "\n".join(entry_lines).strip()
            if not entry_text:
                continue

            first_line = entry_lines[0].strip()
            if '←' in first_line and not first_line.startswith('●'):
                self._parse_referral(first_line, start_page)
            elif first_line.startswith('●'):
                work = self._parse_work(entry_lines, start_page, end_page)
                if work:
                    self.works.append(work)

    def _split_entries(self, text: str) -> List[Tuple[List[str], int, int]]:
        lines = text.split('\n')
        entries = []
        curr_entry_lines = []
        curr_page = 1
        entry_start_page = 1

        for line in lines:
            page_matches = PAGE_TAG_PATTERN.findall(line)
            if page_matches:
                curr_page = int(page_matches[-1])

            stripped = line.strip()
            is_work_header = stripped.startswith('● ')
            is_referral = '←' in stripped and not is_work_header and not stripped.startswith('آغاز') and not stripped.startswith('انجام')

            if (is_work_header or is_referral) and curr_entry_lines:
                entries.append((curr_entry_lines, entry_start_page, curr_page))
                curr_entry_lines = []
                entry_start_page = curr_page

            curr_entry_lines.append(line)

        if curr_entry_lines:
            entries.append((curr_entry_lines, entry_start_page, curr_page))

        return entries

    def _parse_referral(self, line: str, page: int):
        clean = PAGE_TAG_PATTERN.sub('', line).strip()
        parts = clean.split('←')
        if len(parts) >= 2:
            src = parts[0].strip()
            tgt = parts[1].strip()
            self.referrals.append(ReferralEntry(
                source_title=src,
                target_title=tgt,
                volume_number=self.volume_number,
                page=page
            ))

    def _parse_work(self, lines: List[str], start_page: int, end_page: int) -> Optional[WorkEntry]:
        header_line = lines[0]
        raw_header = header_line.lstrip('● ').strip()
        clean_header = PAGE_TAG_PATTERN.sub('', raw_header).strip()

        titles, subject_part, language_part = parse_header_line(clean_header)
        primary_title = titles[0] if titles else clean_header
        alternative_titles = titles[1:] if len(titles) > 1 else []

        subject_raw = subject_part
        subjects = parse_subjects(subject_part)
        language_raw = language_part
        languages = parse_languages(language_part)

        work = WorkEntry(
            primary_title=primary_title,
            alternative_titles=alternative_titles,
            subject=subject_raw,
            subject_raw=subject_raw,
            subjects=subjects,
            language=language_raw,
            language_raw=language_raw,
            languages=languages,
            volume_number=self.volume_number,
            page_start=start_page,
            page_end=end_page
        )

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

        for seq, ms_lines in enumerate(ms_blocks, 1):
            ms = self._parse_manuscript(ms_lines, start_page, seq)
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
                trans_parts = [tp.strip() for tp in cand.split('=') if tp.strip()]
                if trans_parts:
                    work.transliteration = trans_parts[0]
                    work.alternative_transliterations = trans_parts[1:]
                idx += 1

        # 2. Author info
        while idx < total_p and not preamble_lines[idx].strip():
            idx += 1
        if idx < total_p:
            cand = PAGE_TAG_PATTERN.sub('', preamble_lines[idx]).strip()
            next_cand = PAGE_TAG_PATTERN.sub('', preamble_lines[idx+1]).strip() if idx+1 < total_p else None

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
                    work.author_death_date_raw = date_part
                    if re.search(r'(?:م\b|میلادی|قبل میلاد)', date_part) and not re.search(r'(?:ق\b|قمری)', date_part):
                        work.author_death_date_gregorian = date_part
                    else:
                        work.author_death_date_hijri = date_part
                    
                    cent, sort_y, greg_calc = parse_date_triad(date_part)
                    work.author_death_date_century = cent
                    work.author_death_date_sort_year = sort_y
                    if greg_calc:
                        work.author_death_date_gregorian_calculated = greg_calc
                idx += 1

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

        # 3. Remaining preamble: description, related_work, print, composition_date, dedication, incipit/explicit, bibliography
        rem_text = "\n".join(preamble_lines[idx:]).strip()
        if not rem_text:
            return

        # Composition date (تاریخ تألیف / تألیف / تاریخ اجازه / اجازه)
        comp_m = re.search(r'(?:^|[؛،\n])\s*(?:تاریخ تألیف|تألیف|تاریخ اجازه|اجازه):\s*(.+?)(?=(?:[؛،\n]\s*(?:محل تألیف|محل صدور)|(?:\s+این\s+(?:کتاب|رساله))|[؛\n]|$))', rem_text)
        if comp_m:
            work.composition_date = comp_m.group(1).strip()
            rem_text = rem_text.replace(comp_m.group(0), ' ').strip()

        # Dedication (اهداء به / اهدا به / اهدایی به)
        ded_m = re.search(r'(?:^|\n)\s*(?:اهداء به|اهدا به|اهدایی به):\s*([^\n]+)', rem_text)
        if ded_m:
            work.dedication = ded_m.group(1).strip()
            rem_text = rem_text.replace(ded_m.group(0), ' ').strip()

        # Related work (وابسته به: ...)
        rel_m = re.search(r'(?:^|\n)\s*وابسته به:\s*([^\n]+)', rem_text)
        if rel_m:
            work.related_work = rel_m.group(1).strip()
            rem_text = rem_text.replace(rel_m.group(0), ' ').strip()

        # Print info at work level: چاپ: ...
        work_print_m = re.search(r'(?:^|\n)\s*چاپ:\s*([^\n]+)', rem_text)
        if work_print_m:
            work.print_info = work_print_m.group(1).strip()

        # Work level incipit / explicit
        inc_m = re.search(r'(?:^|\n)\s*آغاز:\s*([^\n]+)', rem_text)
        if inc_m:
            work.incipit = inc_m.group(1).strip()
        exp_m = re.search(r'(?:^|\n)\s*انجام:\s*([^\n]+)', rem_text)
        if exp_m:
            work.explicit = exp_m.group(1).strip()

        # Bibliography citations [ ... ] - split each by semicolon (؛)
        bib_matches = re.findall(r'\[([^\]]+)\]', rem_text)
        if bib_matches:
            bib_items = []
            for b in bib_matches:
                citations = [c.strip() for c in b.split('؛') if c.strip()]
                bib_items.extend(citations)
            work.bibliography = bib_items

        # Description is everything before incipit, print, or bibliography
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
            if 'همان نسخه' in ms.original_copy_ref:
                m_num = re.search(r'شماره\s*(\d+)', ms.original_copy_ref)
                if m_num:
                    ms.parent_manuscript_seq = int(m_num.group(1))
                elif fallback_seq > 1:
                    ms.parent_manuscript_seq = fallback_seq - 1

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
        for ep in [r'(?:^|[؛،\n])\s*توضیح:\s*([^؛\n]+)', r'(?:^|[؛،\n])\s*تذکر:\s*([^؛\n]+)']:
            for em in re.finditer(ep, rem_text):
                ms.editorial_notes.append(em.group(1).strip())
                extracted_spans.append(em.group(0))

        # 6. Colophon extract: ترقیمه: / انجامه: / خاتمه:
        colophon_m = re.search(r'(?:^|[؛،\n])\s*(?:ترقیمه|انجامه|خاتمه):\s*([^؛\n]+)', rem_text)
        if colophon_m:
            ms.colophon = colophon_m.group(1).strip()
            extracted_spans.append(colophon_m.group(0))

        # 7. Composition date: تاریخ تألیف / تألیف / تاریخ اجازه / اجازه
        comp_m = re.search(r'(?:^|[؛،\n])\s*(?:تاریخ تألیف|تألیف|تاریخ اجازه|اجازه):\s*(.+?)(?=(?:[؛،\n]\s*(?:محل تألیف|محل صدور)|(?:\s+این\s+(?:کتاب|رساله))|[؛\n]|$))', rem_text)
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
                exp_m = re.search(r'انجام:\s*(?:موجود:)?\s*(.+?)(?=(?:[؛،\n]\s*خط[:\s]|؛|\n|$))', rem_text)
                if exp_m:
                    ms.explicit_text = exp_m.group(1).strip()
                    extracted_spans.append(exp_m.group(0))

        # 10. Defects (افتادگی: ...)
        defects_m = re.search(r'(?:^|[؛،\n])\s*افتادگی:\s*([^؛\n]+)', rem_text)
        if defects_m:
            ms.defects = defects_m.group(1).strip()
            extracted_spans.append(defects_m.group(0))

        # 11. Codicological labels
        script_m = re.search(r'(?:^|[؛،\n])\s*خط:\s*([^،؛\n]+)', rem_text)
        if script_m:
            ms.script = script_m.group(1).strip()
            ms.scripts = parse_scripts(ms.script)
            extracted_spans.append(script_m.group(0))

        if 'بی‌کا' in rem_text or 'بی کا' in rem_text:
            ms.is_bika = True
            ms.scribe = None
            ms.scribe_name = None
            extracted_spans.extend(['بی‌کا', 'بی کا'])
        else:
            scribe_m = re.search(r'(?:^|[؛،\n])\s*(?:کا:|کاتب:)\s*([^،؛\n]+)', rem_text)
            if scribe_m:
                ms.scribe = scribe_m.group(1).strip()
                ms.scribe_name = ms.scribe
                extracted_spans.append(scribe_m.group(0))

        if 'کاتب = مؤلف' in rem_text or 'کاتب=مؤلف' in rem_text or 'به خط مؤلف' in rem_text:
            ms.is_autograph = True
            extracted_spans.extend(['کاتب = مؤلف', 'کاتب=مؤلف', 'به خط مؤلف'])

        date_m = re.search(r'(?:^|[؛،\n])\s*تا:\s*([^،؛\n]+)', rem_text)
        if date_m:
            ms.copy_date_raw = date_m.group(1).strip()
            extracted_spans.append(date_m.group(0))

        place_m = re.search(r'(?:^|[؛،\n])\s*جا:\s*([^،؛\n]+)', rem_text)
        if place_m:
            ms.copy_place = place_m.group(1).strip()
            extracted_spans.append(place_m.group(0))

        folios_m = re.search(r'(\d+[\d\s\/\-–\.]*(?:ص|صص|گ|برگ|ورق|صفحه))\b', rem_text)
        if folios_m:
            ms.folios = folios_m.group(1).strip()
            extracted_spans.append(folios_m.group(0))

        lines_m = re.search(r'(\d+[\d\s\/\-–\.]*سطر|مختلف السطر)', rem_text)
        if lines_m:
            ms.lines = lines_m.group(1).strip()
            extracted_spans.append(lines_m.group(0))

        dim_m = re.search(r'(?:اندازه|ابعاد):\s*([^؛\n]+)', rem_text)
        if dim_m:
            ms.dimensions = dim_m.group(1).strip()
            extracted_spans.append(dim_m.group(0))

        paper_m = re.search(r'کاغذ:\s*([^؛\n]+)', rem_text)
        if paper_m:
            ms.paper = paper_m.group(1).strip()
            extracted_spans.append(paper_m.group(0))

        binding_m = re.search(r'جلد:\s*([^؛\n]+)', rem_text)
        if binding_m:
            ms.binding = binding_m.group(1).strip()
            extracted_spans.append(binding_m.group(0))

        format_m = re.search(r'قطع:\s*([^؛\n]+)', rem_text)
        if format_m:
            ms.format = format_m.group(1).strip()
            extracted_spans.append(format_m.group(0))

        # Ownership & Seals
        raw_seals = []
        for sp in [r'(?:دارای\s*)?مهر(?:ها)?:\s*([^؛\n]+)', r'تملک:\s*([^؛\n]+)', r'وقف:\s*([^؛\n]+)']:
            for sm in re.finditer(sp, rem_text):
                ms.ownership_and_seals.append(sm.group(0).strip())
                if 'مهر' in sm.group(0):
                    raw_seals.append(sm.group(0).strip())
                extracted_spans.append(sm.group(0))

        if raw_seals:
            ms.seals = parse_seals(raw_seals)

        for ap in [r'یادداشت[^\n؛]+', r'ضمیمه[^\n؛]+']:
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
        if 'عکسی' in rem_text or 'عکس' in clean_header or (ms.original_copy_ref and 'عکسی' in ms.original_copy_ref):
            ms.is_facsimile = True
            ms.sequence_number = None
            extracted_spans.append('عکسی')
        if 'غیر همانند' in rem_text or 'غیرهمانند' in rem_text:
            ms.is_distinct_work = True
            extracted_spans.extend(['غیر همانند', 'غیرهمانند'])

        # Residual notes calculation
        res_clean = rem_text
        for span in sorted(list(set(extracted_spans)), key=len, reverse=True):
            if span:
                res_clean = res_clean.replace(span, ' ')

        labels = ['اندازه:', 'ابعاد:', 'کاغذ:', 'جلد:', 'قطع:', 'آغاز:', 'انجام:', 'نسخه اصل:', 'خط:', 'کا:', 'تا:', 'جا:', 'افتادگی:', 'چاپ:', 'شامل:', 'اهدایی:', 'اهدا:', 'ترقیمه:', 'انجامه:', 'خاتمه:', 'تاریخ تألیف:', 'تألیف:', 'تاریخ اجازه:', 'اجازه:', 'توضیح:', 'تذکر:']
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

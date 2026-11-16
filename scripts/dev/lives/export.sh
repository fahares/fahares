#!/usr/bin/env bash
# Exports what blocker.py reads, from the local copy of production (fahares_prod in the fahares_mariadb container).
#   scripts/dev/lives/export.sh <dir> <source-code>
set -euo pipefail
DIR="$1"; CODE="$2"; mkdir -p "$DIR"
q() { docker exec fahares_mariadb sh -c 'mariadb -u root -p"$MARIADB_ROOT_PASSWORD" fahares_prod --default-character-set=utf8mb4 -B -N -e "$1"' sh "$1"; }
# people with works: the only candidates of the first round
q "select id, slug, name, replace(ifnull(aliases,''),'\t',' '), ifnull(birth_year_hijri,''), ifnull(death_year_hijri,''), ifnull(century_hijri,''), replace(ifnull(life_dates_raw,''),'\t',' '), works_count, manuscripts_count from people where works_count>0" > "$DIR/authors.tsv"
q "select author_id, replace(primary_title,'\t',' ') from works where author_id is not null" > "$DIR/works.tsv"
# the entries of the source, and of every book of lives the entries already tied to a person (the measure of recall)
q "select si.id, s.code, si.volume_number, si.item_key, replace(si.title,'\t',' '), left(replace(replace(si.text,'\n',' / '),'\t',' '),900), char_length(si.text) from source_items si join sources s on s.id=si.source_id where s.kind='biographical' and si.kind='entry' and (s.code='$CODE' or exists(select 1 from source_entries se where se.source_item_id=si.id and se.citable_type like '%Person'))" > "$DIR/items.tsv"
q "select se.source_item_id, se.citable_id from source_entries se where se.citable_type like '%Person' and se.source_item_id is not null and ifnull(se.match_method,'') <> 'name_llm'" > "$DIR/gold.tsv"
wc -l "$DIR"/*.tsv

SELECT sv.id vol_id, s.code, s.title cat_title, sv.volume_number, l.name library, l.city,
 (sv.facsimile_code IS NOT NULL AND COALESCE(JSON_VALUE(s.metadata,'$.images'),'true')<>'false') has_images,
 (select count(*) from source_pages p where p.source_volume_id=sv.id) page_texts_in_volume,
 COUNT(DISTINCT se.citable_id) copies,
 COUNT(DISTINCT CASE WHEN se.page_start IS NOT NULL THEN se.citable_id END) copies_with_page,
 COUNT(DISTINCT CASE WHEN COALESCE(se.entry_text,'')<>'' OR sp.id IS NOT NULL THEN se.citable_id END) copies_c_text
FROM source_volumes sv JOIN sources s ON s.id=sv.source_id AND s.kind='library_catalogue'
LEFT JOIN libraries l ON l.id=s.library_id
LEFT JOIN source_entries se ON se.source_volume_id=sv.id AND se.citable_type='App\\Models\\Manuscript' AND se.origin IN ('fankha_citation','match','manual')
LEFT JOIN source_pages sp ON sp.source_volume_id=se.source_volume_id AND sp.page=COALESCE(se.text_page,se.page_start)
GROUP BY sv.id

SELECT m.id, m.library_id,
 MAX(se.id IS NOT NULL AND se.source_volume_id IS NOT NULL) AS a_ref,
 MAX(se.id IS NOT NULL AND se.source_volume_id IS NOT NULL AND se.page_start IS NOT NULL) AS d_prim_page,
 MAX(se.page_start IS NOT NULL AND sv.facsimile_code IS NOT NULL AND COALESCE(JSON_VALUE(s.metadata,'$.images'),'true')<>'false') AS b_img,
 MAX(COALESCE(se.entry_text,'')<>'') AS c_entry,
 MAX(sp.id IS NOT NULL) AS c_page,
 MAX(EXISTS(select 1 from manuscript_catalog_references r where r.manuscript_id=m.id and r.catalog_volume_id is not null)) AS a_mcr
FROM manuscripts m
LEFT JOIN source_entries se ON se.citable_type='App\\Models\\Manuscript' AND se.citable_id=m.id AND se.origin IN ('fankha_citation','match','manual')
LEFT JOIN source_volumes sv ON sv.id=se.source_volume_id
LEFT JOIN sources s ON s.id=se.source_id
LEFT JOIN source_pages sp ON sp.source_volume_id=se.source_volume_id AND sp.page=COALESCE(se.text_page,se.page_start)
GROUP BY m.id, m.library_id

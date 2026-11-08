<?php
// step 14.103: Kalam-turath's own citation forms in its page texts, volume first: al-Dhari'a, Mushar's two books and Mu'allafat
// al-Zaydiyya. Run with «php artisan tinker database/data/citation_forms_step14_103.php»; run again, it changes nothing.
// Undone by deleting the four forms in the panel (/admin/sources → the cited source).
$ids = DB::table('sources')->whereIn('code', ['kalam-turath','dharia','mushar-fa','mushar-authors','zaydiyya'])->pluck('id','code');
$forms = [
  'dharia' => [['الذريعة', 'الذريعة الى تصانيف الشيعة'], ['volumes' => 26, 'pairs_after_name' => true]],
  'mushar-fa' => [['مشار الفارسي', 'مشار فارسي'], ['volumes' => 5, 'unit' => 'ستون', 'pairs_after_name' => true]],
  'mushar-authors' => [['مؤلفين مشار', 'مؤلفي مشار'], ['volumes' => 6, 'pairs_after_name' => true]],
  'zaydiyya' => [['مؤلفات الزيدية'], ['volumes' => 3, 'pairs_after_name' => true]],
];
foreach ($forms as $code => [$names, $options]) {
  App\Models\SourceCitationForm::updateOrCreate(
    ['cited_source_id' => $ids[$code], 'citing_source_id' => $ids['kalam-turath'], 'context' => 'page_text'],
    ['names' => $names, 'regex' => null, 'options' => $options, 'notes' => 'معجم التراث الكلامي: جلد پیش از صفحه (گام ۱۴.۱۰۳)']);
}
App\Sources\CitationForms::forget();
echo DB::table('source_citation_forms')->where('citing_source_id', $ids['kalam-turath'])->count(), " forms\n";

@extends('layouts.admin')

@section('title', "بررسی پیشنهاد اصلاحی #{$suggestion->id}")
@section('page_title', "داوری و بازبینی پیشنهاد اصلاحی #{$suggestion->id}")
@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-[#B38A50]">پیشخوان</a>
    <span>/</span>
    <a href="{{ route('admin.suggestions.index') }}" class="hover:text-[#B38A50]">پیشنهادات اصلاحی</a>
    <span>/</span>
    <span class="text-stone-400">داوری رکورد #{{ $suggestion->id }}</span>
@endsection

@section('content')
<div class="max-w-5xl mx-auto space-y-8">

    <!-- Header & Target Info Card -->
    <div class="p-6 rounded-2xl bg-white dark:bg-[#15192C] border border-stone-200 dark:border-stone-800 shadow-xs space-y-4">
        
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-100 dark:border-stone-800 pb-4">
            <div class="flex items-center gap-3">
                <span class="px-3 py-1 rounded-xl text-xs font-black bg-[#292C56] text-amber-200">
                    {{ \App\Suggestions\SuggestionRegistry::typeLabel($suggestion->suggestable_type) }} #{{ $suggestion->suggestable_id }}
                </span>
                <span class="text-sm font-bold text-stone-900 dark:text-stone-100">
                    @if($suggestion->kind === 'general')
                        پیشنهاد کلی (متن آزاد)
                    @elseif($suggestion->kind === 'relation')
                        پیشنهاد رابطه‌ای: <span class="text-[#B38A50]">{{ $relation['label'] ?? $suggestion->field_name }}</span>
                    @else
                        فیلد هدف: <span class="text-[#B38A50]">{{ $suggestion->field_label }}</span>
                        <code class="text-stone-400 font-mono text-[11px]">{{ $suggestion->field_name }}</code>
                    @endif
                </span>
            </div>

            <!-- Status Indicator -->
            <div>
                @if($suggestion->status === 'pending')
                    <span class="px-3 py-1 rounded-full text-xs font-bold bg-amber-500/15 text-amber-800 dark:text-amber-300">
                        در انتظار داوری
                    </span>
                @elseif($suggestion->status === 'approved')
                    <span class="px-3 py-1 rounded-full text-xs font-bold bg-emerald-500/15 text-emerald-800 dark:text-emerald-300">
                        تایید و اعمال شد (توسط {{ $suggestion->reviewer?->name ?: 'مدیر' }})
                    </span>
                @else
                    <span class="px-3 py-1 rounded-full text-xs font-bold bg-red-500/15 text-red-800 dark:text-red-300">
                        رد شد (توسط {{ $suggestion->reviewer?->name ?: 'مدیر' }})
                    </span>
                @endif
            </div>
        </div>

        <!-- Entity Metadata Summary -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs text-stone-600 dark:text-stone-300">
            <div>
                <span class="text-stone-400">پژوهشگر ارائه‌دهنده:</span>
                <strong class="text-stone-800 dark:text-stone-200 mr-1">{{ $suggestion->guest_name ?: 'پژوهشگر مهمان' }}</strong>
                @if($suggestion->guest_email)
                    <span class="text-stone-400 dir-ltr font-mono text-[11px] block mt-0.5">({{ $suggestion->guest_email }})</span>
                @endif
            </div>
            <div>
                <span class="text-stone-400">تاریخ ارسال:</span>
                <span class="font-mono text-stone-800 dark:text-stone-200 mr-1">{{ $suggestion->created_at->format('Y/m/d H:i') }}</span>
                <span class="text-stone-400 text-[10px]">({{ $suggestion->created_at->diffForHumans() }})</span>
            </div>
        </div>

        @if($target)
            <div class="pt-2">
                @php
                    $base = class_basename($suggestion->suggestable_type);
                    $targetUrl = match($base) {
                        'Manuscript' => route('manuscripts.show', $target),
                        'Work' => route('works.show', $target),
                        'Person' => route('people.show', $target),
                        'Library' => route('libraries.show', $target),
                        default => '#'
                    };
                @endphp
                <a href="{{ $targetUrl }}" target="_blank" class="inline-flex items-center gap-1.5 text-xs text-[#B38A50] hover:underline font-bold">
                    <span>مشاهده صفحه عمومی این رکورد در سایت</span>
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                </a>
            </div>
        @endif

    </div>

    @if($suggestion->kind === 'relation')
        <!-- The two records the suggestion connects -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            @foreach([['این رکورد', $target], [$relation && $relation['key'] === 'move_to_work' ? 'اثر پیشنهادی' : 'رکورد مقصد', $relationTarget]] as [$caption, $record])
                <div class="p-6 rounded-2xl bg-white dark:bg-[#15192C] border border-stone-200 dark:border-stone-800 shadow-xs space-y-2 text-xs">
                    <h4 class="font-bold text-stone-500">{{ $caption }}</h4>
                    @if($record)
                        @php $info = \App\Suggestions\SuggestionRegistry::describe($record); @endphp
                        <a href="{{ $info['url'] }}" target="_blank" class="block text-sm font-bold text-[#292C56] dark:text-amber-200 hover:text-[#B38A50]">{{ $info['label'] }}</a>
                        <div class="text-stone-500"><span class="font-mono">#{{ $info['id'] }}</span>{{ $info['sub'] ? ' · ' . $info['sub'] : '' }}</div>
                    @else
                        <div class="text-red-600 font-bold">این رکورد دیگر وجود ندارد (شاید ادغام یا حذف شده است).</div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    <!-- Side-by-Side Comparison Box -->
    <div class="grid grid-cols-1 {{ $suggestion->kind === 'field' ? 'md:grid-cols-2' : '' }} gap-6">
        @if($suggestion->kind === 'field')
        
        <!-- Current DB Value -->
        <div class="p-6 rounded-2xl bg-white dark:bg-[#15192C] border border-stone-200 dark:border-stone-800 shadow-xs space-y-3">
            <div class="flex items-center gap-2 text-stone-500">
                <span class="w-2.5 h-2.5 rounded-full bg-stone-400"></span>
                <h4 class="text-xs font-bold uppercase tracking-wider">مقدار موجود در پایگاه داده (یا متن چاپ فنخا)</h4>
            </div>
            <div class="p-4 rounded-xl bg-stone-50 dark:bg-stone-900 border border-stone-200 dark:border-stone-800 text-stone-700 dark:text-stone-300 text-sm leading-relaxed min-h-[90px] whitespace-pre-wrap font-sans">
                {{ $suggestion->current_value ?: '— فیلد خالی است —' }}
            </div>
        </div>

        @endif

        <!-- Suggested Value by Researcher -->
        <div class="p-6 rounded-2xl bg-amber-500/5 border-2 border-amber-500/30 rounded-2xl shadow-xs space-y-3">
            <div class="flex items-center gap-2 text-amber-700 dark:text-amber-400">
                <span class="w-2.5 h-2.5 rounded-full bg-amber-500 animate-pulse"></span>
                <h4 class="text-xs font-bold uppercase tracking-wider">مقدار پیشنهادی پژوهشگر</h4>
            </div>
            <div class="p-4 rounded-xl bg-amber-500/10 border border-amber-500/20 text-stone-900 dark:text-amber-100 text-sm font-semibold leading-relaxed min-h-[90px] whitespace-pre-wrap font-sans">
                {{ $suggestion->suggested_value }}
            </div>
        </div>

    </div>

    <!-- Rationale and Citation Box -->
    <div class="p-6 rounded-2xl bg-white dark:bg-[#15192C] border border-stone-200 dark:border-stone-800 shadow-xs space-y-3">
        <div class="flex items-center gap-2 text-stone-700 dark:text-stone-300">
            <svg class="w-4 h-4 text-[#B38A50]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
            </svg>
            <h4 class="text-xs font-bold">مستند علمی، ترقیمه و منبع ادعای پژوهشگر:</h4>
        </div>
        <div class="p-4 rounded-xl bg-stone-50 dark:bg-stone-900 border border-stone-200 dark:border-stone-800 text-xs text-stone-700 dark:text-stone-300 leading-relaxed font-sans">
            {{ $suggestion->rationale_citation ?: '— مستندی ذکر نشده است —' }}
        </div>
    </div>

    @if($suggestion->status === 'pending')
        <!-- Action Desk: Approve & Reject Forms -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 pt-2">
            
            <!-- Approval Form (Takes 2 columns) -->
            <div class="lg:col-span-2 p-6 rounded-2xl bg-white dark:bg-[#15192C] border border-emerald-500/30 shadow-xs space-y-4">
                <div class="flex items-center gap-2 text-emerald-700 dark:text-emerald-400">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <h3 class="text-sm font-bold">{{ $relation ? $relation['action'] : ($field ? 'تأیید و اعمال اصلاحیه در پایگاه داده' : 'رسیدگی به پیشنهاد') }}</h3>
                </div>

                @if($relation)
                    @include('admin.suggestions.partials.relation-approve')
                @else
                <form action="{{ route('admin.suggestions.approve', $suggestion) }}" method="POST" class="space-y-4 text-xs">
                    @csrf

                    @if($errors->any())
                        <div class="p-3 rounded-xl bg-red-50 dark:bg-red-950/30 border border-red-300 dark:border-red-800 text-red-700 dark:text-red-300 font-bold">
                            {{ $errors->first() }}
                        </div>
                    @endif

                    @if(! $field)
                        <div class="p-4 rounded-xl bg-sky-50 dark:bg-sky-950/30 border border-sky-200 dark:border-sky-900 text-sky-900 dark:text-sky-200 leading-relaxed">
                            @if($suggestion->kind === 'general')
                                این پیشنهاد کلی است و خودکار اعمال نمی‌شود. تغییرات لازم را دستی انجام دهید. با تأیید، پیشنهاد «رسیدگی‌شده» ثبت می‌شود.
                            @else
                                فیلد <code class="font-mono">{{ $suggestion->field_name }}</code> در فهرست فیلدهای قابل اصلاح نیست و خودکار اعمال نمی‌شود. تغییر را دستی انجام دهید. با تأیید، پیشنهاد «رسیدگی‌شده» ثبت می‌شود.
                            @endif
                        </div>
                    @elseif($field['input'] === 'boolean')
                        <div class="space-y-1">
                            <label class="block font-bold text-stone-700 dark:text-stone-300">مقدار نهایی «{{ $field['label'] }}»:</label>
                            @php $boolDefault = old('applied_value', in_array(trim($suggestion->suggested_value), ['بله', '1', 'دارد', 'هست', 'true'], true) ? '1' : '0'); @endphp
                            <div class="flex gap-4 pt-1">
                                <label class="inline-flex items-center gap-1.5 font-bold"><input type="radio" name="applied_value" value="1" @checked($boolDefault === '1') class="text-[#B38A50]"> بله</label>
                                <label class="inline-flex items-center gap-1.5 font-bold"><input type="radio" name="applied_value" value="0" @checked($boolDefault === '0') class="text-[#B38A50]"> خیر</label>
                            </div>
                        </div>
                    @else
                        <div class="space-y-1">
                            <label class="block font-bold text-stone-700 dark:text-stone-300">
                                مقدار نهایی «{{ $field['label'] }}» (می‌توانید پیش از تأیید ویرایش کنید):
                            </label>
                            <textarea name="applied_value" rows="{{ in_array($field['input'], ['quote', 'list', 'textarea'], true) ? 5 : 2 }}" class="w-full p-3 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 font-semibold focus:border-[#B38A50]">{{ old('applied_value', $suggestion->suggested_value) }}</textarea>
                            @if($field['input'] === 'list')
                                <p class="text-stone-400">هر مورد در یک سطر. فهرست فعلی به‌طور کامل جایگزین می‌شود.</p>
                            @elseif($field['input'] === 'quote' && count($target?->metadata[$field['meta_key']] ?? []) > 1)
                                <p class="text-amber-700 dark:text-amber-400 font-bold">این نسخه اکنون {{ count($target->metadata[$field['meta_key']]) }} مورد ثبت‌شده دارد. با تأیید، همه با همین یک متن جایگزین می‌شوند.</p>
                            @endif
                        </div>

                        @if($field['input'] === 'hijri_date')
                            <div class="space-y-1">
                                <label class="block font-bold text-stone-700 dark:text-stone-300">سال هجری قمری برای مرتب‌سازی و جستجو:</label>
                                <input type="number" name="hijri_year" min="{{ \App\Support\HijriYear::MIN }}" max="{{ \App\Support\HijriYear::MAX }}" value="{{ old('hijri_year', $parsedYear) }}" class="w-40 p-2.5 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 font-mono font-bold">
                                <p class="text-stone-400">از متن پیشنهادی استخراج شد{{ $parsedYear ? '' : ' (سالی پیدا نشد)' }}. اگر تاریخ فقط سده است یا شمسی و میلادی است، خالی بگذارید.</p>
                            </div>
                        @endif

                        @if($field['input'] === 'person')
                            @php
                                $defaultPick = old('person_pick', $people['suggested'] ? 'p:' . $people['suggested']->id : ($people['current'] ? 'text' : ($people['candidates']->isNotEmpty() ? 'p:' . $people['candidates']->first()->id : 'create')));
                            @endphp
                            <div class="space-y-2 p-4 rounded-xl bg-stone-50 dark:bg-stone-900 border border-stone-200 dark:border-stone-800">
                                <div class="font-bold text-stone-700 dark:text-stone-300">پیوند به جدول اشخاص:</div>

                                @foreach(array_filter(['suggested' => $people['suggested'], 'current' => $people['current']]) as $role => $person)
                                    @include('admin.suggestions.partials.person-option', ['person' => $person, 'note' => $role === 'suggested' ? 'انتخاب پیشنهاددهنده' : ($field['role'] === 'author' ? 'پدیدآور فعلی پیوندخورده' : 'کاتب فعلی پیوندخورده'), 'checked' => $defaultPick === 'p:' . $person->id])
                                @endforeach

                                @foreach($people['candidates'] as $person)
                                    @include('admin.suggestions.partials.person-option', ['person' => $person, 'note' => null, 'checked' => $defaultPick === 'p:' . $person->id])
                                @endforeach

                                <label class="flex items-center gap-2 py-1">
                                    <input type="radio" name="person_pick" value="manual" @checked($defaultPick === 'manual') class="text-[#B38A50]">
                                    <span>پیوند به شخص با شناسه‌ی</span>
                                    <input type="number" name="person_manual_id" value="{{ old('person_manual_id') }}" placeholder="مثلاً 1234" class="w-28 p-1.5 rounded-lg border border-stone-300 dark:border-stone-700 bg-white dark:bg-stone-800 font-mono">
                                </label>
                                <label class="flex items-center gap-2 py-1">
                                    <input type="radio" name="person_pick" value="create" @checked($defaultPick === 'create') class="text-[#B38A50]">
                                    <span>ساخت شخص تازه با همین نام</span>
                                </label>
                                <label class="flex items-center gap-2 py-1">
                                    <input type="radio" name="person_pick" value="text" @checked($defaultPick === 'text') class="text-[#B38A50]">
                                    <span>فقط ذخیره‌ی متن نام{{ $people['current'] ? ' (پیوند فعلی دست نمی‌خورد)' : ' (بدون پیوند)' }}</span>
                                </label>
                            </div>
                        @endif

                        <!-- Revision Category -->
                        <div class="space-y-1">
                            <label class="block font-bold text-stone-700 dark:text-stone-300">رده‌بندی نوع تصحیح:</label>
                            <select name="revision_category" class="w-full p-2.5 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100 font-semibold">
                                <option value="scholarly_correction">تصحیح پژوهشی و نسخه‌شناختی (استناد به ترقیمه، فهارس دیگر و...)</option>
                                <option value="parser_fix">اصلاح خطای پارسر متنی (تفکیک اشتباه فیلدها)</option>
                                <option value="ocr_fix">اصلاح غلط چاپی یا نویسه‌خوان (OCR)</option>
                            </select>
                        </div>

                        <!-- Create Annotation Checkbox -->
                        <div class="flex items-center gap-2 pt-1">
                            <input type="hidden" name="create_annotation" value="0">
                            <input type="checkbox" name="create_annotation" id="create_annotation" value="1" checked class="rounded border-stone-300 text-[#B38A50] focus:ring-[#B38A50] w-4 h-4">
                            <label for="create_annotation" class="text-stone-700 dark:text-stone-300 font-bold select-none cursor-pointer">
                                ثبت در دستگاه علمی به عنوان «پانویس انتقادی رسمی» و نمایش در صفحه نسخه/اثر
                            </label>
                        </div>
                    @endif

                    <!-- Admin Notes -->
                    <div class="space-y-1">
                        <label class="block font-bold text-stone-700 dark:text-stone-300">یادداشت داور علمی (اختیاری):</label>
                        <input type="text" name="admin_notes" value="{{ old('admin_notes') }}" placeholder="توضیحات داوری..." class="w-full p-2.5 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100">
                    </div>

                    <div class="pt-2">
                        <button type="submit" 
                                onclick="return confirm('{{ $field ? 'آیا از تأیید این پیشنهاد و اعمال تغییر در پایگاه داده اطمینان دارید؟' : 'این پیشنهاد «رسیدگی‌شده» ثبت شود؟' }}')"
                                class="w-full py-3 px-4 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm shadow-md transition flex items-center justify-center gap-2">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            <span>{{ $field ? 'تأیید قطعی و اعمال در پایگاه داده' : 'ثبت به‌عنوان رسیدگی‌شده' }}</span>
                        </button>
                    </div>
                </form>
                @endif
            </div>

            <!-- Rejection Form (Takes 1 column) -->
            <div class="p-6 rounded-2xl bg-white dark:bg-[#15192C] border border-red-500/30 shadow-xs space-y-4">
                <div class="flex items-center gap-2 text-red-600 dark:text-red-400">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <h3 class="text-sm font-bold">رد پیشنهاد</h3>
                </div>

                <form action="{{ route('admin.suggestions.reject', $suggestion) }}" method="POST" class="space-y-4 text-xs">
                    @csrf

                    <div class="space-y-1">
                        <label class="block font-bold text-stone-700 dark:text-stone-300">علت رد پیشنهاد (جهت ثبت در تاریخچه):</label>
                        <textarea name="admin_notes" rows="4" placeholder="مثال: مستند ارائه شده کافی نبود یا در متن چاپی فنخا به صراحت خلاف این ذکر شده است..." class="w-full p-2.5 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100"></textarea>
                    </div>

                    <div class="pt-2">
                        <button type="submit" 
                                onclick="return confirm('آیا از رد این پیشنهاد اطمینان دارید؟')"
                                class="w-full py-2.5 px-4 rounded-xl border border-red-500 text-red-600 hover:bg-red-50 dark:hover:bg-red-950/20 font-bold text-xs transition flex items-center justify-center gap-2">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            <span>رد پیشنهاد اصلاحی</span>
                        </button>
                    </div>
                </form>
            </div>

        </div>
    @else
        <!-- Already reviewed notice -->
        <div class="p-6 rounded-2xl bg-stone-50 dark:bg-stone-800/50 border border-stone-200 dark:border-stone-700 text-xs text-stone-600 dark:text-stone-300 space-y-2">
            <h4 class="font-bold text-stone-900 dark:text-stone-100">یادداشت داور ثبت‌شده در زمان بررسی:</h4>
            <p>{{ $suggestion->admin_notes ?: 'هیچ یادداشتی ثبت نشده است.' }}</p>
            @php $res = $suggestion->resolution ?? []; @endphp
            @if($suggestion->status === 'approved' && $res)
                <p class="pt-1">
                    @if(isset($res['kept_id']))
                        ادغام شد: رکورد <span class="font-mono">#{{ $res['removed_id'] }}</span> در <span class="font-mono">#{{ $res['kept_id'] }}</span>.
                    @elseif($suggestion->kind === 'relation' && isset($res['work_id']))
                        نسخه به اثر <a href="{{ route('works.show', $res['work_id']) }}" class="text-[#B38A50] hover:underline font-mono">#{{ $res['work_id'] }}</a> منتقل شد.
                    @elseif(empty($res['applied']))
                        به‌صورت دستی رسیدگی شد (اعمال خودکار نداشت).
                    @else
                        در شناسنامه اعمال شد.
                        @if(($res['person_action'] ?? null) === 'link') پیوند به شخص <a href="{{ route('people.show', $res['person_id']) }}" class="text-[#B38A50] hover:underline font-mono">#{{ $res['person_id'] }}</a>.
                        @elseif(($res['person_action'] ?? null) === 'create') شخص تازه ساخته شد: <a href="{{ route('people.show', $res['person_id']) }}" class="text-[#B38A50] hover:underline font-mono">#{{ $res['person_id'] }}</a>.
                        @elseif(($res['person_action'] ?? null) === 'text') فقط متن نام ذخیره شد.
                        @endif
                        @if(array_key_exists('hijri_year', $res)) سال هجری: {{ $res['hijri_year'] ?? '—' }}. @endif
                    @endif
                </p>
            @endif
            <div class="text-[10px] text-stone-400 pt-2 font-mono">
                داوری توسط {{ $suggestion->reviewer?->name ?: 'سیستم' }} در تاریخ {{ $suggestion->reviewed_at?->format('Y/m/d H:i') }}
            </div>
        </div>
    @endif

</div>
@endsection

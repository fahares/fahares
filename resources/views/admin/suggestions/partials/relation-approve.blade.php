{{-- Approval controls for a relational suggestion (move a manuscript, merge duplicates) --}}
<div class="space-y-4 text-xs">
    @if($errors->any())
        <div class="p-3 rounded-xl bg-red-50 dark:bg-red-950/30 border border-red-300 dark:border-red-800 text-red-700 dark:text-red-300 font-bold">
            {{ $errors->first() }}
        </div>
    @endif

    @if(! $target || ! $relationTarget)
        <p class="p-4 rounded-xl bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 font-bold">یکی از دو رکورد وجود ندارد و این پیشنهاد قابل اعمال نیست. آن را رد کنید.</p>
    @elseif($relation['target'] === 'person')
        <p class="leading-relaxed text-stone-600 dark:text-stone-300">
            ادغام اشخاص با ابزار ادغام اشخاص انجام می‌شود. در آنجا مقایسه‌ی کامل دو شخص را می‌بینید و می‌توانید جای مبدأ و مقصد را عوض کنید. با انجام ادغام، این پیشنهاد خودکار «تأییدشده» ثبت می‌شود و با بازگردانی ادغام، دوباره «در انتظار» می‌شود.
        </p>
        <a href="{{ route('admin.people.merge', ['source_id' => $target->id, 'target_id' => $relationTarget->id, 'suggestion_id' => $suggestion->id]) }}"
           class="w-full py-3 px-4 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm shadow-md transition flex items-center justify-center gap-2">
            رفتن به ابزار ادغام اشخاص
        </a>
    @else
        <form action="{{ route('admin.suggestions.approve', $suggestion) }}" method="POST" class="space-y-4">
            @csrf

            @if($relation['key'] === 'move_to_work')
                <p class="leading-relaxed text-stone-600 dark:text-stone-300">
                    نسخه از اثر کنونی‌اش به اثر پیشنهادی منتقل می‌شود و شمار نسخه‌های هر دو اثر به‌روز می‌شود. این تغییر از «گزارش ممیزی» بازگرداندنی است.
                </p>
            @else
                <div class="space-y-1.5">
                    <span class="block font-bold text-stone-700 dark:text-stone-300">کدام رکورد بماند؟</span>
                    <label class="flex items-center gap-2"><input type="radio" name="keep" value="target" checked class="text-[#B38A50]"> رکورد مقصد (#{{ $relationTarget->id }}) بماند و این رکورد (#{{ $target->id }}) در آن ادغام شود</label>
                    <label class="flex items-center gap-2"><input type="radio" name="keep" value="source" class="text-[#B38A50]"> این رکورد (#{{ $target->id }}) بماند و رکورد مقصد (#{{ $relationTarget->id }}) در آن ادغام شود</label>
                </div>
                <div class="p-3 rounded-xl bg-amber-50 dark:bg-amber-950/30 border border-amber-300 dark:border-amber-800 text-amber-900 dark:text-amber-200 leading-relaxed">
                    @if($relation['target'] === 'work')
                        نسخه‌های اثر حذف‌شونده به اثر ماندنی منتقل می‌شوند و نسخه‌هایی که با همان کتابخانه و شماره در اثر ماندنی هست، ادغام می‌شوند. عنوان اثر حذف‌شونده به عناوین دیگر، و موضوع‌ها و زبان‌هایش به اثر ماندنی افزوده می‌شود.
                    @else
                        فیلدهای خالی نسخه‌ی ماندنی از نسخه‌ی حذف‌شونده پر می‌شود و کاتبانش به آن افزوده می‌شوند.
                    @endif
                    ارجاع مأخذ رکورد حذف‌شونده روی رکورد ماندنی ثبت می‌شود و نشانی‌اش با ریدایرکت دائمی به آن می‌رود.
                    <strong>این ادغام بازگردانی خودکار ندارد.</strong> اطلاعات کامل رکورد حذف‌شونده در گزارش ممیزی ذخیره می‌شود.
                </div>
            @endif

            <div class="space-y-1">
                <label class="block font-bold text-stone-700 dark:text-stone-300">یادداشت داور علمی (اختیاری):</label>
                <input type="text" name="admin_notes" value="{{ old('admin_notes') }}" class="w-full p-2.5 rounded-xl border border-stone-300 dark:border-stone-700 bg-stone-50 dark:bg-stone-800 text-stone-900 dark:text-stone-100">
            </div>

            <button type="submit" onclick="return confirm('{{ $relation['action'] }} انجام شود؟')"
                class="w-full py-3 px-4 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm shadow-md transition flex items-center justify-center gap-2">
                {{ $relation['action'] }}
            </button>
        </form>
    @endif
</div>

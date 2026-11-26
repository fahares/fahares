{{-- The advance notice of a planned maintenance (backlog item 136, part 4; App\Support\MaintenanceNotice),
     above the header of every page until the maintenance should be over. Closing it hides it in this browser
     for this maintenance only. --}}
@php($maintenance = \App\Support\MaintenanceNotice::current())
@if($maintenance)
<div x-data="{ key: 'maintenance-notice-{{ $maintenance['at'] }}', shown: true }"
     x-init="try { shown = localStorage.getItem(key) !== '1' } catch (e) {}"
     x-show="shown" role="status" data-maintenance-notice
     class="relative z-50 bg-gold/15 border-b border-gold/40 text-ink text-sm">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-2 flex items-start gap-3">
        <p class="flex-1 leading-7">
            <strong class="text-cinnabar">{{ \App\Support\MaintenanceNotice::TITLE }}:</strong>
            {{ \App\Support\MaintenanceNotice::sentence($maintenance) }}
            @if($maintenance['note'])
                {{ $maintenance['note'] }}
            @endif
        </p>
        <button type="button" class="shrink-0 px-2 text-muted hover:text-ink" aria-label="بستن"
                @click="shown = false; try { localStorage.setItem(key, '1') } catch (e) {}">×</button>
    </div>
</div>
@endif

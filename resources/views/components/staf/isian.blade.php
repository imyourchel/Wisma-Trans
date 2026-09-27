@props(['nama', 'label', 'bantuan' => null, 'tag' => 'label'])
{{-- Versi Livewire dari x-staf.field: pesan galat dari $errors tampil di samping label --}}
<{{ $tag }} {{ $attributes->merge(['class' => 'block min-w-0']) }}>
    <span class="mb-1.5 flex items-baseline justify-between gap-3">
        <span class="wt-field-label">{{ $label }}</span>
        @error($nama)
            <span class="min-w-0 truncate text-[11px] font-medium text-status-dirty" title="{{ $message }}" data-galat>{{ $message }}</span>
        @enderror
    </span>
    {{ $slot }}
    @if ($bantuan)
        <span class="mt-1.5 block text-xs leading-normal text-ink-500">{{ $bantuan }}</span>
    @endif
</{{ $tag }}>

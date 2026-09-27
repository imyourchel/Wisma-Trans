@props(['nama', 'label', 'bantuan' => null, 'tag' => 'label'])
{{-- Label kolom dengan pesan galat di sampingnya (tinggi panel tidak berubah saat ada galat) --}}
<{{ $tag }} {{ $attributes->merge(['class' => 'block min-w-0']) }}>
    <span class="mb-1.5 flex items-baseline justify-between gap-3">
        <span class="wt-field-label">{{ $label }}</span>
        <span x-show="pesan(@js($nama))" x-text="pesan(@js($nama))" :title="pesan(@js($nama))" x-cloak data-galat
              class="min-w-0 truncate text-[11px] font-medium text-status-dirty"></span>
    </span>
    {{ $slot }}
    @if ($bantuan)
        <span class="mt-1.5 block text-xs leading-normal text-ink-500">{{ $bantuan }}</span>
    @endif
</{{ $tag }}>

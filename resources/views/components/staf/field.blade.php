@props(['nama', 'label', 'labelUbah' => null, 'bantuan' => null, 'tag' => 'label'])
{{--
    Label kolom dengan pesan galat. Pesan pendek tampil di samping label (tinggi panel tidak berubah),
    pesan panjang (lebih dari 60 karakter) tampil utuh di bawah isian. $labelUbah: label lain saat panel dalam mode ubah.
--}}
<{{ $tag }} {{ $attributes->merge(['class' => 'block min-w-0']) }}>
    <span class="mb-1.5 flex items-baseline justify-between gap-3">
        <span class="wt-field-label" @if ($labelUbah) x-text="sedangUbah ? @js($labelUbah) : @js($label)" @endif>{{ $label }}</span>
        <span x-show="pesan(@js($nama)) && pesan(@js($nama)).length <= 60" x-text="pesan(@js($nama))" :title="pesan(@js($nama))" x-cloak data-galat
              class="min-w-0 truncate text-[11px] font-medium text-status-dirty"></span>
    </span>
    {{ $slot }}
    <span x-show="pesan(@js($nama)).length > 60" x-text="pesan(@js($nama))" x-cloak data-galat
          class="mt-1.5 block text-xs font-medium leading-normal text-status-dirty"></span>
    @if ($bantuan)
        <span class="mt-1.5 block text-xs leading-normal text-ink-500">{{ $bantuan }}</span>
    @endif
</{{ $tag }}>

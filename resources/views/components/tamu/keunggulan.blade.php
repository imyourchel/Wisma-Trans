@props(['tipe'])
{{-- Keunggulan tipe kamar (RoomType::KEUNGGULAN), dipakai kartu di /kamar dan halaman detail. Tipe tanpa entri tidak menampilkan apa pun. --}}
@if ($poin = $tipe->keunggulan())
    <ul {{ $attributes->merge(['class' => 'm-0 flex list-none flex-wrap gap-x-4 gap-y-1.5 p-0 text-[13px] text-ink-900']) }} data-keunggulan>
        @foreach ($poin as $p)
            <li class="inline-flex items-center gap-1.5"><x-tamu.ikon nama="cek" ukuran="14" class="flex-none text-status-available" />{{ $p }}</li>
        @endforeach
    </ul>
@endif

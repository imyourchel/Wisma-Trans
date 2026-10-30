{{-- Catatan tamu tanpa baris sistem ("Jumlah peserta: …", "Instansi: …"): pilihan kebutuhan sebagai lencana, sisanya teks
     dengan baris baru tetap terjaga. $catatan dari Reservation::catatanTerpilah(). --}}
@if ($catatan['kebutuhan'])
    <div class="mt-0.5 flex flex-wrap gap-1.5" data-kebutuhan-tamu>
        @foreach ($catatan['kebutuhan'] as $kebutuhan)
            <span class="rounded-full bg-cream-200 px-2.5 py-0.5 text-xs font-semibold text-brown-800">{{ $kebutuhan }}</span>
        @endforeach
    </div>
@endif
@if ($catatan['teks'] !== '')
    <div @class(['whitespace-pre-line', 'mt-1.5' => $catatan['kebutuhan']]) data-catatan-tamu>{{ $catatan['teks'] }}</div>
@endif

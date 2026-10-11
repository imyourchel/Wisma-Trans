{{--
    Pola rombongan satu instansi (ProfilPelanggan::untukInstansi) untuk detail Instansi, Pengajuan Instansi, dan
    Daftar Reservasi. $pola, $nama (nama instansi, opsional). Rekomendasi hanya bila minimal 2 rombongan.
--}}
<section class="rounded border border-cream-300 bg-cream-100 px-4 py-3 text-[13px]" data-pola-rombongan>
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <div class="wt-field-label">Pola rombongan{{ ! empty($nama) ? ' '.$nama : '' }}</div>
        <span class="text-[12px] text-ink-500">{{ $pola['rombongan'] }} kunjungan selesai</span>
    </div>
    @if ($pola['rombongan'] === 0)
        <p class="m-0 mt-1 text-ink-500">Instansi ini belum pernah menginap.</p>
    @else
        <dl class="m-0 mt-2 grid gap-x-6 gap-y-1.5 sm:grid-cols-2">
            <div><dt class="inline text-ink-500">Rata-rata:</dt> <dd class="inline m-0">{{ str_replace('.', ',', (string) $pola['rata_kamar']) }} kamar · {{ str_replace('.', ',', (string) $pola['rata_malam']) }} malam</dd></div>
            <div><dt class="inline text-ink-500">Tipe tersering:</dt> <dd class="inline m-0">{{ $pola['tipe'][0]['nama'] ?? '—' }}{{ isset($pola['tipe'][0]) ? ' ('.$pola['tipe'][0]['jumlah'].' dari '.$pola['kamar_total'].' kamar)' : '' }}</dd></div>
            <div><dt class="inline text-ink-500">Menu terbanyak:</dt> <dd class="inline m-0">{{ collect($pola['menu'])->map(fn ($m) => $m['nama'].' ('.$m['pesanan'].'×)')->join(', ') ?: '—' }}</dd></div>
            <div><dt class="inline text-ink-500">Memakai laundry:</dt> <dd class="inline m-0">{{ $pola['laundry'] }} dari {{ $pola['rombongan'] }} rombongan</dd></div>
        </dl>
        @if ($pola['rekomendasi'])
            <ul class="m-0 mt-2 flex list-none flex-col gap-1 p-0" data-rekomendasi-rombongan>
                @foreach ($pola['rekomendasi'] as $saran)
                    <li class="flex items-start gap-2"><x-staf.ikon nama="bintang" ukuran="14" class="mt-0.5 text-clay-500" />{{ $saran }}</li>
                @endforeach
            </ul>
        @elseif ($pola['rombongan'] < \App\Services\ProfilPelanggan::MIN_KUNJUNGAN)
            <p class="m-0 mt-2 text-[12px] text-ink-500">Rekomendasi muncul setelah 2 kunjungan rombongan.</p>
        @endif
    @endif
</section>

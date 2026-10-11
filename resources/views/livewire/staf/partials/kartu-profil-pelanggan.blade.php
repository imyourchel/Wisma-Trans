{{--
    Kartu profil pelanggan (CRM) untuk Reservasi Langsung, Daftar Reservasi, dan detail pelanggan.
    $profil dari ProfilPelanggan::untuk(), $label "Tamu baru" / "Kunjungan ke-N", $tautan (opsional) ke detail pelanggan.
    Rekomendasi hanya ada bila pelanggan punya minimal 2 kunjungan selesai.
--}}
<div class="rounded border border-cream-300 bg-cream-100 px-4 py-3 text-[13px]" data-kartu-profil>
    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
        <span @class(['wt-lencana', 'bg-[rgba(108,149,196,.16)] text-[#3F6D9E]' => $profil['segmen']['b2b'], 'bg-[rgba(181,113,74,.14)] text-clay-600' => ! $profil['segmen']['b2b']])
              title="{{ $profil['segmen']['rincian'] }}">{{ $profil['segmen']['label'] }}</span>
        <span class="font-semibold" data-label-kunjungan>{{ $label }}</span>
        @if ($profil['kunjungan']['jumlah'] > 0)
            <span class="text-ink-500">{{ $profil['kunjungan']['jumlah'] }} kunjungan selesai · {{ $profil['kunjungan']['malam'] }} malam</span>
        @endif
        @if (! empty($tautan))
            <a href="{{ $tautan }}" class="ml-auto text-[12px] font-semibold text-clay-600">Lihat profil</a>
        @endif
    </div>
    @if ($profil['rekomendasi'])
        <ul class="m-0 mt-2 flex list-none flex-col gap-1 p-0" data-rekomendasi>
            @foreach ($profil['rekomendasi'] as $saran)
                <li class="flex items-start gap-2"><x-staf.ikon nama="bintang" ukuran="14" class="mt-0.5 text-clay-500" />{{ $saran }}</li>
            @endforeach
        </ul>
    @endif
</div>

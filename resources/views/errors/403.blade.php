@php
    // Tautan bertanda tangan (nota, tagihan bulanan, bukti instansi) yang kedaluwarsa dibedakan dari penolakan hak akses
    $kedaluwarsa = ($exception ?? null) instanceof \Illuminate\Routing\Exceptions\InvalidSignatureException;
    $pesanAsli = (string) ($exception?->getMessage() ?? '');
    // Pesan bawaan Laravel diganti; pesan dari kode kita (misalnya "Halaman ini khusus staf Wisma Trans.") dipakai apa adanya
    $bawaan = ['', 'This action is unauthorized.', 'Forbidden', 'Invalid signature.'];
    $staf = (bool) auth()->user()?->isStaff();
    $wa = 'https://wa.me/'.$wisma['whatsapp'].'?text='.rawurlencode('Halo Wisma Trans, tautan saya sudah kedaluwarsa. Mohon dikirim tautan baru.');
@endphp
@include('errors.layout', [
    'kode' => '403',
    'polos' => true,
    'judul' => $kedaluwarsa ? 'Tautan kedaluwarsa' : 'Akses ditolak',
    'pesan' => $kedaluwarsa
        ? 'Tautan ini sudah kedaluwarsa. Minta tautan baru lewat WhatsApp.'
        : (in_array($pesanAsli, $bawaan, true) ? 'Halaman ini bukan untuk peran Anda.' : $pesanAsli),
    'tombol' => $kedaluwarsa
        ? [['label' => 'Minta lewat WhatsApp', 'url' => $wa, 'utama' => true, 'wa' => true], ['label' => 'Kembali ke beranda', 'url' => route('home')]]
        : [$staf ? ['label' => 'Kembali ke Dashboard', 'url' => route('staf.dashboard'), 'utama' => true] : ['label' => 'Kembali ke beranda', 'url' => route('home'), 'utama' => true]],
])

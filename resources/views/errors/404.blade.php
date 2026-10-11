@include('errors.layout', [
    'kode' => '404',
    'judul' => 'Halaman tidak ditemukan',
    'pesan' => 'Maaf, halaman yang Anda cari sudah dipindahkan, dihapus, atau alamatnya kurang tepat. Silakan kembali ke beranda atau hubungi kami bila butuh bantuan.',
    'tombol' => [
        ['label' => 'Kembali ke beranda', 'url' => route('home'), 'utama' => true],
        ['label' => 'Hubungi kami', 'url' => 'https://wa.me/'.$wisma['whatsapp'].'?text='.rawurlencode('Halo Wisma Trans, saya butuh bantuan.'), 'wa' => true],
    ],
])

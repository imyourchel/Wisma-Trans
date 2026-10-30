{{--
    Google One Tap: pop-up kecil "Lanjutkan sebagai ..." di pojok halaman tamu bagi pengunjung yang sedang masuk ke Google
    di perambannya. Kredensialnya diperiksa di GoogleController::oneTap dengan aturan akun yang sama seperti tombol
    "Masuk dengan Google", lalu tamu kembali ke halaman yang sedang dibuka. Tidak tampil di halaman masuk/daftar.
    Ditawarkan sekali setiap kali situs dibuka (sessionStorage wt.one-tap): pindah-pindah halaman tidak memunculkannya lagi,
    tetapi setelah tab ditutup lalu situs dibuka lagi, ditawarkan lagi. Pengguna yang sudah masuk tidak pernah melihatnya (@guest).
    Syarat: GOOGLE_CLIENT_ID terisi dan alamat situs terdaftar sebagai Authorized JavaScript origins di Google Cloud.
--}}
@if (filled(config('services.google.client_id')) && ! request()->routeIs('login', 'register', 'auth.google.lengkapi', 'password.reset'))
    <script>
        (() => {
            const KUNCI = 'wt.one-tap';
            const baca = () => { try { return sessionStorage.getItem(KUNCI); } catch { return '1'; } };
            const tulis = (v) => { try { sessionStorage.setItem(KUNCI, v); } catch { /* mode privat */ } };
            // Sudah ditawarkan pada kunjungan ini: pustaka Google tidak dimuat sama sekali
            if (baca()) return;

            window.wtOneTap = () => {
                google.accounts.id.initialize({
                    client_id: @js(config('services.google.client_id')),
                    context: 'signin',
                    auto_select: false,
                    cancel_on_tap_outside: true,
                    itp_support: true,
                    use_fedcm_for_prompt: true,
                    callback: async ({ credential }) => {
                        const gagal = (teks) => window.Alpine?.store('toasts')?.tampil(teks, '#D4514A');
                        try {
                            const res = await fetch(@js(route('auth.google.one-tap')), {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                },
                                body: JSON.stringify({ credential, kembali: location.href }),
                            });
                            const data = await res.json().catch(() => ({}));
                            if (res.ok && data.redirect) {
                                location.href = data.redirect;
                                return;
                            }
                            gagal(res.status === 429 ? 'Terlalu banyak percobaan. Tunggu sebentar, lalu coba lagi.' : (data.message || 'Masuk dengan Google gagal. Silakan coba lagi.'));
                        } catch {
                            gagal('Tidak dapat terhubung ke server. Periksa koneksi Anda, lalu coba lagi.');
                        }
                    },
                });
                // Ditandai saat ditawarkan: ditutup, diabaikan, atau dipilih sama-sama tidak muncul lagi sampai tab ditutup
                tulis(String(Date.now()));
                google.accounts.id.prompt();
            };

            const s = document.createElement('script');
            s.src = 'https://accounts.google.com/gsi/client?hl=id';
            s.async = true;
            s.defer = true;
            s.onload = () => window.wtOneTap();
            document.head.appendChild(s);
        })();
    </script>
@endif

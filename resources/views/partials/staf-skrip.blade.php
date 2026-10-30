<script>
    document.addEventListener('alpine:init', () => {
        const simpanan = {
            baca(k) { try { return localStorage.getItem(k); } catch { return null; } },
            tulis(k, v) { try { localStorage.setItem(k, v); } catch { /* mode privat */ } },
        };

        // Sidebar: pilihan ciut diingat per perangkat; 768–1279px otomatis ciut
        Alpine.data('panelStaf', () => ({
            ciut: false,
            laci: false,
            akun: false,
            tip: { tampil: false, teks: '', x: 0, y: 0 },
            melayang: null, // id grup yang panel submenunya terbuka saat sidebar diciutkan
            posMelayang: { x: 0, y: 0 },
            init() {
                this.$watch('ciut', () => { this.sembunyi(); this.melayang = null; });
                const tersimpan = simpanan.baca('wt.sidebar.ciut');
                this.ciut = tersimpan !== null ? tersimpan === '1' : window.innerWidth < 1280;
                // Animasi lebar sidebar baru dinyalakan setelah keadaan awal terpasang
                requestAnimationFrame(() => requestAnimationFrame(() => document.body.classList.add('wt-anim')));
            },
            // Label ikon hanya muncul saat sidebar diciutkan (bukan saat laci terbuka di layar kecil)
            modeCiut() { return this.ciut && !this.laci && window.innerWidth >= 768; },
            tunjuk(el) {
                if (el?.dataset.grup && this.modeCiut()) return this.bukaMelayang(el);
                if (el) this.tutupMelayang();
                if (!el || !this.modeCiut()) return this.sembunyi();
                const r = el.getBoundingClientRect();
                this.tip = { tampil: true, teks: el.dataset.tip, x: el.closest('aside').getBoundingClientRect().right + 10, y: r.top + r.height / 2 };
            },
            sembunyi() { this.tip.tampil = false; },
            // Panel submenu: sejajar dengan ikon grup, digeser naik bila melewati bawah layar
            bukaMelayang(el) {
                clearTimeout(this.pewaktuMelayang);
                this.sembunyi();
                const r = el.getBoundingClientRect();
                this.posMelayang = { x: el.closest('aside').getBoundingClientRect().right + 10, y: r.top - 8 };
                this.melayang = el.dataset.grup;
                this.$nextTick(() => {
                    const h = document.getElementById('melayang-' + this.melayang)?.offsetHeight || 0;
                    if (this.posMelayang.y + h > window.innerHeight - 12) this.posMelayang.y = Math.max(12, window.innerHeight - 12 - h);
                });
            },
            alihMelayang(el) { this.melayang === el.dataset.grup ? (this.melayang = null) : this.bukaMelayang(el); },
            tahanMelayang() { clearTimeout(this.pewaktuMelayang); },
            tutupMelayang() {
                clearTimeout(this.pewaktuMelayang);
                this.pewaktuMelayang = setTimeout(() => { this.melayang = null; }, 180);
            },
            ubahCiut() {
                this.ciut = !this.ciut;
                simpanan.tulis('wt.sidebar.ciut', this.ciut ? '1' : '0');
            },
        }));

        // Jam di header: selisih dengan jam server dihitung sekali, lalu berjalan di perangkat
        Alpine.data('jamStaf', (cfg) => ({
            selisih: cfg.ms - Date.now(),
            tanggal: '',
            jam: '',
            init() {
                const fTanggal = new Intl.DateTimeFormat('id-ID', { timeZone: cfg.zona, day: 'numeric', month: 'short', year: 'numeric' });
                const fJam = new Intl.DateTimeFormat('id-ID', { timeZone: cfg.zona, hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
                const tik = () => {
                    const d = new Date(Date.now() + this.selisih);
                    this.tanggal = fTanggal.format(d);
                    this.jam = fJam.format(d);
                };
                tik();
                this.pewaktu = setInterval(tik, 1000);
            },
            destroy() { clearInterval(this.pewaktu); },
        }));

        // Kalender kecil pemilih tanggal (Papan Status). cfg: { mulai, hariIni, panjang } dalam Y-m-d.
        // Minggu dimulai Senin; pita menandai rentang yang akan tampil, mengikuti tanggal yang disorot.
        const keYmd = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        const dariYmd = (t) => { const [y, m, d] = t.split('-').map(Number); return new Date(y, m - 1, d); };
        const fBulan = new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' });
        const fPanjang = new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
        const fPendek = new Intl.DateTimeFormat('id-ID', { weekday: 'short', day: 'numeric', month: 'short' });

        Alpine.data('pilihTanggal', (cfg) => ({
            buka: false,
            mulai: cfg.mulai,
            hariIni: cfg.hariIni,
            panjang: cfg.panjang,
            bulan: dariYmd(cfg.mulai),
            sorot: null,
            get judul() { return fBulan.format(this.bulan); },
            get acuan() { return this.sorot ?? this.mulai; },
            get labelAcuan() { return fPendek.format(dariYmd(this.acuan)); },
            get sel() {
                const y = this.bulan.getFullYear(), m = this.bulan.getMonth();
                const geserAwal = (new Date(y, m, 1).getDay() + 6) % 7;
                const baris = Math.ceil((geserAwal + new Date(y, m + 1, 0).getDate()) / 7);
                const akhirD = dariYmd(this.acuan);
                akhirD.setDate(akhirD.getDate() + this.panjang - 1);
                const akhir = keYmd(akhirD);
                return Array.from({ length: baris * 7 }, (_, i) => {
                    const d = new Date(y, m, 1 - geserAwal + i);
                    const t = keYmd(d);
                    return { t, n: d.getDate(), kolom: i % 7, luar: d.getMonth() !== m, label: fPanjang.format(d),
                        awal: t === this.acuan, akhir: t === akhir, rentang: t > this.acuan && t <= akhir, hariIni: t === this.hariIni };
                });
            },
            kelas(c) {
                if (c.awal) return 'z-10 rounded-md bg-brown-800 font-bold text-cream-50';
                const k = [c.luar ? 'text-ink-500/50' : 'text-ink-900'];
                if (c.rentang) {
                    k.push('bg-[rgba(181,113,74,.14)] hover:bg-[rgba(181,113,74,.26)]');
                    if (c.kolom === 0) k.push('rounded-l-md');
                    if (c.kolom === 6 || c.akhir) k.push('rounded-r-md');
                    if (c.akhir) k.push('font-semibold text-clay-600');
                } else {
                    k.push('rounded-md bg-transparent hover:bg-cream-200');
                }
                return k.join(' ');
            },
            bukaTutup() {
                this.buka = !this.buka;
                if (this.buka) { this.bulan = dariYmd(this.mulai); this.sorot = null; }
            },
            geser(n) { this.bulan = new Date(this.bulan.getFullYear(), this.bulan.getMonth() + n, 1); },
            pilih(t) {
                this.buka = false;
                if (t !== this.mulai) this.$wire.pickDate(t);
            },
        }));

        // Dropdown x-staf.pilih. cfg.pilihan: [{ v, l, w? }]; cfg.kirim: kirim formulir induk saat berubah
        Alpine.data('pilihan', (cfg) => ({
            pilihan: cfg.pilihan,
            nilai: cfg.nilai,
            buka: false,
            kata: '',
            sorot: 0,
            get terpilih() { return this.pilihan.find((p) => p.v === String(this.nilai)); },
            get tampil() {
                const q = this.kata.trim().toLowerCase();
                return q ? this.pilihan.filter((p) => p.l.toLowerCase().includes(q)) : this.pilihan;
            },
            bukaDaftar() {
                this.kata = '';
                this.buka = true;
                this.sorot = Math.max(0, this.tampil.findIndex((p) => p.v === String(this.nilai)));
                this.$nextTick(() => {
                    if (this.pilihan.length > 8) this.$refs.cari.focus();
                    this.$refs.daftar.children[this.sorot]?.scrollIntoView({ block: 'nearest' });
                });
            },
            tutup() { this.buka = false; },
            gerak(arah) {
                if (!this.buka) return this.bukaDaftar();
                const n = this.tampil.length;
                if (!n) return;
                this.sorot = (this.sorot + arah + n) % n;
                this.$refs.daftar.children[this.sorot]?.scrollIntoView({ block: 'nearest' });
            },
            pilih(v) {
                const berubah = v !== String(this.nilai);
                this.nilai = v;
                this.buka = false;
                this.$refs.tombol.focus();
                if (!berubah) return;
                this.$nextTick(() => {
                    this.$el.dispatchEvent(new Event('change', { bubbles: true }));
                    if (cfg.kirim) this.$el.closest('form')?.requestSubmit();
                });
            },
        }));

        // Isian tanggal x-staf.tanggal. cfg: { nilai, min, max, hariIni } dalam Y-m-d; nilai '' = kosong (penyaring).
        // Perubahan dikirim lewat isian tersembunyi (event input untuk wire:model, change untuk formulir biasa).
        const fIsian = new Intl.DateTimeFormat('id-ID', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
        const fBatas = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short' });
        Alpine.data('isianTanggal', (cfg) => ({
            nilai: cfg.nilai || '',
            hariIni: cfg.hariIni,
            buka: false,
            bulan: dariYmd(cfg.nilai || cfg.hariIni),
            get judul() { return fBulan.format(this.bulan); },
            get tampil() { return this.nilai ? fIsian.format(dariYmd(this.nilai)) : ''; },
            // Keterangan rentang yang boleh dipilih, misalnya "13 Okt – 20 Okt"
            get batas() {
                if (cfg.min && cfg.max) return fBatas.format(dariYmd(cfg.min)) + ' – ' + fBatas.format(dariYmd(cfg.max));
                return '';
            },
            tertutup(t) { return (cfg.min && t < cfg.min) || (cfg.max && t > cfg.max); },
            get sel() {
                const y = this.bulan.getFullYear(), m = this.bulan.getMonth();
                const geserAwal = (new Date(y, m, 1).getDay() + 6) % 7;
                const baris = Math.ceil((geserAwal + new Date(y, m + 1, 0).getDate()) / 7);
                return Array.from({ length: baris * 7 }, (_, i) => {
                    const d = new Date(y, m, 1 - geserAwal + i);
                    const t = keYmd(d);
                    return { t, n: d.getDate(), luar: d.getMonth() !== m, label: fPanjang.format(d), hariIni: t === this.hariIni, tutup: this.tertutup(t) };
                });
            },
            bolehGeser(n) {
                const awal = keYmd(new Date(this.bulan.getFullYear(), this.bulan.getMonth() + n, 1));
                const akhir = keYmd(new Date(this.bulan.getFullYear(), this.bulan.getMonth() + n + 1, 0));
                return !(cfg.max && awal > cfg.max) && !(cfg.min && akhir < cfg.min);
            },
            bukaPanel() {
                this.bulan = dariYmd(this.nilai || this.hariIni);
                this.buka = true;
            },
            geser(n) { if (this.bolehGeser(n)) this.bulan = new Date(this.bulan.getFullYear(), this.bulan.getMonth() + n, 1); },
            pilih(t) {
                if (t && this.tertutup(t)) return;
                this.buka = false;
                this.$refs.tombol.focus();
                if (t === this.nilai) return;
                this.nilai = t;
                const isian = this.$refs.isian;
                isian.value = t;
                isian.dispatchEvent(new Event('input', { bubbles: true }));
                isian.dispatchEvent(new Event('change', { bubbles: true }));
                if (cfg.kirim) this.$nextTick(() => this.$el.closest('form')?.requestSubmit());
            },
        }));

        // Pemilih jam x-staf.jam (24 jam). nilai: 'HH:MM'; memilih menit menutup panel. Event change dikirim ke induk.
        Alpine.data('pilihJam', (cfg) => ({
            nilai: String(cfg.nilai || '').slice(0, 5),
            buka: false,
            daftarJam: Array.from({ length: 24 }, (_, i) => String(i).padStart(2, '0')),
            daftarMenit: Array.from({ length: Math.ceil(60 / (cfg.langkah || 5)) }, (_, i) => String(i * (cfg.langkah || 5)).padStart(2, '0')),
            init() {
                // Nilai dari basis data bisa 14:00:00; yang dikirim selalu HH:MM
                const rapikan = (v) => { if (v && String(v).length > 5) this.nilai = String(v).slice(0, 5); };
                rapikan(this.nilai);
                this.$watch('nilai', rapikan);
            },
            get jam() { return this.nilai ? this.nilai.slice(0, 2) : ''; },
            get menit() { return this.nilai ? this.nilai.slice(3, 5) : ''; },
            get tampil() { return this.nilai ? this.jam + '.' + this.menit : ''; },
            bukaPanel() {
                this.buka = true;
                this.$nextTick(() => ['jam', 'menit'].forEach((r) => {
                    const el = this.$refs[r].querySelector('[data-pilih="true"]');
                    if (el) this.$refs[r].scrollTop = el.offsetTop - this.$refs[r].clientHeight / 2 + el.clientHeight / 2;
                }));
            },
            ubah(baru) {
                if (baru === this.nilai) return;
                this.nilai = baru;
                this.$nextTick(() => this.$el.dispatchEvent(new Event('change', { bubbles: true })));
            },
            pilihJamKe(j) { this.ubah(j + ':' + (this.menit || '00')); },
            pilihMenit(m) {
                this.ubah((this.jam || '00') + ':' + m);
                this.buka = false;
                this.$refs.tombol.focus();
            },
        }));

        // Dialog konfirmasi bersama. Tindakan dikirim lewat #form-aksi (PATCH/DELETE),
        // atau menjalankan opsi.aksi (misalnya method Livewire).
        Alpine.store('konfirmasi', {
            buka: false, judul: '', pesan: '', label: '', url: '', metode: 'PATCH', aksi: null,
            tanya(opsi) { Object.assign(this, { url: '', metode: 'PATCH', aksi: null }, opsi, { buka: true }); },
            lanjut() { this.buka = false; this.aksi ? this.aksi() : this.kirim(this.url, this.metode); },
            kirim(url, metode = 'PATCH') {
                const form = document.getElementById('form-aksi');
                form.action = url;
                form.querySelector('[name=_method]').value = metode;
                form.submit();
            },
        });

        /*
         * Halaman data master: pencarian, panel formulir, dan kirim lewat fetch.
         * cfg.baris: [{ nama, cari, isi, url, urlAktif, kelompok? }] sesuai urutan baris tabel.
         * kelompok dipakai penyaring dropdown (x-model="saring"); grup untuk saringan kedua (x-model="saringGrup").
         * peran: daftar id peran baris itu, untuk saringan peran pilihan ganda (x-model="saringPeran"; cocok bila punya salah satunya).
         * cfg.kosong: nilai awal formulir tambah. Galeri memakai isi.foto:
         * [{ jenis: 'lama', path, url } | { jenis: 'baru', file, url }].
         */
        const salin = (o) => JSON.parse(JSON.stringify(o));

        /*
         * Formulir biasa yang dikirim lewat fetch (dipakai halaman Maintenance). Galat 422 tampil di samping kolom
         * (x-staf.field memakai pesan()); berhasil: pindah ke jawaban.redirect atau muat ulang halaman.
         */
        // Isian awal disalin beserta getter-nya (spread biasa akan membekukan getter menjadi nilai)
        Alpine.data('formStaf', (awal = {}) => Object.defineProperties({
            galat: {},
            proses: false,
            pesan(f) { return this.galat[f] || ''; },
            hapus(f) { delete this.galat[f]; },
            async kirim(form, url = form.action) {
                if (this.proses) return;
                const gagal = (teks) => Alpine.store('toasts').tampil(teks, '#D4514A');
                this.proses = true;
                try {
                    const res = await fetch(url, {
                        method: 'POST',
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: new FormData(form),
                    });
                    if (res.ok) {
                        const j = await res.json().catch(() => ({}));
                        if (j.redirect) window.location.href = j.redirect; else window.location.reload();
                        return;
                    }
                    if (res.status === 422) {
                        const { errors } = await res.json();
                        const g = {};
                        for (const [kunci, pesan] of Object.entries(errors)) g[kunci.split('.')[0]] ??= pesan[0];
                        this.galat = g;
                        // Galat di luar kolom formulir (misalnya status laporan) tampil sebagai notifikasi
                        for (const k of ['status', 'jadwal']) if (g[k]) gagal(g[k]);
                        this.$nextTick(() => form.querySelector('[data-galat]:not([style*="display: none"])')?.scrollIntoView({ block: 'center', behavior: 'smooth' }));
                    } else if (res.status === 403) {
                        gagal('Anda tidak berhak melakukan tindakan ini.');
                    } else if (res.status === 419) {
                        gagal('Sesi Anda sudah berakhir. Muat ulang halaman, lalu coba lagi.');
                    } else if (res.status === 413) {
                        gagal('Ukuran foto terlalu besar. Kurangi jumlah atau ukuran foto.');
                    } else {
                        gagal('Terjadi kesalahan. Silakan coba lagi.');
                    }
                } catch {
                    gagal('Tidak dapat terhubung ke server. Periksa koneksi Anda, lalu coba lagi.');
                }
                this.proses = false;
            },
        }, Object.getOwnPropertyDescriptors(awal)));

        Alpine.data('dataMaster', (cfg) => ({
            baris: cfg.baris,
            cari: '',
            saring: '',
            saringGrup: '',
            saringPeran: [],
            buka: false,
            rekam: null,
            isi: salin(cfg.kosong),
            galat: {},
            proses: false,
            seret: null,

            get jumlah() { return this.baris.filter((b) => this.cocok(b)).length; },
            get sedangUbah() { return this.rekam !== null; },
            get urlForm() { return this.sedangUbah ? this.baris[this.rekam].url : cfg.urlSimpan; },

            cocok(b) {
                const q = this.cari.trim().toLowerCase();
                return (!q || b.cari.toLowerCase().includes(q)) && (!this.saring || String(b.kelompok) === this.saring)
                    && (!this.saringGrup || b.grup === this.saringGrup)
                    && (!this.saringPeran.length || this.saringPeran.some((p) => (b.peran || []).includes(p)));
            },
            pesan(f) { return this.galat[f] || ''; },
            hapus(f) { delete this.galat[f]; },

            bukaPanel(isi, rekam) {
                this.$refs.panel.querySelectorAll('input[type=file]').forEach((el) => { el.value = ''; });
                this.rekam = rekam;
                this.isi = isi;
                this.galat = {};
                this.buka = true;
                this.$nextTick(() => this.$refs.panel.querySelector('[data-fokus], input:not([type=hidden]):not([readonly]):not([type=file]), select, textarea')?.focus());
            },
            tambah() { this.bukaPanel(salin(cfg.kosong), null); },
            ubah(i) { this.bukaPanel(salin(this.baris[i].isi), i); },
            tutup() { if (!this.proses) this.buka = false; },

            nonaktifkan(i) {
                const b = this.baris[i];
                Alpine.store('konfirmasi').tanya({
                    judul: 'Nonaktifkan data',
                    pesan: b.pesanNonaktif || `"${b.nama}" tidak akan tampil untuk dipilih lagi. Riwayat transaksi tetap tersimpan.`,
                    label: 'Nonaktifkan',
                    url: b.urlAktif,
                });
            },
            aktifkan(i) { Alpine.store('konfirmasi').kirim(this.baris[i].urlAktif); },
            // b.tolakHapus: alasan data tidak boleh dihapus (server tetap memeriksa ulang)
            hapusData(i) {
                const b = this.baris[i];
                if (b.tolakHapus) {
                    Alpine.store('toasts').tampil(b.tolakHapus, '#D4514A');
                    return;
                }
                Alpine.store('konfirmasi').tanya({
                    judul: 'Hapus data',
                    pesan: b.pesanHapus || `"${b.nama}" akan dihapus permanen.`,
                    label: 'Hapus',
                    url: b.urlHapus,
                    metode: 'DELETE',
                });
            },

            // Galeri: tambah, hapus, jadikan utama, dan seret untuk mengurutkan
            tambahFoto(ev) {
                for (const file of ev.target.files) {
                    this.isi.foto.push({ jenis: 'baru', file, url: URL.createObjectURL(file), nama: file.name });
                }
                ev.target.value = '';
                this.hapus('foto');
            },
            hapusFoto(i) { this.isi.foto.splice(i, 1); },
            jadikanUtama(i) { this.isi.foto.unshift(...this.isi.foto.splice(i, 1)); },
            lepas(i) {
                if (this.seret === null || this.seret === i) return;
                const [f] = this.isi.foto.splice(this.seret, 1);
                this.isi.foto.splice(i, 0, f);
                this.seret = null;
            },

            async kirim(form) {
                if (this.proses) return;
                const data = new FormData(form);
                data.set('_method', cfg.metode || (this.sedangUbah ? 'PUT' : 'POST'));
                if (Array.isArray(this.isi.foto)) {
                    data.set('foto_diatur', '1');
                    let n = 0;
                    this.isi.foto.forEach((f) => {
                        if (f.jenis === 'baru') {
                            data.append('foto_baru[]', f.file);
                            data.append('urutan[]', 'baru:' + n++);
                        } else {
                            data.append('urutan[]', 'lama:' + f.path);
                        }
                    });
                }

                const gagal = (teks) => Alpine.store('toasts').tampil(teks, '#D4514A');
                this.proses = true;
                try {
                    const res = await fetch(this.urlForm, {
                        method: 'POST',
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: data,
                    });
                    if (res.ok) {
                        window.location.reload(); // pesan berhasil tampil sebagai notifikasi setelah dimuat
                        return;
                    }
                    if (res.status === 422) {
                        const { errors } = await res.json();
                        const g = {};
                        for (const [kunci, pesan] of Object.entries(errors)) {
                            let f = kunci.split('.')[0];
                            if (f === 'foto_baru' || f === 'urutan') f = 'foto';
                            g[f] ??= pesan[0];
                        }
                        this.galat = g;
                        this.$nextTick(() => form.querySelector('.is-invalid, [data-galat]:not([style*="display: none"])')?.scrollIntoView({ block: 'center', behavior: 'smooth' }));
                    } else if (res.status === 419) {
                        gagal('Sesi Anda sudah berakhir. Muat ulang halaman, lalu coba lagi.');
                    } else if (res.status === 413) {
                        gagal('Ukuran foto terlalu besar. Kurangi jumlah atau ukuran foto.');
                    } else {
                        gagal('Terjadi kesalahan. Silakan coba lagi.');
                    }
                } catch {
                    gagal('Tidak dapat terhubung ke server. Periksa koneksi Anda, lalu coba lagi.');
                }
                this.proses = false;
            },
        }));
    });
</script>

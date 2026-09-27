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
                const fTanggal = new Intl.DateTimeFormat('id-ID', { timeZone: cfg.zona, weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
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
         * cfg.kosong: nilai awal formulir tambah. Galeri memakai isi.foto:
         * [{ jenis: 'lama', path, url } | { jenis: 'baru', file, url }].
         */
        const salin = (o) => JSON.parse(JSON.stringify(o));

        Alpine.data('dataMaster', (cfg) => ({
            baris: cfg.baris,
            cari: '',
            saring: '',
            saringGrup: '',
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
                    && (!this.saringGrup || b.grup === this.saringGrup);
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

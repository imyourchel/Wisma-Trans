/*
 * Pemeriksaan tampilan responsif SIM Wisma Trans.
 *
 * Persiapan (sekali, atau setiap ingin data contoh terbaru):
 *   php tools/responsive/siapkan.php          salin data contoh ke database tes + daftar halaman
 *   npm install --prefix tools/responsive     pasang playwright-core (memakai Chrome/Edge yang terpasang)
 * Jalankan:
 *   node tools/responsive/periksa.mjs [--ukuran=hp-360,tablet-768] [--rute=kasir,kamar] [--tanpa-foto] [--paralel=3]
 *
 * --paralel: jumlah server PHP (port 8123, 8124, ...) yang dipakai bersamaan; server bawaan PHP hanya melayani
 * satu permintaan sekaligus.
 *
 * Setiap halaman GET tamu dan staf dibuka pada semua ukuran di bawah (staf: masuk sebagai akun contoh peran
 * pertama yang boleh membuka halaman itu; dashboard dibuka untuk tiap peran). Pemeriksaan GAGAL bila halaman
 * bisa digeser horizontal (scrollWidth > clientWidth). Target sentuh < 44px dan isian < 16px di HP/tablet
 * dicatat sebagai catatan. Screenshot: storage/app/responsive/{ukuran}/{nama-route}.png,
 * laporan lengkap: storage/app/responsive/laporan.json.
 */
import { spawn, spawnSync } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright-core';

const AKAR = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const KELUARAN = join(AKAR, 'storage', 'app', 'responsive');
const PORT_AWAL = 8123;

const UKURAN = [
    { nama: 'hp-360', width: 360, height: 800, sentuh: true },
    { nama: 'hp-390', width: 390, height: 844, sentuh: true },
    { nama: 'tablet-768', width: 768, height: 1024, sentuh: true },
    { nama: 'tablet-1024', width: 1024, height: 768, sentuh: true },
    { nama: 'laptop-1280', width: 1280, height: 800, sentuh: false },
    { nama: 'laptop-1366', width: 1366, height: 768, sentuh: false },
    { nama: 'desktop-1920', width: 1920, height: 1080, sentuh: false },
];
const PERAN_STAF = ['admin', 'manager', 'front_office', 'fnb', 'housekeeping'];

const arg = (k) => process.argv.find((a) => a.startsWith(`--${k}=`))?.split('=')[1]?.split(',').filter(Boolean);
const pilihUkuran = arg('ukuran');
const pilihRute = arg('rute');
const tanpaFoto = process.argv.includes('--tanpa-foto');
const paralel = Math.max(1, Number(arg('paralel')?.[0] ?? 3));

const { akun, halaman } = JSON.parse(readFileSync(join(KELUARAN, 'rute.json'), 'utf8'));
const ukuran = UKURAN.filter((u) => !pilihUkuran || pilihUkuran.includes(u.nama));
const daftar = halaman.filter((h) => !pilihRute || pilihRute.some((r) => h.nama.includes(r)));

/* ---------------- Server ---------------- */

async function siap(url, batas = 30000) {
    const mulai = Date.now();
    while (Date.now() - mulai < batas) {
        try {
            const r = await fetch(url);
            if (r.ok) return;
        } catch {}
        await new Promise((r) => setTimeout(r, 300));
    }
    throw new Error('Server pemeriksaan tidak menyala: ' + url);
}

// Keluaran server dibaca dan dibuang supaya tidak memenuhi pipa
const server = [];
// Sinkron, supaya server benar-benar mati sebelum proses keluar
const matikan = () => server.forEach((s) => { try { process.platform === 'win32' ? spawnSync('taskkill', ['/pid', String(s.pid), '/T', '/F']) : s.kill(); } catch {} });
process.on('exit', matikan);
for (let n = 0; n < paralel; n++) {
    const s = spawn('php', [join(AKAR, 'tools', 'responsive', 'serve.php'), String(PORT_AWAL + n)], { cwd: AKAR, stdio: ['ignore', 'pipe', 'pipe'], windowsHide: true });
    s.stdout.resume();
    s.stderr.resume();
    server.push(s);
}
await Promise.all(server.map((_, n) => siap(`http://127.0.0.1:${PORT_AWAL + n}/kontak`)));

/* ---------------- Browser ---------------- */

let browser;
for (const channel of ['chrome', 'msedge']) {
    try { browser = await chromium.launch({ channel }); break; } catch {}
}
if (!browser) throw new Error('Chrome atau Edge tidak ditemukan');

// Satu konteks per pekerja, peran, dan ukuran (cookie sesi terikat port server pekerja)
const konteks = new Map();
async function buka(basis, peran, u) {
    const kunci = `${basis}|${peran ?? 'tamu'}|${u.nama}`;
    if (konteks.has(kunci)) return konteks.get(kunci);
    const ctx = await browser.newContext({
        viewport: { width: u.width, height: u.height }, deviceScaleFactor: 1,
        isMobile: u.sentuh, hasTouch: u.sentuh, locale: 'id-ID', timezoneId: 'Asia/Makassar',
    });
    if (peran) {
        const p = await ctx.newPage();
        await p.goto(basis + '/kontak');
        const status = await p.evaluate(async (email) => {
            const token = document.querySelector('meta[name=csrf-token]').content;
            const r = await fetch('/login', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({ login: email, password: 'password' }) });
            return r.status;
        }, akun[peran]);
        await p.close();
        if (status >= 400) throw new Error(`Gagal masuk sebagai ${peran} (${status})`);
    }
    konteks.set(kunci, ctx);
    return ctx;
}

/* Diperiksa di dalam halaman */
function ukurHalaman(sentuh) {
    const de = document.documentElement;
    const cw = de.clientWidth;
    const nama = (el) => {
        const kelas = [...el.classList].slice(0, 4).join('.');
        const teks = (el.innerText || el.getAttribute('aria-label') || el.getAttribute('placeholder') || '').trim().replace(/\s+/g, ' ').slice(0, 40);
        return `${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''}${kelas ? '.' + kelas : ''}${teks ? ` "${teks}"` : ''}`;
    };
    const terlihat = (el) => {
        const s = getComputedStyle(el);
        const r = el.getBoundingClientRect();
        return s.visibility !== 'hidden' && s.display !== 'none' && r.width > 0 && r.height > 0;
    };
    const terpotongInduk = (el) => {
        for (let p = el.parentElement; p && p !== document.body && p !== de; p = p.parentElement) {
            const s = getComputedStyle(p);
            if (s.overflowX !== 'visible' || s.position === 'fixed') return true;
        }
        return false;
    };
    const semua = [...document.body.querySelectorAll('*')];
    const meluber = semua.filter((el) => {
        const r = el.getBoundingClientRect();
        // Yang menambah lebar gulir hanya yang melewati tepi kanan; elemen fixed (laci, toast) tidak ikut menggulir
        return r.right > cw + 1 && terlihat(el) && !terpotongInduk(el) && getComputedStyle(el).position !== 'fixed';
    });
    // Hanya elemen terluar yang meluber (anaknya tidak dilaporkan lagi)
    const akar = meluber.filter((el) => !meluber.includes(el.parentElement)).slice(0, 6).map((el) => {
        const r = el.getBoundingClientRect();
        return `${nama(el)} [${Math.round(r.left)}..${Math.round(r.right)}]`;
    });

    let sentuhKecil = [];
    let isianKecil = [];
    if (sentuh) {
        sentuhKecil = semua.filter((el) => el.matches('a[href], button, [role=button], summary, select, label:has(> input[type=checkbox])')
            && !el.closest('p, li > p, [data-sentuh-lewati]') && terlihat(el) && !el.closest('[aria-hidden=true]'))
            // Di luar layar (laci tertutup) tidak dihitung
            .filter((el) => { const r = el.getBoundingClientRect(); return r.right > 0 && r.left < cw && (r.width < 44 || r.height < 44); })
            .map((el) => { const r = el.getBoundingClientRect(); return `${nama(el)} ${Math.round(r.width)}×${Math.round(r.height)}`; });
        isianKecil = semua.filter((el) => el.matches('input:not([type=checkbox]):not([type=radio]):not([type=hidden]):not([type=range]), select, textarea') && terlihat(el))
            .filter((el) => parseFloat(getComputedStyle(el).fontSize) < 16).map((el) => `${nama(el)} ${getComputedStyle(el).fontSize}`);
    }
    const viewport = document.querySelector('meta[name=viewport]')?.content ?? '';
    return {
        scrollWidth: de.scrollWidth, clientWidth: cw, meluber: de.scrollWidth > cw, akar,
        phpGalat: /<b>(Notice|Warning|Fatal error|Deprecated)<\/b>/.test(document.body.innerHTML.slice(0, 4000)),
        viewport: /width=device-width/.test(viewport) && /initial-scale=1/.test(viewport),
        sentuhKecil: [...new Set(sentuhKecil)], isianKecil: [...new Set(isianKecil)],
    };
}

async function periksa(basis, h, peran, u, namaFoto) {
    const ctx = await buka(basis, peran, u);
    const page = await ctx.newPage();
    const galatJs = [];
    page.on('pageerror', (e) => galatJs.push(String(e.message).slice(0, 160)));
    let status = 0;
    try {
        const r = await page.goto(basis + h.url, { waitUntil: 'load', timeout: 60000 });
        status = r?.status() ?? 0;
        await page.waitForTimeout(500);
        const hasil = await page.evaluate(ukurHalaman, u.sentuh);
        if (!tanpaFoto) {
            mkdirSync(join(KELUARAN, u.nama), { recursive: true });
            await page.screenshot({ path: join(KELUARAN, u.nama, `${namaFoto}.png`), fullPage: true, timeout: 60000 });
        }
        return { halaman: namaFoto, url: h.url, peran, ukuran: u.nama, status, ...hasil, galatJs };
    } catch (e) {
        return { halaman: namaFoto, url: h.url, peran, ukuran: u.nama, status, galat: String(e.message).slice(0, 200), galatJs };
    } finally {
        await page.close();
    }
}

/* Peran staf pertama yang boleh membuka halaman (tanpa dialihkan dan bukan 403) */
async function peranUntuk(basis, h) {
    for (const peran of PERAN_STAF) {
        if (!akun[peran]) continue;
        const ctx = await buka(basis, peran, UKURAN[UKURAN.length - 1]);
        const r = await ctx.request.get(basis + h.url, { maxRedirects: 0 });
        if (r.status() === 200) return peran;
    }
    return null;
}

/* ---------------- Jalankan ---------------- */

const hasil = [];
const antrean = [...daftar];
async function pekerja(n) {
    const basis = `http://127.0.0.1:${PORT_AWAL + n}`;
    for (let h = antrean.shift(); h; h = antrean.shift()) {
        let tugas;
        if (h.area === 'tamu') tugas = [[null, h.nama]];
        else if (h.area === 'akun') tugas = [['customer', h.nama]];
        else if (h.nama === 'staf.dashboard') tugas = PERAN_STAF.filter((p) => akun[p]).map((p) => [p, `${h.nama}--${p}`]);
        else {
            const peran = await peranUntuk(basis, h);
            if (!peran) {
                hasil.push({ halaman: h.nama, url: h.url, galat: 'Tidak ada peran contoh yang boleh membuka halaman ini' });
                console.log(`GALAT ${h.nama}  tidak ada peran contoh yang boleh membuka halaman ini`);
                continue;
            }
            tugas = [[peran, h.nama]];
        }
        for (const [peran, namaFoto] of tugas) {
            for (const u of ukuran) {
                const x = await periksa(basis, h, peran, u, namaFoto);
                const harapan = h.status ?? 200;
                if (x.status && x.status !== harapan) x.galat = `Status ${x.status}, seharusnya ${harapan}`;
                if (x.phpGalat) x.galat = 'Pesan galat PHP tampil di halaman';
                hasil.push(x);
                const tanda = x.galat ? 'GALAT' : x.meluber ? 'GAGAL' : 'ok   ';
                console.log(`${tanda} ${u.nama.padEnd(12)} ${namaFoto}${x.meluber ? `  scroll ${x.scrollWidth}>${x.clientWidth}: ${x.akar.join(' | ')}` : ''}${x.galat ? '  ' + x.galat : ''}`);
            }
        }
    }
}
await Promise.all(server.map((_, n) => pekerja(n)));

await browser.close();
mkdirSync(KELUARAN, { recursive: true });
const urutan = new Map(daftar.map((h, i) => [h.nama, i]));
hasil.sort((a, b) => (urutan.get(a.halaman.split('--')[0]) ?? 0) - (urutan.get(b.halaman.split('--')[0]) ?? 0));
writeFileSync(join(KELUARAN, 'laporan.json'), JSON.stringify(hasil, null, 2));

const gagal = hasil.filter((x) => x.meluber || x.galat);
const tanpaViewport = hasil.filter((x) => x.viewport === false);
const sentuh = hasil.filter((x) => x.sentuhKecil?.length).length;
const isian = hasil.filter((x) => x.isianKecil?.length).length;
console.log(`\n${hasil.length} pemeriksaan · ${gagal.length} gagal · ${tanpaViewport.length} tanpa meta viewport · catatan: ${sentuh} dengan target sentuh < 44px, ${isian} dengan isian < 16px`);
console.log(`Laporan: ${join(KELUARAN, 'laporan.json')}`);
matikan();
process.exit(gagal.length || tanpaViewport.length ? 1 : 0);

<?php

namespace App\Console\Commands;

use App\Models\Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Mengisi foto menu F&B dari Wikimedia Commons (semua berkasnya berlisensi bebas: CC BY, CC BY-SA, atau domain publik).
 * Setiap menu dicari dengan kata kunci di KATA_KUNCI, gambar pertama yang cocok diunduh ukuran 800 px ke
 * storage/app/public/layanan/{kode}.jpg, lalu services.photo diisi. Sumber dan pembuat foto dicatat di
 * storage/app/public/layanan/kredit-foto-menu.csv supaya bisa dicantumkan di skripsi.
 *
 * Hasil pencarian otomatis tidak selalu tepat: periksa halaman Resto, lalu ganti foto yang keliru lewat
 * menu Layanan (unggah manual) atau jalankan ulang untuk satu kode dengan --kode=KOP13 --ganti.
 *
 *   php artisan menu:isi-foto              mengisi menu yang belum punya foto
 *   php artisan menu:isi-foto --coba       hanya menampilkan pilihan, tanpa mengunduh dan tanpa mengubah database
 *   php artisan menu:isi-foto --ganti      juga mengganti foto yang sudah ada
 *   php artisan menu:isi-foto --kode=JUS01,KOP13 --ganti
 */
class IsiFotoMenu extends Command
{
    protected $signature = 'menu:isi-foto {--kode= : kode menu dipisah koma} {--ganti : ganti foto yang sudah ada} {--coba : tanpa mengunduh}';

    protected $description = 'Isi foto menu F&B dari Wikimedia Commons (lisensi bebas) dan catat kreditnya';

    private const API = 'https://commons.wikimedia.org/w/api.php';

    private const USER_AGENT = 'SIM-Wisma-Trans/1.0 (aplikasi skripsi; pengisian foto menu demo)';

    /** Kata kunci pencarian per kode menu, dicoba berurutan. Bahasa Inggris dipakai bila hasil bahasa Indonesia sedikit. */
    private const KATA_KUNCI = [
        'REK01' => ['iced coffee glass', 'iced coffee'],
        'REK02' => ['es kopi susu', 'iced coffee milk'],
        'REK03' => ['iced matcha latte', 'matcha latte'],
        'JUS01' => ['mango juice'],
        'JUS02' => ['avocado juice', 'jus alpukat'],
        'JUS03' => ['orange juice glass', 'orange juice'],
        'JUS04' => ['dragon fruit juice', 'pitaya juice'],
        'JUS05' => ['lychee juice', 'lychee drink'],
        'JUS06' => ['pineapple juice'],
        'JUS07' => ['apple juice glass', 'apple juice'],
        'JUS08' => ['melon juice', 'cantaloupe juice'],
        'JUS09' => ['soursop juice', 'jus sirsak'],
        'JUS10' => ['passion fruit juice'],
        'TEH01' => ['cup of tea', 'black tea cup'],
        'TEH02' => ['es teh manis', 'iced tea glass'],
        'TEH03' => ['ginger tea', 'jamu'],
        'TEH04' => ['teh tarik'],
        'TEH05' => ['iced lemon tea', 'lemon tea'],
        'TEH06' => ['lychee tea', 'lychee iced tea'],
        'TEH07' => ['thai iced tea', 'thai tea'],
        'TEH08' => ['green tea cup', 'green tea'],
        'TEH09' => ['yuja tea', 'yuja-cha'],
        'KOP01' => ['kopi tubruk', 'black coffee cup'],
        'KOP02' => ['kopi susu', 'coffee with milk glass'],
        'KOP03' => ['caffe americano', 'americano coffee'],
        'KOP04' => ['cappuccino'],
        'KOP05' => ['caffe mocha', 'mochaccino'],
        'KOP06' => ['caramel macchiato', 'latte macchiato'],
        'KOP07' => ['salted caramel latte', 'caramel latte'],
        'KOP08' => ['es kopi gula aren', 'iced coffee palm sugar', 'iced latte'],
        'KOP09' => ['vanilla latte', 'latte'],
        'KOP10' => ['ginseng coffee', 'white coffee'],
        'KOP11' => ['vietnamese coffee phin', 'ca phe sua da'],
        'KOP12' => ['milo coffee', 'iced mocha'],
        'KOP13' => ['honey coffee', 'iced americano'],
        'KOP14' => ['coconut coffee', 'coconut latte'],
        'NCF01' => ['lemonade glass', 'lemonade'],
        'NCF02' => ['blue soda drink', 'italian soda'],
        'NCF03' => ['berry milkshake', 'strawberry milkshake'],
        'NCF04' => ['strawberry milk', 'strawberry latte'],
        'NCF05' => ['milo dinosaur', 'milo drink'],
        'NCF06' => ['hot chocolate', 'iced chocolate'],
        'NCF07' => ['ovaltine drink', 'malted milk drink'],
        'NCF08' => ['wedang jahe', 'ginger drink'],
        'IDM01' => ['indomie kuah', 'instant noodle soup'],
        'IDM02' => ['instant noodle soup bowl', 'indomie soup'],
        'IDM03' => ['indomie soto', 'mie soto'],
        'IDM04' => ['instant noodle soup egg', 'indomie telur'],
        'IDM05' => ['indomie goreng'],
        'IDM06' => ['indomie goreng telur', 'mi goreng instan'],
        'IDM07' => ['indomie goreng sayur', 'fried instant noodles'],
        'IDM08' => ['indomie goreng special', 'mie goreng telur'],
        'IDM09' => ['cup noodles', 'pop mie'],
        'MKN01' => ['mie goreng'],
        'MKN02' => ['bihun goreng', 'fried rice vermicelli'],
        'MKN03' => ['nasi goreng kampung', 'nasi goreng'],
        'MKN04' => ['nasi goreng spesial', 'nasi goreng telur'],
        'MKN05' => ['nasi goreng sambal', 'nasi goreng pedas'],
        'MKN06' => ['spaghetti carbonara'],
        'MKN07' => ['spaghetti bolognese'],
        'MKN08' => ['udang saus', 'fried shrimp sauce'],
        'MKN09' => ['bakso'],
        'MKN10' => ['chicken katsu rice', 'katsu don'],
        'MKN11' => ['palumara', 'ikan kuah kuning'],
        'MKN12' => ['ikan goreng', 'fried fish indonesian'],
        'PKT01' => ['bandeng goreng', 'fried milkfish'],
        'PKT02' => ['ayam goreng lalapan', 'ayam goreng'],
        'SYR01' => ['cah kangkung', 'tumis kangkung'],
        'SYR02' => ['terong goreng', 'fried eggplant'],
        'SYR03' => ['tempe goreng', 'tahu goreng'],
        'CML01' => ['french fries'],
        'CML02' => ['pisang epe', 'grilled banana'],
        'CML03' => ['ubi goreng', 'fried sweet potato'],
        'CML04' => ['pisang goreng', 'fried banana'],
        'CML05' => ['pisang goreng sambal', 'pisang goreng'],
        'CML06' => ['sukun goreng', 'fried breadfruit'],
        'CML07' => ['pisang goreng keju', 'pisang goreng'],
        'CML08' => ['singkong goreng', 'fried cassava'],
        'CML09' => ['pisang goreng', 'banana fritters'],
        'CML10' => ['roti bakar'],
        'TMB01' => ['nasi putih', 'steamed white rice bowl'],
        'TMB02' => ['telur ceplok', 'fried egg'],
        'TMB03' => ['bakso', 'meatball soup'],
        'TMB04' => ['tumis sayur', 'stir fried vegetables'],
        'SRP01' => ['indonesian breakfast', 'nasi goreng telur'],
    ];

    /** Judul berkas Commons yang sudah dipakai menu lain, supaya satu foto tidak dipakai dua kali. */
    private array $terpakai = [];

    public function handle(): int
    {
        $kode = collect(explode(',', (string) $this->option('kode')))->map(fn ($k) => strtoupper(trim($k)))->filter();
        $menu = Service::where('category', 'fnb')
            ->when($kode->isNotEmpty(), fn ($q) => $q->whereIn('code', $kode))
            ->orderBy('code')->get();

        $ganti = (bool) $this->option('ganti');
        $coba = (bool) $this->option('coba');
        $kredit = $this->bacaKredit();
        $this->terpakai = array_column($kredit, 'berkas');

        $hasil = [];
        foreach ($menu as $m) {
            if ($m->fotoUrl() && ! $ganti) {
                $hasil[] = [$m->code, $m->name, 'dilewati (sudah ada foto)', ''];

                continue;
            }

            try {
                $pilih = $this->cari($m->code, $m->name);
            } catch (Throwable $e) {
                $hasil[] = [$m->code, $m->name, 'gagal mencari: '.mb_strimwidth($e->getMessage(), 0, 60, '…'), ''];

                continue;
            }
            if (! $pilih) {
                $hasil[] = [$m->code, $m->name, 'tidak ketemu, unggah manual', ''];

                continue;
            }

            if ($coba) {
                $hasil[] = [$m->code, $m->name, $pilih['berkas'], $pilih['lisensi']];
                $this->terpakai[] = $pilih['berkas'];

                continue;
            }

            try {
                $gambar = Http::withHeaders(['User-Agent' => self::USER_AGENT])->timeout(30)->retry(2, 1000)->get($pilih['url'])->throw()->body();
            } catch (Throwable $e) {
                $hasil[] = [$m->code, $m->name, 'gagal mengunduh', ''];

                continue;
            }

            $ext = str_contains($pilih['mime'], 'png') ? 'png' : 'jpg';
            $path = "layanan/{$m->code}.{$ext}";
            if ($m->photo && $m->photo !== $path) {
                Storage::disk('public')->delete($m->photo);
            }
            Storage::disk('public')->put($path, $gambar);
            $m->update(['photo' => $path]);

            $kredit[$m->code] = ['kode' => $m->code, 'menu' => $m->name] + $pilih;
            $this->terpakai[] = $pilih['berkas'];
            $hasil[] = [$m->code, $m->name, $pilih['berkas'], $pilih['lisensi']];

            usleep(300_000); // sopan terhadap server Wikimedia
        }

        $this->table(['Kode', 'Menu', 'Foto', 'Lisensi'], $hasil);

        if (! $coba) {
            $this->tulisKredit($kredit);
            $this->info('Kredit foto: storage/app/public/layanan/kredit-foto-menu.csv');
            if (! file_exists(public_path('storage'))) {
                $this->warn('Jalankan "php artisan storage:link" supaya foto tampil di halaman Resto.');
            }
        }

        return self::SUCCESS;
    }

    /** Gambar pertama yang cocok dari kata kunci menu, atau null. */
    private function cari(string $kode, string $nama): ?array
    {
        $kunci = self::KATA_KUNCI[$kode] ?? [preg_replace('/\s*\/.*$/', '', $nama)];

        foreach ($kunci as $q) {
            $res = Http::withHeaders(['User-Agent' => self::USER_AGENT])->timeout(20)->retry(2, 1000)->get(self::API, [
                'action' => 'query',
                'format' => 'json',
                'formatversion' => 2,
                'generator' => 'search',
                'gsrsearch' => $q.' filetype:bitmap',
                'gsrnamespace' => 6,
                'gsrlimit' => 15,
                'prop' => 'imageinfo',
                'iiprop' => 'url|mime|size|extmetadata',
                'iiurlwidth' => 800,
            ])->throw()->json();

            $halaman = collect($res['query']['pages'] ?? [])->sortBy('index');
            foreach ($halaman as $p) {
                $info = $p['imageinfo'][0] ?? null;
                if (! $info || in_array($p['title'], $this->terpakai, true)) {
                    continue;
                }
                $w = (int) ($info['width'] ?? 0);
                $h = (int) ($info['height'] ?? 0);
                $rasio = $h > 0 ? $w / $h : 0;
                if (! in_array($info['mime'] ?? '', ['image/jpeg', 'image/png'], true) || $w < 500 || $h < 400 || $rasio < 0.6 || $rasio > 2.2) {
                    continue;
                }
                $meta = $info['extmetadata'] ?? [];

                return [
                    'berkas' => $p['title'],
                    'url' => $info['thumburl'] ?? $info['url'],
                    'mime' => $info['mime'],
                    'pembuat' => trim(html_entity_decode(strip_tags($meta['Artist']['value'] ?? 'tidak diketahui'))),
                    'lisensi' => $meta['LicenseShortName']['value'] ?? '',
                    'sumber' => $info['descriptionurl'] ?? '',
                    'kata_kunci' => $q,
                ];
            }
        }

        return null;
    }

    /** @return array<string, array> kunci kode menu */
    private function bacaKredit(): array
    {
        $file = 'layanan/kredit-foto-menu.csv';
        if (! Storage::disk('public')->exists($file)) {
            return [];
        }
        $isi = ltrim(Storage::disk('public')->get($file), "\xEF\xBB\xBF");
        $baris = array_map('str_getcsv', preg_split('/\r?\n/', trim($isi)));
        $kepala = array_shift($baris);

        return collect($baris)->filter(fn ($b) => count($b) === count($kepala))
            ->mapWithKeys(fn ($b) => [$b[0] => array_combine($kepala, $b)])->all();
    }

    private function tulisKredit(array $kredit): void
    {
        $kolom = ['kode', 'menu', 'berkas', 'pembuat', 'lisensi', 'sumber', 'kata_kunci'];
        $f = fopen('php://temp', 'r+');
        fputcsv($f, $kolom);
        ksort($kredit);
        foreach ($kredit as $k) {
            fputcsv($f, array_map(fn ($c) => $k[$c] ?? '', $kolom));
        }
        rewind($f);
        Storage::disk('public')->put('layanan/kredit-foto-menu.csv', "\xEF\xBB\xBF".stream_get_contents($f)); // BOM supaya Excel membaca UTF-8
        fclose($f);
    }
}

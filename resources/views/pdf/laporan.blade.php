@php
    use App\Services\Laporan\Format;
@endphp
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>{{ $d['judul'] }} {{ $p->label() }}</title>
<style>
    @page { margin: 34px 38px 46px; }
    body { font-family: Helvetica, Arial, sans-serif; font-size: 9px; color: #2E2218; line-height: 1.45; }
    table { border-collapse: collapse; width: 100%; }
    td, th { vertical-align: top; }
    .kanan { text-align: right; }
    .kepala td { border-bottom: 2px solid #4E3423; padding-bottom: 8px; }
    .judul { font-size: 16px; font-weight: bold; letter-spacing: 1.5px; text-transform: uppercase; color: #4E3423; }
    .redup { color: #7A6A58; }
    .kartu td { width: 25%; border: 1px solid #E4D9C8; padding: 7px 9px; background: #FBF7EF; }
    .kartu .label { font-size: 7px; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; color: #8C7B67; }
    .kartu .nilai { font-size: 13px; font-weight: bold; margin-top: 2px; }
    .naik { color: #3F7D4A; } .turun { color: #B23B33; }
    h2 { font-size: 9px; letter-spacing: 1.5px; text-transform: uppercase; margin: 16px 0 6px; color: #3E2A1C; }
    .tabel th { font-size: 7px; letter-spacing: .8px; text-transform: uppercase; color: #8C7B67; text-align: left; padding: 5px 6px; border-top: 1.5px solid #3E2A1C; border-bottom: 1px solid #D9CBAE; }
    .tabel td { padding: 4px 6px; border-bottom: 1px solid #EFE6D3; }
    .tabel tfoot td { font-weight: bold; background: #F6F0E6; }
    /* Grafik batang mendatar dengan tabel (dompdf tidak mendukung flexbox/grid) */
    .grafik td { padding: 1.5px 0; }
    .grafik .lbl { width: 70px; padding-right: 6px; text-align: right; color: #7A6A58; font-size: 7.5px; }
    .grafik .nil { width: 80px; padding-left: 6px; font-size: 7.5px; }
    .batang { height: 9px; }
    .kaki { position: fixed; bottom: -30px; left: 0; right: 0; font-size: 7px; color: #8C7B67; border-top: 1px solid #E4D9C8; padding-top: 4px; }
</style>
</head>
<body>
    <div class="kaki">Wisma Trans · {{ $d['judul'] }} · {{ $p->label() }} · dicetak {{ now()->translatedFormat('j F Y, H.i') }} WITA</div>

    <table class="kepala"><tr>
        <td><div class="judul">{{ $d['judul'] }}</div><div class="redup">Wisma Trans · Malili, Luwu Timur</div></td>
        <td class="kanan"><strong>{{ $p->label() }}</strong><br><span class="redup">dibanding {{ $p->sebelumnya()->label() }}</span></td>
    </tr></table>

    <table class="kartu" style="margin-top: 12px;">
        @foreach (array_chunk($d['ringkasan'], 4) as $baris)
            <tr>
                @foreach ($baris as $k)
                    @php $u = Format::perubahan($k['nilai'], $k['sebelum']); $teks = Format::teksPerubahan($k['nilai'], $k['sebelum']); @endphp
                    <td>
                        <div class="label">{{ $k['label'] }}</div>
                        <div class="nilai">{{ Format::tampil($k['nilai'], $k['format']) }}</div>
                        <div>@if ($teks)<span class="{{ $u['arah'] === 'naik' ? 'naik' : ($u['arah'] === 'turun' ? 'turun' : 'redup') }}">{{ $teks }}</span>@endif
                            @if ($k['ket'])<span class="redup"> {{ $k['ket'] }}</span>@endif</div>
                    </td>
                @endforeach
                @for ($i = count($baris); $i < 4; $i++)<td style="border: 0; background: none;"></td>@endfor
            </tr>
        @endforeach
    </table>

    @foreach ($d['grafik'] as $g)
        @php
            $totalPer = collect($g['label'])->keys()->map(fn ($i) => collect($g['seri'])->sum(fn ($s) => max(0, (float) ($s['nilai'][$i] ?? 0))));
            $maks = max((float) ($g['maks'] ?? 0), (float) $totalPer->max(), 1);
        @endphp
        <h2>{{ $g['judul'] }}</h2>
        @if (count($g['seri']) > 1)
            <div style="margin-bottom: 4px;">
                @foreach ($g['seri'] as $s)<span style="display: inline-block; width: 8px; height: 8px; background: {{ $s['warna'] }};"></span> {{ $s['nama'] }} &nbsp; @endforeach
            </div>
        @endif
        <table class="grafik">
            @foreach ($g['label'] as $i => $label)
                <tr>
                    <td class="lbl">{{ $label }}</td>
                    <td>
                        <table><tr>
                            @foreach ($g['seri'] as $s)
                                @php $lebar = round(max(0, (float) ($s['nilai'][$i] ?? 0)) / $maks * 100, 2); @endphp
                                @if ($lebar > 0)<td class="batang" style="width: {{ $lebar }}%; background: {{ $s['warna'] }};"></td>@endif
                            @endforeach
                            @if ($totalPer[$i] < $maks)<td class="batang" style="width: {{ round(100 - $totalPer[$i] / $maks * 100, 2) }}%;"></td>@endif
                        </tr></table>
                    </td>
                    <td class="nil">{{ Format::tampil($totalPer[$i], $g['format']) }}</td>
                </tr>
            @endforeach
        </table>
    @endforeach

    @foreach ($d['tabel'] as $t)
        <h2>{{ $t['judul'] }}</h2>
        <table class="tabel">
            <thead><tr>@foreach ($t['kolom'] as [$label, $format])<th @if ($format !== 'teks') class="kanan" @endif>{{ $label }}</th>@endforeach</tr></thead>
            <tbody>
                @forelse ($t['baris'] as $baris)
                    <tr>@foreach ($baris as $i => $v)<td @if (($t['kolom'][$i][1] ?? 'teks') !== 'teks') class="kanan" @endif>{{ Format::tampil($v, $t['kolom'][$i][1] ?? 'teks') }}</td>@endforeach</tr>
                @empty
                    <tr><td colspan="{{ count($t['kolom']) }}" class="redup">Belum ada data pada periode ini.</td></tr>
                @endforelse
            </tbody>
            @isset($t['total'])
                <tfoot><tr>@foreach ($t['total'] as $i => $v)<td @if (($t['kolom'][$i][1] ?? 'teks') !== 'teks') class="kanan" @endif>{{ Format::tampil($v, $t['kolom'][$i][1] ?? 'teks') }}</td>@endforeach</tr></tfoot>
            @endisset
        </table>
    @endforeach

    @foreach ($d['catatan'] as $c)
        <p class="redup" style="margin: 8px 0 0;">· {{ $c }}</p>
    @endforeach
</body>
</html>

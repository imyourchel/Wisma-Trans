<?php

namespace App\Support;

use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Rekap kas harian (pengganti rekap Excel): hanya transaksi berhasil pada tanggal itu, dijumlah per metode
 * dan per kanal; pengembalian dana mengurangi total. Refund yang masih menunggu tidak ikut.
 */
class RekapKas
{
    /**
     * @return array{tanggal: CarbonInterface, metode: array<string, array{masuk: float, keluar: float, bersih: float}>,
     *               kanal: array<string, float>, masuk: float, refund: float, bersih: float, jumlah: int}
     */
    public static function untuk(CarbonInterface $tanggal, User $user): array
    {
        $transaksi = Payment::query()->terlihatOleh($user)->where('status', 'success')
            ->whereDate('paid_at', $tanggal->toDateString())->get(['transaction_type', 'method', 'channel', 'amount']);

        $metode = $transaksi->groupBy('method')->map(fn ($g) => [
            'masuk' => (float) $g->where('transaction_type', 'payment')->sum('amount'),
            'keluar' => (float) $g->where('transaction_type', 'refund')->sum('amount'),
        ])->map(fn ($m) => [...$m, 'bersih' => $m['masuk'] - $m['keluar']])
            ->sortKeysUsing(fn ($a, $b) => array_search($a, array_keys(Payment::METHODS)) <=> array_search($b, array_keys(Payment::METHODS)))
            ->all();
        $bertanda = fn ($g) => (float) $g->sum(fn (Payment $p) => $p->jumlahBertanda());
        $masuk = (float) $transaksi->where('transaction_type', 'payment')->sum('amount');
        $refund = (float) $transaksi->where('transaction_type', 'refund')->sum('amount');

        return [
            'tanggal' => $tanggal,
            'metode' => $metode,
            'kanal' => ['online' => $bertanda($transaksi->where('channel', 'online')), 'manual' => $bertanda($transaksi->where('channel', 'manual'))],
            'masuk' => $masuk,
            'refund' => $refund,
            'bersih' => $masuk - $refund,
            'jumlah' => $transaksi->count(),
        ];
    }
}

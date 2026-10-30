<?php

/*
 * Lingkungan khusus pemeriksaan tampilan: database tes (bukan database utama), tanpa Midtrans, Fonnte,
 * Google, dan email sungguhan. Dipakai siapkan.php dan serve.php; nilai ini mendahului .env karena
 * Laravel tidak menimpa variabel lingkungan yang sudah ada.
 */

const DB_PEMERIKSAAN = 'wisma_trans_test';
const PORT_PEMERIKSAAN = 8123;

// Pengaman: pemeriksaan tidak pernah berjalan pada database yang tidak berakhiran _test
if (! str_ends_with(DB_PEMERIKSAAN, '_test')) {
    fwrite(STDERR, "Berhenti: database pemeriksaan harus berakhiran _test.\n");
    exit(1);
}

$lingkungan = [
    'APP_ENV' => 'local',
    'APP_DEBUG' => 'true',
    'APP_URL' => 'http://127.0.0.1:'.PORT_PEMERIKSAAN,
    'DB_DATABASE' => DB_PEMERIKSAAN,
    'DB_URL' => '',
    'SESSION_DRIVER' => 'cookie',
    'CACHE_STORE' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'log',
    'MIDTRANS_SERVER_KEY' => '',
    'MIDTRANS_CLIENT_KEY' => '',
    'MIDTRANS_IS_PRODUCTION' => 'false',
    'FONNTE_TOKEN' => '',
    'GOOGLE_CLIENT_ID' => '',
    'GOOGLE_CLIENT_SECRET' => '',
];

foreach ($lingkungan as $k => $v) {
    putenv("$k=$v");
    $_ENV[$k] = $v;
    $_SERVER[$k] = $v;
}

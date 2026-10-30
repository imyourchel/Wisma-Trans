<?php

/*
 * Router server PHP bawaan untuk pemeriksaan: berkas di public/ dilayani langsung, sisanya ke Laravel.
 * Sama dengan server.php Laravel tetapi tanpa menulis log permintaan ke stdout (di Windows tulisan itu
 * gagal dan peringatannya ikut tampil di halaman).
 */

// Pengaman: tolak melayani bila database bukan database tes (env.php mengisi DB_DATABASE lewat serve.php)
if (! str_ends_with((string) getenv('DB_DATABASE'), '_test')) {
    http_response_code(500);
    exit('Pemeriksaan dihentikan: DB_DATABASE harus berakhiran _test.');
}

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');
$publik = dirname(__DIR__, 2).'/public';

if ($uri !== '/' && is_file($publik.$uri)) {
    return false;
}

$_SERVER['SCRIPT_FILENAME'] = $publik.'/index.php';
require $publik.'/index.php';

<?php

/*
 * Server PHP bawaan untuk pemeriksaan tampilan, memakai lingkungan env.php (database tes).
 * Dijalankan otomatis oleh periksa.mjs; bisa juga sendiri: php tools/responsive/serve.php [port]
 */

require __DIR__.'/env.php';

chdir(dirname(__DIR__, 2).'/public');
$port = (int) ($argv[1] ?? PORT_PEMERIKSAAN);
passthru(escapeshellarg(PHP_BINARY).' -S 127.0.0.1:'.$port.' -t . '.escapeshellarg(__DIR__.'/router.php'));

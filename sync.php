<?php
$publicHtml = dirname(__DIR__); // /home/u145126467/domains/maycosantos.lel.br/public_html
$alertasDir = $publicHtml . '/alertas';
$radarDir   = $publicHtml . '/radar';
$v2Dir      = __DIR__;

function rcopy($src, $dst) {
    if (!is_dir($src)) return;
    if (!is_dir($dst)) @mkdir($dst, 0755, true);
    $dir = opendir($src);
    while (false !== ($file = readdir($dir))) {
        if (($file != '.') && ($file != '..')) {
            if (is_dir($src . '/' . $file)) {
                rcopy($src . '/' . $file, $dst . '/' . $file);
            } else {
                copy($src . '/' . $file, $dst . '/' . $file);
            }
        }
    }
    closedir($dir);
}

if (isset($_GET['token']) && $_GET['token'] === 'ms_radar_deploy_9921') {

    // 1. Garante a copia de email_templates.php, lib_lotes.php e novo index.php para alertas e radar
    copy($v2Dir . '/email_templates.php', $alertasDir . '/email_templates.php');
    if (file_exists($v2Dir . '/index.php')) {
        copy($v2Dir . '/index.php', $alertasDir . '/index.php');
    }
    if (file_exists($v2Dir . '/lib_lotes.php')) {
        copy($v2Dir . '/lib_lotes.php', $alertasDir . '/lib_lotes.php');
    }

    // 2. Copia recursiva de alertas/ para radar/
    rcopy($alertasDir, $radarDir);

    // 3. Atualiza config.php dentro de radar/
    if (file_exists($radarDir . '/config.php')) {
        $cfg = file_get_contents($radarDir . '/config.php');
        $cfg = str_replace("define('ALERTAS_URL', 'https://maycosantos.lel.br/alertas');", "define('ALERTAS_URL', 'https://radar.maycosantos.lel.br');", $cfg);
        $cfg = str_replace("define('ALERTAS_URL', 'http://maycosantos.lel.br/alertas');", "define('ALERTAS_URL', 'https://radar.maycosantos.lel.br');", $cfg);
        file_put_contents($radarDir . '/config.php', $cfg);
    }

    // 4. Cria .htaccess de redirecionamento 301 na pasta antiga /alertas/
    $htaccessAntigo = "<IfModule mod_rewrite.c>\n" .
                      "RewriteEngine On\n" .
                      "RewriteRule ^(.*)$ https://radar.maycosantos.lel.br/$1 [R=301,L]\n" .
                      "</IfModule>\n";
    file_put_contents($alertasDir . '/.htaccess', $htaccessAntigo);

    // 5. Atualiza offline.html se existir
    if (file_exists($v2Dir . '/offline.html')) {
        $offlineContent = file_get_contents($v2Dir . '/offline.html');
        file_put_contents($publicHtml . '/offline.html', $offlineContent);
        if (is_dir($publicHtml . '/colecao')) {
            file_put_contents($publicHtml . '/colecao/offline.html', $offlineContent);
        }
        if (is_dir($radarDir)) {
            file_put_contents($radarDir . '/offline.html', $offlineContent);
        }
    }

    echo "RADAR_MIGRATION_COMPLETED_SUCCESSFULLY";
    exit;
}
echo "ACCESS_DENIED";

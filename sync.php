<?php
// Script de sincronizacao segura
$origDir = dirname(__DIR__) . '/alertas';
$v2Dir = __DIR__;

if (isset($_GET['token']) && $_GET['token'] === 'ms_radar_deploy_9921') {
    if (file_exists($v2Dir . '/index.php')) {
        // copia o index atualizado para a pasta principal
        copy($v2Dir . '/index.php', $origDir . '/index.php');
        echo "OK_UPDATED";
    } else {
        echo "NO_SOURCE";
    }
    exit;
}
echo "ACCESS_DENIED";
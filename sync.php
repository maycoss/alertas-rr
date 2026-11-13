<?php
$origFile = dirname(__DIR__) . '/alertas/index.php';
$bkpFile = __DIR__ . '/backup_index.php';

if (isset($_GET['token']) && $_GET['token'] === 'ms_radar_deploy_9921') {
    if (file_exists($bkpFile)) {
        copy($bkpFile, $origFile);
        echo "RESTORED_CLEAN_OK";
    } else {
        echo "NO_BKP";
    }
    exit;
}
echo "ACCESS_DENIED";
<?php
$origFile = dirname(__DIR__) . '/alertas/index.php';

if (isset($_GET['token']) && $_GET['token'] === 'ms_radar_deploy_9921') {
    if (file_exists($origFile)) {
        $content = file_get_contents($origFile);
        
        // Remove qualquer Â restante antes de ©, º, ª, · etc
        $content = str_replace('Â©', '©', $content);
        $content = str_replace('Â·', '·', $content);
        $content = str_replace('Âº', 'º', $content);
        $content = str_replace('Âª', 'ª', $content);
        $content = str_replace('Â', '', $content);
        
        file_put_contents($origFile, $content);
        echo "CLEANED_SPECIAL_CHARS";
    }
    exit;
}
echo "ACCESS_DENIED";
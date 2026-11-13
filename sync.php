<?php
$origFile = dirname(__DIR__) . '/alertas/index.php';

if (isset($_GET['token']) && $_GET['token'] === 'ms_radar_deploy_9921') {
    if (file_exists($origFile)) {
        $content = file_get_contents($origFile);
        
        // Aspas e traco corrompidos
        $content = str_replace('â€œ', '"', $content);
        $content = str_replace('â€', '"', $content);
        $content = str_replace('âˆ’', '-', $content); // sinal de menos nas porcentagens (-50%)
        
        file_put_contents($origFile, $content);
        echo "CLEANED_QUOTES_AND_MINUS";
    }
    exit;
}
echo "ACCESS_DENIED";
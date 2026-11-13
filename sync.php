<?php
$origFile = dirname(__DIR__) . '/alertas/index.php';
$v2File = __DIR__ . '/index.php';

if (isset($_GET['token']) && $_GET['token'] === 'ms_radar_deploy_9921') {
    if (file_exists($origFile)) {
        $content = file_get_contents($origFile);
        
        // 1. Title
        $content = preg_replace('/<title>.*?<\/title>/i', '<title>Alertas RR · Radar de Oportunidades & Inteligência</title>', $content, 1);
        
        // 2. H1
        $content = preg_replace('/<h1>Roraima <span class="grad-texto">em leilão\.<\/span><\/h1>/i', '<h1>Radar RR <span class="grad-texto">Inteligência & Oportunidades.</span></h1>', $content, 1);
        
        // Salva com lock
        file_put_contents($origFile, $content, LOCK_EX);
        echo "REPLACED_OK";
    } else {
        echo "ORIG_NOT_FOUND";
    }
    exit;
}
echo "ACCESS_DENIED";
<?php
$origFile = dirname(__DIR__) . '/alertas/index.php';

if (isset($_GET['token']) && $_GET['token'] === 'ms_radar_deploy_9921') {
    if (file_exists($origFile)) {
        $content = file_get_contents($origFile);
        
        // Remove BOM se houver
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
        }
        
        // Garante que o header UTF-8 eh a primeira coisa enviada pelo PHP
        if (strpos($content, "header('Content-Type:") === false) {
            $content = preg_replace('/<\?php\s*/i', "<?php\nheader('Content-Type: text/html; charset=UTF-8');\n", $content, 1);
        }
        
        // Converte caracteres corrompidos conhecidos
        // Se o arquivo contem caracteres em dupla codificacao UTF-8 (ex: Ã£ -> ã)
        if (strpos($content, 'Ã£') !== false || strpos($content, 'Ã³') !== false || strpos($content, 'Ã©') !== false) {
            $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
            // se continuar com mojibake UTF-8 duplo, decodifica de ISO-8859-1 para UTF-8:
            $fixed = @iconv('UTF-8', 'ISO-8859-1//IGNORE', $content);
            if ($fixed && mb_detect_encoding($fixed, 'UTF-8', true)) {
                $content = $fixed;
            }
        }
        
        // Tira o "Roraima em leilão"
        $content = preg_replace('/<title>.*?<\/title>/i', '<title>Alertas RR · Radar de Oportunidades & Inteligência</title>', $content, 1);
        $content = preg_replace('/<h1>Roraima\s*<span class="grad-texto">.*?<\/span><\/h1>/iu', '<h1>Radar RR <span class="grad-texto">Inteligência & Oportunidades.</span></h1>', $content, 1);
        
        file_put_contents($origFile, $content);
        echo "FIX_ENCODING_DONE";
    }
    exit;
}
echo "ACCESS_DENIED";
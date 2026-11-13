<?php
$origFile = dirname(__DIR__) . '/alertas/index.php';

if (isset($_GET['token']) && $_GET['token'] === 'ms_radar_deploy_9921') {
    if (file_exists($origFile)) {
        $content = file_get_contents($origFile);
        
        // Dicionario completo de correcao de mojibake (duplo UTF-8)
        $map = [
            'Ã£' => 'ã', 'Ã¡' => 'á', 'Ã ' => 'à', 'Ã¢' => 'â',
            'Ã©' => 'é', 'Ãª' => 'ê',
            'Ã­' => 'í',
            'Ã³' => 'ó', 'Ã´' => 'ô', 'Ãµ' => 'õ',
            'Ãº' => 'ú', 'Ã¼' => 'ü',
            'Ã§' => 'ç',
            'Ãƒ' => 'Ã', 'Ã' => 'Á', 'Ã€' => 'À', 'Ã‚' => 'Â',
            'Ã‰' => 'É', 'ÃŠ' => 'Ê',
            'Ã' => 'Í',
            'Ã“' => 'Ó', 'Ã”' => 'Ô', 'Ã•' => 'Õ',
            'Ãš' => 'Ú',
            'Ã‡' => 'Ç',
            'Â·' => '·', 'â€”' => '—', 'â†—' => '↗', 'âœ•' => '✕',
            'Âº' => 'º', 'Âª' => 'ª', 'â€º' => '›', 'â—' => '●'
        ];
        
        // Remove mojibake de 2º nivel se houver
        $content = str_replace('ÃƒÂ£', 'ã', $content);
        $content = strtr($content, $map);
        
        // Remove "Roraima em leilão"
        $content = str_replace('Roraima em leilão', 'Radar de Oportunidades RR', $content);
        $content = str_replace('<h1>Roraima <span class="grad-texto">em leilão.</span></h1>', '<h1>Radar RR <span class="grad-texto">Oportunidades em Tempo Real.</span></h1>', $content);
        
        file_put_contents($origFile, $content);
        echo "MOJIBAKE_FIXED_PERFECTLY";
    }
    exit;
}
echo "ACCESS_DENIED";
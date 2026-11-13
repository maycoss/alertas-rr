<?php
$origFile = dirname(__DIR__) . '/alertas/index.php';

if (isset($_GET['token']) && $_GET['token'] === 'ms_radar_deploy_9921') {
    if (file_exists($origFile)) {
        $content = file_get_contents($origFile);
        
        // 1. Title
        $content = str_replace('<title>Alertas RR · Roraima em leilão</title>', '<title>Alertas RR · Radar de Oportunidades</title>', $content);
        
        // 2. H1
        $content = str_replace('<h1>Roraima <span class="grad-texto">em leilão.</span></h1>', '<h1>Radar RR <span class="grad-texto">em tempo real.</span></h1>', $content);
        
        // 3. Melhoria cirurgica do visual
        $cssMod = '
/* --- UPGRADE VISUAL CIRURGICO --- */
:root {
  --vazio: #08090d !important;
  --materia: #12151c !important;
  --laranja: #ff6a00 !important;
  --ouro: #ffb800 !important;
  --grad: linear-gradient(135deg, #e65100, #ff8f00) !important;
  --grad-ouro: linear-gradient(135deg, #ff8f00, #ffca28) !important;
}
.topo {
  backdrop-filter: blur(20px) !important;
  -webkit-backdrop-filter: blur(20px) !important;
  background: rgba(8, 9, 13, 0.85) !important;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08) !important;
}
.bloco {
  backdrop-filter: blur(12px) !important;
  -webkit-backdrop-filter: blur(12px) !important;
  border: 1px solid rgba(255, 255, 255, 0.08) !important;
  transition: transform 0.25s ease, border-color 0.25s ease, box-shadow 0.25s ease !important;
}
.bloco:hover {
  transform: translateY(-4px) !important;
  border-color: rgba(255, 106, 0, 0.45) !important;
  box-shadow: 0 16px 36px -10px rgba(0, 0, 0, 0.7), 0 0 24px -6px rgba(255, 106, 0, 0.25) !important;
}
.capa .cracha {
  background: rgba(255, 106, 0, 0.1) !important;
  border: 1px solid rgba(255, 106, 0, 0.3) !important;
  box-shadow: 0 0 15px rgba(255, 106, 0, 0.15) !important;
}
.btn {
  letter-spacing: 0.05em !important;
  box-shadow: 0 4px 18px rgba(230, 81, 0, 0.35) !important;
}
.btn:hover {
  box-shadow: 0 6px 24px rgba(255, 106, 0, 0.5) !important;
}
';
        if (strpos($content, '/* --- UPGRADE VISUAL CIRURGICO --- */') === false) {
            $content = str_replace('</style>', $cssMod . '</style>', $content);
        }
        
        file_put_contents($origFile, $content);
        echo "CLEAN_SURGICAL_APPLIED";
    }
    exit;
}
echo "ACCESS_DENIED";
<?php
$alertasDir = dirname(__DIR__) . '/alertas';
$v2Dir = __DIR__;

if (isset($_GET['token']) && $_GET['token'] === 'ms_radar_deploy_9921') {
    // 1. Instala email_templates.php na pasta alertas
    copy($v2Dir . '/email_templates.php', $alertasDir . '/email_templates.php');

    // 2. Atualiza alNotificarLotes em lib_lotes.php para enviar HTML + Fallback Plain Text
    $lotesPhp = file_get_contents($alertasDir . '/lib_lotes.php');
    if (strpos($lotesPhp, "require_once __DIR__ . '/email_templates.php';") === false) {
        $lotesPhp = "<?php\nrequire_once __DIR__ . '/email_templates.php';\n" . substr($lotesPhp, 5);
    }

    $novaNotificarLotes = 'function alNotificarLotes(array $lotes) {
    [$titulo, $plainBody] = alEmailLotes($lotes);
    $subject = "=?UTF-8?B?" . base64_encode($titulo) . "?=";
    
    // Gera HTML estilizado
    $htmlBody = alEmailCabecalhoHTML("OPORTUNIDADES IDENTIFICADAS EM RORAIMA");
    foreach ($lotes as $l) {
        $htmlBody .= alFormataCardLoteHTML($l);
    }
    $htmlBody .= alEmailRodapeHTML();

    $boundary = "==_BOUNDARY_" . md5(uniqid((string)time(), true));
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "From: Radar RR <" . ALERTAS_EMAIL_FROM . ">\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";

    $message  = "--{$boundary}\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= $plainBody . "\r\n\r\n";

    $message .= "--{$boundary}\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= $htmlBody . "\r\n\r\n";
    $message .= "--{$boundary}--\r\n";

    @mail(ALERTAS_EMAIL_DESTINO, $subject, $message, $headers);
}';

    // Substitui a funcao antiga
    $lotesPhp = preg_replace('/function alNotificarLotes\(array \$lotes\)\s*\{.*?\n\}/s', $novaNotificarLotes, $lotesPhp);
    file_put_contents($alertasDir . '/lib_lotes.php', $lotesPhp);

    // 3. Atualiza alNotificar em lib.php
    $libPhp = file_get_contents($alertasDir . '/lib.php');
    if (strpos($libPhp, "require_once __DIR__ . '/email_templates.php';") === false) {
        $libPhp = "<?php\nrequire_once __DIR__ . '/email_templates.php';\n" . substr($libPhp, 5);
    }

    $novaNotificarEditais = 'function alNotificar($itens, $novos, $rev) {
    $tem_leilao = false;
    foreach ($itens as $i) { if (strpos((string)($i[\'tipo\'] ?? \'\'), \'Leilao eletronico da Receita\') === 0) { $tem_leilao = true; break; } }
    $titulo  = $tem_leilao ? ">> {$novos} NOVIDADE(S) EM RR [RECEITA FEDERAL]" : ">> {$novos} NOVO(S) EDITAL(IS) DE LEILOEIRO EM RR";
    $subject = "=?UTF-8?B?" . base64_encode($titulo . ($rev ? " [+{$rev} P/ REVISAO]" : "")) . "?=";

    $plainBody  = "RADAR RR :: MONITOR DE EDITAIS E LEILÕES\r\n" . str_repeat(\'=\', 50) . "\r\n\r\n";
    $htmlCards = "";
    foreach ($itens as $i) {
        $plainBody .= "[+] " . ($i[\'orgao\'] ?? \'?\');
        if (!empty($i[\'numero\'])) $plainBody .= " -- Edital {$i[\'numero\']}";
        if (!empty($i[\'tipo\']))   $plainBody .= " ({$i[\'tipo\']})";
        $plainBody .= "\r\n";
        if (!empty($i[\'prazo_inscricao\'])) $plainBody .= "    prazo: {$i[\'prazo_inscricao\']}\r\n";
        if (!empty($i[\'link\']))            $plainBody .= "    link.: {$i[\'link\']}\r\n\r\n";

        $htmlCards .= alFormataCardEditalHTML($i);
    }
    $plainBody .= str_repeat(\'-\', 50) . "\r\ndetalhes: " . ALERTAS_URL . "\r\n";

    $htmlBody = alEmailCabecalhoHTML("CHAMAMENTOS E EDITAIS PÚBLICOS");
    $htmlBody .= $htmlCards;
    $htmlBody .= alEmailRodapeHTML();

    $boundary = "==_BOUNDARY_" . md5(uniqid((string)time(), true));
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "From: Radar RR <" . ALERTAS_EMAIL_FROM . ">\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";

    $message  = "--{$boundary}\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= $plainBody . "\r\n\r\n";

    $message .= "--{$boundary}\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= $htmlBody . "\r\n\r\n";
    $message .= "--{$boundary}--\r\n";

    @mail(ALERTAS_EMAIL_DESTINO, $subject, $message, $headers);
}';

    $libPhp = preg_replace('/function alNotificar\(\$itens, \$novos, \$rev\)\s*\{.*?\n\}/s', $novaNotificarEditais, $libPhp);
    file_put_contents($alertasDir . '/lib.php', $libPhp);

    echo "EMAILS_ENGINE_UPGRADED";
    exit;
}
echo "ACCESS_DENIED";
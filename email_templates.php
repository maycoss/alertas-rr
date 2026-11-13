<?php
/**
 * Motor de Templates de E-mail — Radar RR / Alertas RR
 * Lê dinamicamente os tokens de cores do CSS do index.php do site.
 * Quando o CSS do site for alterado, as cores dos e-mails acompanham automaticamente!
 */

function alObterCoresDoSite() {
    static $cores = null;
    if ($cores !== null) return $cores;

    // Valores padrão espelhados do site
    $cores = [
        'vazio'      => '#07090E', // Fundo principal
        'materia'    => '#0E131F', // Superfície do card
        'materia_in' => '#141B2D', // Superfície interna
        'materia_top'=> '#1A233A', // Cabeçalho do card
        'limite'     => '#1E293B', // Bordas e divisores
        'luz'        => '#FFFFFF', // Texto principal
        'poeira'     => '#94A3B8', // Texto secundário
        'laranja'    => '#FF6A00', // Destaque primário (botões e links)
        'ouro'       => '#F59E0B', // Destaque valor / desconto
        'ouro_texto' => '#FBBF24',
        'verde'      => '#22C55E', // Lances / sucesso
        'judicial'   => '#E11D48', // Tag Tribunais
        'receita'    => '#3B82F6', // Tag Receita
    ];

    // Tenta extrair variáveis do index.php em tempo de execução
    $indexPath = __DIR__ . '/index.php';
    if (file_exists($indexPath)) {
        $src = file_get_contents($indexPath);
        if (preg_match('/--vazio:\s*([^;]+);/i', $src, $m))   $cores['vazio'] = trim(str_replace('!important', '', $m[1]));
        if (preg_match('/--materia:\s*([^;]+);/i', $src, $m)) $cores['materia'] = trim(str_replace('!important', '', $m[1]));
        if (preg_match('/--laranja:\s*([^;]+);/i', $src, $m)) $cores['laranja'] = trim(str_replace('!important', '', $m[1]));
        if (preg_match('/--ouro:\s*([^;]+);/i', $src, $m))    $cores['ouro'] = trim(str_replace('!important', '', $m[1]));
    }

    return $cores;
}

function alEmailCabecalhoHTML($subtitulo = 'RADAR DE OPORTUNIDADES EM TEMPO REAL') {
    $c   = alObterCoresDoSite();
    $url = defined('ALERTAS_URL') ? ALERTAS_URL : 'https://maycosantos.lel.br/alertas';

    return '<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Radar RR</title>
</head>
<body style="margin:0;padding:0;background-color:' . $c['vazio'] . ';font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:' . $c['luz'] . ';-webkit-font-smoothing:antialiased;">
<table width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color:' . $c['vazio'] . ';table-layout:fixed;">
  <tr>
    <td align="center" style="padding:24px 12px;">
      <table width="100%" max-width="620" border="0" cellspacing="0" cellpadding="0" style="max-width:620px;background-color:' . $c['materia'] . ';border:1px solid ' . $c['limite'] . ';border-radius:16px;overflow:hidden;box-shadow:0 20px 40px rgba(0,0,0,0.6);">
        <!-- TOPO / BRANDING -->
        <tr>
          <td style="padding:24px 28px;background:linear-gradient(180deg,#141B2D 0%,' . $c['materia'] . ' 100%);border-bottom:1px solid ' . $c['limite'] . ';">
            <table width="100%" border="0" cellspacing="0" cellpadding="0">
              <tr>
                <td>
                  <span style="display:inline-block;padding:4px 10px;background:rgba(255,106,0,0.12);border:1px solid ' . $c['laranja'] . ';border-radius:20px;font-size:10px;font-weight:700;letter-spacing:0.12em;color:' . $c['laranja'] . ';text-transform:uppercase;">
                    RADAR ATIVO · RORAIMA
                  </span>
                  <h1 style="margin:10px 0 0 0;font-size:22px;font-weight:800;color:' . $c['luz'] . ';letter-spacing:-0.02em;">
                    Radar <span style="color:' . $c['laranja'] . ';">RR</span> <span style="font-size:14px;font-weight:400;color:' . $c['poeira'] . ';">| Oportunidades</span>
                  </h1>
                  <p style="margin:4px 0 0 0;font-size:12px;color:' . $c['poeira'] . ';letter-spacing:0.04em;text-transform:uppercase;">
                    ' . htmlspecialchars($subtitulo) . '
                  </p>
                </td>
                <td align="right" valign="top">
                  <a href="' . htmlspecialchars($url) . '" style="display:inline-block;padding:8px 14px;background:' . $c['laranja'] . ';color:#FFFFFF;text-decoration:none;font-size:11px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;border-radius:8px;">
                    Ver no Mapa ↗
                  </a>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        <!-- CONTEUDO -->
        <tr>
          <td style="padding:24px 28px;">';
}

function alEmailRodapeHTML() {
    $c   = alObterCoresDoSite();
    $url = defined('ALERTAS_URL') ? ALERTAS_URL : 'https://maycosantos.lel.br/alertas';
    return '
          </td>
        </tr>
        <!-- RODAPE -->
        <tr>
          <td style="padding:20px 28px;background-color:#080B12;border-top:1px solid ' . $c['limite'] . ';font-size:11px;color:#64748B;line-height:1.6;">
            <table width="100%" border="0" cellspacing="0" cellpadding="0">
              <tr>
                <td>
                  <strong style="color:' . $c['poeira'] . ';">Radar RR · Monitor Inteligente de Leilões e Editais</strong><br>
                  TJRR, TRF1, TRT11, Caixa Econômica, Receita Federal e Leiloeiros Oficiais de Roraima.<br>
                  <a href="' . htmlspecialchars($url) . '" style="color:' . $c['laranja'] . ';text-decoration:none;">Acessar painel completo</a> · <a href="' . htmlspecialchars($url) . '/termos" style="color:#64748B;text-decoration:none;">Termos de uso</a>
                </td>
              </tr>
            </table>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>';
}

function alFormataCardLoteHTML(array $l) {
    $c    = alObterCoresDoSite();
    $x    = $l['x'] ?? [];
    $cat  = mb_strtoupper(alLoteCatNome($l['categoria']), 'UTF-8');
    $titulo = htmlspecialchars($l['titulo']);
    $onde = trim(($l['bairro'] ? $l['bairro'] . ', ' : '') . ($l['municipio'] ?: 'Roraima'));
    
    // Identificação de Tipo de Leilão com cores sincronizadas
    $tipoLeilao = 'LEILÃO PÚBLICO';
    $tipoCor = '#06B6D4'; // Ciano default
    $comitente = $l['comitente'] ?? '';
    
    if (stripos($comitente, 'TJRR') !== false || stripos($comitente, 'Tribunal de Justiça') !== false) {
        $tipoLeilao = 'JUDICIAL · TJRR';
        $tipoCor = $c['judicial'];
    } elseif (stripos($comitente, 'TRF') !== false || stripos($comitente, 'Federal') !== false) {
        $tipoLeilao = 'JUDICIAL · JUSTIÇA FEDERAL';
        $tipoCor = $c['judicial'];
    } elseif (stripos($comitente, 'TRT') !== false || stripos($comitente, 'Trabalho') !== false) {
        $tipoLeilao = 'JUDICIAL · TRT11';
        $tipoCor = $c['judicial'];
    } elseif (stripos($comitente, 'DETRAN') !== false || stripos($l['titulo'], 'DETRAN') !== false) {
        $tipoLeilao = 'VEÍCULOS / DETRAN-RR';
        $tipoCor = $c['ouro'];
    } elseif (stripos($comitente, 'Caixa') !== false || stripos($l['fonte'], 'spy') !== false) {
        $tipoLeilao = 'EXTRAJUDICIAL · BANCOS / CAIXA';
        $tipoCor = $c['verde'];
    } elseif (stripos($comitente, 'Receita') !== false || stripos($l['fonte'], 'receita') !== false) {
        $tipoLeilao = 'RECEITA FEDERAL';
        $tipoCor = $c['receita'];
    }

    // Cálculo de Desconto
    $desc = 0;
    if ($l['valor_avaliacao'] > 0 && $l['valor_lance'] > 0 && $l['valor_lance'] < $l['valor_avaliacao']) {
        $desc = (int)round((1 - $l['valor_lance'] / $l['valor_avaliacao']) * 100);
    }

    $condicoes = [];
    if (!empty($x['fgts']) && stripos($x['fgts'], 'aceita') === 0) $condicoes[] = 'Aceita FGTS';
    if (!empty($x['financiamento']) && stripos($x['financiamento'], 'aceita') === 0) $condicoes[] = 'Aceita Financiamento';
    if (!empty($x['quartos'])) $condicoes[] = $x['quartos'] . ' quarto(s)';
    if (!empty($x['area'])) $condicoes[] = $x['area'];

    $dataLeilao = '';
    if (!empty($l['data_leilao'])) {
        $dataLeilao = date('d/m/Y \à\s H:i', strtotime($l['data_leilao']));
    }

    $card = '<table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin-bottom:20px;background:' . $c['materia_in'] . ';border:1px solid ' . $c['limite'] . ';border-radius:12px;overflow:hidden;">';
    
    // Cabeçalho do Card
    $card .= '<tr><td style="padding:14px 18px;background:' . $c['materia_top'] . ';border-bottom:1px solid ' . $c['limite'] . ';">
      <table width="100%" border="0" cellspacing="0" cellpadding="0">
        <tr>
          <td>
            <span style="display:inline-block;padding:3px 8px;background:rgba(255,255,255,0.06);border:1px solid ' . $tipoCor . ';border-radius:4px;font-size:10px;font-weight:700;color:' . $tipoCor . ';letter-spacing:0.06em;">
              ' . $tipoLeilao . '
            </span>
            <span style="display:inline-block;margin-left:6px;font-size:11px;font-weight:600;color:' . $c['poeira'] . ';">
              ' . $cat . '
            </span>';
            if (!empty($l['termo'])) {
                $card .= ' <span style="display:inline-block;margin-left:4px;padding:2px 6px;background:rgba(255,106,0,0.15);color:' . $c['laranja'] . ';border-radius:4px;font-size:10px;font-weight:700;">MIRA: ' . htmlspecialchars($l['termo']) . '</span>';
            }
    $card .= '</td>';
    if ($desc > 0) {
        $card .= '<td align="right">
          <span style="display:inline-block;padding:3px 8px;background:rgba(245,158,11,0.18);border:1px solid ' . $c['ouro'] . ';border-radius:4px;font-size:11px;font-weight:800;color:' . $c['ouro_texto'] . ';">
            -' . $desc . '% DESCONTO
          </span>
        </td>';
    }
    $card .= '</tr></table></td></tr>';

    // Corpo do Card
    $card .= '<tr><td style="padding:18px;">';
    $card .= '<h3 style="margin:0 0 10px 0;font-size:15px;line-height:1.4;font-weight:700;color:' . $c['luz'] . ';">' . $titulo . '</h3>';
    
    $card .= '<p style="margin:0 0 12px 0;font-size:12px;color:' . $c['poeira'] . ';">
      <strong style="color:#CBD5E1;">Localização:</strong> ' . htmlspecialchars($onde) . '
    </p>';

    // Valores
    $card .= '<table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin:12px 0;padding:10px 14px;background:#0A0E17;border-radius:8px;border:1px solid ' . $c['limite'] . ';"><tr>';
    if ($l['valor_avaliacao'] > 0) {
        $card .= '<td>
          <span style="display:block;font-size:10px;color:#64748B;text-transform:uppercase;letter-spacing:0.04em;">Avaliação</span>
          <span style="font-size:13px;font-weight:600;color:' . $c['poeira'] . ';">' . alBRL($l['valor_avaliacao']) . '</span>
        </td>';
    }
    if ($l['valor_lance'] > 0) {
        $rotuloLance = htmlspecialchars($l['valor_rotulo'] ?: 'Lance inicial');
        $card .= '<td align="right">
          <span style="display:block;font-size:10px;color:' . $c['laranja'] . ';text-transform:uppercase;letter-spacing:0.04em;font-weight:700;">' . $rotuloLance . '</span>
          <span style="font-size:16px;font-weight:800;color:' . $c['verde'] . ';">' . alBRL($l['valor_lance']) . '</span>
        </td>';
    }
    $card .= '</tr></table>';

    if ($condicoes) {
        $card .= '<div style="margin-bottom:12px;">';
        foreach ($condicoes as $cond) {
            $card .= '<span style="display:inline-block;margin-right:6px;margin-bottom:4px;padding:3px 8px;background:rgba(255,255,255,0.05);border-radius:4px;font-size:11px;color:#CBD5E1;">' . htmlspecialchars($cond) . '</span>';
        }
        $card .= '</div>';
    }

    // Rodapé do card
    $card .= '<table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin-top:14px;padding-top:12px;border-top:1px solid ' . $c['limite'] . ';"><tr>';
    $card .= '<td style="font-size:11px;color:#64748B;">';
    if ($dataLeilao) {
        $card .= '<strong style="color:' . $c['ouro'] . ';">Encerra:</strong> ' . $dataLeilao . '<br>';
    }
    $quem = trim((alLotesFontes()[$l['fonte']] ?? $l['fonte']) . ($l['comitente'] ? ' · ' . $l['comitente'] : ''));
    $card .= '<span>Fonte: ' . htmlspecialchars($quem) . '</span>';
    $card .= '</td>';
    $card .= '<td align="right">';
    $card .= '<a href="' . htmlspecialchars($l['link']) . '" style="display:inline-block;padding:8px 14px;background:' . $c['laranja'] . ';color:#FFFFFF;text-decoration:none;font-size:11px;font-weight:700;border-radius:6px;">Acessar Lote ↗</a>';
    $card .= '</td></tr></table>';

    $card .= '</td></tr></table>';
    return $card;
}

function alFormataCardEditalHTML(array $ed) {
    $c      = alObterCoresDoSite();
    $orgao  = htmlspecialchars($ed['orgao'] ?? 'Órgão Público');
    $tipo   = htmlspecialchars($ed['tipo'] ?? 'Edital de Credenciamento');
    $numero = !empty($ed['numero']) ? ' nº ' . htmlspecialchars($ed['numero']) : '';
    $prazo  = !empty($ed['prazo_inscricao']) ? htmlspecialchars($ed['prazo_inscricao']) : 'Consulte o edital';
    $resumo = !empty($ed['resumo']) ? htmlspecialchars($ed['resumo']) : '';
    $link   = !empty($ed['link']) ? $ed['link'] : (!empty($ed['link_pdf']) ? $ed['link_pdf'] : '');

    $card = '<table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin-bottom:20px;background:' . $c['materia_in'] . ';border:1px solid ' . $c['limite'] . ';border-radius:12px;overflow:hidden;">';
    $card .= '<tr><td style="padding:14px 18px;background:' . $c['materia_top'] . ';border-bottom:1px solid ' . $c['limite'] . ';">
      <span style="display:inline-block;padding:3px 8px;background:rgba(245,158,11,0.15);border:1px solid ' . $c['ouro'] . ';border-radius:4px;font-size:10px;font-weight:700;color:' . $c['ouro_texto'] . ';letter-spacing:0.06em;">
        EDITAL PÚBLICO · CREDENCIAMENTO DE LEILOEIRO
      </span>
    </td></tr>';
    
    $card .= '<tr><td style="padding:18px;">';
    $card .= '<h3 style="margin:0 0 6px 0;font-size:16px;font-weight:700;color:' . $c['luz'] . ';">' . $orgao . $numero . '</h3>';
    $card .= '<p style="margin:0 0 12px 0;font-size:12px;color:' . $c['poeira'] . ';">' . $tipo . '</p>';
    
    if ($resumo) {
        $card .= '<p style="margin:0 0 12px 0;padding:10px 12px;background:#0A0E17;border-left:3px solid ' . $c['laranja'] . ';font-size:12px;color:#CBD5E1;line-height:1.5;">' . $resumo . '</p>';
    }

    $card .= '<table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin-top:14px;padding-top:12px;border-top:1px solid ' . $c['limite'] . ';"><tr>';
    $card .= '<td style="font-size:11px;color:' . $c['poeira'] . ';">';
    $card .= '<strong style="color:' . $c['ouro'] . ';">Prazo fatal:</strong> ' . $prazo;
    $card .= '</td>';
    if ($link) {
        $card .= '<td align="right">';
        $card .= '<a href="' . htmlspecialchars($link) . '" style="display:inline-block;padding:8px 14px;background:' . $c['laranja'] . ';color:#FFFFFF;text-decoration:none;font-size:11px;font-weight:700;border-radius:6px;">Baixar Edital ↗</a>';
        $card .= '</td>';
    }
    $card .= '</tr></table>';

    $card .= '</td></tr></table>';
    return $card;
}

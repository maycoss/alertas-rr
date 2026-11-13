<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mapa_rr.php';

$is_admin = alAdmin();   // abre a sessao
$msg = ''; $erro = '';

// ============ ACOES (POST -> grava -> volta por GET, sem reenvio no F5) ============
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao   = $_POST['acao'] ?? '';
    $ancora = '';
    $id     = (int)($_POST['id'] ?? 0);

    if ($acao === 'login') {
        if (alLogin($_POST['senha'] ?? '')) { $msg = 'Bem-vindo de volta Ã  sala do operador.'; }
        else { $erro = 'Senha incorreta.'; }
        $ancora = 'painel';
    }
    if ($acao === 'logout') { alLogout(); $msg = 'SessÃ£o encerrada.'; }

    if ($is_admin && $acao === 'salvar_radar') {
        $cats = array_values(array_intersect(array_keys(alLotesCategorias()), (array)($_POST['cat'] ?? [])));
        alSetCfg('lotes_categorias', implode(',', $cats));
        if (isset($_POST['termos'])) {
            $t = array_filter(array_map('trim', preg_split('/[,;\n]+/', (string)$_POST['termos'])), 'strlen');
            alSetCfg('lotes_termos', implode(', ', array_unique($t)));
        }
        $msg = 'Mira atualizada.';
        $ancora = 'mira';
    }

    if ($is_admin && $acao === 'varrer_lotes') {
        $r = alVarrerLotes();
        $msg = "Lotes conferidos: {$r['rr']} relidos, {$r['novos']} novo(s), " . count($r['avisar']) . ' na mira.'
             . ($r['parciais'] ? ' ' . implode(' Â· ', $r['parciais']) . '.' : '');
        if ($r['erros']) $erro = implode(' Â· ', $r['erros']);
        $ancora = 'lotes';
    }

    // Fontes oficiais: so o que cabe no tempo do navegador, sem IA, sem custo.
    if ($is_admin && $acao === 'verificar_fontes') {
        $r = alVerificarFontes();
        $msg = $r['novos'] . ' edital(is) novo(s) nas fontes oficiais.';
        if ($r['erros']) $erro = implode(' Â· ', $r['erros']);
        $ancora = 'editais';
    }

    if ($is_admin && $acao === 'verificar_agora') {
        try {
            $r = alVerificar();
            if (($r['status'] ?? '') === 'inativo') $erro = 'Monitor desativado â€” ative na configuraÃ§Ã£o.';
            else $msg = "Busca completa: {$r['novos']} novo(s), {$r['revisao']} para conferir.";
        } catch (Exception $e) {
            $erro = 'Erro: ' . $e->getMessage();
        }
        $ancora = 'editais';
    }

    if ($is_admin && $acao === 'salvar_config') {
        $key_post = trim($_POST['anthropic_api_key'] ?? '');
        if ($key_post !== '') alSetCfg('anthropic_api_key', $key_post);
        // Chave em branco NAO apaga a que ja esta gravada - o campo vem vazio
        // toda vez (e password), entao salvar config nao pode zerar a chave.
        $key_gem = trim($_POST['gemini_api_key'] ?? '');
        if ($key_gem !== '') alSetCfg('gemini_api_key', $key_gem);
        alSetCfg('provedor', ($_POST['provedor'] ?? 'gemini') === 'claude' ? 'claude' : 'gemini');
        alSetCfg('modelo_gemini', trim($_POST['modelo_gemini'] ?? 'gemini-2.5-flash'));
        alSetCfg('modelo', trim($_POST['modelo'] ?? 'claude-sonnet-4-6'));
        alSetCfg('ativo', !empty($_POST['ativo']) ? '1' : '0');
        alSetCfg('email_notif', !empty($_POST['email_notif']) ? '1' : '0');
        $msg = 'ConfiguraÃ§Ã£o gravada.';
        $ancora = 'painel';
    }

    // "Arquivar" no lugar de apagar: o hash fica, e o PNCP nao traz o edital de volta.
    if ($is_admin && $acao === 'arquivar' && $id) {
        alDb()->prepare("UPDATE editais SET oculto=1 WHERE id=?")->execute([$id]);
        $msg = 'Edital arquivado.'; $ancora = 'editais';
    }
    if ($is_admin && $acao === 'restaurar' && $id) {
        alDb()->prepare("UPDATE editais SET oculto=0 WHERE id=?")->execute([$id]);
        $msg = 'Edital de volta Ã  lista.'; $ancora = 'editais';
    }
    if ($is_admin && $acao === 'confirmar' && $id) {
        alDb()->prepare("UPDATE editais SET categoria='confirmado', motivo_revisao='' WHERE id=?")->execute([$id]);
        $msg = 'Edital confirmado.'; $ancora = 'editais';
    }
    if ($is_admin && $acao === 'ocultar_lote' && $id) {
        alDb()->prepare("UPDATE lotes SET oculto=1 WHERE id=?")->execute([$id]);
        $msg = 'Lote tirado da lista.'; $ancora = 'lotes';
    }

    $_SESSION['al_flash'] = [$msg, $erro];
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?#') . ($ancora ? '#' . $ancora : ''), true, 303);
    exit;
}
if (!empty($_SESSION['al_flash'])) {
    [$msg, $erro] = $_SESSION['al_flash'];
    unset($_SESSION['al_flash']);
}

// ============ FORMATOS ============
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/*
 * O PNCP manda o orgao em CAIXA ALTA e repetido:
 * "TRIBUNAL DE JUSTICA DO ESTADO DE RORAIMA - Boa Vista/RR (TRIBUNAL DE JUSTIÃ‡A DO ESTADO DE RORAIMA)".
 * Fica o nome da unidade (que vem com acento), sem repetir a cidade, em caixa normal.
 */
function alNomeOrgao($s) {
    $s = trim((string)$s);
    $cidade = '';
    if (preg_match('~^(.*?)\s+-\s+([^()]+?/RR)\s*\((.+)\)$~u', $s, $m)) {
        $a   = alNormTxt($m[1]);
        $b   = alNormTxt(preg_replace('~\s*-\s*RR$~iu', '', $m[3]));
        $cid = alNormTxt(str_replace('/RR', '', $m[2]));
        if ($a === $b || strpos($b, $a) !== false || strpos($b, $cid) !== false) $s = preg_replace('~\s*-\s*RR$~iu', '', $m[3]);
        else $s = $m[1] . ' Â· ' . $m[3];
        if (strpos(alNormTxt($s), $cid) === false) $cidade = $m[2];
    }
    if (mb_strtoupper($s, 'UTF-8') === $s) $s = alTitulo($s);
    return $s . ($cidade ? ' â€” ' . $cidade : '');
}

function alTitulo($s) {
    $min    = ['de', 'da', 'do', 'das', 'dos', 'e', 'em', 'a', 'o', 'para', 'por', 'com', 'na', 'no'];
    $siglas = ['RR', 'TJRR', 'TRF', 'SJRR', 'SELC', 'CRO', 'DETRAN', 'UERR', 'UFRR', 'IFRR', 'JUCERR', 'MPRR', 'TCE', 'PMBV', 'LTDA', 'ME', 'S/A'];
    $p = preg_split('~(\s+)~u', mb_strtolower($s, 'UTF-8'), -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($p as $i => $w) {
        if (trim($w) === '') continue;
        $up = mb_strtoupper($w, 'UTF-8');
        if (in_array(trim($up, '(),.-'), $siglas, true)) { $p[$i] = $up; continue; }
        if ($i > 0 && in_array($w, $min, true)) continue;
        $p[$i] = preg_replace_callback('~(^|[/(-])(\p{L})~u', function ($m) { return $m[1] . mb_strtoupper($m[2], 'UTF-8'); }, $w);
    }
    return preg_replace('~/rr\b~iu', '/RR', implode('', $p));
}

function alMesCurto($n) { return ['', 'jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'][(int)$n]; }

function alDataLonga($ts) {
    $sem = ['domingo', 'segunda-feira', 'terÃ§a-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sÃ¡bado'];
    $mes = ['', 'janeiro', 'fevereiro', 'marÃ§o', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    return $sem[(int)date('w', $ts)] . ', ' . (int)date('j', $ts) . ' de ' . $mes[(int)date('n', $ts)] . ' de ' . date('Y', $ts);
}

// "2026-10-19 10:30" -> "19 out, 10h30"
function alDataCurta($dt) {
    $t = strtotime($dt);
    if (!$t) return '';
    $h = date('H:i', $t);
    $hora = $h === '00:00' || $h === '23:59' ? '' : ', ' . (int)date('G', $t) . 'h' . (date('i', $t) !== '00' ? date('i', $t) : '');
    return (int)date('j', $t) . ' ' . alMesCurto(date('n', $t)) . (date('Y', $t) !== date('Y') ? ' ' . date('Y', $t) : '') . $hora;
}

function alDias($dt) {
    return (int)round((strtotime(substr($dt, 0, 10)) - strtotime(date('Y-m-d'))) / 86400);
}

function alQuando($dt) {
    if (!$dt) return '';
    $d = alDias($dt);
    if ($d < -1) return 'hÃ¡ ' . (-$d) . ' dias';
    if ($d === -1) return 'ontem';
    if ($d === 0) return 'hoje';
    if ($d === 1) return 'amanhÃ£';
    return 'em ' . $d . ' dias';
}

function alRelativo($dt) {
    if (!$dt) return 'nunca';
    $s = time() - strtotime($dt);
    if ($s < 90) return 'agora hÃ¡ pouco';
    if ($s < 3600) return 'hÃ¡ ' . round($s / 60) . ' min';
    if ($s < 86400) return 'hÃ¡ ' . round($s / 3600) . ' h';
    $d = (int)floor($s / 86400);
    return $d === 1 ? 'ontem' : 'hÃ¡ ' . $d . ' dias';
}

// Desenhos de linha (placeholder do lote sem foto, chips, pino)
function alIcone($nome) {
    $p = [
        'apartamento' => '<rect x="14" y="8" width="36" height="48"/><path d="M22 16h6M36 16h6M22 25h6M36 25h6M22 34h6M36 34h6M28 56V45h8v11"/>',
        'casa'        => '<path d="M10 30 32 12l22 18"/><path d="M16 26v28h32V26"/><path d="M27 54V40h10v14"/>',
        'terreno'     => '<path d="M6 46 22 30l12 10 10-8 14 14"/><path d="M6 54h52"/><path d="M14 54v-5M24 54v-5M34 54v-5M44 54v-5M54 54v-5"/><circle cx="46" cy="16" r="5"/>',
        'predio'      => '<path d="M8 54V28l24-14 24 14v26z"/><path d="M8 54h48M20 54V38h24v16M20 44h24"/>',
        'imovel'      => '<circle cx="22" cy="32" r="10"/><path d="M32 32h24M48 32v8M54 32v6"/><circle cx="22" cy="32" r="3"/>',
        'veiculo'     => '<path d="M8 40v-6l6-12h30l10 12h4v6z"/><circle cx="18" cy="42" r="5"/><circle cx="46" cy="42" r="5"/><path d="M18 22l-4 12h40"/>',
        'outro'       => '<path d="m30 10 14 14-6 6-14-14z"/><path d="m37 27-17 17"/><path d="M14 54h24"/><path d="m24 14-6 6M48 28l-6 6"/>',
        'pino'        => '<path d="M32 56s-16-16-16-28a16 16 0 1 1 32 0c0 12-16 28-16 28z"/><circle cx="32" cy="28" r="6"/>',
    ];
    return '<svg viewBox="0 0 64 64" aria-hidden="true" class="ico ico-' . $nome . '">' . ($p[$nome] ?? $p['outro']) . '</svg>';
}

function alOlho() {
    return '<button type="button" class="pw-toggle" hidden aria-label="Mostrar senha" title="Mostrar senha" aria-pressed="false">'
         . '<svg class="olho-on" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>'
         . '<svg class="olho-off" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 5.1A10.4 10.4 0 0 1 12 5c6.4 0 10 7 10 7a17.5 17.5 0 0 1-3.2 4.2M6.6 6.6C3.9 8.4 2 12 2 12s3.6 7 10 7a9.7 9.7 0 0 0 5.4-1.6"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>'
         . '</button>';
}


function alCardLote(array $l, $admin) {
    $x    = $l['x'];
    $cat  = $l['categoria'];
    $novo = $l['criado_em'] && (time() - strtotime($l['criado_em'])) < 2 * 86400;
    $dias = $l['data_leilao'] ? alDias($l['data_leilao']) : null;
    $urg  = $dias !== null && $dias >= 0 && $dias <= 3;
    $onde = trim(($l['bairro'] ? $l['bairro'] . ', ' : '') . ($l['municipio'] ?: 'Roraima'));
    $desc = ($l['valor_avaliacao'] > 0 && $l['valor_lance'] > 0 && $l['valor_lance'] < $l['valor_avaliacao'])
          ? (int)round((1 - $l['valor_lance'] / $l['valor_avaliacao']) * 100) : 0;
    $info = [];
    if (!empty($x['quartos'])) $info[] = $x['quartos'] . ' quarto' . ($x['quartos'] > 1 ? 's' : '');
    if (!empty($x['area']) && stripos($l['titulo'], $x['area']) === false) $info[] = $x['area'];
    $aceita = [];
    if (!empty($x['fgts']) && stripos($x['fgts'], 'aceita') === 0) $aceita[] = 'FGTS';
    if (!empty($x['financiamento']) && stripos($x['financiamento'], 'aceita') === 0) $aceita[] = 'financiamento';
    if ($aceita) $info[] = 'aceita ' . implode(' e ', $aceita);
    if (!empty($x['modalidade'])) $info[] = mb_strtolower($x['modalidade'], 'UTF-8');
    // Pracas so quando ha mais de uma: e o que mostra quanto o preco cai.
    $pracas = (isset($x['pracas']) && count($x['pracas']) >= 2) ? $x['pracas'] : [];
    ob_start(); ?>
<article class="bloco lote<?= $l['termo'] ? ' lote-vigiado' : '' ?>" data-mun-item data-mun="<?= e($l['municipio']) ?>">
  <a class="lote-foto" href="<?= e($l['link']) ?>" target="_blank" rel="noopener" tabindex="-1" aria-hidden="true">
    <span class="lote-ph"><?= alIcone($cat) ?></span>
    <?php if (strpos((string)$l['foto'], 'http') === 0): ?><img src="<?= e($l['foto']) ?>" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer" onerror="this.remove()"><?php endif; ?>
    <span class="selos">
      <?php if ($urg): ?><span class="selo selo-brasa"><i class="ping"></i>Encerra <?= e(alQuando($l['data_leilao'])) ?></span>
      <?php elseif ($novo): ?><span class="selo"><i class="ping"></i>Novo</span><?php endif; ?>
      <?php if ($desc >= 5): ?><span class="selo selo-ouro">âˆ’<?= $desc ?>%</span><?php endif; ?>
    </span>
  </a>
  <span class="marca-dagua" aria-hidden="true"><?= alIcone($cat) ?></span>
  <div class="lote-corpo">
    <p class="lote-topo"><span class="lote-cat"><?= e(alLoteCatNome($cat)) ?></span><?php if ($l['numero'] !== ''): ?><span>Lote #<?= e($l['numero']) ?></span><?php endif; ?></p>
    <?php if ($l['termo']): ?><p class="vigiado"><i class="ping ping-ouro"></i>Vigiado Â· <?= e($l['termo']) ?></p><?php endif; ?>
    <h3 class="lote-tit"><a href="<?= e($l['link']) ?>" target="_blank" rel="noopener"><?= e($l['titulo']) ?></a></h3>
    <p class="lote-onde"><?= alIcone('pino') ?><span><?= e($onde) ?></span><?php if (!empty($l['lat']) && in_array($cat, alLotesFamiliaImovel(), true)): ?><a class="ver-mapa" href="#mapa" data-pino="<?= (int)$l['id'] ?>">no mapa</a><?php endif; ?></p>
    <?php if ($l['valor_avaliacao'] > 0 || $l['valor_lance'] > 0): ?>
    <dl class="lote-valores">
      <?php if ($l['valor_avaliacao'] > 0): ?><div><dt>AvaliaÃ§Ã£o</dt><dd><?= e(alBRL($l['valor_avaliacao'])) ?></dd></div><?php endif; ?>
      <?php if ($l['valor_lance'] > 0): ?><div><dt><?= e($l['valor_rotulo']) ?></dt><dd class="forte"><?= e(alBRL($l['valor_lance'])) ?></dd></div><?php endif; ?>
    </dl>
    <?php endif; ?>
    <?php if ($pracas): ?>
    <ul class="pracas" aria-label="PraÃ§as">
      <?php foreach ($pracas as $pr): ?>
      <li><span><?= e($pr['rotulo']) ?></span><span><?= $pr['data'] ? e(alDataCurta($pr['data'])) : 'â€”' ?></span><span><?= e(alBRL($pr['valor'])) ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if ($info): ?><p class="lote-info"><?= e(implode(' Â· ', $info)) ?></p><?php endif; ?>
    <p class="lote-quando<?= $urg ? ' urgente' : '' ?>">
      <?php if ($l['data_leilao'] && $dias >= 0): ?>Encerra <?= e(alDataCurta($l['data_leilao'])) ?> Â· <strong><?= e(alQuando($l['data_leilao'])) ?></strong>
      <?php elseif ($l['data_leilao']): ?>Data informada: <?= e(alDataCurta($l['data_leilao'])) ?> (<?= e(alQuando($l['data_leilao'])) ?>) Â· ainda aberto na fonte
      <?php elseif (in_array($l['fonte'], ['spy', 'garimpia'], true)): ?>Sem data marcada Â· venda direta ou proposta
      <?php else: ?>Data nÃ£o informada<?php endif; ?>
    </p>
    <p class="lote-fonte"><?= e(alLotesFontes()[$l['fonte']] ?? $l['fonte']) ?><?= $l['comitente'] ? ' Â· ' . e($l['comitente']) : '' ?></p>
    <?php if ($admin): ?>
    <form method="post" class="lote-tirar" onsubmit="return confirm('Tirar este lote da lista?')">
      <input type="hidden" name="acao" value="ocultar_lote"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
      <button type="submit" class="btn-link">Tirar da lista</button>
    </form>
    <?php endif; ?>
  </div>
</article>
<?php
    return ob_get_clean();
}

// O mesmo lote em miniatura, para o balao do mapa de ruas.
function alPopupLote(array $l) {
    $desc = ($l['valor_avaliacao'] > 0 && $l['valor_lance'] > 0 && $l['valor_lance'] < $l['valor_avaliacao'])
          ? (int)round((1 - $l['valor_lance'] / $l['valor_avaliacao']) * 100) : 0;
    $dias = $l['data_leilao'] ? alDias($l['data_leilao']) : null;
    if ($l['data_leilao'] && $dias >= 0)                     $quando = 'Encerra ' . alDataCurta($l['data_leilao']) . ' Â· ' . alQuando($l['data_leilao']);
    elseif ($l['data_leilao'])                               $quando = 'Data informada: ' . alDataCurta($l['data_leilao']) . ' Â· ainda aberto na fonte';
    elseif (in_array($l['fonte'], ['spy', 'garimpia'], true)) $quando = 'Sem data marcada Â· venda direta ou proposta';
    else                                                     $quando = 'Data nÃ£o informada';
    $end = alLoteEndereco($l);
    $cidade = trim(($end['bairro'] !== '' ? $end['bairro'] . ', ' : '') . ($l['municipio'] ?: 'Roraima'));
    $rua = $l['geo'] === 'endereco' ? $end['rua'] : '';
    ob_start(); ?>
<div class="pop">
  <?php if (strpos((string)$l['foto'], 'http') === 0): ?><img class="pop-foto" src="<?= e($l['foto']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()"><?php endif; ?>
  <div class="pop-corpo">
    <p class="pop-cat"><?= e(alLoteCatNome($l['categoria'])) ?><?= $desc >= 5 ? ' Â· âˆ’' . $desc . '%' : '' ?><?= $l['termo'] ? ' Â· vigiado' : '' ?></p>
    <p class="pop-tit"><a href="<?= e($l['link']) ?>" target="_blank" rel="noopener"><?= e($l['titulo']) ?></a></p>
    <?php if ($l['valor_avaliacao'] > 0 || $l['valor_lance'] > 0): ?>
    <dl class="pop-val">
      <?php if ($l['valor_avaliacao'] > 0): ?><div><dt>AvaliaÃ§Ã£o</dt><dd><?= e(alBRL($l['valor_avaliacao'])) ?></dd></div><?php endif; ?>
      <?php if ($l['valor_lance'] > 0): ?><div><dt><?= e($l['valor_rotulo']) ?></dt><dd class="forte"><?= e(alBRL($l['valor_lance'])) ?></dd></div><?php endif; ?>
    </dl>
    <?php endif; ?>
    <p class="pop-quando"><?= e($quando) ?></p>
    <p class="pop-onde"><?php if ($rua !== ''): ?><b><?= e($rua) ?></b> Â· <?= e($cidade) ?><?php else: ?><b>local aproximado</b> Â· <?= e($l['geo'] === 'bairro' ? $cidade : ($l['municipio'] ?: 'Roraima')) ?><?php endif; ?></p>
    <p class="pop-fonte"><?= e(alLotesFontes()[$l['fonte']] ?? $l['fonte']) ?><?= $l['comitente'] ? ' Â· ' . e($l['comitente']) : '' ?></p>
    <a class="btn-link" href="<?= e($l['link']) ?>" target="_blank" rel="noopener">Abrir no leiloeiro â†—</a>
  </div>
</div>
<?php
    return ob_get_clean();
}


// ============ DADOS ============
$pdo    = alDb();
$cats   = alLotesCategorias();
$sel    = alLotesSelecao();
$termos = alLotesTermos();

$lotes = [];
foreach ($pdo->query("SELECT * FROM lotes WHERE oculto=0")->fetchAll(PDO::FETCH_ASSOC) as $l) {
    if (!alLoteVisivel($l)) continue;
    $l['termo']   = alLoteTermo($l, $termos);
    $l['na_mira'] = $l['termo'] !== '' || alLoteNaSelecao($l['categoria'], $sel);
    $l['x']       = json_decode($l['extra'] ?: '[]', true) ?: [];
    $lotes[] = $l;
}
// Vigiado primeiro; depois o que encerra antes; sem data por ultimo.
usort($lotes, function ($a, $b) {
    if (($a['termo'] !== '') !== ($b['termo'] !== '')) return $a['termo'] !== '' ? -1 : 1;
    $da = $a['data_leilao'] && alDias($a['data_leilao']) >= 0 ? $a['data_leilao'] : '9999';
    $db = $b['data_leilao'] && alDias($b['data_leilao']) >= 0 ? $b['data_leilao'] : '9999';
    return [$da, $b['criado_em']] <=> [$db, $a['criado_em']];
});
$mira   = array_values(array_filter($lotes, function ($l) { return $l['na_mira']; }));
$outros = array_values(array_filter($lotes, function ($l) { return !$l['na_mira']; }));

// Mapa de ruas: imoveis que ja tem ponto (o JS junta os que caem no mesmo lugar).
$pinos = [];
foreach ($lotes as $l) {
    if ($l['lat'] === null || !in_array($l['categoria'], alLotesFamiliaImovel(), true)) continue;
    $pinos[] = ['id' => (int)$l['id'], 'lat' => round((float)$l['lat'], 5), 'lon' => round((float)$l['lon'], 5),
                'aprox' => $l['geo'] !== 'endereco', 'mira' => $l['na_mira'], 'mun' => (string)$l['municipio'],
                'tit' => $l['titulo'], 'html' => alPopupLote($l)];
}
$n_ed = $pinos ? '03' : '02'; // numeracao das secoes depois do mapa
$n_op = $pinos ? '04' : '03';

$conta_cat = [];
foreach (array_keys($cats) as $k) {
    $conta_cat[$k] = count(array_filter($lotes, function ($l) use ($k) { return alLoteNaSelecao($l['categoria'], [$k]); }));
}
$conta_outros = [];
$plural = ['apartamento' => 'apartamentos', 'casa' => 'casas', 'terreno' => 'terrenos', 'predio' => 'prÃ©dios/galpÃµes',
           'imovel' => 'imÃ³veis', 'veiculo' => 'veÃ­culos', 'outro' => 'outros bens'];
foreach ($outros as $l) { $conta_outros[$l['categoria']] = ($conta_outros[$l['categoria']] ?? 0) + 1; }
arsort($conta_outros);
$resumo_outros = [];
foreach ($conta_outros as $k => $q) {
    $resumo_outros[] = $q . ' ' . ($q === 1 ? mb_strtolower(alLoteCatNome($k), 'UTF-8') : ($plural[$k] ?? $k));
}

$abertos = []; $conferir = []; $guardados = [];
foreach ($pdo->query("SELECT * FROM editais ORDER BY criado_em DESC")->fetchAll(PDO::FETCH_ASSOC) as $ed) {
    $ed['mun'] = alMunicipioRR($ed['orgao'] . ' ' . $ed['resumo']) ?: 'Boa Vista';
    if (!empty($ed['oculto']) || alEditalVencido($ed)) $guardados[] = $ed;
    elseif ($ed['categoria'] === 'revisao')            $conferir[]  = $ed;
    else                                               $abertos[]   = $ed;
}
usort($abertos, function ($a, $b) { return ($a['prazo_fim'] ?: '9999') <=> ($b['prazo_fim'] ?: '9999'); });

$mapa = alMapaRR();
$por_mun = [];
foreach ($mapa['mun'] as $m) $por_mun[$m['nome']] = ['mira' => 0, 'lotes' => 0, 'editais' => 0];
foreach ($lotes as $l) {
    if (!isset($por_mun[$l['municipio']])) continue;
    $por_mun[$l['municipio']]['lotes']++;
    if ($l['na_mira']) $por_mun[$l['municipio']]['mira']++;
}
foreach ($abertos as $ed) if (isset($por_mun[$ed['mun']])) $por_mun[$ed['mun']]['editais']++;

$edicao   = (int)$pdo->query("SELECT COUNT(*) FROM log")->fetchColumn();
$logs     = $pdo->query("SELECT * FROM log ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
$ativo    = (bool)alCfg('ativo');
$provedor = alCfg('provedor') === 'claude' ? 'claude' : 'gemini';
$tem_key     = trim((string)alCfg('anthropic_api_key')) !== '';
$tem_key_gem = trim((string)alCfg('gemini_api_key')) !== '';
$tem_chave   = $provedor === 'claude' ? $tem_key : $tem_key_gem;

$sel_nomes = array_map(function ($k) use ($cats) { return mb_strtolower($cats[$k], 'UTF-8'); }, $sel);
$frase_mira = $sel_nomes ? implode(', ', $sel_nomes) : 'nenhuma categoria marcada';
if ($termos) $frase_mira .= ' Â· e o que citar ' . implode(', ', array_map(function ($t) { return 'â€œ' . $t . 'â€'; }, $termos));
$termos_achados = array_values(array_unique(array_filter(array_column($mira, 'termo'))));
$termos_sem = array_values(array_diff($termos, $termos_achados));

$rotulos_curtos = ['SÃ£o JoÃ£o da Baliza' => 'SJ Baliza', 'SÃ£o Luiz do AnauÃ¡' => 'S. Luiz'];
$rotulos_desloc = ['SÃ£o JoÃ£o da Baliza' => [2, 24], 'SÃ£o Luiz do AnauÃ¡' => [-16, -6], 'Caroebe' => [4, -18],
                   'UiramutÃ£' => [0, 6], 'Normandia' => [6, 0], 'Boa Vista' => [-4, 0]];

// Faixa de fontes (ticker): ponto aceso = conferida dentro do ciclo esperado.
$fita = [];
foreach (alLotesFontes() as $f => $nome) $fita[] = [$nome, alCfg('lotes_ok_' . $f), 36];
$fita[] = ['PNCP + Receita', alCfg('fontes_ok'), 36];
$fita[] = ['Busca com IA', alCfg('ultima_verificacao'), 8 * 24];
$hoje_curto = (int)date('j') . ' ' . alMesCurto(date('n')) . ' ' . date('Y');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Alertas RR Â· Roraima em leilÃ£o</title>
<meta name="description" content="Lotes de leilÃ£o em Roraima (imÃ³veis e veÃ­culos do TJRR, TRF1, Caixa, bancos e leiloeiros locais) e editais de credenciamento de leiloeiros oficiais em RR.">
<meta name="robots" content="index,follow">
<meta name="theme-color" content="#030304">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Cdefs%3E%3ClinearGradient id='g' x2='1'%3E%3Cstop stop-color='%23EA580C'/%3E%3Cstop offset='1' stop-color='%23F7931A'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='64' height='64' rx='16' fill='%23030304'/%3E%3Cpath d='m30 12 14 14-6 6-14-14zM37 29 20 46M14 54h24' stroke='url(%23g)' stroke-width='5' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<?php if ($pinos): ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css" integrity="sha512-h9FcoyWjHcOcmEVkxOfTLnmZFWIH0iZhZT1H2TbOq55xssQGEJHEaIm+PgoUaZbRvQTNTluNOEfb1ZRy6D3BOw==" crossorigin="anonymous" referrerpolicy="no-referrer">
<?php endif; ?>
<style>
/* =====================================================================
   TOKENS â€” "Bitcoin DeFi": vazio verdadeiro + energia laranja/ouro.
   Tudo o que tem cor, raio, brilho ou fonte sai daqui.
   ===================================================================== */
:root{
  --vazio:#030304;          /* fundo */
  --materia:#0F1115;        /* superficie: blocos, faixas alternadas */
  --luz:#FFFFFF;            /* texto principal */
  --poeira:#94A3B8;         /* texto secundario */
  --limite:#1E293B;         /* borda solida / linhas da grade */
  --fio:rgba(255,255,255,.10);
  --fio-2:rgba(255,255,255,.20);
  --texto-2:rgba(255,255,255,.78);
  --laranja:#F7931A;        /* acento primario */
  --brasa:#EA580C;          /* acento secundario */
  --ouro:#FFD600;           /* valor, sucesso */
  --grad:linear-gradient(to right,#EA580C,#F7931A);
  --grad-ouro:linear-gradient(to right,#F7931A,#FFD600);
  --brilho:0 0 20px -5px rgba(234,88,12,.5);
  --brilho-forte:0 0 30px -5px rgba(247,147,26,.6);
  --brilho-ouro:0 0 20px rgba(255,214,0,.3);
  --brilho-bloco:0 0 30px -10px rgba(247,147,26,.2);
  --elevacao:0 0 50px -10px rgba(247,147,26,.1);
  --r-lg:8px; --r-xl:12px; --r-2xl:16px;
  --f-titulo:'Space Grotesk',system-ui,sans-serif;
  --f-corpo:'Inter',system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;
  --f-mono:'JetBrains Mono',ui-monospace,SFMono-Regular,Consolas,monospace;
  --conteudo:1280px;
  --margem:clamp(16px,4vw,40px);
  color-scheme:dark;
}

/* ---------- base ---------- */
*,*::before,*::after{box-sizing:border-box}
*{margin:0;padding:0}
[hidden]{display:none!important}
html{background:var(--vazio);-webkit-text-size-adjust:100%;scroll-behavior:smooth;scrollbar-color:var(--laranja) var(--materia)}
body{background:var(--vazio);color:var(--luz);font:400 16px/1.65 var(--f-corpo);min-height:100vh;overflow-x:hidden;-webkit-font-smoothing:antialiased}
/* vazio que respira: ruido finissimo por cima de tudo */
body::after{content:"";position:fixed;inset:0;pointer-events:none;z-index:100;opacity:.035;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E")}
::selection{background:rgba(247,147,26,.35);color:#fff}
a{color:inherit}
:focus-visible{outline:2px solid var(--laranja);outline-offset:3px}
.ico{width:1em;height:1em;fill:none;stroke:currentColor;stroke-width:3.4;stroke-linecap:round;stroke-linejoin:round;flex:none}
.mono{font-family:var(--f-mono)}
.grad-texto{background:var(--grad-ouro);-webkit-background-clip:text;background-clip:text;color:transparent}
.dentro{max-width:var(--conteudo);margin:0 auto;padding:0 var(--margem)}

/* grade da rede (assinatura) com vinheta */
.grade{position:absolute;inset:0;pointer-events:none;background-size:50px 50px;
  background-image:linear-gradient(to right,rgba(30,41,59,.5) 1px,transparent 1px),linear-gradient(to bottom,rgba(30,41,59,.5) 1px,transparent 1px);
  -webkit-mask-image:radial-gradient(circle at center,#000 40%,transparent 100%);mask-image:radial-gradient(circle at center,#000 40%,transparent 100%)}
/* campos de energia */
.nebulosa{position:absolute;border-radius:50%;pointer-events:none;filter:blur(120px)}

/* ponto "ao vivo" */
.ping{position:relative;display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--laranja);box-shadow:0 0 10px var(--laranja);flex:none}
.ping::after{content:"";position:absolute;inset:0;border-radius:50%;background:inherit;animation:ping 1.8s cubic-bezier(0,0,.2,1) infinite}
.ping-ouro{background:var(--ouro);box-shadow:0 0 10px var(--ouro)}
.ping-apagado{background:var(--brasa);box-shadow:none}
.ping-apagado::after{display:none}
@keyframes ping{75%,100%{transform:scale(2.6);opacity:0}}

/* ---------- botoes (pilula que emite luz) ---------- */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:44px;padding:0 24px;border-radius:999px;border:0;
  background:var(--grad);color:#fff;font:600 13px/1 var(--f-corpo);letter-spacing:.08em;text-transform:uppercase;text-decoration:none;white-space:nowrap;
  box-shadow:var(--brilho);cursor:pointer;transition:transform .3s,box-shadow .3s,background-color .3s,border-color .3s,color .3s}
.btn:hover{transform:scale(1.05);box-shadow:var(--brilho-forte)}
.btn:disabled{opacity:.6;cursor:progress;transform:none}
.btn-contorno{background:transparent;border:2px solid var(--fio-2);box-shadow:none}
.btn-contorno:hover{border-color:#fff;background:rgba(255,255,255,.1);box-shadow:none}
.btn-fantasma{background:transparent;box-shadow:none}
.btn-fantasma:hover{background:rgba(255,255,255,.1);color:var(--laranja);box-shadow:none;transform:none}
.btn-link{background:none;border:0;padding:0;color:var(--laranja);font:500 11.5px/1.4 var(--f-mono);letter-spacing:.12em;text-transform:uppercase;cursor:pointer;text-decoration:none}
.btn-link:hover{text-decoration:underline;text-underline-offset:4px}

/* ---------- entradas (terminal de dados) ---------- */
input[type=text],input[type=password],select{
  width:100%;height:48px;padding:0 16px;background:rgba(0,0,0,.5);color:#fff;
  border:0;border-bottom:2px solid var(--fio-2);border-radius:var(--r-lg) var(--r-lg) 0 0;
  font:400 15px/1 var(--f-corpo);outline:none;transition:border-color .2s,box-shadow .2s}
input:focus-visible,select:focus-visible{border-bottom-color:var(--laranja);box-shadow:0 10px 20px -10px rgba(247,147,26,.3);outline:none}
input::placeholder{color:rgba(255,255,255,.3)}
input:disabled{opacity:.5;cursor:not-allowed}
select{appearance:none;-webkit-appearance:none;cursor:pointer;padding-right:40px;
  background:rgba(0,0,0,.5) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23F7931A' stroke-width='2'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E") no-repeat right 14px center/16px}
select option{background:var(--materia);color:#fff}
.rotulo{display:block;font:500 11px/1 var(--f-mono);letter-spacing:.16em;text-transform:uppercase;color:var(--poeira);margin-bottom:10px}

/* ---------- bloco (o "card" da cadeia) ---------- */
.bloco{position:relative;background:var(--materia);border:1px solid var(--fio);border-radius:var(--r-2xl);transition:transform .3s,border-color .3s,box-shadow .3s}
.bloco:hover{transform:translateY(-4px);border-color:rgba(247,147,26,.5);box-shadow:var(--brilho-bloco)}
.vidro{background:rgba(0,0,0,.4);-webkit-backdrop-filter:blur(16px);backdrop-filter:blur(16px);border:1px solid var(--fio);border-radius:var(--r-2xl)}
/* cantoneiras laranja: "no selecionado" */
.cantos::before,.cantos::after{content:"";position:absolute;width:22px;height:22px;pointer-events:none;transition:width .3s,height .3s}
.cantos::before{top:-1px;left:-1px;border-top:2px solid var(--laranja);border-left:2px solid var(--laranja);border-top-left-radius:var(--r-2xl)}
.cantos::after{bottom:-1px;right:-1px;border-bottom:2px solid var(--laranja);border-right:2px solid var(--laranja);border-bottom-right-radius:var(--r-2xl)}
.cantos:hover::before,.cantos:hover::after{width:44px;height:44px}

/* ---------- topo ---------- */
.topo{position:sticky;top:0;z-index:50;background:rgba(3,3,4,.72);-webkit-backdrop-filter:blur(14px);backdrop-filter:blur(14px);border-bottom:1px solid var(--fio)}
.topo .dentro{display:flex;align-items:center;gap:18px;height:64px}
.marca{display:inline-flex;align-items:center;gap:10px;text-decoration:none;font:700 17px/1 var(--f-titulo);letter-spacing:-.01em}
.marca-no{display:grid;place-items:center;width:34px;height:34px;border-radius:var(--r-lg);background:rgba(234,88,12,.2);border:1px solid rgba(234,88,12,.5);color:var(--laranja);box-shadow:0 0 16px -4px rgba(234,88,12,.6)}
.marca-no .ico{width:18px;height:18px;stroke-width:4.5}
.topo-nav{display:flex;gap:4px;margin-left:auto}
.topo-nav a{font:500 12px/1 var(--f-mono);letter-spacing:.14em;text-transform:uppercase;color:var(--poeira);text-decoration:none;padding:10px 12px;border-radius:999px;transition:color .2s,background .2s}
.topo-nav a:hover{color:var(--laranja);background:rgba(255,255,255,.06)}
.vivo{display:inline-flex;align-items:center;gap:9px;font:500 11px/1 var(--f-mono);letter-spacing:.14em;text-transform:uppercase;color:var(--ouro);border:1px solid rgba(255,214,0,.35);border-radius:999px;padding:8px 12px;box-shadow:var(--brilho-ouro)}
@media (max-width:760px){.topo-nav a:not(.essencial){display:none}.vivo span{display:none}.vivo{padding:8px}}

/* ---------- mensagens do sistema ---------- */
.sys{margin:20px auto 0;max-width:calc(var(--conteudo) - 2*var(--margem));padding:14px 18px;border-radius:var(--r-xl);border:1px solid rgba(255,214,0,.35);background:rgba(255,214,0,.06);font:400 13.5px/1.5 var(--f-mono);color:var(--ouro)}
.sys.ruim{border-color:rgba(234,88,12,.6);background:rgba(234,88,12,.08);color:#FDBA74}
@media (max-width:1320px){.sys{margin-left:var(--margem);margin-right:var(--margem)}}

/* ---------- capa ---------- */
.capa{position:relative;overflow:hidden;padding:clamp(48px,8vw,104px) 0 clamp(48px,6vw,88px)}
.capa .n1{width:560px;height:560px;right:-120px;top:-140px;background:var(--laranja);opacity:.10}
.capa .n2{width:420px;height:420px;left:-160px;bottom:-200px;background:var(--ouro);opacity:.06}
.capa-in{position:relative;display:grid;grid-template-columns:minmax(0,1.05fr) minmax(0,.95fr);gap:clamp(32px,5vw,72px);align-items:center}
@media (max-width:980px){.capa-in{grid-template-columns:1fr}}
.cracha{display:inline-flex;align-items:center;gap:10px;font:500 11.5px/1 var(--f-mono);letter-spacing:.14em;text-transform:uppercase;color:var(--laranja);
  background:rgba(247,147,26,.08);border:1px solid rgba(247,147,26,.35);border-radius:999px;padding:9px 14px 9px 12px;margin-bottom:26px}
h1{font:700 clamp(44px,8.4vw,104px)/.98 var(--f-titulo);letter-spacing:-.035em}
h1 .grad-texto{display:inline-block;padding-bottom:.06em}
.lead{max-width:36em;margin-top:24px;font-size:clamp(16px,1.6vw,18px);line-height:1.75;color:var(--poeira);text-align:justify;hyphens:auto;-webkit-hyphens:auto}
.lead b{color:#fff;font-weight:600}

/* mira: caixinhas em pilula */
.mira{margin-top:34px}
.mira fieldset{border:0;display:flex;flex-wrap:wrap;gap:10px}
.mira legend{padding:0}
.chip{position:relative;display:inline-flex;align-items:center;gap:9px;min-height:44px;padding:0 16px 0 14px;border-radius:999px;border:1px solid var(--fio-2);
  background:rgba(255,255,255,.03);font:500 14px/1 var(--f-corpo);color:var(--texto-2);cursor:pointer;user-select:none;transition:all .3s}
.chip input{position:absolute;inset:0;opacity:0;margin:0;cursor:pointer}
.chip .ico{font-size:17px;color:var(--poeira);transition:color .3s}
.chip-n{font:500 11.5px/1 var(--f-mono);color:var(--poeira);padding-left:9px;border-left:1px solid var(--fio)}
.chip:hover{border-color:rgba(247,147,26,.5);color:#fff}
.chip:has(input:checked){background:var(--grad);border-color:transparent;color:#fff;box-shadow:var(--brilho)}
.chip:has(input:checked) .ico,.chip:has(input:checked) .chip-n{color:#fff;border-color:rgba(255,255,255,.35)}
.chip:has(input:focus-visible){outline:2px solid var(--ouro);outline-offset:3px}
fieldset[disabled] .chip{cursor:default}
fieldset[disabled] .chip:not(:has(input:checked)){opacity:.55}
fieldset[disabled] .chip:hover:not(:has(input:checked)){border-color:var(--fio-2);color:var(--texto-2)}
.mira-termos{margin-top:24px;max-width:540px}
.campo-linha{display:flex;gap:12px;align-items:end}
.campo-linha input{flex:1;min-width:0}
.nota{font-size:13px;line-height:1.6;color:var(--poeira);margin-top:10px;text-align:justify;hyphens:auto;-webkit-hyphens:auto}

/* palco do mapa: orbe + aneis + cartoes flutuantes */
.palco-caixa{position:relative;width:100%;max-width:580px;margin:0 auto}
.palco{position:relative;width:100%;aspect-ratio:1}
.flutuantes{position:absolute;inset:0;pointer-events:none}
.flutuante{pointer-events:auto}
.palco::before{content:"";position:absolute;inset:8%;border-radius:50%;background:radial-gradient(circle at 50% 45%,rgba(247,147,26,.16),rgba(234,88,12,.05) 45%,transparent 70%)}
.orbita{position:absolute;border-radius:50%;pointer-events:none}
.orbita-1{inset:2%;border:1px dashed rgba(247,147,26,.38);animation:gira 10s linear infinite}
.orbita-2{inset:11%;border:1px solid rgba(255,214,0,.16);animation:gira 15s linear infinite reverse}
.orbita-1::before,.orbita-2::before{content:"";position:absolute;width:9px;height:9px;border-radius:50%;top:-5px;left:calc(50% - 4.5px)}
.orbita-1::before{background:var(--laranja);box-shadow:0 0 14px 2px var(--laranja)}
.orbita-2::before{background:var(--ouro);box-shadow:0 0 12px 2px rgba(255,214,0,.7);top:auto;bottom:-5px}
@keyframes gira{to{transform:rotate(360deg)}}
.mapa-flutua{position:absolute;inset:8% 12%;animation:flutua 8s ease-in-out infinite}
.mapa-flutua svg{width:100%;height:100%;display:block;overflow:visible}
@keyframes flutua{0%,100%{transform:translateY(0)}50%{transform:translateY(-16px)}}
.flutuante{position:absolute;display:flex;flex-direction:column;gap:4px;padding:12px 16px;min-width:128px;border-radius:var(--r-xl);
  background:rgba(0,0,0,.45);-webkit-backdrop-filter:blur(14px);backdrop-filter:blur(14px);border:1px solid var(--fio);box-shadow:var(--elevacao);animation:pulo 3s ease-in-out infinite}
.flutuante span{font:500 10.5px/1 var(--f-mono);letter-spacing:.16em;text-transform:uppercase;color:var(--poeira)}
.flutuante b{font:500 30px/1 var(--f-mono);font-variant-numeric:tabular-nums}
.flutuante.f1{top:4%;left:-2%;border-color:rgba(247,147,26,.45);box-shadow:var(--brilho)}
.flutuante.f1 b{background:var(--grad-ouro);-webkit-background-clip:text;background-clip:text;color:transparent}
.flutuante.f2{top:40%;right:-4%;animation-duration:4s;animation-delay:1s}
.flutuante.f3{bottom:3%;left:6%;animation-duration:3.6s;animation-delay:.5s}
@keyframes pulo{0%,100%{transform:translateY(0)}50%{transform:translateY(-10px)}}
@media (max-width:620px){
  .flutuantes{position:static;display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:16px}
  .flutuante{position:static;min-width:0;padding:10px 12px;animation:none}
  .flutuante b{font-size:24px}
  .flutuante span{font-size:9.5px;letter-spacing:.1em}
}
.legenda{display:flex;flex-wrap:wrap;justify-content:center;gap:8px 18px;margin-top:18px;font:400 11.5px/1.4 var(--f-mono);color:var(--poeira)}
.leg{display:inline-flex;align-items:center;gap:8px}
.leg i{display:inline-block;width:14px;height:10px;border-radius:3px}
.leg-mira i{background:var(--grad);box-shadow:var(--brilho)}
.leg-lote i{background:repeating-linear-gradient(45deg,rgba(247,147,26,.55) 0 1.5px,transparent 1.5px 4px);border:1px solid rgba(247,147,26,.35)}
.leg-edital i{width:12px;height:12px;border-radius:50%;border:1.5px dashed var(--ouro)}
.legenda-dica{flex-basis:100%;text-align:center;color:rgba(148,163,184,.75)}

/* mapa (svg) */
.mun{fill:rgba(255,255,255,.035);stroke:rgba(255,255,255,.16);stroke-width:.9;stroke-linejoin:round;cursor:pointer;transition:fill .25s,opacity .3s,stroke .25s}
.mun:hover,.mun:focus-visible{fill:rgba(247,147,26,.14);stroke:var(--laranja);outline:none}
.mun.tem-lote{fill:url(#hachura)}
.mun.tem-mira{fill:url(#g-mira);stroke:#FDBA74;filter:drop-shadow(0 0 8px rgba(247,147,26,.75))}
.hach-fundo{fill:rgba(247,147,26,.04)}
.hach-traco{stroke:rgba(247,147,26,.55);stroke-width:1.1}
.filtrando .mun:not(.ativo){opacity:.3}
.mun.ativo{stroke:var(--ouro);stroke-width:2.4}
.rot{font:500 12.5px/1 var(--f-mono);letter-spacing:.04em;text-transform:uppercase;fill:var(--poeira);text-anchor:middle;pointer-events:none;paint-order:stroke;stroke:var(--vazio);stroke-width:4px;stroke-linejoin:round}
.rot.capital{fill:#fff;font-size:14px}
.rot.na-mira{fill:#fff;font-weight:700;stroke:#7C2D12}
.pino-pulso{fill:var(--ouro);opacity:.6;transform-box:fill-box;transform-origin:center;animation:pulso 2.4s ease-out infinite}
.pino-ponto{fill:var(--vazio);stroke:var(--ouro);stroke-width:1.4}
.pino-n{font:700 12px/1 var(--f-mono);fill:var(--ouro);text-anchor:middle;pointer-events:none}
.anel{fill:none;stroke:var(--ouro);stroke-width:1.6;stroke-dasharray:3 2.4;pointer-events:none;opacity:.9}
@keyframes pulso{0%{transform:scale(1);opacity:.6}100%{transform:scale(2.6);opacity:0}}

/* ---------- faixa de fontes (ticker) ---------- */
.fita{position:relative;border-top:1px solid var(--fio);border-bottom:1px solid var(--fio);background:rgba(15,17,21,.75);overflow:hidden}
.fita-trilho{display:flex;width:max-content;animation:fita 42s linear infinite}
.fita:hover .fita-trilho{animation-play-state:paused}
.fita-grupo{display:flex;flex-shrink:0}
.fita-item{display:inline-flex;align-items:center;gap:10px;padding:16px 28px;font:500 12px/1 var(--f-mono);letter-spacing:.12em;text-transform:uppercase;color:var(--texto-2);white-space:nowrap;border-right:1px solid var(--fio)}
.fita-item small{font:inherit;color:var(--poeira);letter-spacing:.06em;text-transform:none}
@keyframes fita{to{transform:translateX(-50%)}}

/* ---------- filtro por municipio ---------- */
.filtro{position:sticky;top:76px;z-index:40;display:flex;justify-content:center;margin-top:-22px;pointer-events:none}
.filtro-in{pointer-events:auto;display:inline-flex;align-items:center;gap:12px;padding:8px 8px 8px 18px;border-radius:999px;font:500 13px/1 var(--f-mono);
  background:rgba(0,0,0,.6);-webkit-backdrop-filter:blur(14px);backdrop-filter:blur(14px);border:1px solid var(--laranja);box-shadow:var(--brilho-forte)}
.filtro-in b{color:var(--laranja);font-weight:500}
.filtro .btn{min-height:34px;padding:0 14px;font-size:11px}
.filtro-vazio{font:400 13px/1.6 var(--f-mono);color:var(--poeira);padding:16px 0}

/* ---------- secoes (sem linhas: fundo alternado) ---------- */
.secao{position:relative;padding:clamp(64px,9vw,96px) 0}
.secao-alt{background:var(--materia)}
.sec-topo{display:grid;grid-template-columns:1fr auto;align-items:end;gap:10px 24px;margin-bottom:40px}
.sec-kicker{grid-column:1/-1;font:500 12px/1 var(--f-mono);letter-spacing:.2em;text-transform:uppercase;color:var(--laranja)}
.sec-topo h2{font:600 clamp(30px,4.4vw,52px)/1.05 var(--f-titulo);letter-spacing:-.025em}
.sec-sub{grid-column:1;color:var(--poeira);font-size:15px;margin-top:-2px}
.sec-conta{grid-column:2;grid-row:2/4;align-self:center;font:500 12px/1 var(--f-mono);letter-spacing:.12em;text-transform:uppercase;color:var(--ouro);
  border:1px solid rgba(255,214,0,.35);border-radius:999px;padding:10px 14px;white-space:nowrap}
@media (max-width:560px){.sec-topo{grid-template-columns:1fr}.sec-conta{grid-column:1;grid-row:auto;justify-self:start}}
.vigia{display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin:-14px 0 30px;font:400 13px/1.5 var(--f-mono);color:var(--poeira)}
.vigia b{color:var(--ouro);font-weight:500}

/* ---------- lotes ---------- */
.lotes{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,300px),1fr));gap:32px 24px}
.lote{display:flex;flex-direction:column;overflow:hidden}
.lote-vigiado{border-color:rgba(255,214,0,.45);box-shadow:var(--brilho-ouro)}
.lote-foto{position:relative;display:block;aspect-ratio:16/10;overflow:hidden;background:var(--vazio)}
.lote-foto::after{content:"";position:absolute;inset:0;background:linear-gradient(to top,var(--materia) 2%,rgba(15,17,21,.2) 45%,transparent 70%);pointer-events:none}
.lote-foto img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;transition:transform .5s,filter .5s}
.lote:hover .lote-foto img{transform:scale(1.08);filter:contrast(1.15)}
.lote-ph{position:absolute;inset:0;display:grid;place-items:center;color:rgba(247,147,26,.55);background-size:28px 28px;
  background-image:linear-gradient(to right,rgba(30,41,59,.6) 1px,transparent 1px),linear-gradient(to bottom,rgba(30,41,59,.6) 1px,transparent 1px)}
.lote-ph .ico{width:64px;height:64px;stroke-width:2;filter:drop-shadow(0 0 10px rgba(247,147,26,.5))}
.selos{position:absolute;top:12px;left:12px;right:12px;z-index:2;display:flex;flex-wrap:wrap;gap:6px}
.selo{display:inline-flex;align-items:center;gap:8px;padding:7px 11px;border-radius:999px;font:500 10.5px/1 var(--f-mono);letter-spacing:.12em;text-transform:uppercase;
  color:#fff;background:rgba(0,0,0,.55);-webkit-backdrop-filter:blur(8px);backdrop-filter:blur(8px);border:1px solid var(--fio-2)}
.selo-brasa{border-color:var(--brasa);color:#FDBA74;box-shadow:var(--brilho)}
.selo-ouro{border-color:rgba(255,214,0,.6);color:var(--ouro);box-shadow:var(--brilho-ouro);margin-left:auto}
.marca-dagua{position:absolute;right:-22px;bottom:-26px;width:150px;height:150px;color:var(--laranja);opacity:.05;transform:rotate(-14deg);pointer-events:none;transition:opacity .4s,transform .4s}
.marca-dagua .ico{width:100%;height:100%;stroke-width:2}
.lote:hover .marca-dagua{opacity:.14;transform:rotate(-6deg) scale(1.05)}
.lote-corpo{position:relative;z-index:1;display:flex;flex-direction:column;gap:12px;padding:18px 22px 22px;flex:1}
.lote-topo{display:flex;justify-content:space-between;gap:10px;font:500 11px/1 var(--f-mono);letter-spacing:.14em;text-transform:uppercase;color:var(--poeira)}
.lote-cat{color:var(--laranja)}
.vigiado{align-self:flex-start;display:inline-flex;align-items:center;gap:9px;font:500 11px/1 var(--f-mono);letter-spacing:.12em;text-transform:uppercase;color:var(--ouro);
  border:1px solid rgba(255,214,0,.4);border-radius:999px;padding:7px 11px}
.lote-tit{font:600 19px/1.3 var(--f-titulo);letter-spacing:-.01em;overflow-wrap:anywhere;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.lote-tit a{text-decoration:none;transition:color .2s}
.lote-tit a:hover{color:var(--laranja)}
.lote-onde{display:flex;align-items:center;gap:7px;font:400 12.5px/1.3 var(--f-mono);color:var(--poeira)}
.lote-onde .ico{font-size:14px;color:var(--laranja);stroke-width:4}
.ver-mapa{margin-left:auto;flex:none;font:500 10.5px/1 var(--f-mono);letter-spacing:.12em;text-transform:uppercase;color:var(--laranja);text-decoration:none;padding:6px 10px;border:1px solid rgba(247,147,26,.4);border-radius:999px;transition:background .2s,color .2s}
.ver-mapa:hover{background:var(--grad);color:#fff;border-color:transparent}
.lote-valores{display:grid;grid-template-columns:repeat(auto-fit,minmax(112px,1fr));gap:6px 16px;padding:14px 0;border-top:1px solid var(--fio);border-bottom:1px solid var(--fio)}
.lote-valores dt{font:500 10px/1.3 var(--f-mono);letter-spacing:.14em;text-transform:uppercase;color:var(--poeira)}
.lote-valores dd{font:500 18px/1.3 var(--f-mono);font-variant-numeric:tabular-nums;margin-top:5px}
.lote-valores .forte{background:var(--grad-ouro);-webkit-background-clip:text;background-clip:text;color:transparent;font-size:20px}
.pracas{list-style:none;display:grid;gap:6px;padding-left:12px;border-left:2px solid rgba(247,147,26,.4)}
.pracas li{display:grid;grid-template-columns:1fr auto auto;gap:12px;font:400 12px/1.4 var(--f-mono);color:var(--poeira)}
.pracas li span:last-child{color:#fff;font-variant-numeric:tabular-nums}
.lote-info{font-size:13px;color:var(--texto-2)}
.lote-quando{margin-top:auto;font:400 12.5px/1.5 var(--f-mono);color:var(--poeira)}
.lote-quando strong{color:#fff;font-weight:500}
.lote-quando.urgente,.lote-quando.urgente strong{color:var(--laranja)}
.lote-fonte{font:500 10.5px/1.5 var(--f-mono);letter-spacing:.12em;text-transform:uppercase;color:rgba(148,163,184,.8);padding-top:12px;border-top:1px dashed var(--fio)}

.vazio{display:grid;grid-template-columns:auto 1fr;gap:20px 28px;align-items:start;padding:clamp(24px,4vw,40px);overflow:hidden}
.vazio:hover{transform:none}
.no-holo{display:grid;place-items:center;width:64px;height:64px;border-radius:var(--r-xl);background:rgba(234,88,12,.2);border:1px solid rgba(234,88,12,.5);color:var(--laranja);box-shadow:0 0 22px -4px rgba(234,88,12,.55)}
.no-holo .ico{width:34px;height:34px;stroke-width:2.6}
.vazio-grande{font:600 clamp(22px,2.6vw,30px)/1.2 var(--f-titulo);letter-spacing:-.015em}
.vazio p+p{margin-top:12px;color:var(--poeira);max-width:46em;text-align:justify;hyphens:auto;-webkit-hyphens:auto}
@media (max-width:560px){.vazio{grid-template-columns:1fr}}

details.outros{margin-top:48px}
details.outros>summary{list-style:none;cursor:pointer;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px 20px;
  padding:18px 22px;border-radius:var(--r-2xl);border:1px solid var(--fio);background:rgba(255,255,255,.03);transition:border-color .3s,box-shadow .3s}
details.outros>summary:hover{border-color:rgba(247,147,26,.5);box-shadow:var(--brilho-bloco)}
details.outros>summary::-webkit-details-marker{display:none}
.outros-tit{display:inline-flex;align-items:center;gap:14px;font:600 20px/1.2 var(--f-titulo)}
.outros-tit::before{content:"+";display:grid;place-items:center;width:30px;height:30px;border-radius:50%;background:var(--grad);color:#fff;font:500 18px/1 var(--f-mono);box-shadow:var(--brilho);transition:transform .3s}
details[open] .outros-tit::before{transform:rotate(45deg)}
.outros-n{font:400 12px/1.5 var(--f-mono);color:var(--poeira)}
details.outros .lotes{padding-top:32px}

/* ---------- mapa de ruas (Leaflet + OpenStreetMap) ---------- */
.mapa-barra{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:14px 24px;margin-bottom:18px}
.vistas{display:inline-flex;gap:4px;padding:4px;border-radius:999px;border:1px solid var(--fio-2);background:rgba(0,0,0,.35)}
.vista{min-height:38px;padding:0 16px;border-radius:999px;border:0;background:none;color:var(--texto-2);font:500 11.5px/1 var(--f-mono);letter-spacing:.12em;text-transform:uppercase;cursor:pointer;transition:background .3s,color .3s,box-shadow .3s}
.vista:hover{color:#fff}
.vista[aria-pressed=true]{background:var(--grad);color:#fff;box-shadow:var(--brilho)}
.mapa-leg{display:flex;flex-wrap:wrap;gap:8px 18px;font:400 11.5px/1.4 var(--f-mono);color:var(--poeira)}
.mapa-leg span{display:inline-flex;align-items:center;gap:8px}
.mapa-leg i{display:inline-block;width:12px;height:12px;border-radius:50%}
.ml-mira{background:var(--ouro);box-shadow:0 0 10px var(--ouro)}
.ml-rua{background:var(--laranja);box-shadow:0 0 10px var(--laranja)}
.ml-aprox{border:2px dashed var(--laranja)}
/* isolation: os z-index internos do Leaflet (ate 1000) nao passam por cima do topo fixo */
.mapa-ruas{position:relative;isolation:isolate;height:min(72vh,620px);border-radius:var(--r-2xl);border:1px solid var(--fio-2);background:#0b0c0f;box-shadow:var(--elevacao);overflow:hidden}
@media (max-width:620px){.mapa-ruas{height:64vh}}
.mapa-ruas.leaflet-container{font-family:var(--f-corpo);background:#0b0c0f}
/* OpenStreetMap claro, invertido para o escuro da pagina */
.mapa-ruas .leaflet-tile-pane{filter:invert(1) hue-rotate(180deg) brightness(.82) contrast(.92) saturate(.6)}
.mapa-ruas .leaflet-control-attribution{background:rgba(3,3,4,.75);color:var(--poeira);font:400 10px/1.5 var(--f-mono)}
.mapa-ruas .leaflet-control-attribution a{color:var(--poeira)}
.mapa-ruas .leaflet-bar{border:1px solid var(--fio-2);box-shadow:none}
.mapa-ruas .leaflet-bar a{background:var(--materia);color:#fff;border-bottom-color:var(--fio)}
.mapa-ruas .leaflet-bar a:hover{background:#1a1d24;color:var(--laranja)}
.pino-icone{background:none;border:0}
.pino{display:grid;place-items:center;width:20px;height:20px;margin:3px;border-radius:50%;background:var(--laranja);border:2px solid rgba(255,255,255,.25);
  box-shadow:0 0 0 4px rgba(247,147,26,.22),0 0 18px var(--laranja);font:700 10px/1 var(--f-mono);color:var(--vazio);transition:transform .2s}
.pino.mira{background:var(--ouro);box-shadow:0 0 0 4px rgba(255,214,0,.25),0 0 22px var(--ouro)}
.pino.aprox{width:26px;height:26px;margin:0;background:rgba(247,147,26,.14);border:2px dashed var(--laranja);box-shadow:none;color:var(--laranja)}
.pino.aprox.mira{border-color:var(--ouro);color:var(--ouro);background:rgba(255,214,0,.12)}
.pino.varios{width:26px;height:26px;margin:0}
.leaflet-marker-icon:hover .pino,.leaflet-marker-icon:focus-visible .pino{transform:scale(1.2)}
.mapa-ruas .leaflet-popup-content-wrapper{padding:0;background:var(--materia);color:#fff;border:1px solid var(--fio-2);border-radius:var(--r-xl);box-shadow:0 0 40px -8px rgba(247,147,26,.35)}
.mapa-ruas .leaflet-popup-tip{background:var(--materia)}
.mapa-ruas .leaflet-popup-content{margin:0;width:280px!important;max-height:min(440px,58vh);overflow:auto;font:inherit;line-height:1.5}
.mapa-ruas .leaflet-popup-content p{margin:0}
.mapa-ruas a.leaflet-popup-close-button{z-index:2;width:28px;height:28px;top:6px;right:6px;border-radius:50%;background:rgba(0,0,0,.55);color:#fff;font:400 18px/28px var(--f-corpo)}
.pop+.pop{border-top:1px dashed var(--fio-2)}
.pop-foto{display:block;width:100%;height:132px;object-fit:cover}
.pop:first-child .pop-foto{border-radius:var(--r-xl) var(--r-xl) 0 0}
.pop-corpo{display:grid;gap:8px;padding:14px 16px 16px}
.pop-cat{font:500 10.5px/1 var(--f-mono);letter-spacing:.12em;text-transform:uppercase;color:var(--laranja)}
.pop-tit{font:600 15px/1.3 var(--f-titulo);letter-spacing:-.01em;overflow-wrap:anywhere}
.mapa-ruas .pop-tit a{color:#fff;text-decoration:none}
.mapa-ruas .pop-tit a:hover{color:var(--laranja)}
.pop-val{display:flex;flex-wrap:wrap;gap:4px 20px;padding:8px 0;border-top:1px solid var(--fio);border-bottom:1px solid var(--fio)}
.pop-val dt{font:500 9.5px/1.3 var(--f-mono);letter-spacing:.12em;text-transform:uppercase;color:var(--poeira)}
.pop-val dd{font:500 15px/1.3 var(--f-mono);font-variant-numeric:tabular-nums;margin-top:3px}
.pop-val .forte{color:var(--ouro)}
.pop-quando,.pop-onde,.pop-fonte{font:400 11.5px/1.45 var(--f-mono);color:var(--poeira)}
.pop-quando{color:var(--texto-2)}
.pop-onde b{color:var(--laranja);font-weight:500}
.mapa-ruas .pop .btn-link{color:var(--laranja);justify-self:start}

/* ---------- editais: a cadeia ---------- */
.cadeia{list-style:none;position:relative;padding-left:64px}
.cadeia::before{content:"";position:absolute;left:21px;top:10px;bottom:0;width:2px;background:linear-gradient(to bottom,var(--laranja),rgba(247,147,26,.25) 60%,transparent)}
.cadeia>li{position:relative}
.cadeia>li+li{margin-top:28px}
.no{position:absolute;left:-64px;top:22px;display:grid;place-items:center;width:44px;height:44px;border-radius:50%;background:var(--vazio);border:1px solid var(--laranja);
  color:var(--laranja);font:500 13px/1 var(--f-mono);box-shadow:var(--brilho)}
.edital{padding:clamp(20px,3vw,30px)}
.edital-cabeca{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px 16px}
.edital-tipo{font:500 11px/1.4 var(--f-mono);letter-spacing:.14em;text-transform:uppercase;color:var(--laranja)}
.prazo{display:inline-flex;align-items:center;gap:9px;padding:8px 13px;border-radius:999px;border:1px solid var(--fio-2);font:500 11.5px/1 var(--f-mono);letter-spacing:.08em;text-transform:uppercase;color:#fff;white-space:nowrap}
.prazo small{font:inherit;color:var(--poeira)}
.edital h3{font:600 clamp(19px,2.2vw,24px)/1.25 var(--f-titulo);letter-spacing:-.01em;margin-top:14px;overflow-wrap:anywhere}
.edital-resumo{margin-top:12px;color:var(--texto-2);max-width:64em;text-align:justify;hyphens:auto;-webkit-hyphens:auto}
.edital-req{margin-top:10px;font-size:14px;color:var(--poeira);max-width:64em;text-align:justify;hyphens:auto;-webkit-hyphens:auto}
.edital-meta{display:flex;flex-wrap:wrap;align-items:center;gap:10px 20px;margin-top:18px;font:400 12px/1.4 var(--f-mono);color:var(--poeira)}
.edital-meta form{display:inline}
.urgente .no{background:var(--grad);color:#fff;border-color:transparent;box-shadow:var(--brilho-forte)}
.urgente .prazo{border-color:var(--brasa);color:#FDBA74;box-shadow:var(--brilho)}
.conferir .no{border-style:dashed;border-color:var(--ouro);color:var(--ouro);box-shadow:var(--brilho-ouro)}
.conferir .edital-tipo{color:var(--ouro)}
.conferir .cantos::before,.conferir .cantos::after{border-color:var(--ouro)}
.motivo{margin-top:14px;padding:12px 16px;border-radius:var(--r-lg);border-left:2px solid var(--ouro);background:rgba(255,214,0,.06);font-size:14px;color:var(--texto-2);text-align:justify;hyphens:auto;-webkit-hyphens:auto}
.sub-cadeia{display:flex;align-items:center;gap:14px;margin:56px 0 28px;font:500 12px/1 var(--f-mono);letter-spacing:.2em;text-transform:uppercase;color:var(--ouro)}
.sub-cadeia::after{content:"";flex:1;height:1px;background:linear-gradient(to right,rgba(255,214,0,.4),transparent)}
details.guardados{margin-top:48px;padding:6px 22px;border-radius:var(--r-2xl);border:1px solid var(--fio);background:rgba(0,0,0,.25)}
details.guardados>summary{cursor:pointer;padding:14px 0;font:400 12.5px/1.5 var(--f-mono);color:var(--poeira)}
details.guardados ul{list-style:none;padding-bottom:12px}
details.guardados li{display:grid;grid-template-columns:110px 1fr auto;gap:16px;padding:12px 0;border-top:1px solid var(--fio);font-size:14px;color:var(--texto-2);align-items:baseline}
details.guardados li>span:first-child{font:400 12px/1.4 var(--f-mono);color:var(--poeira)}@media (max-width:560px){details.guardados li{grid-template-columns:1fr}.cadeia{padding-left:0}.cadeia::before{display:none}.no{position:static;margin-bottom:12px;width:38px;height:38px}}

/* ---------- sala do operador ---------- */
.painel{position:relative;overflow:hidden}
.painel .n1{width:520px;height:520px;left:50%;top:10%;transform:translateX(-50%);background:var(--brasa);opacity:.07}
.painel-vidro{position:relative;padding:clamp(22px,4vw,40px)}
.painel-grade{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:clamp(28px,5vw,56px)}
@media (max-width:880px){.painel-grade{grid-template-columns:1fr}}
.painel h3{font:500 12px/1 var(--f-mono);letter-spacing:.18em;text-transform:uppercase;color:var(--laranja);margin-bottom:18px}
.campo{margin-bottom:20px}
.marca-check{display:flex;align-items:center;gap:12px;min-height:44px;font-size:15px;color:var(--texto-2);cursor:pointer}
.marca-check input{width:18px;height:18px;accent-color:var(--laranja)}
.situacao{display:grid;grid-template-columns:auto 1fr;gap:10px 18px;margin-bottom:30px;font:400 13px/1.4 var(--f-mono)}
.situacao dt{color:var(--poeira)}
.situacao dd{display:flex;align-items:center;gap:9px}
.situacao .ok{color:var(--ouro)}
.situacao .ruim{color:#FDBA74}
.acoes{display:flex;flex-direction:column;gap:16px;margin-bottom:34px}
.acao{display:grid;grid-template-columns:auto 1fr;gap:6px 18px;align-items:center}
.acao .btn{min-width:236px}
.acao p{font-size:13px;line-height:1.55;color:var(--poeira)}
@media (max-width:560px){.acao{grid-template-columns:1fr}.acao .btn{min-width:0;width:100%}}
.registro{list-style:none;font:400 12px/1.5 var(--f-mono)}
.registro li{display:grid;grid-template-columns:88px 1fr;gap:12px;padding:10px 0;border-top:1px solid var(--fio);color:var(--texto-2)}
.registro li::before{content:none}
.registro time{color:var(--laranja)}
.registro .erro-l{color:#FDBA74}
.login{max-width:440px}
.sair{margin-top:24px}

/* senha com olho (padrao dos sites) */
.pw-wrap{position:relative}
.pw-wrap input{padding-right:48px}
.pw-toggle{position:absolute;right:4px;top:50%;transform:translateY(-50%);width:40px;height:40px;display:flex;align-items:center;justify-content:center;background:none;border:0;box-shadow:none;border-radius:0;text-transform:none;letter-spacing:0;color:var(--poeira);cursor:pointer;padding:0}
.pw-toggle[hidden]{display:none}
.pw-toggle:hover{color:var(--laranja)}
.pw-toggle svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.pw-toggle .olho-off{display:none}
.pw-toggle.on .olho-on{display:none}
.pw-toggle.on .olho-off{display:block}

/* ---------- rodape ---------- */
.rodape{background:var(--materia);border-top:1px solid var(--fio);padding:56px 0 40px}
.fontes{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px}
.fonte{padding:18px 20px}
.fonte:hover{transform:translateY(-2px)}
.fonte h4{display:flex;align-items:center;gap:10px;font:600 16px/1.2 var(--f-titulo)}
.fonte p{font-size:13px;line-height:1.55;color:var(--poeira);margin-top:8px;text-align:justify;hyphens:auto;-webkit-hyphens:auto}
.fonte small{display:block;margin-top:10px;font:400 11.5px/1 var(--f-mono);color:var(--laranja)}
.rodape-pe{display:flex;flex-wrap:wrap;justify-content:space-between;gap:10px;margin-top:40px;font:400 11.5px/1.5 var(--f-mono);letter-spacing:.06em;color:rgba(148,163,184,.7)}
.rodape-termos{margin-top:36px;padding:16px 20px;border:1px solid var(--fio);border-left:2px solid var(--laranja);border-radius:var(--r-lg);font-size:13px;line-height:1.6;color:var(--poeira);text-align:justify;hyphens:auto;-webkit-hyphens:auto}
.rodape-termos a{color:var(--laranja);text-underline-offset:3px}

/* menos movimento: para tudo o que gira, flutua, pisca ou corre */
@media (prefers-reduced-motion:reduce){
  .orbita,.mapa-flutua,.flutuante,.ping::after,.pino-pulso,.fita-trilho{animation:none!important}
  .pino-pulso{opacity:0}
  html{scroll-behavior:auto}
  .btn:hover,.bloco:hover{transform:none}
}
</style>
</head>
<body>

<header class="topo">
  <div class="dentro">
    <a class="marca" href="./"><span class="marca-no"><?= alIcone('outro') ?></span><span>Alertas <span class="grad-texto">RR</span></span></a>
    <nav class="topo-nav" aria-label="SeÃ§Ãµes">
      <a href="#lotes">Lotes</a>
      <?php if ($pinos): ?><a href="#mapa">Mapa</a><?php endif; ?>
      <a href="#editais">Editais</a>
      <a href="#painel" class="essencial"><?= $is_admin ? 'Operador â—' : 'Operador' ?></a>
    </nav>
    <span class="vivo" title="Ãšltima conferÃªncia dos lotes: <?= e(alRelativo(alCfg('lotes_ultima'))) ?>"><i class="ping ping-ouro"></i><span>Radar ativo</span></span>
  </div>
</header>

<?php if ($msg): ?><p class="sys" role="status">â€º <?= e($msg) ?></p><?php endif; ?>
<?php if ($erro): ?><p class="sys ruim" role="alert">! <?= e($erro) ?></p><?php endif; ?>

<!-- ============ CAPA ============ -->
<section class="capa">
  <div class="grade" aria-hidden="true"></div>
  <div class="nebulosa n1" aria-hidden="true"></div>
  <div class="nebulosa n2" aria-hidden="true"></div>
  <div class="dentro capa-in">
    <div>
      <p class="cracha"><i class="ping"></i>EdiÃ§Ã£o nÂº <?= $edicao ?> Â· <?= e($hoje_curto) ?></p>
      <h1>Roraima <span class="grad-texto">em leilÃ£o.</span></h1>
      <p class="lead">Lotes de imÃ³veis e veÃ­culos e editais de credenciamento de leiloeiro, colhidos todo dia nas fontes oficiais e nos leiloeiros que atuam no estado. Ã‰ por eles que o <b>TJRR</b>, o <b>TRF1</b>, a <b>Caixa</b> e os bancos vendem o que vai a leilÃ£o aqui.</p>

      <form method="post" class="mira" id="mira">
        <input type="hidden" name="acao" value="salvar_radar">
        <span class="rotulo" id="mira-tit">O que estou procurando</span>
        <fieldset aria-labelledby="mira-tit"<?= $is_admin ? '' : ' disabled' ?>>
          <?php foreach ($cats as $k => $nome): ?>
          <label class="chip">
            <input type="checkbox" name="cat[]" value="<?= e($k) ?>"<?= in_array($k, $sel, true) ? ' checked' : '' ?>>
            <?= alIcone($k) ?><span><?= e($nome) ?></span><span class="chip-n"><?= $conta_cat[$k] ?></span>
          </label>
          <?php endforeach; ?>
        </fieldset>
        <div class="mira-termos">
          <label class="rotulo" for="termos">Vigiar tambÃ©m Â· bairro, condomÃ­nio, rua</label>
          <div class="campo-linha">
            <input type="text" id="termos" name="termos" value="<?= e(implode(', ', $termos)) ?>" placeholder="ex.: Monte Cristo, Cond. Buriti"<?= $is_admin ? '' : ' disabled' ?>>
            <?php if ($is_admin): ?><button type="submit" class="btn">Salvar</button><?php endif; ?>
          </div>
          <p class="nota"><?= $is_admin
              ? 'Separe por vÃ­rgula. Lote que citar um desses termos sobe para o topo e vai para o seu e-mail, seja qual for a categoria. As caixinhas salvam sozinhas.'
              : 'Para mudar a mira, entre na sala do operador, no fim da pÃ¡gina.' ?></p>
        </div>
      </form>
    </div>

    <figure>
      <div class="palco-caixa">
      <div class="palco">
        <div class="orbita orbita-1" aria-hidden="true"></div>
        <div class="orbita orbita-2" aria-hidden="true"></div>
        <div class="mapa-flutua">
          <svg viewBox="-12 -12 <?= $mapa['w'] + 24 ?> <?= $mapa['h'] + 24 ?>" role="group" aria-label="Mapa de Roraima por municÃ­pio">
            <defs>
              <linearGradient id="g-mira" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#EA580C"/><stop offset="1" stop-color="#F7931A"/></linearGradient>
              <pattern id="hachura" width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
                <rect class="hach-fundo" width="6" height="6"/><line class="hach-traco" x1="0" y1="0" x2="0" y2="6"/>
              </pattern>
            </defs>
            <g>
            <?php foreach ($mapa['mun'] as $m):
                $c = $por_mun[$m['nome']];
                $cls = $c['mira'] ? ' tem-mira' : ($c['lotes'] ? ' tem-lote' : '');
                $desc = $m['nome'] . ': ' . $c['mira'] . ' na mira, ' . $c['lotes'] . ' lote(s) em leilÃ£o, ' . $c['editais'] . ' edital(is) aberto(s)'; ?>
              <path class="mun<?= $cls ?>" d="<?= $m['d'] ?>" data-mun="<?= e($m['nome']) ?>" tabindex="0" role="button" aria-pressed="false" aria-label="<?= e($desc) ?>"><title><?= e($desc) ?></title></path>
            <?php endforeach; ?>
            </g>
            <g aria-hidden="true">
            <?php foreach ($mapa['mun'] as $m):
                $c = $por_mun[$m['nome']];
                [$dx, $dy] = $rotulos_desloc[$m['nome']] ?? [0, 0];
                $tem_pino = $c['mira'] > 0;
                if ($c['editais']): ?>
              <circle class="anel" cx="<?= $m['x'] + 15 ?>" cy="<?= $m['y'] - 13 ?>" r="7"/>
              <?php endif;
                if ($tem_pino): ?>
              <circle class="pino-pulso" cx="<?= $m['x'] ?>" cy="<?= $m['y'] - 6 ?>" r="9"/>
              <circle class="pino-ponto" cx="<?= $m['x'] ?>" cy="<?= $m['y'] - 6 ?>" r="9"/>
              <text class="pino-n" x="<?= $m['x'] ?>" y="<?= $m['y'] - 2.5 ?>"><?= $c['mira'] ?></text>
              <?php endif; ?>
              <text class="rot<?= $m['nome'] === 'Boa Vista' ? ' capital' : '' ?><?= $c['mira'] ? ' na-mira' : '' ?>" x="<?= $m['x'] + $dx ?>" y="<?= $m['y'] + $dy + ($tem_pino ? 16 : 3) ?>"><?= e($rotulos_curtos[$m['nome']] ?? $m['nome']) ?></text>
            <?php endforeach; ?>
            </g>
          </svg>
        </div>
      </div>
        <div class="flutuantes">
          <div class="flutuante f1"><span>Na mira</span><b><?= count($mira) ?></b></div>
          <div class="flutuante f2"><span>Lotes em RR</span><b><?= count($lotes) ?></b></div>
          <div class="flutuante f3"><span>Editais abertos</span><b><?= count($abertos) ?></b></div>
        </div>
      </div>
      <figcaption class="legenda">
        <span class="leg leg-mira"><i></i>lote na mira</span>
        <span class="leg leg-lote"><i></i>outros lotes</span>
        <span class="leg leg-edital"><i></i>edital aberto</span>
        <span class="legenda-dica">Toque num municÃ­pio para ver sÃ³ o que estÃ¡ nele.</span>
      </figcaption>
    </figure>
  </div>
</section>

<!-- faixa de fontes -->
<div class="fita" role="region" aria-label="SituaÃ§Ã£o das fontes">
  <div class="fita-trilho">
    <?php for ($v = 0; $v < 2; $v++): ?>
    <div class="fita-grupo"<?= $v ? ' aria-hidden="true"' : '' ?>>
      <?php foreach ($fita as [$nome, $quando, $horas]):
          $aceso = $quando && (time() - strtotime($quando)) < $horas * 3600; ?>
      <span class="fita-item"><i class="ping<?= $aceso ? ' ping-ouro' : ' ping-apagado' ?>"></i><?= e($nome) ?> <small><?= e(alRelativo($quando)) ?></small></span>
      <?php endforeach; ?>
    </div>
    <?php endfor; ?>
  </div>
</div>

<div class="filtro" id="filtro" hidden><div class="filtro-in">Mostrando sÃ³ <b></b><button type="button" class="btn" id="filtro-limpar">Ver tudo âœ•</button></div></div>

<main>
<!-- ============ 01 LOTES ============ -->
<section class="secao" id="lotes">
  <div class="dentro">
    <header class="sec-topo">
      <p class="sec-kicker">// 01 Â· Radar de lotes</p>
      <h2>Na mira</h2>
      <p class="sec-sub"><?= e(ucfirst($frase_mira)) ?></p>
      <span class="sec-conta"><?= count($mira) ?> lote<?= count($mira) === 1 ? '' : 's' ?></span>
    </header>
    <?php if ($termos_sem): ?>
    <p class="vigia"><i class="ping ping-ouro"></i>Vigiando <b><?= e(implode(', ', $termos_sem)) ?></b> Â· nada nas fontes agora. Quando aparecer, vai para o seu e-mail.</p>
    <?php endif; ?>
    <?php if ($mira): ?>
    <div class="lotes"><?php foreach ($mira as $l) echo alCardLote($l, $is_admin); ?></div>
    <p class="filtro-vazio" hidden>â€º nada na mira neste municÃ­pio.</p>
    <?php else: ?>
    <div class="bloco vazio">
      <span class="no-holo"><?= alIcone($sel[0] ?? 'outro') ?></span>
      <div>
        <p class="vazio-grande">Nada na mira em Roraima neste momento.</p>
        <p>O radar confere a WR LeilÃµes, o Amazonas LeilÃµes (por onde leiloam o TJRR e o TRF1), o Spy LeilÃµes e o Garimpia (Caixa, bancos e leiloeiros do paÃ­s) a cada execuÃ§Ã£o do cron. Quando aparecer um lote do que estÃ¡ marcado acima, ele entra aqui e chega no seu e-mail.</p>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($outros): ?>
    <details class="outros" id="outros">
      <summary>
        <span class="outros-tit">Outros lotes em Roraima</span>
        <span class="outros-n"><?= count($outros) ?> fora da mira Â· <?= e(implode(' Â· ', $resumo_outros)) ?></span>
      </summary>
      <div class="lotes"><?php foreach ($outros as $l) echo alCardLote($l, $is_admin); ?></div>
      <p class="filtro-vazio" hidden>â€º nenhum outro lote neste municÃ­pio.</p>
    </details>
    <?php endif; ?>
  </div>
</section>

<?php if ($pinos):
    $n_rua = count(array_filter($pinos, function ($p) { return !$p['aprox']; }));
    $tem_bv = (bool)array_filter($pinos, function ($p) { return $p['mun'] === 'Boa Vista'; }); ?>
<!-- ============ 02 MAPA ============ -->
<section class="secao secao-alt" id="mapa">
  <div class="dentro">
    <header class="sec-topo">
      <p class="sec-kicker">// 02 Â· Mapa dos imÃ³veis</p>
      <h2>Onde fica cada um</h2>
      <p class="sec-sub">Os imÃ³veis do radar no mapa de ruas Â· toque no alfinete para ver valor, data e o link do leiloeiro</p>
      <span class="sec-conta"><?= count($pinos) ?> no mapa</span>
    </header>
    <div class="mapa-barra">
      <div class="vistas" role="group" aria-label="Enquadramento do mapa">
        <?php if ($tem_bv): ?><button type="button" class="vista" data-vista="bv" aria-pressed="true">Boa Vista</button><?php endif; ?>
        <button type="button" class="vista" data-vista="rr" aria-pressed="<?= $tem_bv ? 'false' : 'true' ?>">Roraima inteiro</button>
      </div>
      <div class="mapa-leg">
        <span><i class="ml-mira"></i>na mira</span>
        <span><i class="ml-rua"></i>endereÃ§o achado na rua</span>
        <span><i class="ml-aprox"></i>local aproximado</span>
      </div>
    </div>
    <div class="mapa-ruas" id="mapa-ruas" role="region" aria-label="Mapa de ruas com os imÃ³veis em leilÃ£o em Roraima"></div>
    <p class="nota" id="mapa-nota"><?= count($pinos) ?> imÃ³ve<?= count($pinos) === 1 ? 'l' : 'is' ?> no mapa Â· <?= $n_rua ?> com o endereÃ§o achado na rua<?= count($pinos) - $n_rua ? ' Â· ' . (count($pinos) - $n_rua) . ' em local aproximado (sÃ³ o bairro ou o municÃ­pio, comum em Ã¡rea rural)' : '' ?>. A posiÃ§Ã£o vem do endereÃ§o anunciado e nÃ£o substitui a matrÃ­cula nem o edital.</p>
  </div>
</section>
<?php endif; ?>

<!-- ============ <?= $n_ed ?> EDITAIS ============ -->
<section class="secao<?= $pinos ? '' : ' secao-alt' ?>" id="editais">
  <div class="dentro">
    <header class="sec-topo">
      <p class="sec-kicker">// <?= $n_ed ?> Â· Credenciamento</p>
      <h2>Editais abertos</h2>
      <p class="sec-sub">Chamamentos de leiloeiro oficial e leilÃµes da Receita em Roraima Â· sÃ³ o que ainda estÃ¡ no prazo</p>
      <span class="sec-conta"><?= count($abertos) ?> aberto<?= count($abertos) === 1 ? '' : 's' ?></span>
    </header>

    <?php
    $render_aviso = function (array $ed, $conferir, $n) use ($is_admin) {
        $p = substr((string)$ed['prazo_fim'], 0, 10);
        $dias = $p ? alDias($p) : null;
        $urg = $dias !== null && $dias <= 7;
        ob_start(); ?>
      <li class="<?= $conferir ? 'conferir' : '' ?><?= $urg ? ' urgente' : '' ?>" data-mun-item data-mun="<?= e($ed['mun']) ?>">
        <span class="no" aria-hidden="true"><?= sprintf('%02d', $n) ?></span>
        <article class="bloco edital cantos">
          <div class="edital-cabeca">
            <p class="edital-tipo"><?= e($ed['tipo'] ?: 'Edital') ?><?= $ed['retificacao'] ? ' Â· retificaÃ§Ã£o' : '' ?></p>
            <?php if ($p): ?>
            <span class="prazo"><?php if ($urg): ?><i class="ping"></i><?php endif; ?>Prazo <?= e(date('d/m/Y', strtotime($p))) ?> <small>Â· <?= $dias === 0 ? 'vence hoje' : 'faltam ' . $dias . ' dia' . ($dias === 1 ? '' : 's') ?></small></span>
            <?php else: ?>
            <span class="prazo"><small><?= $conferir ? 'a conferir' : 'sem prazo informado' ?></small></span>
            <?php endif; ?>
          </div>
          <h3><?= e(alNomeOrgao($ed['orgao'])) ?></h3>
          <?php if ($ed['resumo']): ?><p class="edital-resumo"><?= nl2br(e($ed['resumo'])) ?></p><?php endif; ?>
          <?php if ($ed['requisitos']): ?><p class="edital-req"><?= e($ed['requisitos']) ?></p><?php endif; ?>
          <?php if ($conferir && $ed['motivo_revisao']): ?><p class="motivo"><?= e($ed['motivo_revisao']) ?></p><?php endif; ?>
          <div class="edital-meta">
            <?php if ($ed['link']): ?><a class="btn-link" href="<?= e($ed['link']) ?>" target="_blank" rel="noopener">PublicaÃ§Ã£o â†—</a><?php endif; ?>
            <?php if ($ed['link_pdf']): ?><a class="btn-link" href="<?= e($ed['link_pdf']) ?>" target="_blank" rel="noopener">Arquivo â†—</a><?php endif; ?>
            <?php if ($ed['numero']): ?><span>nÂº <?= e($ed['numero']) ?></span><?php endif; ?>
            <?php if ($ed['data_publicacao'] && strtotime($ed['data_publicacao'])): ?><span>publicado em <?= e(date('d/m/Y', strtotime($ed['data_publicacao']))) ?></span><?php endif; ?>
            <?php if ($is_admin && $conferir): ?>
            <form method="post"><input type="hidden" name="acao" value="confirmar"><input type="hidden" name="id" value="<?= (int)$ed['id'] ?>"><button type="submit" class="btn-link">Confirmar</button></form>
            <?php endif; ?>
            <?php if ($is_admin): ?>
            <form method="post" onsubmit="return confirm('Arquivar este edital? Ele sai da lista, mas fica guardado â€” nÃ£o volta a alertar.')"><input type="hidden" name="acao" value="arquivar"><input type="hidden" name="id" value="<?= (int)$ed['id'] ?>"><button type="submit" class="btn-link">Arquivar</button></form>
            <?php endif; ?>
          </div>
        </article>
      </li>
    <?php return ob_get_clean(); };
    ?>

    <?php if ($abertos): ?>
    <ol class="cadeia"><?php foreach ($abertos as $i => $ed) echo $render_aviso($ed, false, $i + 1); ?></ol>
    <p class="filtro-vazio" hidden>â€º nenhum edital aberto neste municÃ­pio.</p>
    <?php else: ?>
    <div class="bloco vazio"><span class="no-holo"><?= alIcone('outro') ?></span><div><p class="vazio-grande">Nenhum edital de credenciamento aberto em Roraima agora.</p><p>As fontes oficiais (PNCP e Receita) sÃ£o conferidas a cada execuÃ§Ã£o, e a busca na web passa pelo DiÃ¡rio Oficial, Folha BV, prefeituras e tribunais uma vez por semana.</p></div></div>
    <?php endif; ?>

    <?php if ($conferir): ?>
    <p class="sub-cadeia">A conferir Â· <?= count($conferir) ?></p>
    <ol class="cadeia"><?php foreach ($conferir as $i => $ed) echo $render_aviso($ed, true, count($abertos) + $i + 1); ?></ol>
    <?php endif; ?>

    <?php if ($guardados): ?>
    <details class="guardados">
      <summary>â€º <?= count($guardados) ?> edita<?= count($guardados) === 1 ? 'l' : 'is' ?> com prazo vencido ou arquivado<?= count($guardados) === 1 ? '' : 's' ?> â€” fora da lista, guardado<?= count($guardados) === 1 ? '' : 's' ?> para nÃ£o alertar de novo</summary>
      <ul>
        <?php foreach ($guardados as $ed): ?>
        <li>
          <span><?= $ed['prazo_fim'] ? e(date('d/m/Y', strtotime($ed['prazo_fim']))) : 'arquivado' ?></span>
          <span><?= e(alNomeOrgao($ed['orgao'])) ?> â€” <?= e($ed['tipo']) ?></span>
          <?php if ($is_admin && !empty($ed['oculto'])): ?>
          <form method="post"><input type="hidden" name="acao" value="restaurar"><input type="hidden" name="id" value="<?= (int)$ed['id'] ?>"><button type="submit" class="btn-link">Restaurar</button></form>
          <?php elseif ($ed['link']): ?><a class="btn-link" href="<?= e($ed['link']) ?>" target="_blank" rel="noopener">ver â†—</a><?php else: ?><span></span><?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
    </details>
    <?php endif; ?>
  </div>
</section>

<!-- ============ <?= $n_op ?> SALA DO OPERADOR ============ -->
<section class="secao painel" id="painel">
  <div class="grade" aria-hidden="true"></div>
  <div class="nebulosa n1" aria-hidden="true"></div>
  <div class="dentro">
    <header class="sec-topo">
      <p class="sec-kicker">// <?= $n_op ?> Â· Acesso restrito</p>
      <h2>Sala do operador</h2>
      <p class="sec-sub"><?= $is_admin ? 'ConfiguraÃ§Ã£o, varreduras manuais e o registro das Ãºltimas execuÃ§Ãµes' : 'Entre para mudar a mira, varrer na hora e configurar a busca' ?></p>
    </header>

    <div class="vidro painel-vidro cantos">
    <?php if (!$is_admin): ?>
      <form method="post" class="login">
        <input type="hidden" name="acao" value="login">
        <div class="campo">
          <label class="rotulo" for="senha">Senha</label>
          <div class="pw-wrap"><input type="password" id="senha" name="senha" autocomplete="current-password" required><?= alOlho() ?></div>
        </div>
        <button type="submit" class="btn">Entrar</button>
      </form>
    <?php else: ?>
      <div class="painel-grade">
        <div>
          <h3>SituaÃ§Ã£o</h3>
          <dl class="situacao">
            <dt>Monitor</dt><dd class="<?= $ativo ? 'ok' : 'ruim' ?>"><i class="ping<?= $ativo ? ' ping-ouro' : ' ping-apagado' ?>"></i><?= $ativo ? 'ativo' : 'desativado' ?></dd>
            <dt>Busca na web</dt><dd class="<?= $tem_chave ? 'ok' : 'ruim' ?>"><?= $provedor === 'claude' ? 'Claude' : 'Gemini' ?> Â· <?= $tem_chave ? 'chave gravada' : 'sem chave â€” a busca com IA nÃ£o roda' ?></dd>
            <dt>Ãšltima busca IA</dt><dd><?= alCfg('ultima_verificacao') ? e(date('d/m/Y H:i', strtotime(alCfg('ultima_verificacao')))) : 'nunca' ?></dd>
            <?php foreach (alLotesFontes() as $f => $nome): ?>
            <dt><?= e($nome) ?></dt><dd><?= e(alRelativo(alCfg('lotes_ok_' . $f))) ?></dd>
            <?php endforeach; ?>
          </dl>

          <h3>Varrer agora</h3>
          <div class="acoes">
            <form method="post" class="acao" data-espera="Lendo as fontesâ€¦">
              <input type="hidden" name="acao" value="varrer_lotes">
              <button type="submit" class="btn">Conferir lotes</button>
              <p>WR, Amazonas, Spy, Garimpia e Receita Â· sem IA, sem custo, atÃ© ~45 s. O que nÃ£o couber, o cron completa.</p>
            </form>
            <form method="post" class="acao" data-espera="Consultando PNCP e Receitaâ€¦">
              <input type="hidden" name="acao" value="verificar_fontes">
              <button type="submit" class="btn btn-contorno">Fontes oficiais</button>
              <p>PNCP e Receita/SLE Â· sem IA, sem custo, ~30 s.</p>
            </form>
            <form method="post" class="acao" data-espera="Buscandoâ€¦ pode dar 504">
              <input type="hidden" name="acao" value="verificar_agora">
              <button type="submit" class="btn btn-contorno">Busca completa com IA</button>
              <p>Inclui a busca na web do modelo. Costuma passar de 60 s e o servidor corta com 504 â€” o que jÃ¡ foi gravado fica. O caminho normal dela Ã© o cron.</p>
            </form>
          </div>

          <h3>Registro</h3>
          <ol class="registro">
            <?php foreach ($logs as $lg): ?>
            <li><time><?= e(date('d/m H:i', strtotime($lg['executado_em'] . ' UTC'))) ?></time><span class="<?= $lg['status'] === 'ok' ? '' : 'erro-l' ?>"><?= e($lg['mensagem']) ?><?= (int)$lg['encontrados'] && strpos($lg['mensagem'], 'Lotes RR') !== 0 ? ' Â· ' . (int)$lg['encontrados'] . ' novo(s)' : '' ?><?= (int)$lg['revisao'] ? ' Â· ' . (int)$lg['revisao'] . ' a conferir' : '' ?></span></li>
            <?php endforeach; ?>
          </ol>
        </div>

        <div>
          <h3>ConfiguraÃ§Ã£o</h3>
          <form method="post">
            <input type="hidden" name="acao" value="salvar_config">
            <div class="campo">
              <label class="rotulo" for="provedor">Provedor da busca na web</label>
              <select id="provedor" name="provedor">
                <option value="gemini"<?= $provedor === 'gemini' ? ' selected' : '' ?>>Gemini (busca do Google)</option>
                <option value="claude"<?= $provedor === 'claude' ? ' selected' : '' ?>>Claude (web search)</option>
              </select>
            </div>
            <div class="campo">
              <label class="rotulo" for="apikey_gem">Chave da API Gemini</label>
              <div class="pw-wrap"><input type="password" id="apikey_gem" name="gemini_api_key" autocomplete="off" placeholder="<?= $tem_key_gem ? 'â€¢â€¢â€¢â€¢â€¢â€¢â€¢â€¢ gravada â€” preencha sÃ³ para trocar' : 'AIzaâ€¦ ou AQâ€¦' ?>"><?= alOlho() ?></div>
            </div>
            <div class="campo">
              <label class="rotulo" for="modelo_gemini">Modelo Gemini</label>
              <?php $modg = alCfg('modelo_gemini') ?: 'gemini-2.5-flash'; ?>
              <select id="modelo_gemini" name="modelo_gemini">
                <option value="gemini-2.5-flash"<?= $modg === 'gemini-2.5-flash' ? ' selected' : '' ?>>gemini-2.5-flash (recomendado)</option>
                <option value="gemini-2.5-pro"<?= $modg === 'gemini-2.5-pro' ? ' selected' : '' ?>>gemini-2.5-pro (mÃ¡ximo)</option>
              </select>
            </div>
            <div class="campo">
              <label class="rotulo" for="apikey">Chave da API Anthropic</label>
              <div class="pw-wrap"><input type="password" id="apikey" name="anthropic_api_key" autocomplete="off" placeholder="<?= $tem_key ? 'â€¢â€¢â€¢â€¢â€¢â€¢â€¢â€¢ gravada â€” preencha sÃ³ para trocar' : 'sk-ant-api03-â€¦' ?>"><?= alOlho() ?></div>
            </div>
            <div class="campo">
              <label class="rotulo" for="modelo">Modelo Claude</label>
              <?php $mod = alCfg('modelo') ?: 'claude-sonnet-4-6'; ?>
              <select id="modelo" name="modelo">
                <option value="claude-sonnet-4-6"<?= $mod === 'claude-sonnet-4-6' ? ' selected' : '' ?>>claude-sonnet-4-6 (recomendado)</option>
                <option value="claude-haiku-4-5-20251001"<?= $mod === 'claude-haiku-4-5-20251001' ? ' selected' : '' ?>>claude-haiku-4-5 (econÃ´mico)</option>
                <option value="claude-opus-4-8"<?= $mod === 'claude-opus-4-8' ? ' selected' : '' ?>>claude-opus-4-8 (mÃ¡ximo)</option>
              </select>
            </div>
            <label class="marca-check"><input type="checkbox" name="ativo"<?= $ativo ? ' checked' : '' ?>> Monitor ativo (cron)</label>
            <label class="marca-check"><input type="checkbox" name="email_notif"<?= alCfg('email_notif') ? ' checked' : '' ?>> Avisar por e-mail (<?= e(ALERTAS_EMAIL_DESTINO) ?>)</label>
            <button type="submit" class="btn" style="margin-top:16px">Gravar configuraÃ§Ã£o</button>
          </form>
          <form method="post" class="sair"><input type="hidden" name="acao" value="logout"><button type="submit" class="btn btn-fantasma">Sair</button></form>
        </div>
      </div>
    <?php endif; ?>
    </div>
  </div>
</section>
</main>

<footer class="rodape">
  <div class="dentro">
    <div class="fontes">
      <div class="bloco fonte"><h4><i class="ping ping-ouro"></i>WR LeilÃµes</h4><p>Leiloeiro de Boa Vista: TJRR, DETRAN-RR, JustiÃ§a Eleitoral, ExÃ©rcito.</p><small>conferido <?= e(alRelativo(alCfg('lotes_ok_wr'))) ?></small></div>
      <div class="bloco fonte"><h4><i class="ping ping-ouro"></i>Amazonas LeilÃµes</h4><p>Onde leiloam o Tribunal de JustiÃ§a de Roraima e o TRF 1Âª RegiÃ£o (SeÃ§Ã£o de Roraima).</p><small>conferido <?= e(alRelativo(alCfg('lotes_ok_amazonas'))) ?></small></div>
      <div class="bloco fonte"><h4><i class="ping ping-ouro"></i>Spy LeilÃµes</h4><p>Agregador com os imÃ³veis da Caixa, de bancos e de leiloeiros do paÃ­s, inclusive venda direta.</p><small>conferido <?= e(alRelativo(alCfg('lotes_ok_spy'))) ?></small></div>
      <div class="bloco fonte"><h4><i class="ping ping-ouro"></i>Garimpia</h4><p>Agregador de imÃ³veis de leilÃ£o: Caixa, Banco do Brasil, Resale, Pestana e outros.</p><small>conferido <?= e(alRelativo(alCfg('lotes_ok_garimpia'))) ?></small></div>
      <div class="bloco fonte"><h4><i class="ping ping-ouro"></i>Receita Federal</h4><p>Lotes dos leilÃµes da Receita em Boa Vista, Pacaraima e Bonfim: veÃ­culos, mercadorias apreendidas e outros bens.</p><small>conferido <?= e(alRelativo(alCfg('lotes_ok_receita'))) ?></small></div>
      <div class="bloco fonte"><h4><i class="ping ping-ouro"></i>PNCP e Receita</h4><p>Credenciamentos no Portal Nacional de ContrataÃ§Ãµes e leilÃµes da Receita Federal em RR.</p><small>conferido <?= e(alRelativo(alCfg('fontes_ok'))) ?></small></div>
      <div class="bloco fonte"><h4><i class="ping ping-ouro"></i>Busca na web</h4><p>DiÃ¡rio Oficial, Folha BV, JUCERR, tribunais, prefeituras e cÃ¢maras de RR, uma vez por semana.</p><small>Ãºltima <?= e(alRelativo(alCfg('ultima_verificacao'))) ?></small></div>
    </div>
    <p class="rodape-termos">O uso desta pÃ¡gina estÃ¡ sujeito aos <a href="<?= e(ALERTAS_URL) ?>/termos">Termos de Uso</a>. A consulta Ã© pessoal: Ã© proibido copiar, republicar ou repassar o conteÃºdo a terceiros â€” em grupos, redes sociais, planilhas ou outros sites â€” sem autorizaÃ§Ã£o por escrito.</p>
    <p class="rodape-pe"><span>ALERTAS RR Â· monitor de leilÃµes e editais de leiloeiro em Roraima Â· <a href="<?= e(ALERTAS_URL) ?>/termos">Termos de Uso</a></span><span>Mapas: IBGE, malha municipal Â· ruas Â© OpenStreetMap Â· <?= date('Y') ?></span></p>
  </div>
</footer>

<?php if ($pinos): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js" integrity="sha512-puJW3E/qXDqYp9IfhAI54BJEaWIfloJ7JWs7OeD5i6ruC9JZL1gERT1wjtwXFlh7CjE7ZJ+/vcRZRkIYIb6p4g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<?php endif; ?>
<script>
(function () {
  // Caixinhas da mira salvam sozinhas (so para o operador)
  var mira = document.getElementById('mira');
  if (mira && !mira.querySelector('fieldset[disabled]')) {
    mira.addEventListener('change', function (ev) {
      if (ev.target.name === 'cat[]') { mira.style.opacity = '.6'; mira.submit(); }
    });
  }

  // Botoes que demoram: trava e avisa
  document.querySelectorAll('form[data-espera]').forEach(function (f) {
    f.addEventListener('submit', function () {
      var b = f.querySelector('button[type=submit]');
      if (b) { b.disabled = true; b.textContent = f.getAttribute('data-espera'); }
    });
  });

  // Olho da senha
  document.querySelectorAll('.pw-wrap').forEach(function (w) {
    var i = w.querySelector('input'), b = w.querySelector('.pw-toggle');
    if (!i || !b) return;
    b.hidden = false;
    b.addEventListener('click', function () {
      var ini = i.selectionStart, fim = i.selectionEnd, mostra = i.type === 'password';
      i.type = mostra ? 'text' : 'password';
      b.classList.toggle('on', mostra);
      b.setAttribute('aria-pressed', mostra ? 'true' : 'false');
      b.setAttribute('aria-label', mostra ? 'Ocultar senha' : 'Mostrar senha');
      b.title = mostra ? 'Ocultar senha' : 'Mostrar senha';
      i.focus();
      try { i.setSelectionRange(ini, fim); } catch (e) {}
    });
  });

  // Filtro pelo mapa
  var filtro = document.getElementById('filtro'), atual = null;
  function filtrar(m) {
    atual = (m && m !== atual) ? m : null;
    document.querySelectorAll('[data-mun-item]').forEach(function (el) {
      el.hidden = !!atual && el.getAttribute('data-mun') !== atual;
    });
    document.querySelectorAll('.mun').forEach(function (p) {
      var on = p.getAttribute('data-mun') === atual;
      p.classList.toggle('ativo', on);
      p.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    document.documentElement.classList.toggle('filtrando', !!atual);
    filtro.hidden = !atual;
    if (atual) filtro.querySelector('b').textContent = atual;
    document.querySelectorAll('.filtro-vazio').forEach(function (v) {
      var caixa = v.previousElementSibling;
      v.hidden = !atual || !caixa || !!caixa.querySelector('[data-mun-item]:not([hidden])');
    });
    var outros = document.getElementById('outros');
    if (atual && outros && outros.querySelector('[data-mun-item]:not([hidden])')) outros.open = true;
    filtrarMapa(atual);
    if (atual) document.getElementById('lotes').scrollIntoView({behavior: 'smooth', block: 'start'});
  }
  document.querySelectorAll('.mun').forEach(function (p) {
    p.addEventListener('click', function () { filtrar(p.getAttribute('data-mun')); });
    p.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); filtrar(p.getAttribute('data-mun')); }
    });
  });
  document.getElementById('filtro-limpar').addEventListener('click', function () { filtrar(null); });

  // Mapa de ruas (Leaflet + OpenStreetMap). So monta quando a secao chega
  // perto da tela - quem nao rola ate la nao baixa nenhum pedaco de mapa.
  var PINOS = <?= json_encode($pinos, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
  var caixa = document.getElementById('mapa-ruas');
  var reduz = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  var mapa = null, marcas = {}, grupos = [], vistas = {}, vista = null;

  function enquadrar(v) {
    vista = v;
    document.querySelectorAll('.vista').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-vista') === v ? 'true' : 'false'); });
    if (!mapa || !vistas[v]) return;
    mapa.closePopup();
    if (reduz) mapa.fitBounds(vistas[v], {maxZoom: 14});
    else mapa.flyToBounds(vistas[v], {maxZoom: 14, duration: .8});
  }

  function montarMapa() {
    if (mapa || !caixa || !window.L || !PINOS.length) return mapa;
    var movel = L.Browser.mobile;
    // No celular um dedo rola a pagina; dois dedos mexem no mapa.
    mapa = L.map(caixa, {scrollWheelZoom: false, dragging: !movel, zoomSnap: .5});
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>'
    }).addTo(mapa);
    mapa.on('click', function () { mapa.scrollWheelZoom.enable(); }); // roda do mouse so depois de clicar no mapa
    caixa.addEventListener('mouseleave', function () { mapa.scrollWheelZoom.disable(); });

    var porPonto = {}, todos = [], bv = [];
    PINOS.forEach(function (p) { var k = p.lat + ',' + p.lon; (porPonto[k] = porPonto[k] || []).push(p); });
    Object.keys(porPonto).forEach(function (k) {
      var g = porPonto[k], p = g[0];
      var aprox = g.every(function (x) { return x.aprox; }), naMira = g.some(function (x) { return x.mira; });
      var icone = L.divIcon({className: 'pino-icone', iconSize: [26, 26], iconAnchor: [13, 13], popupAnchor: [0, -10],
        html: '<span class="pino' + (aprox ? ' aprox' : '') + (naMira ? ' mira' : '') + (g.length > 1 ? ' varios' : '') + '">' + (g.length > 1 ? g.length : '') + '</span>'});
      var titulo = g.map(function (x) { return x.tit; }).join(' / ');
      var m = L.marker([p.lat, p.lon], {icon: icone, title: titulo, alt: titulo, riseOnHover: true, zIndexOffset: naMira ? 500 : 0}).addTo(mapa);
      m.bindPopup(g.map(function (x) { return x.html; }).join(''), {maxWidth: 300, minWidth: 260, autoPanPaddingTopLeft: [16, 90], autoPanPaddingBottomRight: [16, 16]});
      m.alMuns = g.map(function (x) { return x.mun; });
      m.alAprox = aprox;
      g.forEach(function (x) { marcas[x.id] = m; });
      grupos.push(m);
      todos.push([p.lat, p.lon]);
      if (p.mun === 'Boa Vista') bv.push([p.lat, p.lon]);
    });
    vistas.rr = L.latLngBounds(todos).pad(.12);
    // Boa Vista: a mancha urbana inteira, mesmo com poucos alfinetes
    if (bv.length) vistas.bv = L.latLngBounds([[2.765, -60.785], [2.865, -60.615]]).extend(bv);
    mapa.fitBounds(vistas[vista && vistas[vista] ? vista : (vistas.bv ? 'bv' : 'rr')], {maxZoom: 14});
    if (movel) document.getElementById('mapa-nota').insertAdjacentText('beforeend', ' No celular, use dois dedos para mover e aproximar o mapa.');
    if (atual) filtrarMapa(atual);
    return mapa;
  }

  // Toque num municipio (mapa de cima): o de ruas apaga os outros e enquadra o escolhido.
  function filtrarMapa(m) {
    if (!mapa) return;
    var alvo = [];
    grupos.forEach(function (mk) {
      var dentro = !m || mk.alMuns.indexOf(m) >= 0;
      mk.setOpacity(dentro ? 1 : .2);
      if (m && dentro) alvo.push(mk.getLatLng());
    });
    mapa.closePopup();
    if (m && alvo.length) mapa.fitBounds(L.latLngBounds(alvo).pad(.4), {maxZoom: 14});
    else if (!m) enquadrar(vista || (vistas.bv ? 'bv' : 'rr'));
  }

  if (caixa && PINOS.length) {
    document.querySelectorAll('.vista').forEach(function (b) {
      b.addEventListener('click', function () { montarMapa(); enquadrar(b.getAttribute('data-vista')); });
    });
    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (es) {
        if (es.some(function (x) { return x.isIntersecting; })) { io.disconnect(); montarMapa(); }
      }, {rootMargin: '400px 0px'});
      io.observe(caixa);
    } else montarMapa();

    // "no mapa" no card: desce ate o mapa e abre o balao daquele lote
    document.querySelectorAll('.ver-mapa').forEach(function (a) {
      a.addEventListener('click', function (ev) {
        ev.preventDefault();
        document.getElementById('mapa').scrollIntoView({behavior: reduz ? 'auto' : 'smooth', block: 'start'});
        if (!montarMapa()) return;
        var mk = marcas[a.getAttribute('data-pino')];
        if (!mk) return;
        mk.setOpacity(1);
        mapa.setView(mk.getLatLng(), mk.alAprox ? 12 : 16, {animate: !reduz});
        setTimeout(function () { mk.openPopup(); }, reduz ? 0 : 450);
      });
    });
  }
})();
</script>
</body>
</html>
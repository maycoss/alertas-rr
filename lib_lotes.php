<?php
/**
 * ALERTAS RR — RADAR DE LOTES EM RORAIMA
 *
 * Tres fontes, todas lidas direto (sem IA, sem chave, sem custo):
 *
 *  - Amazonas Leiloes: a API JSON que a propria busca do site usa. E por ela
 *    que o TJRR e o TRF1/SJRR leiloam - os dois tribunais NAO tem leilao no
 *    proprio site, entram como "comitente" na plataforma (conferido em
 *    08/10/2026). Cuidado: o campo de estado da API vem errado em TODOS os
 *    lotes ("AC/Acrelandia"); Roraima se reconhece pelo texto do lote, pelo
 *    comitente e pelo numero do processo.
 *  - WR Leiloes: leiloeiro de Boa Vista (TJRR, DETRAN-RR, Justica Eleitoral,
 *    Exercito). Desde 09/11/2026 o site e um app: so o LEILAO aberto (sitemap).
 *  - Spy Leiloes: agregador que junta Caixa, bancos e leiloeiros do pais. E o
 *    unico caminho ate os imoveis da Caixa - o site da Caixa barra robo com
 *    captcha (testado em 08/10/2026), e captcha nao se contorna. O robots.txt
 *    do Spy libera tudo menos /api/ e pede 1s entre pedidos; cada imovel tem
 *    uma versao .md feita para ser lida por maquina.
 *  - Garimpia (09/10/2026) e Receita Federal/SLE (16/10/2026): ver as secoes
 *    de cada uma, mais abaixo.
 *
 * O lote fica guardado mesmo depois de sumir da tela: o hash (fonte|id) e o
 * que impede o mesmo lote de alertar duas vezes.
 */

define('AL_LOTES_UA',  'Mozilla/5.0 (compatible; AlertasRR/1.0; +' . ALERTAS_URL . ')');
define('AL_AM_BASE',  'https://www.amazonasleiloes.com.br/');
define('AL_WR_BASE',  'https://www.wrleiloes.com.br');
define('AL_SPY_BASE', 'https://spyleiloes.com.br');

/*
 * Tempo total da varredura de lotes. No cron (CLI) sobra: so o Spy pede 1s
 * entre pedidos e sao ~15 imoveis. No botao do painel o proxy da Hostinger
 * corta em ~60s, entao para antes - o que ficar de fora o cron pega.
 */
define('AL_LOTES_ORCAMENTO', php_sapi_name() === 'cli' ? 240 : 45);

// Lote que some da fonte continua na tela por 2 dias (uma pagina que falhou
// numa varredura nao pode apagar o lote).
define('AL_LOTES_TOLERANCIA', 2 * 86400);

function alLotesCategorias() {
    return [
        'apartamento' => 'Apartamentos',
        'casa'        => 'Casas',
        'terreno'     => 'Terrenos',
        'predio'      => 'Prédios e galpões',
        'imovel'      => 'Imóveis (todos)',
        'veiculo'     => 'Veículos',
    ];
}

// Nome no singular, para o rotulo do lote.
function alLoteCatNome($cat) {
    $n = ['apartamento' => 'Apartamento', 'casa' => 'Casa', 'terreno' => 'Terreno',
          'predio' => 'Prédio / galpão', 'imovel' => 'Imóvel', 'veiculo' => 'Veículo', 'outro' => 'Outros bens'];
    return $n[$cat] ?? 'Outros bens';
}

// "Imoveis (todos)" marcado cobre qualquer tipo de imovel.
function alLotesFamiliaImovel() {
    return ['apartamento', 'casa', 'terreno', 'predio', 'imovel'];
}

function alLotesFontes() {
    return [
        'amazonas'  => 'Amazonas Leilões',
        'wr'        => 'WR Leilões',
        'spy'       => 'Spy Leilões',
        'garimpia'  => 'Garimpia',
        'receita'   => 'Receita Federal (SLE)',
        'publicjud' => 'PublicJud',
    ];
}

function alLotesSelecao() {
    $v = (string)alCfg('lotes_categorias');
    $ok = array_keys(alLotesCategorias());
    return array_values(array_intersect($ok, array_filter(array_map('trim', explode(',', $v)))));
}

// Termos vigiados: qualquer lote que cite um deles acende e vai pro e-mail,
// seja qual for a categoria. Nasceu com "Monte Cristo" (08/10/2026).
function alLotesTermos() {
    $v = (string)alCfg('lotes_termos');
    $t = array_map('trim', preg_split('/[,;\n]+/', $v));
    return array_values(array_unique(array_filter($t, 'strlen')));
}

function alLoteNaSelecao($cat, array $sel) {
    if (in_array($cat, $sel, true)) return true;
    return in_array('imovel', $sel, true) && in_array($cat, alLotesFamiliaImovel(), true);
}

function alLoteTermo(array $l, array $termos) {
    if (!$termos) return '';
    $alvo = alNormTxt(($l['titulo'] ?? '') . ' ' . ($l['descricao'] ?? '') . ' ' . ($l['bairro'] ?? '') . ' ' . ($l['leilao'] ?? ''));
    foreach ($termos as $t) {
        $n = alNormTxt($t);
        if ($n !== '' && strpos($alvo, $n) !== false) return $t;
    }
    return '';
}

// ---------------------------------------------------------------
// TEXTO: normalizar, reconhecer Roraima, municipio e categoria
// ---------------------------------------------------------------
function alNormTxt($s) {
    $s = strtr((string)$s, ['ü' => 'u', 'Ü' => 'U', 'ñ' => 'n', 'Ñ' => 'N', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'î' => 'i', 'û' => 'u']);
    return trim(preg_replace('/\s+/u', ' ', alSleNorm($s)));
}

function alMunicipiosRR() {
    // chave = como aparece normalizado; valor = nome oficial (IBGE)
    return [
        'SAO JOAO DA BALIZA' => 'São João da Baliza', 'SAO LUIZ DO ANAUA' => 'São Luiz do Anauá',
        'SAO LUIS DO ANAUA' => 'São Luiz do Anauá', 'SAO LUIZ' => 'São Luiz do Anauá',
        'RORAINOPOLIS' => 'Rorainópolis', 'ALTO ALEGRE' => 'Alto Alegre', 'CARACARAI' => 'Caracaraí',
        'PACARAIMA' => 'Pacaraima', 'NORMANDIA' => 'Normandia', 'UIRAMUTA' => 'Uiramutã',
        'AMAJARI' => 'Amajari', 'CAROEBE' => 'Caroebe', 'MUCAJAI' => 'Mucajaí', 'IRACEMA' => 'Iracema',
        'BONFIM' => 'Bonfim', 'CANTA' => 'Cantá', 'BOA VISTA' => 'Boa Vista',
    ];
}

/*
 * E de Roraima? Sinais, do mais forte ao mais fraco:
 *  - "RR" como palavra ("Canta/RR", "Boa Vista - RR") ou "Roraima";
 *  - numero de processo de tribunal de RR (CNJ): TJRR = .8.23., TRE-RR = .6.23.,
 *    Justica Federal em RR = .4.01.42xx, Varas do Trabalho de Boa Vista = .5.11.005x.
 *    Foi assim que os veiculos do TRF1 no Amazonas Leiloes se revelaram de RR:
 *    o texto nao diz a cidade, o processo diz;
 *  - municipio que so existe em RR (Boa Vista, Bonfim e Iracema existem em
 *    outros estados e por isso NAO bastam sozinhos);
 *  - unidades militares sediadas em Boa Vista que leiloam pela WR.
 */
function alEhRR($texto) {
    $t = ' ' . alNormTxt($texto) . ' ';
    if (preg_match('~[^A-Z]RR[^A-Z]~', $t)) return true;
    if (strpos($t, 'RORAIMA') !== false) return true;
    if (preg_match('~\d{7}-\d{2}\.\d{4}\.(8\.23\.|6\.23\.|4\.01\.42\d\d|5\.11\.005\d)~', $t)) return true;
    foreach (['AMAJARI', 'CANTA', 'CARACARAI', 'CAROEBE', 'MUCAJAI', 'PACARAIMA', 'RORAINOPOLIS',
              'SAO JOAO DA BALIZA', 'SAO LUIZ DO ANAUA', 'UIRAMUTA',
              'BATALHAO LOGISTICO DE SELVA', 'BRIGADA DE INFANTARIA DE SELVA'] as $m) {
        if (preg_match('~[^A-Z]' . $m . '[^A-Z]~', $t)) return true;
    }
    return false;
}

function alMunicipioRR($texto) {
    $t = ' ' . alNormTxt($texto) . ' ';
    $mun = alMunicipiosRR();
    // 1) o que vem colado no "/RR" e o endereco do bem
    foreach ($mun as $k => $nome) {
        if (preg_match('~[^A-Z]' . $k . '\s*[/-]\s*RR[^A-Z]~', $t)) return $nome;
    }
    // 2) qualquer municipio citado; Boa Vista por ultimo - aparece como comarca
    //    ou sede de orgao em quase tudo, mesmo quando o bem esta no interior
    foreach ($mun as $k => $nome) {
        if ($k !== 'BOA VISTA' && preg_match('~[^A-Z]' . $k . '[^A-Z]~', $t)) return $nome;
    }
    return strpos($t, 'BOA VISTA') !== false ? 'Boa Vista' : '';
}

/*
 * Categoria pelo TEXTO do lote. O rotulo da plataforma nao serve: no Amazonas
 * Leiloes um apartamento vem como "Comerciais". A ordem importa - apartamento
 * antes de predio ("apto no Edificio X"), predio antes de casa.
 * $dica = tipo informado pela fonte, usado so quando o texto nao diz nada.
 */
function alClassificarLote($texto, $dica = '') {
    $t = ' ' . alNormTxt($texto) . ' ';
    $re = function ($p) use ($t) { return preg_match('~' . $p . '~u', $t) === 1; };
    $pal = function (array $ps) { return '(?<![A-Z])(' . implode('|', $ps) . ')(?![A-Z])'; };

    if ($re($pal(['APARTAMENTO', 'APARTAMENTOS', 'APTO', 'KITNET', 'KITINETE', 'QUITINETE', 'FLAT', 'UNIDADE AUTONOMA']))
        || $re('(?<![A-Z])APT\.?\s?\d')
        || ($re('\d+\s*[º°O]?\s*ANDAR(?![A-Z])') && !$re($pal(['SALA', 'SALAS', 'LOJA', 'CONJUNTO COMERCIAL'])))) return 'apartamento';
    if ($re($pal(['PREDIO', 'PREDIOS', 'EDIFICIO', 'GALPAO', 'GALPOES', 'PAVILHAO']))) return 'predio';
    if ($re($pal(['CASA', 'CASAS', 'RESIDENCIA', 'SOBRADO', 'CHALE', 'IMOVEL RESIDENCIAL']))) return 'casa';
    if ($re($pal(['TERRENO', 'TERRENOS', 'GLEBA', 'CHACARA', 'SITIO', 'FAZENDA', 'IMOVEL RURAL', 'AREA RURAL',
                  'LOTE RURAL', 'LOTE URBANO', 'AREA DE TERRAS?', 'LOTES? DE TERRAS?', 'TERRAS RURAL']))) return 'terreno';
    if ($re($pal(['IMOVEL', 'IMOVEIS', 'SALA', 'SALA COMERCIAL', 'SALAS COMERCIAIS', 'LOJA', 'PONTO COMERCIAL', 'BENFEITORIA', 'MATRICULA']))
        || $re('\d\s*(M²|M2|HA)(?![A-Z0-9])')) return 'imovel';
    if ($re($pal(['VEICULO', 'VEICULOS', 'AUTOMOVEL', 'CARRO', 'MOTOCICLETA', 'MOTONETA', 'MOTO', 'CAMINHAO',
                  'CAMINHONETE', 'CAMIONETE', 'ONIBUS', 'MICRO-?ONIBUS', 'REBOQUE', 'SEMI-?R?REBOQUE', 'TRATOR',
                  'PLACA', 'CHASSI', 'RENAVAM', 'EMBARCACAO', 'LANCHA', 'BARCO', 'QUADRICICLO', 'MOTOR DE POPA']))
        || $re('(?<!\d)(19|20)\d\d\s*/\s*(19|20)\d\d(?!\d)')) return 'veiculo';
    // "MARCA/Modelo" e o jeito da WR (e do DETRAN) escrever veiculo - caminhao sem ano vinha como "outros"
    if ($re('(?<![A-Z])(TOYOTA|VW|VOLKSWAGEN|FORD|CHEVROLET|GM|FIAT|HONDA|YAMAHA|MERCEDES|MERCEDES BENZ|M\.?BENZ|MITSUBISHI|MMC|RENAULT|HYUNDAI|NISSAN|JEEP|CHERY|KIA|PEUGEOT|CITROEN|SUZUKI|LAND ROVER|SSANGYONG|VOLVO|SCANIA|IVECO|AGRALE|JAC|BMW|AUDI|DAFRA|SHINERAY|KAWASAKI|TRAXX|MARCOPOLO|TROLLER|DODGE|RAM|BYD|CAOA|LIFAN|HAOJUE|SUNDOWN|KASINSKI)\s*/')) return 'veiculo';

    $d = alNormTxt($dica);
    if (strpos($d, 'APARTAMENTO') !== false) return 'apartamento';
    if (strpos($d, 'CASA') !== false) return 'casa';
    if (strpos($d, 'TERRENO') !== false || strpos($d, 'RURAL') !== false) return 'terreno';
    if (strpos($d, 'IMOV') !== false || strpos($d, 'COMERCIAL') !== false) return 'imovel';
    return 'outro';
}

// "R$ 221.600,00" -> 221600.0
function alMoeda($s) {
    if (!preg_match('~([\d.]+(?:,\d{1,2})?)~', (string)$s, $m)) return 0.0;
    return (float)str_replace(',', '.', str_replace('.', '', $m[1]));
}

// ---------------------------------------------------------------
// HTTP
// ---------------------------------------------------------------
function alHttp($url, $json = null) {
    $ch = curl_init($url);
    $hdr = ['Accept-Language: pt-BR,pt;q=0.9'];
    $opt = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => AL_LOTES_UA,
    ];
    if ($json !== null) {
        $opt[CURLOPT_POST]       = true;
        $opt[CURLOPT_POSTFIELDS] = json_encode($json);
        $hdr[] = 'Content-Type: application/json; charset=utf-8';
    }
    $opt[CURLOPT_HTTPHEADER] = $hdr;
    curl_setopt_array($ch, $opt);
    $r    = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($r !== false && $code >= 200 && $code < 300) ? $r : null;
}

function alTxtHtml($h) {
    $h = preg_replace('~<(script|style)[^>]*>.*?</\1>~si', ' ', (string)$h);
    $h = preg_replace('~<(br|/p|/div|/li|/h\d)[^>]*>~i', "\n", $h);
    $t = html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace('/[ \t\x{00A0}]+/u', ' ', $t);
    return trim(preg_replace('/\n\s*\n+/', "\n", $t));
}

// ---------------------------------------------------------------
// FONTE 1: AMAZONAS LEILOES (API da plataforma)
// ---------------------------------------------------------------
function alAmData($s) {
    $t = strtotime((string)$s);
    return ($t && (int)date('Y', $t) > 2000) ? date('Y-m-d H:i', $t) : '';
}

/*
 * Devolve [itens de RR, completo?]. A busca da plataforma pagina em dois
 * niveis: Pagina (24 por pagina) e PaginaIndex (blocos de 8 que o site carrega
 * rolando). Sem filtro de estado de proposito - o estado da API e lixo.
 */
function alLotesAmazonas($limite) {
    $corpo = ['RangeValores' => 0, 'Scopo' => 0, 'IgnoreScopo' => 0, 'OrientacaoBusca' => 0, 'Mapa' => '',
              'Busca' => '', 'ID_Categoria' => 0, 'ID_Estado' => 0, 'ID_Cidade' => 0, 'Bairro' => '',
              'ID_Regiao' => 0, 'ValorMinSelecionado' => 0, 'ValorMaxSelecionado' => 0, 'CFGs' => '',
              'Pagina' => 1, 'sInL' => '', 'Ordem' => 0, 'OrdSt' => 0, 'QtdPorPagina' => 24, 'SubStatus' => [],
              'ID_Leiloes_Status' => [], 'PaginaIndex' => 1, 'BuscaProcesso' => '', 'NomesPartes' => '',
              'CodLeilao' => '', 'TiposLeiloes' => [], 'PracaAtual' => 0, 'DataAbertura' => '',
              'DataEncerramento' => '', 'Filtro' => new stdClass()];
    $brutos = []; $completo = true; $total = 0;
    for ($p = 1; $p <= 20; $p++) {
        $max = 1;
        for ($i = 1; $i <= $max; $i++) {
            if (microtime(true) > $limite) { $completo = false; break 2; }
            $corpo['Pagina'] = $p; $corpo['PaginaIndex'] = $i;
            $r = alHttp(AL_AM_BASE . "ApiEngine/GetBusca/$p/$i/0", $corpo);
            $j = $r !== null ? json_decode($r, true) : null;
            if (!is_array($j) || !array_key_exists('Lotes', $j)) {
                if ($p === 1 && $i === 1) throw new Exception('API nao respondeu');
                $completo = false; break 2;
            }
            $total = (int)($j['CountTotal'] ?? 0);
            $max   = max(1, (int)($j['PageIndexMax'] ?? 1));
            foreach (($j['Lotes'] ?? []) as $l) $brutos[(int)$l['ID_Leiloes_Lote']] = $l;
        }
        if (count($brutos) >= $total) break;
    }

    $itens = [];
    foreach ($brutos as $l) {
        $lote = trim((string)($l['Lote'] ?? ''));
        $partes = array_values(array_filter(array_map('trim', explode('|', $lote)), 'strlen'));
        $desc = implode(' · ', $partes);
        $proc = '';
        foreach (($l['CFGForms'] ?? []) as $c) $proc .= ' ' . ($c['Label'] ?? '') . ' ' . ($c['Value'] ?? '') . ' ';
        $texto = $desc . ' | ' . ($l['Leilao'] ?? '') . ' | ' . ($l['Comitente'] ?? '') . ' | ' . $proc;
        if (strpos(alNormTxt($texto), 'SIMULADOR') !== false) continue; // lote de treino do TJRR
        if (!alEhRR($texto)) continue;

        $rt = $l['GetLoteRealTime'][0] ?? [];
        $pracas = [];
        foreach (['Primeira', 'Segunda', 'Terceira'] as $k => $n) {
            $fim = alAmData($rt["DataHoraEncerramento{$n}PracaFormat"] ?? ($rt["DataHoraEncerramento{$n}Praca"] ?? ''));
            // Leilao com sessao marcada (o unificado do TRF1, 10/2026) so preenche a
            // ABERTURA de cada praca - o encerramento vem 1900 e o card mostrava a 1a
            // praca, ja passada, no lugar da 2a.
            if ($fim === '') $fim = alAmData($rt["DataHoraAbertura{$n}PracaFormat"] ?? ($rt["DataHoraAbertura{$n}Praca"] ?? ''));
            if ($fim === '') continue;
            $pracas[] = ['rotulo' => trim((string)($rt["Label{$n}Praca"] ?? '')) ?: ($k + 1) . 'º leilão',
                         'data'   => $fim,
                         'valor'  => (float)($rt["ValorMinimoLance{$n}Praca"] ?? 0)];
        }
        $agora = date('Y-m-d H:i');
        $prox = ''; $prox_rot = ''; $fim = '';
        foreach ($pracas as $pr) {
            $fim = max($fim, $pr['data']);
            if ($prox === '' && $pr['data'] >= $agora) { $prox = $pr['data']; $prox_rot = $pr['rotulo']; }
        }
        if ($prox === '') $prox = alAmData($rt['DataTermino'] ?? '');

        $lance_atual = (float)($rt['ValorLanceAtual'] ?? 0);
        $rotulo = $lance_atual > 0 ? 'Lance atual' : ('Lance mínimo' . ($prox_rot ? ' · ' . $prox_rot : ''));
        $valor  = $lance_atual > 0 ? $lance_atual : (float)($rt['ProximoLance'] ?? 0);

        $sit = trim((string)($rt['StatusLote'] ?? ''));
        $sub = trim((string)($rt['Lote_SubStatus_Label'] ?? ''));
        $foto = $l['Fotos'][0]['Foto'] ?? '';

        $itens[] = [
            'fonte'           => 'amazonas',
            'id'              => (string)$l['ID_Leiloes_Lote'],
            'leilao'          => trim((string)($l['Leilao'] ?? '')) . (!empty($l['CodLeilao']) ? ' (' . $l['CodLeilao'] . ')' : ''),
            'comitente'       => trim((string)($l['Comitente'] ?? '')),
            'numero'          => trim((string)($l['LoteNumero'] ?? '')),
            'titulo'          => mb_strimwidth(implode(' · ', array_slice($partes, 0, 3)), 0, 140, '…', 'UTF-8'),
            'descricao'       => $desc . (trim($proc) !== '' ? ' · ' . trim(preg_replace('/\s+/', ' ', $proc)) : ''),
            'categoria'       => alClassificarLote($desc),
            'municipio'       => alMunicipioRR($desc) ?: alMunicipioRR($texto),
            'bairro'          => '',
            'valor_avaliacao' => (float)($l['ValorAvaliacao'] ?? 0),
            'valor_lance'     => $valor,
            'valor_rotulo'    => $rotulo,
            'data_leilao'     => $prox,
            'data_fim'        => $fim,
            'situacao'        => $sit . ($sub !== '' && stripos($sit, $sub) === false ? ' — ' . $sub : ''),
            'link'            => AL_AM_BASE . ltrim((string)($l['URLlote'] ?? $l['URLleilao'] ?? ''), '/'),
            'foto'            => $foto ? AL_AM_BASE . 'imagens-center/350x282/' . rawurlencode($foto) : '',
            'extra'           => ['pracas' => $pracas, 'modalidade' => (string)($l['LabelModalidade'] ?? '')],
        ];
    }
    return [$itens, $completo];
}

// ---------------------------------------------------------------
// FONTE 2: WR LEILOES (so o LEILAO, desde 09/11/2026)
// ---------------------------------------------------------------
/*
 * Em 09/11/2026 a WR trocou o site por um app (Lovable + Supabase): a
 * /agenda-de-leiloes deu 404 e a lista, os precos e as cidades passaram a ser
 * montados no navegador. O HTML do servidor so traz, de cada leilao, o JSON-LD
 * "Event" (nome, descricao, inicio, fim, status) - dos lotes, nem preco nem
 * cidade nem a qual leilao pertencem. Decisao do dono: o radar avisa o LEILAO
 * aberto (um item por leilao, link para a pagina dele) e nao le a API do site.
 *
 * Caminho: /sitemap.xml -> /leiloes/<uuid> -> JSON-LD. robots.txt: Allow /.
 */
function alWrJsonLd($h, $tipo) {
    if (!preg_match_all('~<script[^>]+application/ld\+json[^>]*>(.*?)</script>~si', (string)$h, $mm)) return null;
    foreach ($mm[1] as $js) {
        $j = json_decode(html_entity_decode($js, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        if (is_array($j) && ($j['@type'] ?? '') === $tipo) return $j;
    }
    return null;
}

// "2026-11-16T16:00:00+00:00" (UTC) -> "2026-11-16 12:00" no fuso da casa
function alWrData($iso) {
    $t = strtotime((string)$iso);
    return $t ? date('Y-m-d H:i', $t) : '';
}

function alLotesWR($limite) {
    $sm = alHttp(AL_WR_BASE . '/sitemap.xml');
    if ($sm === null) throw new Exception('sitemap nao respondeu');
    preg_match_all('~<loc>[^<]*?/leiloes/([0-9a-f-]{36})</loc>~i', $sm, $mm);
    $ids = array_unique($mm[1]);
    if (!$ids) throw new Exception('sitemap veio sem leiloes (site mudou de novo?)');

    $itens = []; $completo = true; $lidos = 0;
    foreach ($ids as $uuid) {
        if (microtime(true) > $limite) { $completo = false; break; }
        $url = AL_WR_BASE . '/leiloes/' . $uuid;
        $h = alHttp($url);
        if ($h === null) { $completo = false; continue; }
        $ev = alWrJsonLd($h, 'Event');
        if (!$ev) { $completo = false; continue; }
        $lidos++;

        $nome = trim(html_entity_decode((string)($ev['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $desc = trim(preg_replace('/\s+/u', ' ', html_entity_decode((string)($ev['description'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $ini  = alWrData($ev['startDate'] ?? '');
        $fim  = alWrData($ev['endDate'] ?? '') ?: $ini;
        $st   = (string)($ev['eventStatus'] ?? '');
        if ($fim !== '' && strtotime($fim) < time()) continue;          // ja acabou
        if (preg_match('~Cancelled|Postponed~i', $st)) continue;
        $texto = $nome . ' · ' . $desc;
        if (!alEhRR($texto)) continue;

        $agora = date('Y-m-d H:i');
        $itens[] = [
            'fonte'           => 'wr',
            'id'              => $uuid,
            'leilao'          => $nome,
            'comitente'       => preg_replace('~^Leil[ãa]o\s+(d[eoa]s?\s+)?~iu', '', $nome),
            'numero'          => '',
            'titulo'          => mb_strimwidth($nome, 0, 140, '…', 'UTF-8'),
            'descricao'       => mb_strimwidth($desc, 0, 1500, '…', 'UTF-8'),
            'categoria'       => alClassificarLote($texto),
            'municipio'       => alMunicipioRR($texto),
            'bairro'          => '',
            'valor_avaliacao' => 0,
            'valor_lance'     => 0,
            'valor_rotulo'    => 'Lance',
            'data_leilao'     => ($ini !== '' && $ini > $agora) ? $ini : $fim,
            'data_fim'        => $fim,
            'situacao'        => ($ini !== '' && $ini > $agora ? 'Leilão abre em ' . date('d/m H:i', strtotime($ini)) : 'Leilão aberto')
                                 . ' · lotes e preços no site da WR',
            'link'            => $url,
            'foto'            => '',
            'extra'           => ['modalidade' => 'Leilão (aviso do leilão inteiro)'],
        ];
    }
    if (!$lidos && $ids) throw new Exception('nenhuma pagina de leilao respondeu');
    return [$itens, $completo];
}

// ---------------------------------------------------------------
// FONTE 3: SPY LEILOES (agregador: Caixa, bancos, leiloeiros)
// ---------------------------------------------------------------
function alSpyCampo($md, $campo) {
    if (!preg_match('~^-\s*' . preg_quote($campo, '~') . ':\s*(.+)$~mu', $md, $m)) return '';
    return trim(preg_replace('~\[([^\]]*)\]\([^)]*\)~', '$1', $m[1]));
}

function alLotesSpy($limite) {
    $h = alHttp(AL_SPY_BASE . '/imoveis-leilao/rr');
    if ($h === null) throw new Exception('pagina de RR nao respondeu');
    preg_match_all('~href="(/leilao/(\d+)/[a-z0-9-]+)"~', $h, $mm, PREG_SET_ORDER);
    $urls = [];
    foreach ($mm as $m) $urls[$m[2]] = $m[1];
    if (!$urls) throw new Exception('pagina de RR veio sem imoveis (layout mudou?)');

    /*
     * Pelo botao do painel (web) o imovel conferido nas ultimas 12h so e
     * marcado como visto: com 1s entre pedidos, reler tudo nao cabe nos ~60s
     * do proxy. O cron (CLI) le tudo, todo dia.
     */
    $atalho = php_sapi_name() !== 'cli';
    $visto = alDb()->prepare("SELECT visto_em FROM lotes WHERE hash=?");
    $tocar = alDb()->prepare("UPDATE lotes SET visto_em=? WHERE hash=?");

    $itens = []; $completo = true; $primeiro = true;
    foreach ($urls as $id => $path) {
        if (microtime(true) > $limite) { $completo = false; break; }
        if ($atalho) {
            $visto->execute(['spy|' . $id]);
            $v = $visto->fetchColumn();
            if ($v && strtotime($v) > time() - 12 * 3600) { $tocar->execute([date('Y-m-d H:i:s'), 'spy|' . $id]); continue; }
        }
        if (!$primeiro) sleep(1); // Crawl-delay: 1 do robots.txt deles
        $primeiro = false;
        $md = alHttp(AL_SPY_BASE . $path . '.md');
        if ($md === null || strpos($md, '# ') === false) continue;

        $titulo = preg_match('~^#\s+(.+)$~m', $md, $x) ? trim($x[1]) : '';
        $status = preg_match('~^Status:\s*(.+)$~m', $md, $x) ? trim($x[1]) : '';
        $tipo   = alSpyCampo($md, 'Tipo');
        $end    = alSpyCampo($md, 'Endereço');
        $cidade = alSpyCampo($md, 'Cidade');
        $estado = alSpyCampo($md, 'Estado');
        $aval   = alMoeda(alSpyCampo($md, 'Valor de avaliação'));
        $lance  = alMoeda(alSpyCampo($md, 'Lance inicial'));
        $resumo = preg_match('~##\s*Descri[çc][ãa]o\s*\n+(.+?)(?:\n\s*\n|\n##|$)~su', $md, $x) ? trim($x[1]) : '';
        $anuncio = preg_match('~^>\s*(Valor de avalia.+)$~mu', $md, $x) ? trim($x[1]) : '';

        // Titulo do Spy repete o do leiloeiro ("X | Y | Canta/RR X | Y"): fica a primeira metade.
        $titulo = preg_replace('/\s*\|\s*/', ' · ', $titulo);
        $meio = (int)floor(mb_strlen($titulo, 'UTF-8') / 2);
        if ($meio > 20 && mb_strpos($titulo, mb_substr($titulo, 0, 20, 'UTF-8'), 20, 'UTF-8') !== false) {
            $titulo = trim(mb_substr($titulo, 0, mb_strpos($titulo, mb_substr($titulo, 0, 20, 'UTF-8'), 20, 'UTF-8'), 'UTF-8'), ' ·');
        }

        // "ID Bem 144227 - Lote: 50 - 2.117 - CAROEBE/RR - ...": o prefixo e codigo interno do banco
        $titulo = preg_replace('~^ID\s*Bem\s*\d+\s*-\s*Lote:\s*\d+\s*-\s*[\d.]+\s*-\s*~iu', '', $titulo);
        // Titulo sem o tipo ("Cond Buriti") ganha o tipo e a area na frente
        if ($tipo !== '' && alClassificarLote($titulo) === 'outro') {
            $titulo = $tipo . ' · ' . $titulo;
        }
        if (($ar = alSpyCampo($md, 'Área')) !== '' && !preg_match('~\d\s*(m²|m2|ha)(?![a-z])~iu', $titulo)) $titulo .= ' · ' . $ar;
        $texto = $titulo . ' ' . $end . ' ' . $cidade . ' ' . $estado . ' ' . $resumo . ' ' . $anuncio;
        if (stripos($estado, 'Roraima') === false && !alEhRR($texto)) continue;

        $pracas = [];
        if (preg_match_all('~^-\s*(\d)º leilão:\s*(\d{2})/(\d{2})/(\d{4})(?:\s*às\s*(\d{2}):(\d{2}))?\s*(?:—|-)\s*R\$\s*([\d.,]+)~mu', $md, $pp, PREG_SET_ORDER)) {
            foreach ($pp as $p) {
                $pracas[] = ['rotulo' => $p[1] . 'º leilão', 'data' => "$p[4]-$p[3]-$p[2] " . ($p[5] !== '' ? "$p[5]:$p[6]" : '00:00'),
                             'valor' => alMoeda($p[7])];
            }
        }
        $agora = date('Y-m-d H:i'); $prox = '';
        foreach ($pracas as $pr) if ($prox === '' && $pr['data'] >= $agora) $prox = $pr['data'];
        /*
         * Data de fim so quando da para afirmar que nao ha praca depois: o Spy
         * as vezes lista so a 1a praca enquanto o lance inicial ja e o da 2a
         * (casa de Rorainopolis, 08/10/2026). Nesse caso quem manda e o Status.
         */
        $ult = $pracas ? end($pracas) : null;
        $fim = ($ult && abs($ult['valor'] - $lance) < 1) ? $ult['data'] : '';

        /*
         * Foto: o .md nao traz, a pagina HTML traz no og:image (no caso da Caixa,
         * a foto oficial do imovel). So se busca na PRIMEIRA vez que o imovel
         * aparece - depois vem do banco. '-' = ja procurado, sem foto.
         */
        $q = alDb()->prepare("SELECT foto FROM lotes WHERE hash=?");
        $q->execute(['spy|' . $id]);
        $foto = (string)$q->fetchColumn();
        if ($foto === '' && microtime(true) < $limite) {
            sleep(1);
            $hh = alHttp(AL_SPY_BASE . $path);
            $foto = ($hh !== null && preg_match('~<meta property="og:image" content="([^"]+)"~', $hh, $x)
                     && stripos($x[1], 'logo') === false) ? html_entity_decode($x[1], ENT_QUOTES, 'UTF-8') : '-';
        }

        $caixa = preg_match('~(?<!\d)8444\d{6,}~', str_replace(['.', '-'], '', $md)) || stripos($md, 'Caixa Econ') !== false;
        $quartos = preg_match('~Quartos:\s*(\d+)~u', $md, $x) ? (int)$x[1] : 0;
        $area = alSpyCampo($md, 'Área');

        $itens[] = [
            'fonte'           => 'spy',
            'id'              => (string)$id,
            'leilao'          => $pracas ? 'Leilão' : 'Venda direta / proposta',
            'comitente'       => $caixa ? 'Caixa Econômica Federal' : '',
            'numero'          => '',
            'titulo'          => mb_strimwidth($titulo, 0, 140, '…', 'UTF-8'),
            'descricao'       => trim($end . ($resumo ? ' · ' . $resumo : '')),
            'categoria'       => alClassificarLote($tipo . ' ' . $titulo . ' ' . $anuncio, $tipo),
            'municipio'       => alMunicipioRR($cidade . '/RR') ?: alMunicipioRR($texto),
            'bairro'          => alSpyCampo($md, 'Bairro'),
            'valor_avaliacao' => $aval,
            'valor_lance'     => $lance,
            'valor_rotulo'    => $pracas ? 'Lance inicial' : 'Valor mínimo',
            'data_leilao'     => $prox,
            'data_fim'        => $fim,
            'situacao'        => $status,
            'link'            => AL_SPY_BASE . $path,
            'foto'            => $foto,
            'extra'           => array_filter(['pracas' => $pracas, 'area' => $area, 'quartos' => $quartos,
                                               'fgts' => alSpyCampo($md, 'FGTS'), 'financiamento' => alSpyCampo($md, 'Financiamento'),
                                               'desconto' => alSpyCampo($md, 'Desconto'), 'endereco' => $end]),
        ];
    }
    return [$itens, $completo];
}

// ---------------------------------------------------------------
// FONTE 4: GARIMPIA (agregador: Caixa, Banco do Brasil, Resale, Pestana...)
// ---------------------------------------------------------------
/*
 * Indicado pelo dono em 08/10/2026. robots.txt: "Allow: /". A lista de RR
 * (/imoveis/uf/RR) e uma tabela Laravel/Livewire ja renderizada no HTML,
 * 50 por pagina; cada linha traz categoria, praca abreviada ("148K"), titulo,
 * bairro, cidade, miniatura e o link da fonte original. O valor exato e a
 * data de cada praca so estao na pagina do anuncio - 1 pedido por imovel,
 * com 1s de intervalo (cortesia; o robots nao pede).
 */
define('AL_GAR_BASE', 'https://www.garimpia.com');

function alGarFonte($url) {
    $h = strtolower((string)parse_url($url, PHP_URL_HOST));
    $nomes = ['caixa.gov.br' => 'Caixa Econômica Federal', 'seuimovelbb' => 'Banco do Brasil',
              'resale.com.br' => 'Resale', 'pestanaleiloes' => 'Pestana Leilões'];
    foreach ($nomes as $k => $n) if (strpos($h, $k) !== false) return $n;
    return preg_replace('~^www\.~', '', $h);
}

// "148K" -> 148000 ; "1,2M" -> 1200000
function alGarAbrev($s) {
    if (!preg_match('~([\d.,]+)\s*([KM])~i', (string)$s, $m)) return 0.0;
    return (float)str_replace(',', '.', $m[1]) * (strtoupper($m[2]) === 'M' ? 1e6 : 1e3);
}

function alLotesGarimpia($limite) {
    $linhas = [];
    for ($pag = 1; $pag <= 5; $pag++) {
        $h = alHttp(AL_GAR_BASE . '/imoveis/uf/RR' . ($pag > 1 ? '?page=' . $pag : ''));
        if ($h === null) { if ($pag === 1) throw new Exception('pagina de RR nao respondeu'); break; }
        $rows = array_slice(preg_split('~<tr\s+wire:key="ad-~', $h), 1);
        if ($pag === 1 && !$rows && stripos($h, 'garimp') === false) throw new Exception('pagina de RR veio estranha (layout mudou?)');
        foreach ($rows as $r) $linhas[(int)$r] = substr($r, 0, (int)strpos($r, '</tr>') ?: 30000);
        if (count($rows) < 50) break; // pagina cheia = pode haver proxima
    }

    $atalho = php_sapi_name() !== 'cli';
    $visto  = alDb()->prepare("SELECT visto_em FROM lotes WHERE hash=?");
    $tocar  = alDb()->prepare("UPDATE lotes SET visto_em=? WHERE hash=?");

    $itens = []; $completo = true; $primeiro = true;
    foreach ($linhas as $id => $r) {
        if (!$id) continue;
        if (microtime(true) > $limite) { $completo = false; break; }
        if ($atalho) {
            $visto->execute(['garimpia|' . $id]);
            $v = $visto->fetchColumn();
            if ($v && strtotime($v) > time() - 12 * 3600) { $tocar->execute([date('Y-m-d H:i:s'), 'garimpia|' . $id]); continue; }
        }

        preg_match_all('~<td[^>]*>(.*?)</td>~s', $r, $td);
        $cel = array_map(function ($c) {
            $c = preg_replace('~<span x-show="fs !== \'lg\'">[^<]*</span>~', '', $c); // rotulo abreviado ("Imó")
            return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($c), ENT_QUOTES, 'UTF-8')));
        }, $td[1]);
        if (!preg_match('~href="(https://www\.garimpia\.com/anuncio/[^"]+)"[^>]*>\s*([^<]+?)\s*</a>~', $r, $an)) continue;
        $titulo_lista = trim($an[2]);
        // link da CIDADE = um segmento so (/uf/RR/boa-vista); o do bairro tem dois
        if (!preg_match('~/imoveis/uf/RR/[a-z0-9-]+"[^>]*>\s*([^<]+?)\s*</a>~', $r, $cid)) continue; // so RR
        $cidade = trim($cid[1]);
        $externo = '';
        if (preg_match_all('~href="(https?://[^"]+)"~', $r, $lk)) {
            foreach ($lk[1] as $u) if (stripos($u, 'garimpia.com') === false) { $externo = html_entity_decode($u, ENT_QUOTES, 'UTF-8'); break; }
        }
        $categ = '';
        foreach ($cel as $c) if (strpos($c, ' / ') !== false) { $categ = $c; break; }
        $bairro = '';
        foreach ($cel as $i => $c) {
            if ($c === $titulo_lista) { $b = $cel[$i + 1] ?? ''; $bairro = ($b === '-' ? '' : $b); break; }
        }
        $p1 = $p2 = 0.0;
        foreach ($cel as $c) if (preg_match('~p1:\s*([\d.,]+[KM])?\s*p2:\s*([\d.,]+[KM])?~i', $c, $pp)) {
            $p1 = alGarAbrev($pp[1] ?? ''); $p2 = alGarAbrev($pp[2] ?? ''); break;
        }
        $foto = preg_match('~<img[^>]+src="(/storage/[^"]+)"~', $r, $im) ? AL_GAR_BASE . $im[1] : '';

        // Pagina do anuncio: valor exato e data de cada praca, endereco.
        $pracas = []; $end = ''; $area = '';
        if (!$primeiro) sleep(1);
        $primeiro = false;
        $ha = alHttp($an[1]);
        if ($ha !== null) {
            $t = alTxtHtml($ha);
            $ord = ['PRIMEIRA' => 1, 'SEGUNDA' => 2, 'TERCEIRA' => 3];
            if (preg_match_all('~(Primeira|Segunda|Terceira) praça\s*R\$\s*([\d.,]+)\s*(?:(\d{2})/(\d{2})/(\d{4})(?:\s+(\d{2}):(\d{2}))?)?~u', $t, $mm, PREG_SET_ORDER)) {
                foreach ($mm as $m) {
                    $n = $ord[alNormTxt($m[1])] ?? 1;
                    $pracas[] = ['rotulo' => $n . 'ª praça',
                                 'data'   => !empty($m[3]) ? "$m[5]-$m[4]-$m[3] " . (!empty($m[6]) ? "$m[6]:$m[7]" : '00:00') : '',
                                 'valor'  => alMoeda($m[2])];
                }
            }
            if (preg_match('~Endereço:\s*([^\n]+)~u', $t, $x)) $end = trim($x[1], " ,-");
            if (preg_match('~Bairro:\s*([^\n]+)~u', $t, $x) && trim($x[1]) !== '-') $bairro = trim($x[1]);
            if (preg_match('~Área (?:útil|total):\s*([\d.,]+)\s*m²~u', $t, $x) && alMoeda($x[1]) > 0) $area = $x[1] . ' m²';
        }
        if (!$pracas) { // pagina do anuncio falhou: fica o abreviado da lista
            if ($p1) $pracas[] = ['rotulo' => '1ª praça', 'data' => '', 'valor' => $p1];
            if ($p2) $pracas[] = ['rotulo' => '2ª praça', 'data' => '', 'valor' => $p2];
        }

        $agora = date('Y-m-d H:i'); $prox = ''; $prox_rot = ''; $todas_com_data = (bool)$pracas; $fim = '';
        foreach ($pracas as $pr) {
            if ($pr['data'] === '') { $todas_com_data = false; continue; }
            $fim = max($fim, $pr['data']);
            if ($prox === '' && $pr['data'] >= $agora) { $prox = $pr['data']; $prox_rot = $pr['rotulo']; }
        }
        // Valor de referencia: a praca que vem; sem data, a menor (e o que se paga no fim).
        $ref = null;
        foreach ($pracas as $pr) if ($pr['rotulo'] === $prox_rot) $ref = $pr;
        if (!$ref && $pracas) { $ref = $pracas[0]; foreach ($pracas as $pr) if ($pr['valor'] > 0 && $pr['valor'] < $ref['valor']) $ref = $pr; }

        $titulo = ($end !== '' && $end !== '-') ? $end : preg_replace('~\bRr\b~', 'RR', preg_replace('~\bBb\b~', 'BB', $titulo_lista));
        // (sem prefixo de tipo: o card ja mostra a categoria em cima do titulo)
        $origem = $externo ? alGarFonte($externo) : '';

        $itens[] = [
            'fonte'           => 'garimpia',
            'id'              => (string)$id,
            'leilao'          => $origem ? 'Fonte original: ' . $origem : 'Garimpia',
            'comitente'       => $origem,
            'numero'          => '',
            'titulo'          => mb_strimwidth($titulo, 0, 140, '…', 'UTF-8'),
            'descricao'       => trim($categ . ' · ' . $titulo_lista . ($end ? ' · ' . $end : '') . ($bairro ? ' · ' . $bairro : '') . ' · ' . $cidade . '/RR'),
            'categoria'       => alClassificarLote($categ . ' ' . $titulo_lista, $categ),
            'municipio'       => alMunicipioRR($cidade . '/RR'),
            'bairro'          => $bairro,
            'valor_avaliacao' => 0,
            'valor_lance'     => $ref ? $ref['valor'] : 0,
            'valor_rotulo'    => $ref ? ($ref['rotulo'] === '1ª praça' && count($pracas) === 1 ? 'Lance mínimo' : 'Lance mínimo · ' . $ref['rotulo']) : 'Lance',
            'data_leilao'     => $prox,
            'data_fim'        => ($todas_com_data && $fim !== '') ? $fim : '',
            'situacao'        => 'Listado no Garimpia',
            'link'            => $externo ?: $an[1],
            'foto'            => $foto,
            'extra'           => array_filter(['pracas' => $pracas, 'area' => $area, 'garimpia' => $an[1], 'endereco' => $end !== '-' ? $end : '']),
        ];
    }
    return [$itens, $completo];
}

// ---------------------------------------------------------------
// FONTE 5: RECEITA FEDERAL - SLE (16/10/2026)
// ---------------------------------------------------------------
/*
 * O aviso de EDITAL da Receita em RR ja existe desde 08/2026 (alSleLeiloesRR,
 * no lib.php). Aqui entram os LOTES de cada edital, na mesma API JSON que o
 * portal usa (sem login, sem chave):
 *   api/editais-disponiveis           -> editais (o filtro de RR e o do lib.php)
 *   api/edital/{unid}/{num}/{ano}     -> listaLotes: tipo, lance minimo, avaliacao, situacao
 *   api/lote/{unid}/{num}/{ano}/{n}   -> itens do lote (descricao, recinto)
 * O detalhe custa um pedido por lote (~0,5 s), entao so e pedido na 1a vez:
 * descricao nao muda, o resto (situacao, valor) vem da lista do edital.
 * So editais Disponibilizado (2) e Aberto para Proposta (3) - depois disso a
 * proposta ja fechou e o lote nao serve mais para quem quer comprar.
 */
define('AL_SLE_BASE', 'https://www25.receita.fazenda.gov.br/sle-sociedade/');

// Situacao do lote (enum do portal). So 11 e 12 aceitam proposta.
function alSleSitLote($n) {
    $s = [11 => 'Disponibilizado', 12 => 'Aberto para proposta', 13 => 'Fechado para proposta',
          14 => 'Suspenso', 15 => 'Aberto para lance', 16 => 'Encerrado', 8 => 'Arrematado',
          9 => 'Lote excluído', 10 => 'Lote baixado'];
    return $s[(int)$n] ?? 'Encerrado';
}

/*
 * Na Receita a categoria sai do TIPO do lote, nao do texto: mercadoria
 * apreendida cita "casa", "sala", "chassi" a toda hora (no teste de 16/10, um
 * lote de eletronicos virou "Casa" e mandaria e-mail de imovel). O tipo e
 * confiavel: VEICULO, EMBARCACAO, INFORMATICA, OUTROS...
 */
function alSleCategoria($tipo, $desc) {
    $t = alNormTxt($tipo);
    if (preg_match('~VEICUL|EMBARCA|AERONAVE|MOTOCICL~', $t)) return 'veiculo';
    if (strpos($t, 'IMOV') !== false) {
        $c = alClassificarLote($desc, 'IMOVEL');
        return in_array($c, alLotesFamiliaImovel(), true) ? $c : 'imovel';
    }
    return 'outro';
}

/*
 * EDITAL REGIONAL (09/11/2026). A Superintendencia da 2a Regiao Fiscal (Belem)
 * leiloa num edital so o que esta apreendido em PA, AM, AP, AC, RO e RR. O
 * edital 200100/2/2026 tinha 179 lotes, 71 deles no patio da DRF Boa Vista e da
 * IRF Pacaraima - e passou batido, porque o filtro so olhava a cidade do edital
 * ("BELEM"). Nesses editais o que diz se o lote e de RR e o RECINTO onde a
 * mercadoria esta guardada, e ele so vem no detalhe de cada lote.
 */
function alSleEditalRegional(array $e) {
    $alvo = alSleNorm(($e['cidade'] ?? '') . ' ' . ($e['uaNm'] ?? ''));
    if (preg_match('~\b2\s*[ªA°O]?\s*REGIAO FISCAL~u', $alvo)) return true;
    foreach (['BELEM', 'MANAUS', 'MACAPA', 'PORTO VELHO', 'RIO BRANCO', 'SANTAREM'] as $c) {
        if (strpos($alvo, $c) !== false) return true;
    }
    return false;
}

function alSleEditalRR(array $e) {
    $alvo = alSleNorm(($e['cidade'] ?? '') . ' ' . ($e['uaNm'] ?? ''));
    foreach (alSleTermosRR() as $t) if (strpos($alvo, $t) !== false) return true;
    return false;
}

// Recinto de RR: dentro da 2a RF "Boa Vista" so pode ser a de Roraima.
function alSleRecintoRR($recinto) {
    $r = ' ' . alSleNorm($recinto) . ' ';
    foreach (alSleTermosRR() as $t) if (strpos($r, $t) !== false) return true;
    return false;
}

/*
 * Detalhe do lote, com cache em tabela propria (sle_lote) - inclusive dos lotes
 * que NAO sao de RR: um edital regional tem 150-300 lotes e, sem guardar o
 * "nao e de RR", cada rodada pediria tudo de novo (~0,5 s por lote).
 * Devolve a linha do cache, null se acabou o tempo ou false se a API falhou.
 */
function alSleDetalhe($edle, $n, $tipo, $limite) {
    static $prep = false;
    $pdo = alDb();
    if (!$prep) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sle_lote (id TEXT PRIMARY KEY, rr INTEGER, recintos TEXT,
                    titulo TEXT, descricao TEXT, categoria TEXT, municipio TEXT, criado_em TEXT)");
        $prep = true;
    }
    $id = $edle . '#' . (int)$n;
    $q = $pdo->prepare("SELECT * FROM sle_lote WHERE id=?");
    $q->execute([$id]);
    if ($c = $q->fetch(PDO::FETCH_ASSOC)) return $c;
    if (microtime(true) > $limite) return null;

    $d = alHttp(AL_SLE_BASE . 'api/lote/' . $edle . '/' . (int)$n);
    $d = $d !== null ? json_decode($d, true) : null;
    usleep(200000); // gentileza com o servidor da Receita
    if (!is_array($d)) return false;

    $descs = []; $recintos = [];
    foreach (($d['itensDetalhesLote'] ?? []) as $it) {
        $txt = trim(preg_replace('~(/{2,}\s*)+$~', '', preg_replace('/\s+/', ' ', (string)($it['descricao'] ?? ''))));
        $qt = (float)($it['quantidade'] ?? 0);
        if ($txt !== '') $descs[] = ($qt > 1 ? rtrim(rtrim(number_format($qt, 2, ',', '.'), '0'), ',') . ' ' . ($it['unMedida'] ?? '') . ' · ' : '') . $txt;
        if (!empty($it['recintoArmazenador'])) $recintos[trim($it['recintoArmazenador'])] = true;
    }
    foreach (($d['avisosErratas'] ?? []) as $a) if (!empty($a['texto'])) $descs[] = ($a['tipo'] ?? 'Aviso') . ': ' . trim($a['texto']);
    $rec  = implode(', ', array_keys($recintos));
    $desc = implode(' · ', $descs) . ($rec !== '' ? ' · Local: ' . $rec : '');
    $nit  = count($d['itensDetalhesLote'] ?? []);
    $tit  = $descs ? $descs[0] . ($nit > 1 ? ' (+' . ($nit - 1) . ' itens)' : '')
                   : ucfirst(mb_strtolower((string)$tipo, 'UTF-8'));
    $rr = 0;
    foreach (array_keys($recintos) as $r) if (alSleRecintoRR($r)) { $rr = 1; break; }

    $c = ['id' => $id, 'rr' => $rr, 'recintos' => $rec,
          'titulo' => mb_strimwidth($tit, 0, 140, '…', 'UTF-8'), 'descricao' => $desc,
          'categoria' => alSleCategoria((string)$tipo, $desc),
          'municipio' => alMunicipioRR($rec), 'criado_em' => date('Y-m-d H:i:s')];
    $pdo->prepare("INSERT OR REPLACE INTO sle_lote (id, rr, recintos, titulo, descricao, categoria, municipio, criado_em)
                   VALUES (?,?,?,?,?,?,?,?)")->execute(array_values($c));
    return $c;
}

function alSleFeed() {
    $r = alHttp(AL_SLE_API);
    $j = $r !== null ? json_decode($r, true) : null;
    if (!is_array($j) || !isset($j['situacoes'])) throw new Exception('API nao respondeu');
    return $j;
}

function alLotesReceita($limite) {
    $j = alSleFeed();
    $editais = [];
    foreach ($j['situacoes'] as $bloco) foreach (($bloco['lista'] ?? []) as $e) {
        if (!in_array((int)($e['codigoSituacao'] ?? 0), [2, 3], true)) continue;
        if (alSleEditalRR($e))           $editais[] = [$e, false];
        elseif (alSleEditalRegional($e)) $editais[] = [$e, true];
    }

    $itens = []; $completo = true;
    foreach ($editais as [$e, $regional]) {
        [$its, $ok] = alReceitaLotesEdital($e, $limite, $regional);
        $itens = array_merge($itens, $its);
        if (!$ok) $completo = false; // segue: o cache do que ja leu fica para a proxima
    }
    return [$itens, $completo];
}

/**
 * Raspador de Editais e Lotes Judiciais do PublicJud exclusivo para Roraima (Estado ID = 22)
 */
function alLotesPublicJud($limite) {
    $url = 'https://www.publicjud.com.br/consulta';
    $postData = http_build_query([
        '_method' => 'POST',
        'data' => [
            'Diario' => [
                'estado' => '22', // Roraima
                'periodo_leilao' => '1', // Leilões Futuros
            ]
        ]
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $html = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if (!$html && $err) {
        throw new Exception("Falha de conexão com PublicJud: " . $err);
    }

    $itens = [];
    if ($html) {
        // Se houver resultados (tabela ou cards de editais)
        if (preg_match_all('~<tr[^>]*>(.*?)</tr>~is', $html, $matches)) {
            foreach ($matches[1] as $row) {
                if (stripos($row, '<th') !== false) continue;
                if (preg_match('~<a[^>]+href="([^"]+)"[^>]*>(.*?)</a>~is', $row, $linkM)) {
                    $link = $linkM[1];
                    if (strpos($link, 'http') !== 0) $link = 'https://www.publicjud.com.br' . ltrim($link, '/');
                    $titulo = trim(strip_tags($linkM[2]));
                    if ($titulo !== '') {
                        $itens[] = [
                            'fonte'           => 'publicjud',
                            'link'            => $link,
                            'titulo'          => $titulo,
                            'descricao'       => $titulo,
                            'categoria'       => 'judicial',
                            'municipio'       => 'Boa Vista',
                            'valor_lance'     => 0,
                            'valor_avaliacao' => 0,
                            'data_leilao'     => null,
                            'visto_em'        => date('Y-m-d H:i:s'),
                        ];
                    }
                }
            }
        }
    }

    return [$itens, true];
}

/*
 * $regional = edital de fora de RR: so entra o lote guardado em recinto de RR.
 * Separada de proposito: da para testar com um edital de outro estado.
 */
function alReceitaLotesEdital(array $e, $limite, $regional = false) {
    $edle = trim((string)($e['edle'] ?? ''));
    if (!preg_match('~^\d+/\d+/\d{4}$~', $edle)) return [[], true];
    $r = alHttp(AL_SLE_BASE . 'api/edital/' . $edle);
    $ed = $r !== null ? json_decode($r, true) : null;
    if (!is_array($ed) || !isset($ed['listaLotes'])) return [[], false];

    $ua      = trim((string)($e['uaNm'] ?? ''));
    $cidade  = trim((string)($e['cidade'] ?? ''));
    $fimProp = alAmData($e['dataFimPropostas'] ?? '');
    $lances  = alAmData($e['dataAberturaLances'] ?? '');
    $modal   = 'proposta até ' . ($fimProp ? date('d/m H:i', strtotime($fimProp)) : '?')
             . ', disputa ' . ($lances ? date('d/m H:i', strtotime($lances)) : '?')
             . (!empty($e['permitePF']) ? ' · aceita pessoa física' : ' · só pessoa jurídica')
             . ($regional ? ' · edital regional de ' . mb_convert_case($cidade ?: $ua, MB_CASE_TITLE, 'UTF-8') : '');

    $itens = []; $completo = true;
    foreach ($ed['listaLotes'] as $lt) {
        $sit = (int)($lt['situacaoLote'] ?? 0);
        if (!in_array($sit, [11, 12], true)) continue;
        $n = (int)($lt['nrAtribuido'] ?? 0);
        $c = alSleDetalhe($edle, $n, (string)($lt['tipo'] ?? ''), $limite);
        if (!$c) { $completo = false; if ($c === null) break; continue; }
        if ($regional && !$c['rr']) continue;

        $itens[] = [
            'fonte'           => 'receita',
            'id'              => $edle . '#' . $n,
            'leilao'          => 'Edital ' . trim((string)($e['edital'] ?? $edle)),
            'comitente'       => 'edital ' . $edle . ($cidade !== '' ? ' · ' . mb_convert_case($cidade, MB_CASE_TITLE, 'UTF-8') : ''),
            'numero'          => (string)$n,
            'titulo'          => $c['titulo'],
            'descricao'       => $c['descricao'],
            'categoria'       => $c['categoria'],
            'municipio'       => $c['municipio'] ?: (alMunicipioRR($cidade . ' ' . $ua) ?: 'Boa Vista'),
            'bairro'          => '',
            'valor_avaliacao' => (float)($lt['valorAvaliacao'] ?? 0),
            'valor_lance'     => (float)($lt['valorMinimo'] ?? 0),
            'valor_rotulo'    => 'Lance mínimo',
            'data_leilao'     => $fimProp,
            'data_fim'        => $fimProp,
            'situacao'        => alSleSitLote($sit),
            'link'            => AL_SLE_BASE . 'portal/edital/' . $edle . '/lote/' . $n,
            'foto'            => '',
            'extra'           => ['modalidade' => $modal, 'tipo_sle' => (string)($lt['tipo'] ?? ''), 'disputa' => $lances],
        ];
    }
    return [$itens, $completo];
}

/*
 * Para o aviso de EDITAL (lib.php): quantos lotes de um edital regional estao
 * em RR, por recinto. null = nao deu para ler tudo (tenta na proxima rodada).
 */
function alSleRegionalRR(array $e, $limite) {
    $edle = trim((string)($e['edle'] ?? ''));
    if (!preg_match('~^\d+/\d+/\d{4}$~', $edle)) return null;
    $r = alHttp(AL_SLE_BASE . 'api/edital/' . $edle);
    $ed = $r !== null ? json_decode($r, true) : null;
    if (!is_array($ed) || !isset($ed['listaLotes'])) return null;
    $por = [];
    foreach ($ed['listaLotes'] as $lt) {
        if (!in_array((int)($lt['situacaoLote'] ?? 0), [11, 12], true)) continue;
        $c = alSleDetalhe($edle, (int)($lt['nrAtribuido'] ?? 0), (string)($lt['tipo'] ?? ''), $limite);
        if (!$c) return null;
        if (!$c['rr']) continue;
        foreach (array_filter(array_map('trim', explode(',', $c['recintos']))) as $rc) {
            if (alSleRecintoRR($rc)) { $por[$rc] = ($por[$rc] ?? 0) + 1; break; }
        }
    }
    return $por;
}

// ---------------------------------------------------------------
// MAPA: ENDERECO -> COORDENADA (09/10/2026)
// ---------------------------------------------------------------
/*
 * O mapa de ruas da pagina (Leaflet + OpenStreetMap) precisa de lat/lon de
 * cada imovel, e nenhuma fonte manda coordenada - mandam endereco. Quem
 * converte e o Nominatim, o geocodificador do OpenStreetMap: gratis, sem
 * chave, com regra de uso (1 pedido por segundo, User-Agent proprio, guardar
 * a resposta - a tabela geo - em vez de perguntar de novo).
 *
 * Cascata: rua + numero -> bairro -> sede do municipio (tabela fixa, sem
 * rede). Bairro e municipio entram no mapa como "local aproximado" - e o
 * caso das areas rurais (gleba, vicinal, sitio), que nao tem rua.
 *
 * O endereco do jeito que a fonte manda falha ("Avenida Jardim, N. 1336
 * Apto. 303 Bl 10, Lt 526, Qd 719, Cidade Satelite - Boa Vista"): fica so
 * "rua, numero". Testado em 09/10/2026 com os 12 imoveis do radar: 7 acharam
 * a rua, 1 o bairro, 4 rurais ficaram na sede.
 */
define('AL_GEO_URL', 'https://nominatim.openstreetmap.org/search');
define('AL_GEO_VERSAO', 1);   // mudou a regra de montar o endereco? sobe o numero e tudo se localiza de novo
define('AL_GEO_RAIO_KM', 45); // rua/bairro achado mais longe que isso da sede do municipio = homonimo de outra cidade

// Sede de cada municipio (Nominatim, 09/10/2026). Reserva quando o endereco nao acha nada.
function alSedesRR() {
    return [
        'Boa Vista' => [2.82085, -60.67196], 'Alto Alegre' => [2.99009, -61.30896], 'Amajari' => [3.65239, -61.41943],
        'Bonfim' => [3.36138, -59.84272], 'Cantá' => [2.60994, -60.60583], 'Caracaraí' => [1.82737, -61.12494],
        'Caroebe' => [0.88087, -59.69638], 'Iracema' => [2.16647, -61.05130], 'Mucajaí' => [2.44861, -60.92000],
        'Normandia' => [3.87924, -59.62934], 'Pacaraima' => [4.47879, -61.13898], 'Rorainópolis' => [0.93873, -60.42365],
        'São João da Baliza' => [0.95166, -59.91329], 'São Luiz do Anauá' => [0.84711, -60.14407], 'Uiramutã' => [4.59583, -60.16248],
    ];
}

function alDistKm($a, $b) {
    $r = M_PI / 180;
    $x = ($b[1] - $a[1]) * $r * cos(($a[0] + $b[0]) / 2 * $r);
    $y = ($b[0] - $a[0]) * $r;
    return sqrt($x * $x + $y * $y) * 6371;
}

/*
 * "Rua Yeye Coelho, 596 - Qd 218, Jardim Floresta, Boa Vista - Roraima"
 *   -> ['rua' => 'Rua Yeye Coelho, 596', 'bairro' => 'Jardim Floresta']
 * Rodovia, estrada e vicinal nao contam como rua: o Nominatim acharia a
 * estrada inteira e o alfinete cairia num ponto qualquer dela.
 */
function alLimparEndereco($s, $municipio = '') {
    $s = preg_replace('~,?\s*CEP:?\s*[\d.\-]+~iu', '', (string)$s);
    $partes = array_values(array_filter(array_map('trim', preg_split('~\s+-\s+|[,|·;]~u', $s)), 'strlen'));
    $rua = ''; $num = ''; $iRua = -1;
    foreach ($partes as $i => $p) {
        // a rua pode vir no meio ("Boa Vista RR Travessa Projetada E 206", do Garimpia)
        if (preg_match('~(?<!\p{L})(Rua|R\.|Avenida|Av\.?|Travessa|Tv\.?|Alameda|Al\.|Pra[çc]a)\s+\S.*$~iu', $p, $x)) { $rua = $x[0]; $iRua = $i; break; }
    }
    if ($rua !== '') {
        // o que vem depois do nome nao e endereco: apto, bloco, lote, quadra, matricula
        $rua = preg_replace('~\s+(Apto?\.?|Ap\.|Apartamento|Bl\.?|Bloco|Lt\.?|Lote|Qd\.?|Quadra|Casa|Sala|Matr[ií]cula|Zona|Conj\.?|Conjunto)(\s|\d|$).*$~iu', '', $rua);
        if (preg_match('~^(.*?)\s*,?\s+(?:N\.?|n[ºo°]\.?)\s*(\d+)~u', $rua, $x)) { $rua = $x[1]; $num = $x[2]; }
        elseif (preg_match('~^(.*\D)\s+(\d{1,5})$~u', $rua, $x)) { $rua = $x[1]; $num = $x[2]; }
        // "Rua 07" e o NOME da rua, nao "Rua, numero 07"
        if ($num !== '' && preg_match('~^(Rua|R\.|Avenida|Av\.?|Travessa|Tv\.?|Alameda|Al\.|Pra[çc]a)$~iu', trim($rua))) { $rua .= ' ' . $num; $num = ''; }
        $prox = $partes[$iRua + 1] ?? '';
        if ($num === '' && preg_match('~^(?:N\.?|n[ºo°]\.?)?\s*(\d{1,5})(?!\d)(?!\s*(m²|m2|ha))~iu', $prox, $x)) $num = $x[1];
        $rua = trim($rua, " ,.-");
    }
    // bairro: "Bairro X" escrito, ou o pedaco logo antes do municipio
    $bairro = '';
    if (preg_match('~\bBairro:?\s+([^,;|·\-]{3,40})~iu', $s, $x)) $bairro = trim($x[1]);
    elseif ($municipio !== '') {
        $mun = alNormTxt($municipio);
        foreach ($partes as $i => $p) {
            if ($i > 0 && $i - 1 !== $iRua && strpos(alNormTxt($p), $mun) === 0) {
                $cand = $partes[$i - 1];
                // "Area de 49 ha", "Qd 218", "Lote 17": nao e bairro
                if (!preg_match('~^(Lt|Lote|Qd|Quadra|Zona|Bl|Bloco|Apto?|N\.|Casa|Sala|[ÁA]rea|Matr|Im[óo]vel|Terreno|Gleba|S[íi]tio)~iu', $cand)
                    && !preg_match('~\d~', $cand)) $bairro = $cand;
                break;
            }
        }
    }
    return ['rua' => $rua !== '' ? $rua . ($num !== '' ? ', ' . $num : '') : '', 'bairro' => $bairro];
}

// Endereco do lote: o campo que a fonte manda (Spy, Garimpia) ou o achado no texto (Amazonas, WR).
function alLoteEndereco(array $l) {
    $x = is_array($l['extra'] ?? null) ? $l['extra'] : (json_decode($l['extra'] ?? '', true) ?: []);
    $bruto = trim((string)($x['endereco'] ?? ''));
    if ($bruto === '') {
        $txt = $l['titulo'] . ' · ' . $l['descricao'];
        if (preg_match('~(?<![A-Za-zÀ-ú])((?:Rua|R\.|Avenida|Av\.|Travessa|Tv\.|Alameda)\s[^·|;]{2,90})~u', $txt, $m)) $bruto = $m[1];
        if (preg_match('~\bBairro:?\s+([^,;|·\-]{3,40})~iu', $txt, $m)) $bruto .= ' · Bairro ' . trim($m[1]);
    }
    $e = alLimparEndereco($bruto, $l['municipio']);
    // Amazonas: "1 Lote de Terras · Area de 600m² · Santa Cecilia · Canta/RR" - o bairro e o pedaco antes do municipio
    if ($e['bairro'] === '' && $bruto === '') $e['bairro'] = alLimparEndereco($l['descricao'], $l['municipio'])['bairro'];
    if (trim((string)$l['bairro']) !== '') $e['bairro'] = trim($l['bairro']);
    return $e;
}

// Uma pergunta ao Nominatim, com cache. Devolve [lat, lon, tipo], null (nao achou) ou false (sem rede).
function alGeoBuscar($q) {
    $chave = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $q)), 'UTF-8');
    $pdo = alDb();
    $c = $pdo->prepare("SELECT lat, lon, tipo, em FROM geo WHERE q=?");
    $c->execute([$chave]);
    if ($r = $c->fetch(PDO::FETCH_ASSOC)) {
        if ($r['lat'] !== null) return [(float)$r['lat'], (float)$r['lon'], $r['tipo']];
        if (strtotime($r['em']) > time() - 30 * 86400) return null; // "nao achou" vale 30 dias
    }
    static $ultimo = 0.0;
    $espera = $ultimo + 1.1 - microtime(true);
    if ($espera > 0) usleep((int)($espera * 1e6));
    $ultimo = microtime(true);
    $r = alHttp(AL_GEO_URL . '?' . http_build_query([
        'q' => $q, 'format' => 'jsonv2', 'limit' => 1, 'countrycodes' => 'br', 'accept-language' => 'pt-BR',
        'viewbox' => '-64.9,5.3,-58.8,-1.6', 'bounded' => 1, // so dentro de Roraima
    ]));
    if ($r === null) return false; // falha de rede: nao guarda, tenta na proxima
    $j = json_decode($r, true);
    $achou = (is_array($j) && !empty($j[0]['lat'])) ? [(float)$j[0]['lat'], (float)$j[0]['lon'], (string)($j[0]['addresstype'] ?? '')] : null;
    $pdo->prepare("INSERT OR REPLACE INTO geo (q, lat, lon, tipo, em) VALUES (?,?,?,?,?)")
        ->execute([$chave, $achou[0] ?? null, $achou[1] ?? null, $achou[2] ?? null, date('Y-m-d H:i:s')]);
    return $achou;
}

// [lat, lon, precisao] do lote; precisao '' = sem ponto (municipio desconhecido); null = sem rede.
function alGeoLote(array $e, $municipio) {
    $sede = alSedesRR()[$municipio] ?? null;
    $perto = function ($r) use ($sede) { return !$sede || alDistKm($sede, $r) <= AL_GEO_RAIO_KM; };
    $cidade = ['city', 'town', 'village', 'municipality', 'county', 'state', 'region', 'country'];
    $local = ($municipio ?: '') . ($municipio ? ', ' : '') . 'Roraima';
    if ($e['rua'] !== '') {
        $r = alGeoBuscar($e['rua'] . ', ' . $local);
        if ($r === false) return null;
        if ($r && !in_array($r[2], $cidade, true) && $perto($r)) {
            $bairro = in_array($r[2], ['suburb', 'neighbourhood', 'quarter', 'city_district'], true);
            return [$r[0], $r[1], $bairro ? 'bairro' : 'endereco'];
        }
    }
    if ($e['bairro'] !== '') {
        $r = alGeoBuscar($e['bairro'] . ', ' . $local);
        if ($r === false) return null;
        // por nome de bairro so vale resposta que E bairro (nao uma loja ou rua com o mesmo nome)
        $tipos_bairro = ['suburb', 'neighbourhood', 'quarter', 'city_district', 'residential', 'hamlet', 'isolated_dwelling', 'allotments', 'borough'];
        if ($r && in_array($r[2], $tipos_bairro, true) && $perto($r)) return [$r[0], $r[1], 'bairro'];
    }
    return $sede ? [$sede[0], $sede[1], 'municipio'] : [null, null, ''];
}

/*
 * Localiza os imoveis ativos ainda sem ponto (ou cujo endereco mudou).
 * Roda no fim da varredura; o que nao couber no tempo fica para a proxima.
 * Devolve quantos foram localizados agora.
 */
function alGeoLotes($limite) {
    $pdo = alDb();
    $up = $pdo->prepare("UPDATE lotes SET lat=?, lon=?, geo=?, geo_chave=? WHERE id=?");
    $n = 0;
    foreach ($pdo->query("SELECT * FROM lotes WHERE oculto=0 ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC) as $l) {
        if (!in_array($l['categoria'], alLotesFamiliaImovel(), true) || !alLoteAtivo($l)) continue;
        $e = alLoteEndereco($l);
        $chave = AL_GEO_VERSAO . '|' . $e['rua'] . '|' . $e['bairro'] . '|' . $l['municipio'];
        if ($l['geo_chave'] === $chave) continue;
        if (microtime(true) > $limite) break;
        $g = alGeoLote($e, $l['municipio']);
        if ($g === null) break; // Nominatim fora do ar: nao grava sede no lugar da rua
        [$lat, $lon, $prec] = $g;
        $up->execute([$lat, $lon, $prec, $chave, $l['id']]);
        $n++;
    }
    return $n;
}

// ---------------------------------------------------------------
// PERSISTENCIA E VARREDURA
// ---------------------------------------------------------------
function alSalvarLote(array $l) {
    $pdo = alDb();
    $agora = date('Y-m-d H:i:s');
    $hash = $l['fonte'] . '|' . $l['id'];
    $extra = json_encode($l['extra'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ins = $pdo->prepare("INSERT OR IGNORE INTO lotes
        (hash, fonte, leilao, comitente, numero, titulo, descricao, categoria, municipio, bairro,
         valor_avaliacao, valor_lance, valor_rotulo, data_leilao, data_fim, situacao, link, foto, extra, criado_em, visto_em)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $ins->execute([$hash, $l['fonte'], $l['leilao'], $l['comitente'], $l['numero'], $l['titulo'], $l['descricao'],
                   $l['categoria'], $l['municipio'], $l['bairro'], $l['valor_avaliacao'], $l['valor_lance'],
                   $l['valor_rotulo'], $l['data_leilao'], $l['data_fim'], $l['situacao'], $l['link'], $l['foto'],
                   $extra, $agora, $agora]);
    if ($ins->rowCount() > 0) return true;

    // Ja conhecido: atualiza o que muda (lance, praca, situacao) e marca como visto.
    $pdo->prepare("UPDATE lotes SET leilao=?, comitente=?, titulo=?, descricao=?, categoria=?, municipio=?, bairro=?,
        valor_avaliacao=?, valor_lance=?, valor_rotulo=?, data_leilao=?, data_fim=?, situacao=?, link=?, foto=?,
        extra=?, visto_em=? WHERE hash=?")
        ->execute([$l['leilao'], $l['comitente'], $l['titulo'], $l['descricao'], $l['categoria'], $l['municipio'],
                   $l['bairro'], $l['valor_avaliacao'], $l['valor_lance'], $l['valor_rotulo'], $l['data_leilao'],
                   $l['data_fim'], $l['situacao'], $l['link'], $l['foto'], $extra, $agora, $hash]);
    return false;
}

// Aberto para lance/proposta? (vale para a linha do banco e para o item recem-lido)
function alLoteAtivo(array $l) {
    if (!empty($l['oculto'])) return false;
    if (preg_match('~SUSPENS|CANCELAD|ENCERRAD|ARREMATAD|VENDID|PREJUDICAD|RETIRAD|SUSTAD~', alNormTxt($l['situacao'] ?? ''))) return false;
    if (!empty($l['data_fim']) && strtotime($l['data_fim']) < time()) return false;
    return true;
}

// Aparece na tela? Ativo E visto na ultima varredura boa da fonte (com folga).
function alLoteVisivel(array $l) {
    if (!alLoteAtivo($l)) return false;
    static $oks = [];
    if (!array_key_exists($l['fonte'], $oks)) $oks[$l['fonte']] = alCfg('lotes_ok_' . $l['fonte']);
    $ok = $oks[$l['fonte']];
    if (!$ok) return true;
    return strtotime($l['visto_em']) >= strtotime($ok) - AL_LOTES_TOLERANCIA;
}

// Chave para reconhecer o mesmo bem em duas fontes (o Spy repete o Amazonas).
function alLoteChave($s) {
    return substr(preg_replace('~[^A-Z0-9]~', '', alNormTxt($s)), 0, 28);
}

function alVarrerLotes() {
    $limite = microtime(true) + AL_LOTES_ORCAMENTO;
    @set_time_limit(AL_LOTES_ORCAMENTO + 60);
    $sel = alLotesSelecao(); $termos = alLotesTermos();
    $res = ['rr' => 0, 'novos' => 0, 'avisar' => [], 'erros' => [], 'parciais' => []];

    $coletados = []; $falhas = 0;
    foreach (['amazonas' => 'alLotesAmazonas', 'wr' => 'alLotesWR', 'spy' => 'alLotesSpy', 'garimpia' => 'alLotesGarimpia', 'receita' => 'alLotesReceita', 'publicjud' => 'alLotesPublicJud'] as $f => $fn) {
        try {
            [$itens, $completo] = $fn($limite);
            $coletados[$f] = $itens;
            if ($completo) alSetCfg('lotes_ok_' . $f, date('Y-m-d H:i:s'));
            else $res['parciais'][] = alLotesFontes()[$f] . ': leitura parcial (acabou o tempo; o cron completa)';
        } catch (Exception $e) {
            $res['erros'][] = alLotesFontes()[$f] . ': ' . $e->getMessage();
            $falhas++;
        }
    }

    // O Spy agrega leiloeiros - inclusive o Amazonas. O mesmo bem nao entra duas vezes.
    $chaves = [];
    foreach (['amazonas', 'wr'] as $f) foreach (($coletados[$f] ?? []) as $l) {
        $chaves[] = alLoteChave($l['titulo']);
        $chaves[] = alLoteChave($l['descricao']);
    }
    $recentes = alDb()->prepare("SELECT titulo, descricao FROM lotes WHERE fonte<>'spy' AND visto_em >= ?");
    $recentes->execute([date('Y-m-d H:i:s', time() - 3 * 86400)]);
    foreach ($recentes as $r) { $chaves[] = alLoteChave($r['titulo']); $chaves[] = alLoteChave($r['descricao']); }
    $chaves = array_filter(array_unique($chaves), function ($c) { return strlen($c) >= 18; });
    if (!empty($coletados['spy'])) {
        $coletados['spy'] = array_values(array_filter($coletados['spy'], function ($l) use ($chaves) {
            $k = preg_replace('~[^A-Z0-9]~', '', alNormTxt($l['titulo'] . ' ' . $l['descricao']));
            foreach ($chaves as $c) if (strpos($k, $c) !== false) return false;
            return true;
        }));
    }

    /*
     * O Garimpia agrega a Caixa (que ja vem pelo Spy) e leiloeiros. Os titulos
     * nao batem entre os dois, mas o VALOR bate: mesmo municipio e um valor
     * igual (ate R$ 2 - o Garimpia arredonda os centavos) = mesmo imovel.
     */
    if (!empty($coletados['garimpia'])) {
        $vals = function (array $l) {
            $v = [(float)$l['valor_lance'], (float)$l['valor_avaliacao']];
            $x = is_array($l['extra'] ?? null) ? $l['extra'] : (json_decode($l['extra'] ?? '', true) ?: []);
            foreach (($x['pracas'] ?? []) as $p) $v[] = (float)$p['valor'];
            return array_filter($v, function ($n) { return $n > 0; });
        };
        $outros = [];
        foreach (['amazonas', 'wr', 'spy'] as $f) foreach (($coletados[$f] ?? []) as $l) $outros[] = [$l['municipio'], $vals($l)];
        $q = alDb()->prepare("SELECT municipio, valor_lance, valor_avaliacao, extra FROM lotes
                              WHERE fonte<>'garimpia' AND oculto=0 AND visto_em >= ?");
        $q->execute([date('Y-m-d H:i:s', time() - 3 * 86400)]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $l) $outros[] = [$l['municipio'], $vals($l)];
        $coletados['garimpia'] = array_values(array_filter($coletados['garimpia'], function ($g) use ($outros, $vals) {
            foreach ($outros as [$mun, $vs]) {
                if ($mun !== $g['municipio']) continue;
                foreach ($vals($g) as $a) foreach ($vs as $b) if (abs($a - $b) < 2) return false;
            }
            return true;
        }));
    }

    foreach ($coletados as $itens) foreach ($itens as $l) {
        $res['rr']++;
        if (!alSalvarLote($l)) continue;
        $res['novos']++;
        $termo = alLoteTermo($l, $termos);
        if (alLoteAtivo($l) && ($termo !== '' || alLoteNaSelecao($l['categoria'], $sel))) {
            $l['termo'] = $termo;
            $res['avisar'][] = $l;
        }
    }

    // Mapa: localiza o que ainda nao tem ponto. Pelo botao do painel ganha uns
    // segundos a mais (cabe nos ~60s do proxy); o cron tem folga.
    $res['geo'] = alGeoLotes(max($limite, microtime(true) + (php_sapi_name() === 'cli' ? 60 : 8)));

    alSetCfg('lotes_ultima', date('Y-m-d H:i:s'));
    alDb()->prepare("INSERT INTO log (status, encontrados, mensagem) VALUES (?,?,?)")
        ->execute([$falhas ? 'erro' : 'ok', count($res['avisar']),
                   "Lotes RR: {$res['rr']} lidos, {$res['novos']} novos, " . count($res['avisar']) . ' na mira'
                   . ($res['geo'] ? ", {$res['geo']} no mapa" : '')
                   . (($res['erros'] || $res['parciais']) ? ' :: ' . implode(' | ', array_merge($res['erros'], $res['parciais'])) : '')]);

    if ($res['avisar'] && alCfg('email_notif')) alNotificarLotes($res['avisar']);
    return $res;
}

function alBRL($v) {
    $v = (float)$v;
    return 'R$ ' . number_format($v, $v >= 1000 ? 0 : 2, ',', '.');
}

// Assunto e corpo separados do envio: da para conferir o texto sem mandar nada.
function alEmailLotes(array $lotes) {
    $termos = array_values(array_unique(array_filter(array_column($lotes, 'termo'))));
    $cats = [];
    foreach ($lotes as $l) $cats[alLoteCatNome($l['categoria'])] = true;
    $titulo = '>> ' . count($lotes) . ' LOTE(S) EM RORAIMA: ' . mb_strtoupper(implode(', ', $termos ?: array_keys($cats)), 'UTF-8');

    $body  = "ALERTAS RR :: RADAR DE LOTES EM RORAIMA\r\n" . str_repeat('=', 50) . "\r\n\r\n";
    foreach ($lotes as $l) {
        $body .= '[' . mb_strtoupper(alLoteCatNome($l['categoria']), 'UTF-8') . '] ' . $l['titulo'] . "\r\n";
        if (!empty($l['termo']))     $body .= "    VIGIADO..: {$l['termo']}\r\n";
        $onde = trim(($l['bairro'] ? $l['bairro'] . ', ' : '') . ($l['municipio'] ?: 'Roraima'));
        $body .= "    onde.....: {$onde}\r\n";
        if ($l['data_leilao'])       $body .= '    quando...: ' . date('d/m/Y H:i', strtotime($l['data_leilao'])) . "\r\n";
        $val = [];
        if ($l['valor_avaliacao'] > 0) $val[] = 'avaliacao ' . alBRL($l['valor_avaliacao']);
        if ($l['valor_lance'] > 0)     $val[] = mb_strtolower($l['valor_rotulo'], 'UTF-8') . ' ' . alBRL($l['valor_lance']);
        if ($val)                      $body .= '    valor....: ' . implode(' | ', $val) . "\r\n";
        $quem = trim(alLotesFontes()[$l['fonte']] . ($l['comitente'] ? ' · ' . $l['comitente'] : ''));
        $body .= "    fonte....: {$quem}\r\n";
        $body .= "    link.....: {$l['link']}\r\n\r\n";
    }
    $body .= str_repeat('-', 50) . "\r\ntodos os lotes: " . ALERTAS_URL . "\r\n";
    return [$titulo, $body];
}

function alNotificarLotes(array $lotes) {
    [$titulo, $body] = alEmailLotes($lotes);
    $subject = '=?UTF-8?B?' . base64_encode($titulo) . '?=';
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "From: ALERTAS RR <" . ALERTAS_EMAIL_FROM . ">\r\n";
    @mail(ALERTAS_EMAIL_DESTINO, $subject, $body, $headers);
}

<?php
// ============================================================
// Futsal da Firma — API REST (JSON) com banco de dados em arquivo
// Sem MySQL: os dados ficam em data/futsal.json (ver db.php).
// Contrato idêntico ao da versão MySQL — o index.html não muda.
// ============================================================

require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');

function responder(array $dados, int $status = 200): void
{
    global $LOCK;
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    db_unlock($LOCK ?? null);
    exit;
}

// 1. VERIFICAÇÃO DE INSTALAÇÃO (trava fica em data/, então apagar install/ é seguro)
if (!sistema_instalado()) {
    http_response_code(503);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Sistema não instalado. Por favor, acesse a pasta /install para realizar a configuração inicial.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 2. SESSÃO SEGURA
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

$method = $_SERVER['REQUEST_METHOD'];

// 3. PROTEÇÃO CSRF: requisições de escrita precisam ser JSON (formulários de outros sites
//    não conseguem enviar Content-Type application/json sem preflight CORS).
if ($method !== 'GET') {
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') === false) {
        http_response_code(415);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Requisição inválida.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $hostOrigin = parse_url($origin, PHP_URL_HOST);
        if ($hostOrigin && strcasecmp($hostOrigin, $_SERVER['HTTP_HOST'] ? explode(':', $_SERVER['HTTP_HOST'])[0] : '') !== 0) {
            http_response_code(403);
            echo json_encode(['sucesso' => false, 'mensagem' => 'Origem não permitida.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

// 4. LOCK: GET usa lock compartilhado; escrita usa exclusivo durante todo o ciclo ler->gravar
$LOCK = db_lock($method !== 'GET');
$db = db_ler();

function verificarAutenticacao(): void
{
    if (empty($_SESSION['admin_logado']) || $_SESSION['admin_logado'] !== true) {
        responder(['sucesso' => false, 'mensagem' => 'Acesso negado. Por favor, faça login.'], 403);
    }
}

function nome_valido(string $nome): string
{
    $nome = trim(strip_tags($nome));
    return mb_substr($nome, 0, 80);
}

function valor_valido($v): float
{
    $f = round(floatval($v), 2);
    return $f < 0 ? -1 : $f;
}

/** Limite de confirmados (0 = sem limite). */
function max_jogadores(array $db): int
{
    return max(0, intval(db_config($db, 'max_jogadores', '0')));
}

function lista_cheia(array $db): bool
{
    $max = max_jogadores($db);
    return $max > 0 && count($db['jogadores']) >= $max;
}

// ==========================================
// CRAQUE DO DIA — votação aberta do dia do jogo até 2 dias depois
// ==========================================
const CRAQUE_DIAS_VALIDADE = 2;

/** Data efetiva de um jogo da agenda (considera reagendamento). */
function data_efetiva_jogo(array $g): string
{
    return (($g['status'] ?? '') === 'reagendado' && !empty($g['nova_data'])) ? $g['nova_data'] : $g['data'];
}

/** Jogo cuja votação está aberta hoje (dia do jogo até +2 dias), ou null. */
function jogo_votacao_aberta(array $db): ?array
{
    $hoje = date('Y-m-d');
    $limite = date('Y-m-d', strtotime($hoje . ' -' . CRAQUE_DIAS_VALIDADE . ' days'));
    $melhor = null;
    foreach ($db['agenda'] ?? [] as $g) {
        if (($g['status'] ?? '') === 'cancelado') continue;
        $d = data_efetiva_jogo($g);
        if ($d <= $hoje && $d >= $limite && (!$melhor || $d > data_efetiva_jogo($melhor))) $melhor = $g;
    }
    return $melhor;
}

/** Candidatos atuais: goleiros fixos + jogadores confirmados. */
function candidatos_atuais(array $db): array
{
    $cand = [];
    foreach ($db['goleiros'] ?? [] as $g) { $n = trim($g['nome'] ?? ''); if ($n !== '') $cand[] = $n; }
    foreach ($db['jogadores'] ?? [] as $j) { $n = trim($j['nome'] ?? ''); if ($n !== '') $cand[] = $n; }
    return array_values(array_unique($cand));
}

/** Votação do jogo: a gravada ou uma "virtual" (somente leitura) ainda sem votos. */
function obter_votacao(array $db, array $jogo): array
{
    $d = data_efetiva_jogo($jogo);
    if (isset($db['votacoes'][$d])) return $db['votacoes'][$d];
    return [
        'data' => $d,
        'encerra_em' => date('Y-m-d', strtotime($d . ' +' . CRAQUE_DIAS_VALIDADE . ' days')),
        'candidatos' => candidatos_atuais($db),
        'votos' => [],
        'eleitores' => [],
    ];
}

/** Resumo público de uma votação (sem dados de eleitores). */
function resumo_votacao(array $v, bool $aberta, string $chaveEleitor = ''): array
{
    $votos = (array)($v['votos'] ?? []);
    arsort($votos);
    $lider = null; $max = 0; $empate = [];
    foreach ($votos as $nome => $qtd) {
        if ($qtd > $max) { $max = $qtd; $empate = [$nome]; }
        elseif ($qtd === $max && $qtd > 0) { $empate[] = $nome; }
    }
    $eleitores = (array)($v['eleitores'] ?? []);
    return [
        'data' => $v['data'], 'encerra_em' => $v['encerra_em'], 'aberta' => $aberta,
        'candidatos' => $v['candidatos'] ?? [], 'votos' => $votos, 'total' => array_sum($votos),
        'lideres' => $empate, 'max_votos' => $max,
        'meu_voto' => $chaveEleitor !== '' ? ($eleitores[$chaveEleitor] ?? null) : null,
    ];
}

function chave_eleitor(): string
{
    $tok = $_COOKIE['futsal_voto'] ?? '';
    if (!preg_match('/^[a-f0-9]{32}$/', $tok)) {
        $tok = bin2hex(random_bytes(16));
        setcookie('futsal_voto', $tok, ['expires' => time() + 31536000, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    }
    return hash('sha256', $tok);
}

// ==========================================
// GET — leitura pública do painel
// ==========================================
if ($method === 'GET') {
    $jogadores = $db['jogadores'];
    usort($jogadores, fn($a, $b) => $a['id'] <=> $b['id']);

    $pagamentos = $db['pagamentos_quadra'];
    usort($pagamentos, fn($a, $b) => $b['id'] <=> $a['id']);

    $pendencias = $db['pendencias'];
    usort($pendencias, fn($a, $b) => $b['id'] <=> $a['id']);

    $totalPagoQuadra = array_reduce($pagamentos, fn($s, $p) => $s + floatval($p['valor']), 0.0);

    $logado = !empty($_SESSION['admin_logado']);
    $admins = $logado
        ? array_map(fn($u) => ['id' => $u['id'], 'usuario' => $u['usuario']], $db['usuarios'])
        : [];

    $resp = [
        'jogadores' => array_values($jogadores),
        'valor_quadra' => floatval(db_config($db, 'custo_quadra', '0')),
        'saldo_acumulado' => floatval(db_config($db, 'saldo_acumulado', '0')),
        'chave_pix' => db_config($db, 'chave_pix'),
        'whatsapp' => db_config($db, 'whatsapp'),
        'valor_pendencia' => floatval(db_config($db, 'valor_pendencia', '10')),
        'max_jogadores' => max_jogadores($db),
        'goleiros' => array_values(array_map(fn($g) => ['slot' => intval($g['slot']), 'nome' => $g['nome'] ?? '', 'foto' => $g['foto'] ?? ''], $db['goleiros'])),
        'total_pago_quadra' => floatval($totalPagoQuadra),
        'pagamentos_quadra' => array_values($pagamentos),
        'pendencias_acumuladas' => array_values($pendencias),
        'administradores' => array_values($admins),
        'sessao_ativa' => $logado,
    ];
    if ($logado) {
        $resp['historico_etapas'] = array_values(array_reverse($db['historico_etapas']));
        $resp['fila_espera'] = array_values(array_map(fn($f) => ['id' => $f['id'], 'nome' => $f['nome'], 'criado_em' => $f['criado_em']], $db['fila_espera']));
    }
    $resp['total_fila_espera'] = count($db['fila_espera']);

    // Agenda: público vê só jogos de hoje em diante; admin vê tudo (para gerenciar)
    $hoje = date('Y-m-d');
    $agenda = $db['agenda'] ?? [];
    usort($agenda, fn($a, $b) => strcmp($a['data'] . ($a['hora'] ?? ''), $b['data'] . ($b['hora'] ?? '')));
    $resp['agenda'] = array_values($logado ? $agenda : array_filter($agenda, function ($g) use ($hoje) {
        $efetiva = ($g['status'] ?? '') === 'reagendado' && !empty($g['nova_data']) ? $g['nova_data'] : $g['data'];
        return $g['data'] >= $hoje || $efetiva >= $hoje;
    }));
    $resp['hoje'] = $hoje;

    // Craque do Dia: votação aberta (dia do jogo até +2 dias) ou o último craque eleito
    $jv = jogo_votacao_aberta($db);
    if ($jv) {
        $resp['craque'] = resumo_votacao(obter_votacao($db, $jv), true, chave_eleitor());
    } else {
        $resp['craque'] = null;
        $datas = array_keys($db['votacoes'] ?? []);
        rsort($datas);
        foreach ($datas as $d) {
            $r = resumo_votacao($db['votacoes'][$d], false);
            if ($r['total'] > 0) { $resp['craque'] = $r; break; }
        }
    }
    if ($logado) {
        $hist = [];
        foreach ($db['votacoes'] ?? [] as $d => $v) $hist[] = resumo_votacao($v, $jv && data_efetiva_jogo($jv) === $d);
        usort($hist, fn($a, $b) => strcmp($b['data'], $a['data']));
        $resp['historico_craques'] = $hist;
    }
    // Admin vê o último sorteio sempre; público só vê depois de confirmado (sem dados internos)
    $s = $db['ultimo_sorteio'];
    if ($logado) {
        $resp['ultimo_sorteio'] = $s;
    } elseif ($s && !empty($s['confirmado'])) {
        $resp['ultimo_sorteio'] = ['criado_em' => $s['criado_em'], 'times' => $s['times'], 'reservas' => $s['reservas'] ?? [], 'confirmado' => true];
    } else {
        $resp['ultimo_sorteio'] = null;
    }
    responder($resp);
}

// ==========================================
// POST
// ==========================================
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) $data = [];
    $acao = $data['acao'] ?? '';

    // ---------- LOGIN (com limite de tentativas) ----------
    if ($acao === 'login') {
        $usuario = trim($data['usuario'] ?? '');
        $senha = (string)($data['senha'] ?? '');

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'x';
        $chave = hash('sha256', $ip);
        $tent = $db['tentativas_login'][$chave] ?? ['n' => 0, 't' => 0];
        if ($tent['n'] >= 5 && (time() - $tent['t']) < 900) {
            responder(['sucesso' => false, 'mensagem' => 'Muitas tentativas. Aguarde 15 minutos.'], 429);
        }

        $userObj = null;
        foreach ($db['usuarios'] as $u) {
            if (hash_equals($u['usuario'], $usuario)) { $userObj = $u; break; }
        }

        if ($userObj && password_verify($senha, $userObj['senha'])) {
            session_regenerate_id(true);
            $_SESSION['admin_logado'] = true;
            $_SESSION['usuario'] = $userObj['usuario'];
            unset($db['tentativas_login'][$chave]);
            db_gravar($db);
            responder(['sucesso' => true, 'usuario' => $userObj['usuario']]);
        }

        $n = ((time() - $tent['t']) < 900) ? $tent['n'] + 1 : 1;
        $db['tentativas_login'][$chave] = ['n' => $n, 't' => time()];
        // limpa registros antigos
        foreach ($db['tentativas_login'] as $k => $v) {
            if (time() - $v['t'] > 3600) unset($db['tentativas_login'][$k]);
        }
        db_gravar($db);
        responder(['sucesso' => false, 'mensagem' => 'Usuário ou senha incorretos.'], 401);
    }

    if ($acao === 'logout') {
        $_SESSION = [];
        session_destroy();
        responder(['sucesso' => true]);
    }

    // ---------- CONFIRMAR PRESENÇA (público — vai para a fila de espera) ----------
    // ---------- CRAQUE DO DIA (público): 1 voto por aparelho, pode trocar enquanto aberta ----------
    if ($acao === 'votar_craque') {
        $jv = jogo_votacao_aberta($db);
        if (!$jv) responder(['sucesso' => false, 'mensagem' => 'A votação do Craque do Dia está fechada. Ela abre no dia do jogo e vale por 2 dias.']);
        $v = obter_votacao($db, $jv);
        $d = $v['data'];
        $nome = trim($data['nome'] ?? '');
        if (!in_array($nome, $v['candidatos'], true)) responder(['sucesso' => false, 'mensagem' => 'Escolha um jogador da lista.']);

        // Anti-abuso extra: no máximo 3 aparelhos diferentes por IP em cada votação
        $ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'x') . '|' . $d);
        $eleitor = chave_eleitor();
        $v['ips'] = (array)($v['ips'] ?? []);
        $v['votos'] = (array)($v['votos'] ?? []);
        $v['eleitores'] = (array)($v['eleitores'] ?? []);
        $anterior = $v['eleitores'][$eleitor] ?? null;
        if ($anterior === null) {
            $doIp = $v['ips'][$ipHash] ?? 0;
            if ($doIp >= 3) responder(['sucesso' => false, 'mensagem' => 'Limite de votos desta rede atingido.']);
            $v['ips'][$ipHash] = $doIp + 1;
        } elseif ($anterior === $nome) {
            responder(['sucesso' => true, 'mensagem' => 'Você já votou em ' . $nome . '!', 'craque' => resumo_votacao($v, true, $eleitor)]);
        } else {
            $v['votos'][$anterior] = max(0, ($v['votos'][$anterior] ?? 1) - 1);
            if ($v['votos'][$anterior] === 0) unset($v['votos'][$anterior]);
        }
        $v['votos'][$nome] = ($v['votos'][$nome] ?? 0) + 1;
        $v['eleitores'][$eleitor] = $nome;
        $db['votacoes'][$d] = $v;
        // Mantém só as últimas 30 votações
        if (count($db['votacoes']) > 30) { ksort($db['votacoes']); $db['votacoes'] = array_slice($db['votacoes'], -30, null, true); }
        db_gravar($db);
        responder(['sucesso' => true, 'mensagem' => $anterior ? 'Voto alterado para ' . $nome . '!' : 'Voto registrado em ' . $nome . '! ⭐', 'craque' => resumo_votacao($v, true, $eleitor)]);
    }

    if ($acao === 'confirmar_presenca') {
        $nome = nome_valido($data['nome'] ?? '');
        if (mb_strlen($nome) < 2) {
            responder(['sucesso' => false, 'mensagem' => 'Informe seu nome.']);
        }
        if (lista_cheia($db)) {
            responder(['sucesso' => false, 'mensagem' => 'Lista completa! Todas as vagas já foram preenchidas.']);
        }

        // Anti-spam: por IP, 1 pedido a cada 30s e no máximo 3 na fila
        $ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'x');
        $doIp = array_filter($db['fila_espera'], fn($f) => ($f['ip_hash'] ?? '') === $ipHash);
        if (count($doIp) >= 3) {
            responder(['sucesso' => false, 'mensagem' => 'Você já tem pedidos aguardando aprovação.']);
        }
        foreach ($doIp as $f) {
            if (time() - strtotime($f['criado_em']) < 30) {
                responder(['sucesso' => false, 'mensagem' => 'Aguarde alguns segundos antes de enviar outro pedido.']);
            }
        }
        if (count($db['fila_espera']) >= 100) {
            responder(['sucesso' => false, 'mensagem' => 'Fila de espera cheia. Fale com o organizador.']);
        }

        // Evita duplicados (já na fila ou já confirmado)
        $norm = fn($s) => mb_strtolower(trim(preg_replace('/\s+/', ' ', $s)));
        foreach ($db['fila_espera'] as $f) {
            if ($norm($f['nome']) === $norm($nome)) {
                responder(['sucesso' => false, 'mensagem' => 'Esse nome já está na fila de espera.']);
            }
        }
        foreach ($db['jogadores'] as $j) {
            if ($norm($j['nome']) === $norm($nome)) {
                responder(['sucesso' => false, 'mensagem' => 'Esse nome já está na lista de confirmados.']);
            }
        }

        $db['fila_espera'][] = [
            'id' => db_proximo_id($db, 'fila_espera'),
            'nome' => $nome,
            'criado_em' => date('Y-m-d H:i:s'),
            'ip_hash' => $ipHash,
        ];
        db_gravar($db);
        responder(['sucesso' => true, 'mensagem' => 'Pedido enviado! Aguarde a aprovação do organizador.']);
    }

    // ========================================================
    // ABAIXO DESTA LINHA, APENAS ADMINISTRADORES PODEM AGIR
    // ========================================================
    verificarAutenticacao();

    if ($acao === 'zerar_votacao_craque') {
        $d = trim($data['data'] ?? '');
        if (!isset($db['votacoes'][$d])) responder(['sucesso' => false, 'mensagem' => 'Votação não encontrada.']);
        unset($db['votacoes'][$d]);
        db_gravar($db);
        responder(['sucesso' => true, 'mensagem' => 'Votação zerada.']);
    }

    // ---------- FILA DE ESPERA (admin) ----------
    if ($acao === 'aceitar_fila' || $acao === 'recusar_fila') {
        $id = intval($data['id'] ?? 0);
        $item = null;
        foreach ($db['fila_espera'] as $f) {
            if (intval($f['id']) === $id) { $item = $f; break; }
        }
        if (!$item) responder(['sucesso' => false, 'mensagem' => 'Pedido não encontrado.']);

        $db['fila_espera'] = array_values(array_filter($db['fila_espera'], fn($f) => intval($f['id']) !== $id));
        if ($acao === 'aceitar_fila') {
            if (lista_cheia($db)) {
                responder(['sucesso' => false, 'mensagem' => 'Lista completa (' . max_jogadores($db) . ' vagas). Aumente o limite ou recuse o pedido.']);
            }
            $db['jogadores'][] = [
                'id' => db_proximo_id($db, 'jogadores'),
                'nome' => $item['nome'],
                'valor_pago' => 0,
                'convidado' => !empty($data['convidado']),
                'criado_em' => date('Y-m-d H:i:s'),
            ];
        }
        db_gravar($db);
        responder(['sucesso' => true]);
    }

    if ($acao === 'aceitar_toda_fila') {
        $restantes = [];
        $aceitos = 0;
        foreach ($db['fila_espera'] as $f) {
            if (lista_cheia($db)) { $restantes[] = $f; continue; }
            $db['jogadores'][] = [
                'id' => db_proximo_id($db, 'jogadores'),
                'nome' => $f['nome'],
                'valor_pago' => 0,
                'criado_em' => date('Y-m-d H:i:s'),
            ];
            $aceitos++;
        }
        $db['fila_espera'] = $restantes;
        db_gravar($db);
        $msg = "$aceitos pedido(s) aceito(s).";
        if ($restantes) $msg .= ' ' . count($restantes) . ' ficaram na fila porque a lista está completa.';
        responder(['sucesso' => true, 'mensagem' => $msg]);
    }


    // ---------- GOLEIROS FIXOS (não pagam, fora da contabilidade) ----------
    if ($acao === 'salvar_goleiro' || $acao === 'foto_goleiro' || $acao === 'remover_foto_goleiro') {
        $slot = intval($data['slot'] ?? 0);
        $idx = null;
        foreach ($db['goleiros'] as $i => $g) {
            if (intval($g['slot']) === $slot) { $idx = $i; break; }
        }
        if ($idx === null) responder(['sucesso' => false, 'mensagem' => 'Vaga de goleiro inválida.']);

        $apagarFoto = function (string $rel) {
            if ($rel !== '' && preg_match('#^fotos/goleiro\d_[a-z0-9]+\.(jpg|png|webp)$#', $rel)) {
                @unlink(__DIR__ . '/' . $rel);
            }
        };

        if ($acao === 'salvar_goleiro') {
            $db['goleiros'][$idx]['nome'] = nome_valido($data['nome'] ?? '');
            db_gravar($db);
            responder(['sucesso' => true, 'mensagem' => 'Goleiro salvo!']);
        }

        if ($acao === 'remover_foto_goleiro') {
            $apagarFoto($db['goleiros'][$idx]['foto'] ?? '');
            $db['goleiros'][$idx]['foto'] = '';
            db_gravar($db);
            responder(['sucesso' => true]);
        }

        // foto_goleiro: imagem em base64 (data URL), já redimensionada no navegador
        $img = (string)($data['imagem'] ?? '');
        if (!preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#', $img, $m)) {
            responder(['sucesso' => false, 'mensagem' => 'Formato de imagem inválido (use JPG, PNG ou WEBP).']);
        }
        $bin = base64_decode($m[2], true);
        if ($bin === false || strlen($bin) > 2 * 1024 * 1024) {
            responder(['sucesso' => false, 'mensagem' => 'Imagem inválida ou maior que 2 MB.']);
        }
        $info = @getimagesizefromstring($bin);
        $tipos = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
        if (!$info || !isset($tipos[$info[2]])) {
            responder(['sucesso' => false, 'mensagem' => 'O arquivo enviado não é uma imagem válida.']);
        }
        $ext = $tipos[$info[2]];

        // Se a extensão GD existir, recodifica a imagem (remove qualquer conteúdo oculto)
        if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
            $gd = @imagecreatefromstring($bin);
            if ($gd) {
                ob_start();
                imagejpeg($gd, null, 92);
                $bin = ob_get_clean();
                imagedestroy($gd);
                $ext = 'jpg';
            }
        }

        if (!is_dir(FOTOS_DIR)) @mkdir(FOTOS_DIR, 0755, true);
        $nomeArq = 'goleiro' . $slot . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (@file_put_contents(FOTOS_DIR . '/' . $nomeArq, $bin) === false) {
            responder(['sucesso' => false, 'mensagem' => 'Sem permissão de escrita na pasta fotos/.']);
        }

        $apagarFoto($db['goleiros'][$idx]['foto'] ?? '');
        $db['goleiros'][$idx]['foto'] = 'fotos/' . $nomeArq;
        db_gravar($db);
        responder(['sucesso' => true, 'foto' => 'fotos/' . $nomeArq]);
    }

    // ---------- SORTEIO DE TIMES (evita repetir duplas entre etapas) ----------
    if ($acao === 'sortear_times') {
        // Times fixos de 4 jogadores de linha. Goleiros não entram no sorteio.
        $POR_TIME = 4;
        // Separa membros fixos e convidados (sem nomes repetidos)
        $fixos = []; $convidados = []; $vistos = [];
        foreach ($db['jogadores'] as $j) {
            $n = trim($j['nome'] ?? '');
            $k = mb_strtolower($n);
            if ($n === '' || isset($vistos[$k])) continue;
            $vistos[$k] = true;
            if (!empty($j['convidado'])) $convidados[] = $n; else $fixos[] = $n;
        }
        $total = count($fixos) + count($convidados);
        $qtdTimes = intdiv($total, $POR_TIME);
        if ($qtdTimes < 2) {
            responder(['sucesso' => false, 'mensagem' => 'Jogadores insuficientes: são necessários pelo menos ' . ($POR_TIME * 2) . ' jogadores de linha (2 times de ' . $POR_TIME . '). Hoje há ' . $total . '.']);
        }
        // Ordem de prioridade: fixos (embaralhados) primeiro, convidados (embaralhados) por último
        shuffle($fixos);
        shuffle($convidados);
        $ordem = array_merge($fixos, $convidados);
        $vagas = $qtdTimes * $POR_TIME;
        $jogam = array_slice($ordem, 0, $vagas);
        // Quem sobra (não completa 4) forma o próximo time (ex.: Time 4, Time 5...) — incompleto
        $sobra = array_values(array_slice($ordem, $vagas)); // convidados ficam por último aqui
        $reservas = [];
        // Times 1 e 2 (A e B): as 8 primeiras vagas, preenchidas pelos fixos.
        // Convidados só entram em A/B se faltar fixo. Times 3+ recebem o restante.
        $grupoAB = array_slice($jogam, 0, 2 * $POR_TIME);
        $grupoResto = array_slice($jogam, 2 * $POR_TIME);
        $mapaConvidado = array_fill_keys(array_map('mb_strtolower', $convidados), true);

        $norm = fn($s) => mb_strtolower(trim(preg_replace('/\s+/', ' ', $s)));
        $chave = function ($a, $b) use ($norm) {
            $x = [$norm($a), $norm($b)];
            sort($x);
            return $x[0] . '|' . $x[1];
        };
        $pares = $db['pares_sorteio'] ?? [];

        // Custo = soma (vezes que cada dupla já jogou junta)^2  -> penaliza muito repetir a mesma dupla
        $custo = function (array $times) use ($pares, $chave) {
            $c = 0;
            foreach ($times as $t) {
                $n = count($t);
                for ($i = 0; $i < $n; $i++) {
                    for ($k = $i + 1; $k < $n; $k++) {
                        $v = $pares[$chave($t[$i], $t[$k])] ?? 0;
                        $c += $v * $v;
                    }
                }
            }
            return $c;
        };

        // Distribui um grupo de nomes em N times minimizando duplas repetidas
        // (busca aleatória + melhoria por trocas; trocas só acontecem dentro do grupo)
        $otimizar = function (array $nomesGrupo, int $n) use ($custo) {
            if ($n <= 0) return [];
            $melhor = null;
            $melhorCusto = PHP_INT_MAX;
            for ($t = 0; $t < 80 && $melhorCusto > 0; $t++) {
                $emb = $nomesGrupo;
                shuffle($emb);
                $times = array_fill(0, $n, []);
                foreach ($emb as $i => $nm) $times[$i % $n][] = $nm;
                $c = $custo($times);
                $melhorou = true;
                $voltas = 0;
                while ($melhorou && $c > 0 && $voltas++ < 10) {
                    $melhorou = false;
                    for ($a = 0; $a < $n; $a++) {
                        for ($b = $a + 1; $b < $n; $b++) {
                            foreach ($times[$a] as $ia => $pa) {
                                foreach ($times[$b] as $ib => $pb) {
                                    $times[$a][$ia] = $pb; $times[$b][$ib] = $pa;
                                    $nc = $custo($times);
                                    if ($nc < $c) { $c = $nc; $melhorou = true; $pa = $pb; }
                                    else { $times[$a][$ia] = $pa; $times[$b][$ib] = $pb; }
                                }
                            }
                        }
                    }
                }
                if ($c < $melhorCusto) { $melhorCusto = $c; $melhor = $times; }
            }
            return $melhor;
        };

        $melhor = array_merge($otimizar($grupoAB, 2), $otimizar($grupoResto, $qtdTimes - 2));
        // Time subsequente com quem sobrou (menos de 4 jogadores)
        $idxIncompleto = -1;
        if (count($sobra) > 0) {
            $melhor[] = $sobra;
            $idxIncompleto = count($melhor) - 1;
        }

        // Conta duplas repetidas no resultado (para mostrar no popup)
        $repetidas = [];
        $resultado = [];
        foreach ($melhor as $i => $t) {
            shuffle($t);
            $n = count($t);
            for ($x = 0; $x < $n; $x++) {
                for ($y = $x + 1; $y < $n; $y++) {
                    $v = $pares[$chave($t[$x], $t[$y])] ?? 0;
                    if ($v > 0) $repetidas[] = ['a' => $t[$x], 'b' => $t[$y], 'vezes' => $v];
                }
            }
            $resultado[] = [
                'nome' => 'Time ' . ($i + 1),
                'jogadores' => $t,
                'incompleto' => ($i === $idxIncompleto),
                'convidados' => array_values(array_filter($t, fn($nm) => isset($mapaConvidado[mb_strtolower($nm)]))),
            ];
        }

        $sorteio = [
            'criado_em' => date('Y-m-d H:i:s'),
            'por' => $_SESSION['usuario'] ?? '',
            'times' => $resultado,
            'reservas' => $reservas,
            'reservas_convidados' => array_values(array_filter($reservas, fn($nm) => isset($mapaConvidado[mb_strtolower($nm)]))),
            'repeticoes' => $repetidas,
            'confirmado' => false,
        ];
        $db['ultimo_sorteio'] = $sorteio;
        db_gravar($db);
        responder(['sucesso' => true, 'sorteio' => $sorteio]);
    }

    // Confirmar o sorteio: só aqui as duplas entram na memória (refazer o sorteio não "gasta" combinações)
    if ($acao === 'confirmar_sorteio') {
        $s = $db['ultimo_sorteio'];
        if (!$s) responder(['sucesso' => false, 'mensagem' => 'Nenhum sorteio para confirmar.']);
        if (!empty($s['confirmado'])) responder(['sucesso' => false, 'mensagem' => 'Este sorteio já foi confirmado.']);

        $norm = fn($x) => mb_strtolower(trim(preg_replace('/\s+/', ' ', $x)));
        foreach ($s['times'] as $t) {
            $js = $t['jogadores'];
            $n = count($js);
            for ($x = 0; $x < $n; $x++) {
                for ($y = $x + 1; $y < $n; $y++) {
                    $p = [$norm($js[$x]), $norm($js[$y])];
                    sort($p);
                    $k = $p[0] . '|' . $p[1];
                    $db['pares_sorteio'][$k] = ($db['pares_sorteio'][$k] ?? 0) + 1;
                }
            }
        }
        $db['ultimo_sorteio']['confirmado'] = true;
        $db['historico_sorteios'][] = $db['ultimo_sorteio'];
        $db['historico_sorteios'] = array_slice($db['historico_sorteios'], -50);
        db_gravar($db);
        responder(['sucesso' => true, 'mensagem' => 'Times confirmados! As duplas foram registradas para evitar repetição nos próximos sorteios.']);
    }

    if ($acao === 'zerar_memoria_sorteio') {
        $db['pares_sorteio'] = [];
        db_gravar($db);
        responder(['sucesso' => true, 'mensagem' => 'Memória de duplas zerada.']);
    }

    // ---------- AGENDA DE JOGOS ----------
    if ($acao === 'salvar_jogo') {
        $id = intval($data['id'] ?? 0);
        $dataJogo = trim($data['data'] ?? '');
        $hora = trim($data['hora'] ?? '');
        $status = $data['status'] ?? 'confirmado';
        $novaData = trim($data['nova_data'] ?? '');
        $novaHora = trim($data['nova_hora'] ?? '');
        $local = mb_substr(trim(strip_tags($data['local'] ?? '')), 0, 100);
        $obs = mb_substr(trim(strip_tags($data['obs'] ?? '')), 0, 200);

        $dataOk = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4));
        $horaOk = fn($h) => $h === '' || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $h);

        if (!$dataOk($dataJogo)) responder(['sucesso' => false, 'mensagem' => 'Data do jogo inválida.']);
        if (!$horaOk($hora)) responder(['sucesso' => false, 'mensagem' => 'Horário inválido.']);
        if (!in_array($status, ['confirmado', 'reagendado', 'cancelado'], true)) responder(['sucesso' => false, 'mensagem' => 'Situação inválida.']);
        if ($status === 'reagendado') {
            if (!$dataOk($novaData)) responder(['sucesso' => false, 'mensagem' => 'Informe a nova data do jogo reagendado.']);
            if (!$horaOk($novaHora)) responder(['sucesso' => false, 'mensagem' => 'Novo horário inválido.']);
        } else {
            $novaData = ''; $novaHora = '';
        }

        $registro = [
            'data' => $dataJogo, 'hora' => $hora, 'local' => $local, 'status' => $status,
            'nova_data' => $novaData, 'nova_hora' => $novaHora, 'obs' => $obs,
        ];

        if ($id > 0) {
            $achou = false;
            foreach ($db['agenda'] as &$g) {
                if (intval($g['id']) === $id) { $g = array_merge($g, $registro); $achou = true; break; }
            }
            unset($g);
            if (!$achou) responder(['sucesso' => false, 'mensagem' => 'Jogo não encontrado.']);
        } else {
            foreach ($db['agenda'] as $g) {
                if ($g['data'] === $dataJogo) responder(['sucesso' => false, 'mensagem' => 'Já existe um jogo cadastrado nessa data.']);
            }
            $registro['id'] = db_proximo_id($db, 'agenda');
            $db['agenda'][] = $registro;
        }
        db_gravar($db);
        responder(['sucesso' => true, 'mensagem' => 'Jogo salvo!']);
    }

    // Gera jogos semanais (mesmo dia da semana e horário), pulando datas já cadastradas
    if ($acao === 'gerar_recorrencia') {
        $inicio = trim($data['inicio'] ?? '');
        $semanas = intval($data['semanas'] ?? 0);
        $hora = trim($data['hora'] ?? '');
        $local = mb_substr(trim(strip_tags($data['local'] ?? '')), 0, 100);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $inicio) || !checkdate((int)substr($inicio, 5, 2), (int)substr($inicio, 8, 2), (int)substr($inicio, 0, 4))) {
            responder(['sucesso' => false, 'mensagem' => 'Data do primeiro jogo inválida.']);
        }
        if ($semanas < 1 || $semanas > 52) responder(['sucesso' => false, 'mensagem' => 'Escolha entre 1 e 52 semanas.']);
        if ($hora !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) responder(['sucesso' => false, 'mensagem' => 'Horário inválido.']);

        $existentes = array_fill_keys(array_map(fn($g) => $g['data'], $db['agenda']), true);
        $d = new DateTime($inicio);
        $criados = 0; $pulados = 0;
        for ($i = 0; $i < $semanas; $i++) {
            $iso = $d->format('Y-m-d');
            if (isset($existentes[$iso])) {
                $pulados++;
            } else {
                $db['agenda'][] = [
                    'id' => db_proximo_id($db, 'agenda'), 'data' => $iso, 'hora' => $hora, 'local' => $local,
                    'status' => 'confirmado', 'nova_data' => '', 'nova_hora' => '', 'obs' => '',
                ];
                $criados++;
            }
            $d->modify('+7 days');
        }
        db_gravar($db);
        $msg = "$criados jogo(s) criado(s).";
        if ($pulados) $msg .= " $pulados data(s) já existiam e foram mantidas.";
        responder(['sucesso' => true, 'mensagem' => $msg]);
    }

    // Exclui vários jogos de uma vez (lista de IDs)
    if ($acao === 'excluir_jogos') {
        $ids = $data['ids'] ?? [];
        if (!is_array($ids) || count($ids) === 0) responder(['sucesso' => false, 'mensagem' => 'Nenhum jogo selecionado.']);
        $ids = array_fill_keys(array_map('intval', $ids), true);
        $antes = count($db['agenda']);
        $db['agenda'] = array_values(array_filter($db['agenda'], fn($g) => !isset($ids[intval($g['id'])])));
        $removidos = $antes - count($db['agenda']);
        db_gravar($db);
        responder(['sucesso' => true, 'mensagem' => "$removidos jogo(s) excluído(s)."]);
    }

    if ($acao === 'excluir_jogo') {
        $id = intval($data['id'] ?? 0);
        $antes = count($db['agenda']);
        $db['agenda'] = array_values(array_filter($db['agenda'], fn($g) => intval($g['id']) !== $id));
        if (count($db['agenda']) === $antes) responder(['sucesso' => false, 'mensagem' => 'Jogo não encontrado.']);
        db_gravar($db);
        responder(['sucesso' => true]);
    }

    if ($acao === 'salvar_max_jogadores') {
        $v = intval($data['valor'] ?? 0);
        if ($v < 0 || $v > 500) responder(['sucesso' => false, 'mensagem' => 'Informe um número entre 0 e 500 (0 = sem limite).']);
        db_set_config($db, 'max_jogadores', strval($v));
        db_gravar($db);
        responder(['sucesso' => true, 'mensagem' => $v > 0 ? "Limite definido: $v jogadores." : 'Limite removido (sem limite).']);
    }

    if ($acao === 'salvar_chave_pix') {
        db_set_config($db, 'chave_pix', mb_substr(trim($data['chave_pix'] ?? ''), 0, 120));
        db_gravar($db);
        responder(['sucesso' => true, 'mensagem' => 'Chave PIX atualizada!']);
    }

    if ($acao === 'salvar_whatsapp') {
        $zap = preg_replace('/\D/', '', $data['whatsapp'] ?? '');
        if (strlen($zap) < 10 || strlen($zap) > 15) {
            responder(['sucesso' => false, 'mensagem' => 'Número de WhatsApp inválido (use DDI+DDD+número).']);
        }
        db_set_config($db, 'whatsapp', $zap);
        db_gravar($db);
        responder(['sucesso' => true, 'mensagem' => 'WhatsApp atualizado!']);
    }

    if ($acao === 'salvar_valor_pendencia') {
        $v = valor_valido($data['valor'] ?? 0);
        if ($v <= 0) responder(['sucesso' => false, 'mensagem' => 'Valor inválido.']);
        db_set_config($db, 'valor_pendencia', strval($v));
        db_gravar($db);
        responder(['sucesso' => true, 'mensagem' => 'Valor da pendência atualizado!']);
    }

    if ($acao === 'alterar_senha') {
        $usuario = trim($data['usuario'] ?? '');
        $novaSenha = (string)($data['nova_senha'] ?? '');

        if (!$usuario || !$novaSenha) {
            responder(['sucesso' => false, 'mensagem' => 'Dados incompletos.']);
        }
        if (strlen($novaSenha) < 6) {
            responder(['sucesso' => false, 'mensagem' => 'A senha deve ter pelo menos 6 caracteres.']);
        }

        $achou = false;
        foreach ($db['usuarios'] as &$u) {
            if ($u['usuario'] === $usuario) {
                $u['senha'] = password_hash($novaSenha, PASSWORD_DEFAULT);
                $achou = true;
                break;
            }
        }
        unset($u);
        if (!$achou) responder(['sucesso' => false, 'mensagem' => 'Usuário não encontrado.']);
        db_gravar($db);
        responder(['sucesso' => true, 'mensagem' => 'Senha alterada com sucesso!']);
    }

    if ($acao === 'cadastrar_admin') {
        $novoUsuario = nome_valido($data['novo_usuario'] ?? '');
        $novaSenha = (string)($data['nova_senha'] ?? '');

        if (!$novoUsuario || !$novaSenha) {
            responder(['sucesso' => false, 'mensagem' => 'Preencha usuário e senha.']);
        }
        if (strlen($novaSenha) < 6) {
            responder(['sucesso' => false, 'mensagem' => 'A senha deve ter pelo menos 6 caracteres.']);
        }
        foreach ($db['usuarios'] as $u) {
            if (strcasecmp($u['usuario'], $novoUsuario) === 0) {
                responder(['sucesso' => false, 'mensagem' => 'Usuário já existe.']);
            }
        }

        $db['usuarios'][] = [
            'id' => db_proximo_id($db, 'usuarios'),
            'usuario' => $novoUsuario,
            'senha' => password_hash($novaSenha, PASSWORD_DEFAULT),
        ];
        db_gravar($db);
        responder(['sucesso' => true, 'mensagem' => 'Novo administrador cadastrado!']);
    }

    if ($acao === 'adicionar_jogador') {
        $nome = nome_valido($data['nome'] ?? '');
        $valor = valor_valido($data['valor'] ?? 0);
        if ($nome === '') responder(['sucesso' => false, 'mensagem' => 'Informe o nome do jogador.']);
        if ($valor < 0) responder(['sucesso' => false, 'mensagem' => 'Valor inválido.']);
        if (lista_cheia($db)) {
            responder(['sucesso' => false, 'mensagem' => 'Lista completa (' . max_jogadores($db) . ' vagas). Aumente o limite para adicionar.']);
        }

        $db['jogadores'][] = [
            'id' => db_proximo_id($db, 'jogadores'),
            'nome' => $nome,
            'valor_pago' => $valor,
            'criado_em' => date('Y-m-d H:i:s'),
        ];
        db_gravar($db);
        responder(['sucesso' => true]);
    }

    // ---------- MARCAR CONVIDADO / RENOMEAR JOGADOR ----------
    if ($acao === 'marcar_convidado') {
        $id = intval($data['id'] ?? 0);
        $achou = false;
        foreach ($db['jogadores'] as &$j) {
            if (intval($j['id']) === $id) { $j['convidado'] = !empty($data['convidado']); $achou = true; break; }
        }
        unset($j);
        if (!$achou) responder(['sucesso' => false, 'mensagem' => 'Jogador não encontrado.']);
        db_gravar($db);
        responder(['sucesso' => true]);
    }

    if ($acao === 'renomear_jogador') {
        $id = intval($data['id'] ?? 0);
        $nome = nome_valido($data['nome'] ?? '');
        if ($nome === '') responder(['sucesso' => false, 'mensagem' => 'Informe o nome.']);
        foreach ($db['jogadores'] as $j) {
            if (intval($j['id']) !== $id && mb_strtolower(trim($j['nome'])) === mb_strtolower($nome)) {
                responder(['sucesso' => false, 'mensagem' => 'Já existe um jogador com esse nome na lista.']);
            }
        }
        $achou = false;
        foreach ($db['jogadores'] as &$j) {
            if (intval($j['id']) === $id) { $j['nome'] = $nome; $achou = true; break; }
        }
        unset($j);
        if (!$achou) responder(['sucesso' => false, 'mensagem' => 'Jogador não encontrado.']);
        db_gravar($db);
        responder(['sucesso' => true]);
    }

    if ($acao === 'editar_jogador') {
        $id = intval($data['id'] ?? 0);
        $valor = valor_valido($data['valor'] ?? 0);
        if ($valor < 0) responder(['sucesso' => false, 'mensagem' => 'Valor inválido.']);
        $achou = false;
        foreach ($db['jogadores'] as &$j) {
            if (intval($j['id']) === $id) { $j['valor_pago'] = $valor; $achou = true; break; }
        }
        unset($j);
        if (!$achou) responder(['sucesso' => false, 'mensagem' => 'Jogador não encontrado.']);
        db_gravar($db);
        responder(['sucesso' => true]);
    }

    if ($acao === 'salvar_custo_quadra') {
        $v = valor_valido($data['valor'] ?? 0);
        if ($v < 0) responder(['sucesso' => false, 'mensagem' => 'Valor inválido.']);
        db_set_config($db, 'custo_quadra', strval($v));
        db_gravar($db);
        responder(['sucesso' => true]);
    }

    if ($acao === 'pagar_parcela_quadra') {
        $valor = valor_valido($data['valor'] ?? 0);
        if ($valor <= 0) responder(['sucesso' => false, 'mensagem' => 'Valor inválido.']);
        $descricao = mb_substr(trim(strip_tags($data['descricao'] ?? '')), 0, 120) ?: 'Pagamento Quadra';
        $db['pagamentos_quadra'][] = [
            'id' => db_proximo_id($db, 'pagamentos_quadra'),
            'descricao' => $descricao,
            'valor' => $valor,
            'criado_em' => date('Y-m-d H:i:s'),
        ];
        db_gravar($db);
        responder(['sucesso' => true]);
    }

    if ($acao === 'quitar_pendencia') {
        $id = intval($data['id'] ?? 0);
        $valorPago = valor_valido($data['valor_pago'] ?? 0);

        if ($id <= 0 || $valorPago <= 0) {
            responder(['sucesso' => false, 'mensagem' => 'Valor de pagamento inválido.']);
        }
        $existe = false;
        foreach ($db['pendencias'] as $p) {
            if (intval($p['id']) === $id) { $existe = true; break; }
        }
        if (!$existe) responder(['sucesso' => false, 'mensagem' => 'Pendência não encontrada.']);

        $saldoAtual = floatval(db_config($db, 'saldo_acumulado', '0'));
        db_set_config($db, 'saldo_acumulado', strval(round($saldoAtual + $valorPago, 2)));

        $db['pendencias'] = array_values(array_filter(
            $db['pendencias'],
            fn($p) => intval($p['id']) !== $id
        ));

        db_gravar($db);
        responder(['sucesso' => true, 'mensagem' => 'Pendência quitada com sucesso!']);
    }

    if ($acao === 'finalizar_etapa') {
        $arrecadadoEtapa = array_reduce($db['jogadores'], fn($s, $j) => $s + floatval($j['valor_pago']), 0.0);
        $pagoQuadraEtapa = array_reduce($db['pagamentos_quadra'], fn($s, $p) => $s + floatval($p['valor']), 0.0);

        $saldoAnterior = floatval(db_config($db, 'saldo_acumulado', '0'));
        $novoSaldoAcumulado = round(($saldoAnterior + $arrecadadoEtapa) - $pagoQuadraEtapa, 2);
        db_set_config($db, 'saldo_acumulado', strval($novoSaldoAcumulado));

        // Quem pagou menos que o valor da etapa gera pendência da diferença (inclui pagamento parcial)
        $valorEtapa = floatval(db_config($db, 'valor_pendencia', '10'));
        foreach ($db['jogadores'] as $j) {
            $falta = round($valorEtapa - floatval($j['valor_pago']), 2);
            if ($falta > 0) {
                $db['pendencias'][] = [
                    'id' => db_proximo_id($db, 'pendencias'),
                    'nome' => $j['nome'],
                    'valor_devido' => $falta,
                    'criado_em' => date('Y-m-d H:i:s'),
                ];
            }
        }

        // Guarda histórico da etapa antes de limpar
        $db['historico_etapas'][] = [
            'id' => db_proximo_id($db, 'historico_etapas'),
            'finalizada_em' => date('Y-m-d H:i:s'),
            'finalizada_por' => $_SESSION['usuario'] ?? '',
            'arrecadado' => round($arrecadadoEtapa, 2),
            'pago_quadra' => round($pagoQuadraEtapa, 2),
            'saldo_anterior' => $saldoAnterior,
            'saldo_novo' => $novoSaldoAcumulado,
            'jogadores' => $db['jogadores'],
            'pagamentos_quadra' => $db['pagamentos_quadra'],
            'fila_descartada' => array_map(fn($f) => ['nome' => $f['nome'], 'criado_em' => $f['criado_em']], $db['fila_espera']),
        ];

        $filaDescartada = count($db['fila_espera']);
        $db['jogadores'] = [];
        $db['pagamentos_quadra'] = [];
        $db['fila_espera'] = []; // pedidos pendentes não passam para a próxima etapa

        db_gravar($db);
        responder(['sucesso' => true, 'novo_saldo' => $novoSaldoAcumulado, 'fila_descartada' => $filaDescartada]);
    }

    if ($acao === 'zerar_caixa') {
        db_set_config($db, 'saldo_acumulado', '0');
        db_gravar($db);
        responder(['sucesso' => true]);
    }

    responder(['sucesso' => false, 'mensagem' => 'Ação inválida.'], 400);
}

// ==========================================
// DELETE
// ==========================================
if ($method === 'DELETE') {
    verificarAutenticacao();

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) $data = [];
    $id = intval($data['id'] ?? 0);
    $tipo = $data['tipo'] ?? '';

    $mapa = ['jogador' => 'jogadores', 'pagamento_quadra' => 'pagamentos_quadra', 'pendencia' => 'pendencias'];

    if (isset($mapa[$tipo])) {
        $col = $mapa[$tipo];
        $db[$col] = array_values(array_filter($db[$col], fn($x) => intval($x['id']) !== $id));
        db_gravar($db);
        responder(['sucesso' => true]);
    }

    if ($tipo === 'admin') {
        if (count($db['usuarios']) <= 1) {
            responder(['sucesso' => false, 'mensagem' => 'Não é possível excluir o único administrador.']);
        }
        foreach ($db['usuarios'] as $u) {
            if (intval($u['id']) === $id && $u['usuario'] === ($_SESSION['usuario'] ?? '')) {
                responder(['sucesso' => false, 'mensagem' => 'Você não pode excluir o próprio usuário logado.']);
            }
        }
        $db['usuarios'] = array_values(array_filter($db['usuarios'], fn($u) => intval($u['id']) !== $id));
        db_gravar($db);
        responder(['sucesso' => true]);
    }

    responder(['sucesso' => false, 'mensagem' => 'Tipo inválido.'], 400);
}

responder(['sucesso' => false, 'mensagem' => 'Método não suportado.'], 405);

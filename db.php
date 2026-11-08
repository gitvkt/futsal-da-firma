<?php
// ============================================================
// Futsal da Firma — Camada de dados em JSON (sem MySQL)
// ------------------------------------------------------------
// Todos os dados ficam em data/futsal.json.
// Concorrência: o ciclo inteiro LER -> ALTERAR -> GRAVAR é
// protegido por um lock exclusivo em data/futsal.lock, evitando
// que duas requisições simultâneas sobrescrevam uma à outra.
// ============================================================

date_default_timezone_set('America/Sao_Paulo');

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

define('DATA_DIR', __DIR__ . '/data');
define('DATA_FILE', DATA_DIR . '/futsal.json');
define('LOCK_FILE', DATA_DIR . '/futsal.lock');
define('INSTALL_LOCK', DATA_DIR . '/installed.lock');
define('FOTOS_DIR', __DIR__ . '/fotos');

/** Estrutura inicial do "banco". */
function db_padrao(): array
{
    return [
        'configuracoes' => [
            'chave_pix'       => 'SUA_CHAVE_PIX',
            'custo_quadra'    => '200',
            'saldo_acumulado' => '0',
            'whatsapp'        => '5500000000000',
            'valor_pendencia' => '10',   // valor cobrado de quem não pagou na etapa
            'max_jogadores'   => '0',    // limite de confirmados (0 = sem limite)
        ],
        'jogadores'         => [], // [{id, nome, valor_pago, criado_em}]
        'pagamentos_quadra' => [], // [{id, descricao, valor, criado_em}]
        'pendencias'        => [], // [{id, nome, valor_devido, criado_em}]
        'usuarios'          => [], // [{id, usuario, senha(hash)}]
        'historico_etapas'  => [], // [{id, finalizada_em, arrecadado, pago_quadra, saldo_anterior, saldo_novo, jogadores, pagamentos_quadra}]
        'fila_espera'       => [], // [{id, nome, criado_em, ip_hash}] — pedidos de "Confirmar Presença" aguardando aprovação
        // Agenda de jogos: [{id, data:'YYYY-MM-DD', hora:'HH:MM', local, status:'confirmado|reagendado|cancelado', nova_data, nova_hora, obs}]
        'agenda'            => [],
        // Sorteio de times: contagem de vezes que cada dupla jogou junta (persiste entre etapas)
        'pares_sorteio'     => [], // { "nomeA|nomeB": vezes }
        'ultimo_sorteio'    => null, // {criado_em, times:[{nome, goleiro, jogadores[]}], repeticoes}
        'historico_sorteios'=> [], // últimos 50 sorteios
        // Vagas fixas de goleiro (não pagam, não entram na contabilidade nem no limite de jogadores)
        'goleiros'          => [
            ['slot' => 1, 'nome' => '', 'foto' => ''],
            ['slot' => 2, 'nome' => '', 'foto' => ''],
        ],
        'sequencias'        => ['jogadores' => 0, 'pagamentos_quadra' => 0, 'pendencias' => 0, 'usuarios' => 0, 'historico_etapas' => 0, 'fila_espera' => 0],
    ];
}

function db_garantir_pasta(): void
{
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0755, true);
    }
    // Proteção extra para servidores Apache (Nginx: bloqueie /data na config do servidor)
    $ht = DATA_DIR . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht, "Require all denied\nDeny from all\n");
    }
    $idx = DATA_DIR . '/index.html';
    if (!file_exists($idx)) {
        @file_put_contents($idx, '');
    }
}

/** Abre o lock global. $exclusivo=true para escrita, false para leitura. */
function db_lock(bool $exclusivo = true)
{
    db_garantir_pasta();
    $fp = @fopen(LOCK_FILE, 'c');
    if ($fp === false) {
        http_response_code(500);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Sem permissão de escrita na pasta data/.']);
        exit;
    }
    flock($fp, $exclusivo ? LOCK_EX : LOCK_SH);
    return $fp;
}

function db_unlock($fp): void
{
    if ($fp) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/** Lê todo o banco (deve ser chamado com o lock já obtido). */
function db_ler(): array
{
    db_garantir_pasta();
    if (!file_exists(DATA_FILE)) {
        $db = db_padrao();
        db_gravar($db);
        return $db;
    }
    $raw = file_get_contents(DATA_FILE);
    $db = json_decode($raw ?: '', true);
    if (!is_array($db)) {
        @copy(DATA_FILE, DATA_FILE . '.corrompido-' . date('Ymd-His'));
        $db = db_padrao();
        db_gravar($db);
    }
    $db = array_replace_recursive(db_padrao(), $db);
    unset($db['configuracoes']['valor_quadra']); // campo legado não utilizado
    return $db;
}

/** Grava o banco inteiro com escrita atômica (tmp + rename). */
function db_gravar(array $db): void
{
    db_garantir_pasta();
    $tmp = DATA_FILE . '.tmp';
    $json = json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        http_response_code(500);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Falha ao gravar dados na pasta data/.']);
        exit;
    }
    rename($tmp, DATA_FILE);
}

/** Próximo ID auto-incremento de uma coleção. */
function db_proximo_id(array &$db, string $colecao): int
{
    $db['sequencias'][$colecao] = ($db['sequencias'][$colecao] ?? 0) + 1;
    return $db['sequencias'][$colecao];
}

function db_config(array $db, string $chave, string $padrao = ''): string
{
    return isset($db['configuracoes'][$chave]) ? strval($db['configuracoes'][$chave]) : $padrao;
}

function db_set_config(array &$db, string $chave, string $valor): void
{
    $db['configuracoes'][$chave] = $valor;
}

/** Sistema instalado = existe data/installed.lock (ou trava antiga em install/) e há ao menos 1 admin. */
function sistema_instalado(): bool
{
    if (file_exists(INSTALL_LOCK)) return true;
    // Compatibilidade: migra a trava antiga de install/ para data/
    $antigo = __DIR__ . '/install/installed.lock';
    if (file_exists($antigo)) {
        db_garantir_pasta();
        @copy($antigo, INSTALL_LOCK);
        return true;
    }
    return false;
}

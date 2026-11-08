<?php
require_once __DIR__ . '/../db.php';
session_start();

$data_dir = '../data';

// 1. Bloqueio de Segurança: Se já instalado, interrompe a execução
if (sistema_instalado()) {
    die("
        <div style='font-family:sans-serif; text-align:center; padding: 50px; background:#0f172a; color:#f8fafc; min-height:100vh;'>
            <h1 style='color:#ef4444;'>Sistema Já Instalado!</h1>
            <p>Para executar a instalação novamente, exclua o arquivo <strong>data/installed.lock</strong>. Recomenda-se apagar a pasta <strong>install/</strong>.</p>
            <a href='../index.html' style='color:#38bdf8;'>Ir para o Sistema</a>
        </div>
    ");
}

$mensagem = '';
$erro = false;

// 2. Processa o Formulário de Instalação (sem banco de dados — só cria o admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin_user = mb_substr(trim(strip_tags($_POST['admin_user'] ?? '')), 0, 80);
    $admin_pass = $_POST['admin_pass'] ?? '';

    if ($admin_user === '' || strlen($admin_pass) < 6) {
        $erro = true;
        $mensagem = "Informe o usuário e uma senha com pelo menos 6 caracteres!";
    } elseif (strcasecmp($admin_user, 'admin') === 0 && $admin_pass === 'admin123') {
        $erro = true;
        $mensagem = "Não use a senha padrão admin123.";
    } else {
        if (!is_writable(dirname($data_dir)) && !is_dir($data_dir)) {
            $erro = true;
            $mensagem = "Sem permissão para criar a pasta data/. Dê permissão de escrita (755/777) na pasta do sistema.";
        } else {
            $lk = db_lock(true);
            $db = db_ler();

            // Cadastra o Administrador Inicial
            $db['usuarios'][] = [
                'id' => db_proximo_id($db, 'usuarios'),
                'usuario' => $admin_user,
                'senha' => password_hash($admin_pass, PASSWORD_DEFAULT),
            ];
            db_gravar($db);

            // Cria o arquivo de trava em data/ (assim apagar install/ não quebra o sistema)
            file_put_contents(INSTALL_LOCK, 'INSTALADO_EM_' . date('Y-m-d H:i:s'));
            db_unlock($lk);

            $mensagem = "Instalação concluída com sucesso! Por segurança, apague a pasta install/ do servidor.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalador - Futsal da Firma</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-900 text-slate-100 flex items-center justify-center min-h-screen p-4 font-sans">

    <div class="bg-slate-800 border border-slate-700/80 p-6 rounded-2xl max-w-md w-full shadow-2xl space-y-5">
        <div class="flex items-center justify-center gap-3 border-b border-slate-700/60 pb-4">
            <div class="bg-emerald-500/10 text-emerald-400 p-3 rounded-xl border border-emerald-500/20">
                <i class="fa-solid fa-futbol text-2xl"></i>
            </div>
            <div>
                <h2 class="text-lg font-bold text-slate-200">Futsal da Firma</h2>
                <p class="text-xs text-slate-400">Instalação — sem banco de dados MySQL</p>
            </div>
        </div>

        <?php if (!empty($mensagem)): ?>
            <div class="p-4 text-xs rounded-xl border <?= $erro ? 'bg-rose-500/10 text-rose-300 border-rose-500/20' : 'bg-emerald-500/10 text-emerald-300 border-emerald-500/20' ?>">
                <p class="font-bold mb-1"><?= $erro ? 'Falha na Instalação' : 'Sucesso!' ?></p>
                <p><?= $mensagem ?></p>
                <?php if (!$erro): ?>
                    <a href="../index.html" class="inline-block mt-3 bg-emerald-600 hover:bg-emerald-500 text-white font-bold px-4 py-2 rounded-xl transition">
                        Acessar o Sistema
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (empty($mensagem) || $erro): ?>
        <form method="POST" class="space-y-4 text-xs">
            <p class="text-slate-400">
                Esta versão guarda todos os dados em um arquivo <strong>data/futsal.json</strong> no próprio servidor.
                Não é preciso criar banco de dados no cPanel — basta definir o administrador abaixo.
            </p>

            <div class="space-y-2">
                <h3 class="font-bold text-amber-400 uppercase tracking-wider flex items-center gap-2">
                    <i class="fa-solid fa-user-shield"></i> Administrador do Sistema
                </h3>

                <div>
                    <label class="block mb-1 text-slate-300 font-medium">Usuário Admin:</label>
                    <input type="text" name="admin_user" value="admin" required class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2.5 text-slate-200 focus:outline-none focus:border-emerald-500">
                </div>

                <div>
                    <label class="block mb-1 text-slate-300 font-medium">Senha Admin:</label>
                    <input type="password" name="admin_pass" placeholder="Defina uma senha forte" required class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2.5 text-slate-200 focus:outline-none focus:border-emerald-500">
                </div>
            </div>

            <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-500 font-bold py-3 rounded-xl transition text-white text-xs flex items-center justify-center gap-2 shadow-lg mt-4">
                <i class="fa-solid fa-gears"></i> Instalar
            </button>
        </form>
        <?php endif; ?>
    </div>

</body>
</html>

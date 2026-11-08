# Changelog

Todas as alterações notáveis neste projeto serão documentadas neste arquivo.
O formato é baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/) e o projeto adere ao [Versionamento Semântico](https://semver.org/lang/pt-BR/2.0.0/).

## [Unreleased]

### A fazer
- Exportação do relatório financeiro em PDF/CSV.
- Equilíbrio de times por nível técnico no sorteio.

---

## [6.0.0] - 2026-11-08

Grande reformulação: o sistema passa a funcionar **sem MySQL** e ganha sorteio de times, agenda e fila de espera.

### 🔄 Modificado (quebra de compatibilidade)
- Persistência migrada de **MySQL/PDO** para um único arquivo **`data/futsal.json`** (`db.php`), com lock exclusivo e gravação atômica.
- Instalador simplificado: pede apenas usuário e senha do administrador.
- Trava de instalação movida para `data/installed.lock` — apagar a pasta `install/` não derruba mais o sistema.
- Removidos `conexao.php.example` e `schema.sql`.

### 🚀 Adicionado
- **Próximo Jogo:** bloco no topo da página pública com status (confirmado, reagendado, cancelado), contagem regressiva e frases motivacionais.
- **Agenda de jogos:** popup com jogos futuros; cadastro, edição, reagendamento e cancelamento; geração de **jogos semanais**; exclusão de vários jogos de uma vez.
- **Avisar a galera:** compartilhamento do próximo jogo via WhatsApp.
- **Confirmar Presença** pública com **Fila de Espera** no painel (aceitar, aceitar como convidado, recusar, aceitar todos, atualização automática) e proteção anti-spam.
- **Limite de jogadores** confirmados, com bloqueio de novos pedidos quando a lista lota.
- **Goleiros fixos:** duas vagas que não pagam, com foto (recorte automático em alta resolução) e popup ampliado.
- **Convidados:** marcação de convidado e edição de nome dos jogadores.
- **Sorteio de times:** botão com dado animado em SVG; times de 4 na linha; Times 1 e 2 priorizam membros fixos; quem sobra forma o time seguinte; **memória de duplas entre etapas** para evitar repetições; confirmação do sorteio; zerar memória.
- **Ver Sorteio** na página pública (somente sorteios confirmados) e link direto `?sorteio=1`.
- **Compartilhar sorteio** no WhatsApp com emojis Unicode, cores por time (🟢 1–2, 🟡 3, 🟠 4+) e próximo jogo.
- **Histórico de etapas** finalizadas (jogadores, pagamentos, saldos).
- **Valor por jogador na etapa** configurável; pendência da diferença para pagamentos parciais.

### 🛡️ Segurança
- Removido o administrador padrão `admin/admin123` criado automaticamente.
- Sessão com cookie `HttpOnly`/`SameSite=Strict` e `session_regenerate_id` no login.
- Limite de 5 tentativas de login a cada 15 minutos.
- Proteção CSRF (exige JSON + checagem de `Origin`).
- Escape de HTML em todas as listas (proteção contra XSS).
- Validações no servidor: nomes, valores não negativos, senha mínima, WhatsApp, datas e horários.
- Upload de fotos validado e recodificado; pasta `fotos/` sem execução de scripts.

### 🐛 Corrigido
- Perda de dados em gravações simultâneas.
- Troca de senha retornava sucesso para usuário inexistente.
- Valor de pendência fixo no código (agora configurável).
- Fuso horário definido como `America/Sao_Paulo`.
- Emoji quebrado (�) na mensagem do WhatsApp.

---

## [5.0.1] - 2026-07-28

### 🚀 Melhorias
- Rota principal do instalador em `install/index.php`.
- Importação dinâmica do banco a partir de `schema.sql`.
- Criação automática das credenciais administrativas na instalação.

### 🐛 Corrigido
- Gravação das credenciais do banco no `conexao.php` gerado.
- Escape de caracteres especiais nas senhas do MySQL.
- Verificação de instalação no topo do `api.php` e fluxo da trava `installed.lock`.

## [5.0.0] - 2026-07-28

### 🚀 Adicionado
- Painel de dívidas de etapas anteriores.
- Baixa manual de pendências com reflexo no Saldo Caixa.
- Seção para identificar quem ainda não pagou no ciclo atual.

### 🔄 Modificado
- Quitações de pendências passam a alimentar o saldo do caixa.
- Tabelas do admin refatoradas em componentes Tailwind responsivos.

### 🛡️ Segurança
- Tratamento de sessões expiradas (HTTP 403) com fechamento do painel.

## [4.4.0] - 2026-07-25
### Alterado
- Ocultado o selo "Pendente" na lista pública.
- Renderização dinâmica sem recarregar a página.
### Corrigido
- Ordenação dos pagamentos da quadra e respostas de erro padronizadas em JSON.

## [4.3.0] - 2026-07-20
### Adicionado
- Lançamento de parcelas pagas à quadra com cálculo do saldo em tempo real.
- Edição do valor de contribuição por jogador.
- Rodapé com créditos à VKT CLOUD e redes sociais.

## [4.0.0] - 2026-06-01
### Modificado
- Migração do Firebase (Firestore) para PHP + MySQL.
- Refatoração visual com Tailwind CSS.
### Adicionado
- Área administrativa com autenticação.
- Cópia rápida da chave PIX e envio de comprovante via WhatsApp.

# ⚽ Futsal da Firma — Lista de Presença, Sorteio de Times & Gestão Financeira

Aplicação web leve, responsiva e **sem banco de dados** para organizar partidas recreativas de futsal: confirmação de presença, fila de espera, sorteio inteligente de times, agenda de jogos e prestação de contas do caixa — tudo em uma única página, acessível pelo celular, sem cadastro para os jogadores.

> **Versão 6.1** — todos os dados ficam em um único arquivo `data/futsal.json`. Basta PHP; não é preciso MySQL.

### 🌐 [Ver demonstração ao vivo → vktcloud.com.br/futsal](https://vktcloud.com.br/futsal/)

> Sistema real em uso pelo grupo. A área administrativa é protegida por login.

![Preview](preview_site.png)

---

## 🎓 Projeto Extensionista (Contexto Acadêmico)

Este repositório compõe um **Projeto Extensionista Universitário**, focado na aplicação prática da tecnologia para resolver problemas organizacionais no âmbito comunitário:

- Digitalização do processo informal de arrecadação e controle de presença em grupos esportivos locais.
- Prestação de contas clara em tempo real: arrecadação, pagamentos da quadra e saldo do caixa.
- Interface que dispensa cadastros ou instalação de aplicativos por parte dos participantes.

Mais detalhes em [CONCEITO.md](CONCEITO.md).

---

## 🚀 Funcionalidades

### 👥 Página pública (jogadores)

| Função | Descrição |
|---|---|
| 📅 **Próximo Jogo** | Bloco colorido no topo: *Teremos futebol* (verde), *Reagendado* (amarelo) ou *Cancelado* (vermelho), com contagem regressiva e frases motivacionais. |
| 🗓️ **Agenda** | Ícone de calendário abre um popup com todos os jogos futuros. |
| 🟢 **Avisar a galera** | Envia o aviso do próximo jogo pelo WhatsApp. |
| 🏆 **Craque do Dia** | Votação aberta no dia do jogo e por até 2 dias; o craque aparece no card do Próximo Jogo. |
| ✋ **Confirmar Presença** | O jogador digita o nome e entra na fila de espera para aprovação do organizador. |
| 🧤 **Goleiros fixos** | Duas vagas fixas com foto (clique para ampliar). Goleiros não pagam. |
| 🎲 **Ver Sorteio** | Mostra os times do último sorteio confirmado e permite compartilhar no WhatsApp. |
| 💰 **Caixa transparente** | Arrecadado, custo da quadra, total pago e saldo do caixa. |
| 💳 **PIX** | Copiar chave, QR Code e link para enviar comprovante via WhatsApp. |

### 🛡️ Painel administrativo

| Função | Descrição |
|---|---|
| ⏳ **Fila de espera** | Aceitar como membro fixo, aceitar como **convidado**, recusar ou aceitar todos. Atualização automática a cada 15 s. |
| 🏷️ **Convidados** | Marcar/desmarcar jogadores como convidados e editar nomes. Lista ordenada: pagos, não pagos e convidados (ordem alfabética). |
| 🎲 **Sorteio de times** | Times de 4 na linha; **Times 1 e 2 priorizam membros fixos**, convidados vão para o Time 3 em diante; quem sobra forma o time seguinte (incompleto). Dado animado em SVG durante o sorteio. |
| 🔁 **Sem repetições** | O sorteio memoriza as duplas que já jogaram juntas (mesmo entre etapas) e minimiza repetições. |
| 📲 **Compartilhar** | Mensagem formatada para WhatsApp com emojis, cores por time (🟢 1–2, 🟡 3, 🟠 4+), próximo jogo e link direto para o sorteio (`?sorteio=1`). |
| 🗓️ **Agenda** | Cadastrar, editar, reagendar ou cancelar jogos; gerar **jogos semanais** (4 a 26 semanas); excluir vários de uma vez. |
| 🧤 **Goleiros** | Nome e foto das duas vagas fixas (recorte automático, até 1024×1024). |
| 👥 **Limite de jogadores** | Máximo de confirmados (0 = sem limite). Bloqueia novos pedidos quando lotado. |
| 💵 **Financeiro** | Pagamentos, pendências acumuladas, valor por jogador na etapa, custo da quadra, saldo. |
| 🏁 **Finalizar etapa** | Fecha a rodada: atualiza o caixa, gera pendências de quem não pagou (inclusive parcial), limpa a fila e guarda o **histórico da etapa**. |
| 🔐 **Administradores** | Cadastro de vários admins e troca de senha. |

---

## 🔒 Segurança

- Senhas com `password_hash`; sessão com cookie `HttpOnly` + `SameSite=Strict` e `session_regenerate_id` no login.
- Limite de **5 tentativas de login a cada 15 minutos** por IP.
- Proteção **CSRF**: escrita só via JSON + verificação de `Origin`.
- **Lock exclusivo** em todo o ciclo ler → alterar → gravar do JSON (sem perda de dados em acessos simultâneos) e gravação atômica.
- Escape de HTML em todos os nomes exibidos (proteção contra XSS).
- Upload de fotos validado (tipo real da imagem, tamanho, nome aleatório) e recodificado com GD quando disponível; a pasta `fotos/` não executa scripts.
- Pasta `data/` bloqueada para acesso direto via `.htaccess`.
- Validação de entradas no servidor (nomes, valores, datas, horários, WhatsApp).

---

## 🛠️ Tecnologias

- **Front-end:** HTML5, JavaScript (Vanilla ES6+), [Tailwind CSS (CDN)](https://tailwindcss.com/), Font Awesome, QRCode.js.
- **Back-end:** PHP 8.x — API REST em JSON.
- **Armazenamento:** arquivo JSON com lock e escrita atômica (sem MySQL).

---

## 📁 Estrutura

```text
.
├── api.php              # API REST (GET, POST, DELETE)
├── db.php               # Camada de dados JSON (leitura/escrita com lock)
├── index.html           # Interface SPA (público + painel admin)
├── install/index.php    # Instalador (cria o primeiro administrador)
├── data/
│   ├── .htaccess        # Bloqueia acesso web direto aos dados
│   └── futsal.json      # Criado automaticamente (o "banco de dados")
├── fotos/
│   └── .htaccess        # Impede execução de scripts na pasta de fotos
├── favicon.png
├── preview_site.png
├── CHANGELOG.md
├── CONCEITO.md
└── INSTRUÇÕES.md
```

---

## ⚡ Instalação rápida

1. Envie os arquivos para o servidor (ex.: `public_html/futsal/`).
2. Dê permissão de escrita na pasta do sistema (para criar `data/` e `fotos/`).
3. Acesse `https://seusite.com.br/futsal/install/` e defina o administrador.
4. Apague a pasta `install/` (seguro — a trava fica em `data/installed.lock`).

Guia completo em [INSTRUÇÕES.md](INSTRUÇÕES.md).

---

## 💾 Backup

Copie `data/futsal.json` (e a pasta `fotos/`, se usar fotos de goleiros). Para restaurar, envie os arquivos de volta.

---

## 📜 Licença

[MIT](LICENSE) © 2026 VKT Sistemas — Desenvolvido por **Deivid — [VKT CLOUD](https://vktcloud.com.br/)**

# ⚽ Futsal da Firma — Sistema de Gestão de Lista, Times e Caixa de Jogos

## 📄 Conceito do Projeto

### 1. 📌 O que é?
O **Futsal da Firma** é uma aplicação web leve, responsiva e dinâmica desenvolvida para automatizar a organização de partidas recreativas de futsal. O sistema combina a confirmação de presença dos jogadores, a agenda de jogos e o sorteio de times com um painel financeiro transparente, permitindo acompanhar o custo da quadra, as arrecadações e o saldo do caixa.

### 2. 🎯 Para quê? (Objetivos)
- **Transparência financeira:** exibir a arrecadação total, quanto já foi pago pelo aluguel da quadra e o saldo do caixa.
- **Facilidade de pagamento:** chave PIX com cópia direta e QR Code, além de link para envio de comprovantes via WhatsApp.
- **Organização dos jogos:** agenda com status de cada data (confirmado, reagendado ou cancelado) e aviso para o grupo.
- **Times justos e variados:** sorteio que evita repetir as mesmas duplas entre rodadas e prioriza os membros fixos.
- **Praticidade de acesso:** jogadores usam o sistema sem criar conta; o controle administrativo é protegido por login.

### 3. 💡 Por quê? (A Motivação)
A organização de jogos amadores enfrenta desafios recorrentes:
- **Falta de controle de pagamentos:** difícil saber quem já pagou e quem está pendente.
- **Falta de clareza no caixa:** a arrecadação em grupos de mensagens se perde nas conversas.
- **Inadimplência não intencional:** jogadores esquecem o PIX por falta de um canal centralizado.
- **Dúvidas sobre o próximo jogo:** datas reagendadas ou canceladas se perdem no grupo.
- **Sorteios repetitivos:** os mesmos jogadores acabam sempre no mesmo time.

O projeto resolve essas dores com uma solução centralizada, acessível por qualquer celular, eliminando planilhas manuais ou anotações informais.

### 4. 👥 Para quem serve? (Público-Alvo)
- **Organizadores de futsal/futebol amador:** quem aluga a quadra, recolhe o dinheiro e monta os times.
- **Jogadores e participantes:** confirmam presença, copiam o PIX, veem os times e a situação financeira.
- **Comunidades e grupos de esporte recreativo:** qualquer grupo que precise de controle simples de lista, agenda e caixa.

### 5. 🛠️ Como foi idealizado e construído? (Arquitetura)

**Idealização:** ágil, elegante e funcional em dispositivos móveis (*mobile-first*), em uma única página (SPA) que consome os dados de forma assíncrona, sem recarregar a tela.

**Front-end**
- **HTML5 semântico.**
- **Tailwind CSS (CDN):** estilização utilitária com tema escuro.
- **JavaScript (Vanilla ES6+):** manipulação do DOM, `fetch` assíncrono, animações SVG (dado do sorteio) e integração com WhatsApp.
- **Font Awesome** e **QRCode.js**.

**Back-end**
- **PHP 8.x:** API REST enxuta (GET, POST, DELETE) que responde em JSON.

**Armazenamento**
- **Arquivo JSON (`data/futsal.json`)** com lock exclusivo e gravação atômica — dispensa MySQL e facilita o backup (basta copiar um arquivo).

---

## 🎓 Aplicação para Projeto Extensionista

Este projeto foi desenvolvido como uma solução de **Inovação e Tecnologia Aplicada à Comunidade local**, demonstrando como a transformação digital de processos informais (como a gestão de grupos esportivos) promove transparência, organização comunitária e inclusão digital. Por usar tecnologias web abertas e não depender de banco de dados, o sistema pode ser replicado gratuitamente por associações de bairro, centros comunitários e pequenos grupos esportivos da região.

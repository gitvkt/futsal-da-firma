# 🚀 Guia de Instalação e Configuração

Guia passo a passo para instalar o **Futsal da Firma** em hospedagens cPanel / Apache (ou localmente com WAMP/XAMPP).

> Esta versão **não usa MySQL**: os dados ficam em `data/futsal.json`.

---

## ⚙️ Pré-requisitos

- **PHP 8.x** com as extensões padrão `json` e `session` (presentes em qualquer hospedagem).
- **Recomendado:** extensão `gd` (recodifica as fotos dos goleiros com mais segurança).
- **Apache** (para os arquivos `.htaccess`). Em **Nginx**, veja a seção específica abaixo.
- Acesso ao Gerenciador de Arquivos do cPanel ou FTP/SSH.

---

## 🛠️ Passo a passo

### 1. Envio dos arquivos
Envie todos os arquivos do projeto para o diretório do site, por exemplo `public_html/futsal/`.

### 2. Permissões
A pasta do sistema precisa de permissão de escrita (**755**, ou **775/777** se necessário) para que o PHP crie:
- `data/` — arquivo de dados `futsal.json`;
- `fotos/` — fotos dos goleiros.

### 3. Assistente de instalação
Acesse no navegador:

```text
https://seusite.com.br/futsal/install/
```

Informe o **usuário** e uma **senha** de administrador (mínimo de 6 caracteres; a senha `admin123` não é aceita) e clique em **Instalar**.

O instalador:
- [x] cria `data/futsal.json` com a estrutura inicial;
- [x] cadastra o administrador com senha criptografada (`password_hash`);
- [x] cria a trava `data/installed.lock`.

---

## 🔒 Após instalar

> [!IMPORTANT]
> Apague a pasta `install/` do servidor. Isso é seguro: a trava de instalação fica em `data/installed.lock`.

Depois, entre em **Área Admin** e configure:
1. Chave PIX e WhatsApp para comprovantes.
2. Valor da quadra e valor por jogador na etapa.
3. Máximo de jogadores (0 = sem limite).
4. Goleiros fixos (nome e foto).
5. Agenda de jogos (ícone 📅 no bloco *Próximo Jogo*) — use **Jogos semanais** para gerar várias datas de uma vez.

---

## 🌐 Servidores Nginx

O `.htaccess` não funciona no Nginx. Adicione à configuração do site:

```nginx
location ^~ /futsal/data/ { deny all; }
location ~* ^/futsal/fotos/.*\.(php|phtml|phar)$ { deny all; }
```

---

## 💾 Backup e restauração

- **Backup:** copie `data/futsal.json` e a pasta `fotos/`.
- **Restaurar:** envie os arquivos de volta para as mesmas pastas.

---

## 🔄 Reinstalação

1. Exclua `data/installed.lock`.
2. (Opcional) Exclua `data/futsal.json` para começar do zero.
3. Envie novamente a pasta `install/` e acesse `https://seusite.com.br/futsal/install/`.

---

## 🧪 Ambiente local (WAMP/XAMPP)

Copie a pasta para `C:\wamp64\www\futsal\` (ou `htdocs`) e acesse `http://localhost/futsal/install/`.

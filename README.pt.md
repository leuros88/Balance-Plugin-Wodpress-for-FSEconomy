<p align="center">
  <img src="assets/logobalance.jpeg" alt="Logotipo do FSE Balance" width="200">
</p>

<h1 align="center">FSE Balance — Plugin de WordPress para FSEconomy</h1>

<p align="center">
  Exibe o saldo bancário de qualquer conta ou grupo do FSEconomy com um shortcode.<br>
  Sincronizado automaticamente a cada 30 minutos e servido do cache — sem chamadas à API por visita.
</p>

<p align="center">
  <a href="README.md">English</a> ·
  <a href="README.es.md">Español</a> ·
  <a href="README.pt.md"><strong>Português</strong></a>
</p>

<p align="center">
  <a href="https://github.com/leuros88/Balance-Plugin-Wodpress-for-FSEconomy/releases/latest"><img src="https://img.shields.io/github/v/release/leuros88/Balance-Plugin-Wodpress-for-FSEconomy?label=%C3%BAltima%20vers%C3%A3o" alt="Última versão"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/leuros88/Balance-Plugin-Wodpress-for-FSEconomy" alt="Licença: MIT"></a>
  <img src="https://img.shields.io/badge/WordPress-6.0%2B-blue" alt="WordPress 6.0+">
  <img src="https://img.shields.io/badge/PHP-7.4%2B-777BB4" alt="PHP 7.4+">
</p>

---

## Índice

- [Funcionalidades](#funcionalidades)
- [Como funciona](#como-funciona)
- [Requisitos](#requisitos)
- [Instalação](#instalação)
- [Uso](#uso)
- [Atualizações](#atualizações)
- [Desinstalação](#desinstalação)
- [Segurança](#segurança)
- [Licença](#licença)

## Funcionalidades

- 📊 Shortcode `[fse_balance]` que exibe o saldo em cache como moeda formatada (ex.: `$12,345.67`).
- ⏱️ Sincronização automática em segundo plano a cada 30 minutos com intervalo próprio do WP-Cron.
- ⚡ Saída em cache — os visitantes nunca disparam uma requisição ao vivo à API.
- 🔒 Trava anti-sobreposição para evitar consultas concorrentes (cron + atualização manual).
- 🖥️ Página de administração moderna com logotipo, resumo do saldo, status de sincronização e indicador de saúde.
- ⚙️ Configurações para a URL da API, atualização manual com um clique e verificador de atualizações.
- 🔄 Atualizações auto-hospedadas via GitHub Releases (um clique + auto-updates).
- 🧹 Desinstalação limpa: remove opções, travas e eventos agendados.

## Como funciona

1. Cole a URL da API do FSEconomy (incluindo sua chave) em `WP Admin > FSE Balance`.
2. A cada 30 minutos, o WP-Cron consulta a API, extrai `Statistic > Bank_balance` do XML e salva no banco de dados.
3. O shortcode `[fse_balance]` exibe o valor em cache onde você colocá-lo.

> **Nota:** o WP-Cron executa a partir das visitas ao site. Em sites com pouco tráfego, configure um cron real do sistema chamando `wp-cron.php` a cada 30 minutos.

## Requisitos

| Requisito   | Versão          |
|-------------|-----------------|
| WordPress   | 6.0 ou superior |
| PHP         | 7.4 ou superior |
| FSEconomy   | URL de API válida com chave |

## Instalação

1. Acesse [**Releases**](https://github.com/leuros88/Balance-Plugin-Wodpress-for-FSEconomy/releases) e baixe o **`fse-balance-plugin.zip`** mais recente (não os arquivos `Source code`).
2. Instale por um destes métodos:
   - **Opção A — WP Admin (recomendado):** vá em `Plugins > Adicionar novo > Enviar plugin`, envie o `.zip` e clique em **Ativar**.
   - **Opção B — FTP:** extraia o zip e envie a pasta `fse-balance-plugin/` para `wp-content/plugins/`, depois ative em `Plugins`.
3. Vá em `FSE Balance` no menu e cole a URL da API do FSEconomy no campo **API URL**, depois clique em **Save URL**.

## Uso

Adicione o shortcode onde quiser (post, página, widget, bloco):

```
[fse_balance]
```

Ele exibe o último saldo em cache, ex.: `$12,345.67`.

Para sincronizar sob demanda, use o botão **Force balance refresh now** na página de configurações. Ele consulta a API na hora e atualiza o valor, a data e o status.

## Atualizações

O plugin verifica este repositório **a cada 12 horas** pela API do GitHub Releases.

- Quando há uma versão nova, o WordPress a oferece em `Painel > Atualizações` e em `Plugins`, com atualização em um clique e janela de novidades.
- Ative a instalação totalmente automática com **Ativar atualizações automáticas** na linha do plugin.
- Force uma verificação imediata em `FSE Balance > Check for updates now`.

## Desinstalação

Desativar mantém suas configurações. Excluir o plugin remove tudo: opções, travas, eventos agendados e dados de atualização em cache.

## Segurança

- **Proteção SSRF:** somente URLs `http/https` em `*.fseconomy.net` com portas padrão (80/443) são aceitas; credenciais na URL são rejeitadas.
- **Parsing XML seguro:** entidades externas desativadas (proteção XXE) com limite de 500 KB por resposta.
- **Admin reforçado:** verificação de capacidades (`manage_options`) e nonces em cada formulário e ação.

## Licença

Licença MIT — criado por **[Leuros88](https://github.com/leuros88)**.

Veja [LICENSE](LICENSE) para detalhes.

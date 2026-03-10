# WP SigNoz

> Plugin WordPress para integração com [SigNoz](https://signoz.io/) — observabilidade completa com traces, métricas e logs via OpenTelemetry.

![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-blue?logo=wordpress)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple?logo=php)
![License](https://img.shields.io/badge/license-GPL--2.0-green)
![OpenTelemetry](https://img.shields.io/badge/OpenTelemetry-1.x-orange?logo=opentelemetry)

---

## Sumário

- [Visão Geral](#visão-geral)
- [Funcionalidades](#funcionalidades)
- [Requisitos](#requisitos)
- [Instalação](#instalação)
- [Configuração](#configuração)
- [Como Funciona](#como-funciona)
- [Dados Coletados](#dados-coletados)
- [Estrutura do Plugin](#estrutura-do-plugin)
- [Desenvolvimento](#desenvolvimento)
- [FAQ](#faq)
- [Changelog](#changelog)
- [Licença](#licença)

---

## Visão Geral

O **WP SigNoz** instrumenta automaticamente sua instalação WordPress para enviar dados de observabilidade ao SigNoz. Com ele, você consegue:

- Rastrear o ciclo de vida completo de cada requisição HTTP
- Monitorar o desempenho de queries ao banco de dados
- Capturar e centralizar logs de erros e eventos
- Visualizar métricas de performance em tempo real no painel do SigNoz

O plugin utiliza o protocolo **OpenTelemetry (OTLP)** como padrão, garantindo compatibilidade tanto com o SigNoz self-hosted quanto com o SigNoz Cloud.

---

## Funcionalidades

### 🔍 Distributed Tracing
- Span automático por requisição HTTP (página, REST API, WP-Cron)
- Rastreamento de queries ao banco de dados MySQL/MariaDB
- Rastreamento de requisições HTTP externas (`wp_remote_get`, `wp_remote_post`)
- Propagação de contexto W3C TraceContext

### 📊 Métricas
- Tempo de resposta por rota/página
- Contagem e duração de queries SQL
- Taxa de erros PHP
- Uso de memória por requisição

### 📋 Logs
- Captura automática de erros PHP (`E_ERROR`, `E_WARNING`)
- Logs de autenticação (login, logout, falhas)
- Integração com o sistema nativo `error_log` do WordPress
- Suporte a severity levels (INFO, WARN, ERROR)

### ⚙️ Configuração via Admin
- Painel de configuração nativo no wp-admin
- Suporte a SigNoz Self-Hosted e Cloud
- Configuração de amostragem (sampling rate)
- Teste de conexão direto pelo painel

---

## Requisitos

| Requisito | Versão mínima |
|---|---|
| WordPress | 5.8 |
| PHP | 7.4 |
| Composer | 2.x |
| Extensão PHP `curl` | qualquer |
| Extensão PHP `json` | qualquer |
| SigNoz | 0.12+ (self-hosted) ou Cloud |

---

## Instalação

### Instalação Manual

1. Clone ou baixe o repositório:
   ```bash
   git clone https://github.com/seomarc/wp-signoz.git
   ```

2. Instale as dependências PHP via Composer:
   ```bash
   cd wp-signoz
   composer install --no-dev --optimize-autoloader
   ```

3. Copie a pasta para o diretório de plugins do WordPress:
   ```bash
   cp -r wp-signoz /var/www/html/wp-content/plugins/
   ```

4. Acesse o painel WordPress → **Plugins** → Ative o **WP SigNoz**.

### Instalação via Composer (em projetos gerenciados)

```bash
composer require seomarc/wp-signoz
```

---

## Configuração

Após ativar o plugin, acesse **WordPress Admin → Configurações → SigNoz**.

### Parâmetros disponíveis
|---|---|---|
| **Endpoint OTLP** | URL do coletor SigNoz | `http://signoz-host:4318` |
| **Access Token** | Token para SigNoz Cloud (deixe em branco para self-hosted) | `xxxxxxxxxx` |
| **Nome do Serviço** | Identificador do serviço no SigNoz | `meu-site-wordpress` |
| **Ambiente** | Tag de ambiente | `production` |
| **Sampling Rate** | Taxa de amostragem de traces (0.0 a 1.0) | `1.0` |
| **Rastrear Queries SQL** | Habilita instrumentação do banco de dados | ✅ |
| **Rastrear HTTP Externo** | Habilita rastreamento de chamadas externas | ✅ |
| **Capturar Logs** | Envia logs de erro ao SigNoz | ✅ |

### Configuração via `wp-config.php`

Você também pode definir as configurações diretamente no `wp-config.php`:

```php
define('SIGNOZ_ENDPOINT', 'http://signoz-host:4318');
define('SIGNOZ_ACCESS_TOKEN', '');
define('SIGNOZ_SERVICE_NAME', 'meu-site-wordpress');
define('SIGNOZ_ENVIRONMENT', 'production');
define('SIGNOZ_SAMPLING_RATE', 1.0);
```

> Configurações definidas via `wp-config.php` têm prioridade sobre as do painel admin.

---

## Como Funciona

```
Requisição HTTP
      │
      ▼
┌─────────────────────────────┐
│       WordPress Core        │
│                             │
│  ┌───────────────────────┐  │
│  │  WP SigNoz Plugin     │  │
│  │                       │  │
│  │  • Cria RootSpan      │  │
│  │  • Hooks em queries   │  │
│  │  • Intercepta HTTP    │  │
│  │  • Captura erros      │  │
│  └───────────┬───────────┘  │
└──────────────┼──────────────┘
               │ OTLP/HTTP
               ▼
    ┌──────────────────────┐
    │   SigNoz Collector   │
    └──────────┬───────────┘
               │
       ┌───────┴────────┐
       ▼                ▼
   Traces            Métricas
   & Logs
```

O plugin utiliza **hooks nativos do WordPress** (`add_action`, `add_filter`) para instrumentação não-intrusiva, sem modificar arquivos do core.

---

## Dados Coletados

### Atributos do Span principal (por requisição)

| Atributo | Descrição |
|---|---|
| `http.method` | Método HTTP (GET, POST...) |
| `http.url` | URL da requisição |
| `http.status_code` | Código de resposta HTTP |
| `http.user_agent` | User-Agent do cliente |
| `wordpress.template` | Template utilizado |
| `wordpress.post_type` | Tipo de post (page, post, CPT) |
| `wordpress.is_admin` | Se é uma requisição de admin |

### Atributos de Span de Query SQL

| Atributo | Descrição |
|---|---|
| `db.system` | `mysql` |
| `db.statement` | Query SQL executada |
| `db.operation` | Operação (SELECT, INSERT...) |
| `db.sql.table` | Tabela principal da query |

---

## Estrutura do Plugin

```
wp-signoz/
├── wp-signoz.php                 # Entry point e registro de hooks
├── composer.json                 # Dependências (opentelemetry-php)
├── vendor/                       # Dependências instaladas
├── includes/
│   ├── class-signoz-tracer.php   # Gerenciamento de spans e traces
│   ├── class-signoz-metrics.php  # Coleta de métricas
│   ├── class-signoz-logger.php   # Captura e envio de logs
│   └── class-signoz-config.php   # Leitura de configurações
├── admin/
│   ├── class-signoz-admin.php    # Registro de menu e settings
│   └── views/
│       └── settings-page.php     # Template HTML da tela de config
├── assets/
│   ├── css/admin.css
│   └── js/admin.js
└── README.md
```

---

## Desenvolvimento

### Setup do ambiente

```bash
git clone https://github.com/seomarc/wp-signoz.git
cd wp-signoz
composer install
```

### Executar testes

```bash
composer test
```

### Padrões de código

O projeto segue o [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/).

```bash
composer lint
```

### Contribuindo

1. Faça um fork do repositório: `https://github.com/seomarc/wp-signoz`
2. Crie uma branch para sua feature: `git checkout -b feature/minha-feature`
3. Commit suas mudanças: `git commit -m 'feat: adiciona suporte a X'`
4. Push para a branch: `git push origin feature/minha-feature`
5. Abra um Pull Request

---

## FAQ

**O plugin impacta a performance do meu site?**
O envio de dados é feito de forma assíncrona ao final de cada requisição (hook `shutdown`), minimizando o impacto. Em casos de alta carga, recomenda-se reduzir o `SIGNOZ_SAMPLING_RATE`.

**Funciona com SigNoz Cloud?**
Sim. Basta preencher o campo **Access Token** com seu token de ingestão do SigNoz Cloud e usar o endpoint correto (`https://ingest.<region>.signoz.cloud:443`).

**É compatível com caches como WP Rocket ou W3 Total Cache?**
Sim, mas páginas servidas diretamente pelo cache (sem executar PHP) não serão instrumentadas, pois o plugin precisa do PHP em execução.

**Posso usar com WooCommerce?**
Sim. O plugin instrumena qualquer requisição WordPress, incluindo as do WooCommerce. Versões futuras incluirão spans específicos para eventos de e-commerce (checkout, pedidos, etc.).

---

## Changelog

### [1.0.0] — Em desenvolvimento
- Instrumentação automática de requisições HTTP
- Rastreamento de queries SQL
- Captura de logs de erro
- Painel de configuração no wp-admin
- Suporte a SigNoz Self-Hosted e Cloud

---

## Licença

Distribuído sob a licença **GPL-2.0-or-later**. Veja [`LICENSE`](LICENSE) para mais informações.

---

<p align="center">
  Feito com ❤️ para a comunidade WordPress + OpenTelemetry
</p>
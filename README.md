# 🤖 Módulo de Cadastro de Produtos via IA — Leilão Pronerd

Módulo independente, acoplado ao sistema legado PHP do Leilão Pronerd, que utiliza **Inteligência Artificial (Google Vision + NLP)** para automatizar o cadastro de produtos em lote a partir de fotos, reduzindo o trabalho manual de digitação de nome, descrição, categoria, dimensões e valor inicial.

---

## 📌 Visão Geral

O fluxo tradicional de cadastro de itens no sistema de leilão exige que o operador preencha manualmente, item a item, campos como nome, descrição, categoria e medidas. Este módulo resolve isso permitindo que o usuário:

1. Faça upload de várias fotos de produtos de uma vez (cadastro em lote), **ou**
2. Selecione produtos **já existentes** no sistema para serem reprocessados/enriquecidos pela IA.

A partir das imagens, um microsserviço Python analisa cada foto (rótulos, entidades web, texto via OCR), gera automaticamente uma sugestão completa de produto (nome, descrição, categoria, dimensões, valor) e apresenta tudo em uma tela de revisão, onde o operador pode ajustar os dados antes de importar definitivamente para a base do leilão.

---

## 🏗️ Arquitetura

O projeto é dividido em três camadas que se comunicam entre si:

```
┌─────────────────────┐        ┌──────────────────────────┐        ┌─────────────────────────┐
│   FRONTEND (JS)      │  HTTP  │   BACKEND PHP (legado)    │  HTTP  │  MICROSSERVIÇO PYTHON    │
│  jQuery + Bootstrap  │ ─────► │  Endpoints em /ai/*        │ ─────► │  Flask (ai_service)      │
│  Select2 / AJAX      │ ◄───── │  processar_ai.php, etc.    │ ◄───── │  Google Vision + NLP     │
└─────────────────────┘        └──────────────────────────┘        └─────────────────────────┘
                                            │
                                            ▼
                                  ┌───────────────────┐
                                  │   Banco de Dados    │
                                  │   MySQL             │
                                  │  (leilao + staging) │
                                  └───────────────────┘
```

### 1. Frontend (JavaScript / jQuery)
Responsável por toda a interação do usuário: upload de imagens, configuração de valores padrão, acompanhamento do processamento em tempo real (*polling*) e revisão/edição dos produtos sugeridos antes da importação final.

### 2. Backend PHP (sistema legado)
Camada intermediária que expõe endpoints REST simples (`ai/*.php`) para o frontend, gerencia autenticação por sessão/cookies, cria os *jobs* de processamento no banco e dispara o processamento para o microsserviço Python.

### 3. Microsserviço Python (Flask)
Serviço isolado, rodando em processo separado (`127.0.0.1:5001`), responsável pelo trabalho pesado: chamar a API do Google Vision para analisar as imagens e usar um pipeline de NLP para transformar os dados brutos da imagem em uma ficha de produto pronta para revisão.

> **Por que separar em microsserviço?** Isso isola dependências pesadas de IA (Google Cloud Vision, bibliotecas de NLP) do código legado PHP, evita travar o servidor web principal durante o processamento de imagens e permite escalar/reiniciar o serviço de IA de forma independente.

---

## 🔄 Fluxo de Funcionamento

### A) Cadastro em lote (produtos novos)

1. O usuário abre a tela de cadastro via IA e define os valores padrão do lote (categoria, dimensões, frete, status, estoque etc.).
2. Seleciona uma ou mais imagens e clica em **Enviar**.
3. O frontend monta um `FormData` com os arquivos e metadados e envia para `processar_ai.php`, que:
   - Cria um registro de **job** (`ai_jobs`) e as imagens associadas (`ai_job_images`);
   - Aciona o processamento (síncrono ou assíncrono) no microsserviço Python.
4. O frontend entra em modo de **polling**, consultando `processar_ai.php?action=status&job_id=...` a cada 5 segundos.
5. No microsserviço Python (`process_job`):
   - Para cada imagem do job, `analyze_image()` chama o **Google Cloud Vision** e extrai `labels`, `web_entities` e texto via **OCR**;
   - Esses dados são passados para `generate_product()`, que gera a ficha do produto (nome, descrição, categoria, dimensões, valor estimado e um score de **confiança**);
   - Produtos com confiança abaixo de **85%** são marcados com `requires_review = 1`, sinalizando revisão manual obrigatória;
   - Cada sugestão é gravada na tabela de **staging** `ai_job_products` (não altera o catálogo real ainda).
6. Quando o job finaliza (`status = done`), o frontend busca as categorias disponíveis e renderiza a **tela de revisão**, com um card por produto contendo imagem, campos editáveis e um checkbox de seleção.
7. O usuário revisa/ajusta os dados e clica em **Importar**, enviando o formulário para `importar_ai.php`, que grava definitivamente os produtos selecionados na tabela real do leilão (`leilao_itens`).

### B) Reprocessamento de produtos existentes (fila de IA)

Além de produtos novos, o módulo permite enriquecer/atualizar produtos **já cadastrados**:

1. O usuário abre o modal de produtos existentes (`ai/list_products.php`), pesquisa e navega pelas páginas.
2. Adiciona os produtos desejados a uma **fila local** (`processingQueue`) através de `ai/queue_manager.php` (ações `add_to_queue` / `remove_from_queue` / `get_queue` / `clear_queue`).
3. Ao clicar em **Processar Fila**, os IDs são enviados para `ai/process_existing.php`, que cria um novo job vinculando cada produto ao seu `source_product_id`.
4. O mesmo pipeline Python é executado, mas agora usando os dados do produto original (`leilao_itens`) como contexto adicional para o `generate_product()`, permitindo uma sugestão mais precisa de atualização.
5. O resultado passa pelo mesmo fluxo de revisão e importação.

---

## 🧩 Principais Componentes

### Frontend (JS)

| Função | Responsabilidade |
|---|---|
| `carregarCategorias()` | Carrega a lista de categorias do produto via `ai/get_categoria.php` |
| `#btnEnviarAI` (click) | Monta o `FormData` do lote e envia para `processar_ai.php` |
| `pollStatus(jobId)` / `pollStatusAI(jobId)` | Consulta periodicamente o status do job até `done` ou `error` |
| `renderPreview(products)` | Monta o HTML da tela de revisão com os produtos sugeridos |
| `carregarProdutosExistentes(pagina)` | Lista produtos já cadastrados, com busca e paginação |
| `toggleQueue()`, `carregarFila()`, `processarFila()`, `limparFila()`, `removerItemFila()` | Gerenciam a fila de reprocessamento de produtos existentes |
| `escapeHtml()` / `stripTags()` | Sanitização de dados exibidos em tela (proteção contra XSS) |

### Backend PHP — endpoints (`/ai/*`)

| Endpoint | Função |
|---|---|
| `processar_ai.php` | Cria job de processamento (upload novo) e consulta status (`?action=status`) |
| `ai/get_categoria.php` | Lista categorias de produto disponíveis |
| `ai/get_categoria_dimensoes.php` | Retorna dimensões/valor padrão de uma categoria |
| `ai/salvar_dimensoes_categoria.php` | Atualiza os valores padrão de dimensões de uma categoria |
| `ai/list_products.php` | Lista produtos já cadastrados (com paginação e busca) |
| `ai/queue_manager.php` | Gerencia a fila de reprocessamento (add/remove/list/clear) |
| `ai/process_existing.php` | Cria job de reprocessamento a partir de produtos existentes |
| `ai/get_staging_image.php` | Serve as imagens em staging para pré-visualização |
| `importar_ai.php` | Importa os produtos revisados/aprovados para a tabela real do leilão |

### Microsserviço Python (Flask — `ai_service`)

| Item | Descrição |
|---|---|
| `GET /health` | *Health check* simples do serviço |
| `POST /process_job` | Processa todas as imagens de um `job_id`: chama Vision API, gera o produto via NLP e grava na tabela de staging |
| `vision_pipeline.analyze_image()` | Integração com **Google Cloud Vision** (labels, entidades web, OCR) |
| `nlp_pipeline.generate_product()` | Gera a ficha do produto a partir dos metadados da imagem (e do produto original, se houver) |
| `execute_with_retry()` | Executa comandos SQL com retry automático em caso de *lock wait timeout* (até 3 tentativas, com backoff exponencial) |
| Logging | Todos os passos são registrados em `logs/ai_service.log` |

---

## 🗄️ Modelo de Dados (tabelas envolvidas)

| Tabela | Descrição |
|---|---|
| `ai_jobs` | Controle de cada job de processamento (status, totais, timestamps) |
| `ai_job_images` | Imagens associadas a um job, incluindo `source_product_id` quando for reprocessamento |
| `ai_job_products` | Área de **staging**: produtos sugeridos pela IA, aguardando revisão/importação |
| `leilao_itens` | Tabela real de produtos do sistema de leilão (destino final após importação) |

**Status possíveis de um job:** `queued` → `processing` → `done` ou `error`

---

## ⚙️ Configuração e Execução

### Requisitos
- PHP (sistema legado do leilão já em produção)
- MySQL/MariaDB
- Python 3.x
- Conta e credenciais do **Google Cloud Vision API**

### Variáveis de ambiente (`.env`)
O microsserviço Python carrega variáveis a partir de um arquivo `.env`:

```
GOOGLE_APPLICATION_CREDENTIALS=/caminho/para/gcp-final-credentials.json
```

### Subindo o microsserviço de IA

```bash
cd ai_service
pip install -r requirements.txt
python app.py
```

O serviço sobe em `127.0.0.1:5001`, acessível apenas internamente pelo backend PHP (não exposto publicamente).

### Logs
Os logs de execução ficam em `ai_service/logs/ai_service.log`, com nível `INFO`, registrando cada etapa do processamento (imagem por imagem) e eventuais falhas críticas com stack trace completo.

---

## 🔐 Considerações de Segurança

- Todas as saídas HTML no frontend passam por `escapeHtml()` / `stripTags()` para mitigar **XSS**.
- Requisições AJAX usam `withCredentials: true`, respeitando a sessão autenticada do sistema legado.
- O microsserviço Python roda em `127.0.0.1`, isolado de acesso externo direto.
- Falhas de banco por concorrência (*lock wait timeout*) são tratadas com retry e rollback automático, evitando jobs travados.
- Produtos com baixa confiança da IA (`< 85%`) **nunca** são importados automaticamente — sempre passam por revisão humana antes de irem para a base real.

---

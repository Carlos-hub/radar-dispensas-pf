# Etapa 1: base em Laravel com contas

Data: 2026-10-12
Status: aguardando revisão

## Contexto

O Radar de Dispensas PF hoje é uma ferramenta local de uma pessoa: PHP puro, sem login, que consulta o PNCP ao vivo e guarda candidaturas numa tabela única. O objetivo é transformá-lo num SaaS gratuito, sustentado por anúncios, em que qualquer pessoa física se cadastra, salva dispensas, acompanha candidaturas e recebe ajuda para montar a proposta que enviará no sistema oficial (gov.br e demais portais de origem). O site nunca protocola proposta em nome do usuário.

O SaaS foi dividido em quatro etapas, cada uma com spec e plano próprios:

1. **Base em Laravel com contas** (este documento)
2. Montador de proposta, com guia de cadastro da pessoa física nos sistemas oficiais
3. Alertas por e-mail de novas dispensas
4. Publicidade e páginas legais (privacidade, termos, consentimento de cookies)
5. Produção (domínio, HTTPS, hospedagem, e-mail transacional, proteção contra robôs no cadastro, backup do banco, monitoramento da sincronização)

**Restrição de lançamento:** o site só é aberto ao público depois da etapa 4, porque esta etapa já coleta nome e e-mail e ainda não tem política de privacidade nem termos de uso.

## Objetivo desta etapa

Ao final, o projeto roda em Laravel com `docker compose up`, e:

- um visitante sem conta navega e filtra o radar e abre a página de qualquer dispensa;
- uma pessoa cria conta por e-mail e senha ou pelo Google, e entra;
- uma pessoa logada salva dispensas e gerencia status e notas das suas candidaturas, sem enxergar as de outros;
- as dispensas vêm do banco, alimentado por uma sincronização periódica com o PNCP.

## Decisões já tomadas

| Tema | Decisão |
|---|---|
| Candidatura | Acompanhar e direcionar ao sistema oficial; montar proposta é opcional (etapa 2) |
| Login | E-mail e senha, mais "Entrar com Google" |
| Stack | Laravel (versão estável atual, 12 ou superior), PHP 8.3, PostgreSQL 16 |
| Acesso | Radar e páginas de dispensa públicos; conta só para salvar e acompanhar |
| Interface | Blade renderizado no servidor, reaproveitando o CSS atual |

## Arquitetura

### Serviços do docker compose

| Serviço | Função |
|---|---|
| `app` | Apache + PHP 8.3 servindo `public/` do Laravel |
| `scheduler` | Mesma imagem, rodando `php artisan schedule:work` |
| `db` | PostgreSQL 16 com volume persistente e extensão `unaccent` |
| `mailpit` | Captura os e-mails em desenvolvimento, com caixa de entrada em `localhost:8025` |

Ao subir, o `app` aplica as migrations pendentes e o `scheduler` roda uma sincronização imediata antes de entrar no ciclo de 15 minutos, para que uma instalação nova não fique com o radar vazio.

Sessão e cache usam o driver `database`. Os e-mails são enviados de forma síncrona; não há worker de fila nesta etapa. O fuso da aplicação é `America/Sao_Paulo`, porque o PNCP devolve datas no horário de Brasília sem fuso.

### Organização do código

| Arquivo | Responsabilidade |
|---|---|
| `app/Services/Pncp/PncpClient.php` | Busca páginas de `/contratacoes/proposta` no PNCP, 6 requisições em paralelo |
| `app/Services/ClassificadorPf.php` | Recebe um registro bruto do PNCP e devolve os campos normalizados, as categorias, o `fit` e o motivo. Porta a função `normalize` atual sem mudar as regras |
| `app/Console/Commands/SincronizarPncp.php` | Comando `pncp:sync`: busca, classifica e grava |
| `app/Models/{User,Dispensa,Candidatura}.php` | Modelos |
| `app/Enums/{Fit,StatusCandidatura}.php` | Valores fechados de aderência e de status |
| `app/Http/Controllers/RadarController.php` | Listagem pública com filtros |
| `app/Http/Controllers/DispensaController.php` | Página pública de uma dispensa |
| `app/Http/Controllers/CandidaturaController.php` | Listar, salvar, alterar e remover candidaturas do usuário logado |
| `app/Http/Controllers/Auth/GoogleController.php` | Redirecionamento e retorno do OAuth do Google |
| `app/Policies/CandidaturaPolicy.php` | Só o dono altera ou remove |

Cadastro, login, confirmação de e-mail, recuperação de senha e alteração de nome, e-mail e senha vêm do Laravel Fortify, com telas próprias em Blade no CSS do projeto. A exclusão de conta é um controller do projeto. O Breeze foi descartado porque suas telas dependem de Tailwind e de build com Node, e ele está em modo de manutenção. O login com Google usa o Laravel Socialite. As mensagens de autenticação e validação são traduzidas para `pt_BR`.

## Dados

### `users`

Colunas padrão do Laravel, com duas mudanças: `password` passa a aceitar nulo e entra `google_id` (texto, único, nulo).

### `dispensas`

| Coluna | Tipo | Observação |
|---|---|---|
| `id` | bigint | Chave primária, usada nas URLs |
| `pncp_id` | text, único | `numeroControlePNCP` |
| `objeto`, `complemento` | text | Complemento limitado a 400 caracteres, como hoje |
| `orgao`, `unidade`, `municipio` | text | |
| `uf` | varchar(2) | |
| `esfera` | text | Federal, Estadual, Municipal, Distrital ou Não informada |
| `valor` | numeric(15,2), nulo | Nulo quando sigiloso |
| `abre_em`, `encerra_em` | timestamp, nulo | Horário de Brasília |
| `amparo`, `disputa` | text | |
| `link_pncp`, `link_origem` | text, nulo | |
| `categorias` | jsonb | Lista de chaves: `ti`, `ensino`, `consultoria`, `conteudo`, `eventos` |
| `fit` | text | `alta`, `verificar`, `baixa` ou `fora` |
| `motivo` | text | |
| `sincronizado_em` | timestamp | Última vez que o PNCP devolveu este registro |

Índices em `uf`, `encerra_em` e `fit`. Nenhuma dispensa é apagada nesta etapa: as encerradas continuam acessíveis pela URL e pelas candidaturas de quem salvou.

### `candidaturas`

| Coluna | Tipo | Observação |
|---|---|---|
| `id` | bigint | |
| `user_id` | FK `users` | Apaga em cascata quando a conta é excluída |
| `dispensa_id` | FK `dispensas` | Restrita: dispensa com candidatura não pode ser apagada |
| `status` | text | `salva`, `enviada`, `ganhei`, `perdi` ou `descartada`; padrão `salva` |
| `notas` | text | Até 5.000 caracteres |

Única por (`user_id`, `dispensa_id`). A tabela `candidaturas` do projeto atual não é migrada: é local e está vazia.

## Sincronização com o PNCP

O comando `pncp:sync` roda a cada 15 minutos, sem sobreposição de execuções.

1. Consulta `/contratacoes/proposta` com `codigoModalidadeContratacao=8`, `dataFinal` igual a hoje mais 90 dias, sem UF, 50 registros por página.
2. Percorre todas as páginas, com teto de segurança de 400 páginas (20.000 registros). Se o teto for atingido, registra um aviso no log. Em 2026-10-12 a consulta devolvia 3.411 registros em 69 páginas, cerca de 1 segundo por página.
3. Classifica cada registro com o `ClassificadorPf` e grava por `pncp_id` (insere ou atualiza), marcando `sincronizado_em`.
4. Se o PNCP falhar no meio, o que já foi gravado permanece, o erro vai para o log e a próxima execução tenta de novo. O comando termina com código de erro.

O radar lista apenas dispensas com `encerra_em` no futuro (ou nulo) e `sincronizado_em` nas últimas 24 horas. A segunda condição tira da lista avisos que o órgão retirou antes do prazo.

A data e hora da última sincronização bem-sucedida ficam visíveis no radar. Se ela tiver mais de 1 hora, a página mostra um aviso de que os dados podem estar desatualizados.

## Páginas e rotas

| Rota | Acesso | Descrição |
|---|---|---|
| `GET /` | público | Radar com filtros e paginação |
| `GET /dispensas/{dispensa}` | público | Página de uma dispensa |
| `GET /candidaturas` | logado | Lista do usuário, com filtro por status |
| `POST /dispensas/{dispensa}/candidatura` | logado e confirmado | Salva a dispensa |
| `PATCH /candidaturas/{candidatura}` | dono | Altera status e/ou notas |
| `DELETE /candidaturas/{candidatura}` | dono | Remove |
| `GET /auth/google`, `GET /auth/google/callback` | visitante | Login com Google |
| Rotas do Fortify | conforme o caso | Cadastro, login, confirmação, recuperação de senha, alteração de dados e senha |
| `GET /perfil`, `DELETE /perfil` | logado | Página de perfil e exclusão da conta |

### Radar (`/`)

Os filtros ficam na query string, o que torna cada combinação um link compartilhável:

- `uf`: sigla; ausente significa Brasil todo
- `dias`: 7, 15, 30 ou 60; padrão 30
- `vmax`: valor máximo
- `q`: busca sem diferenciar acento nem maiúsculas em objeto, órgão, município e complemento
- `cat[]`: uma ou mais categorias
- `verificar` (ligado por padrão), `baixa`, `fora`: quais níveis de aderência incluir além de `alta`

Ordenação por `encerra_em` crescente, 30 fichas por página. O resumo (exibidas, fit PF, a verificar, abertas no recorte, valor estimado) é calculado no banco sobre o recorte inteiro, e não só sobre a página.

Cada ficha mantém o visual atual. Para quem está logado, o botão mostra "Salvar" ou "✓ Salva" conforme o caso.

### Página da dispensa

Mostra todos os campos da ficha, o motivo da classificação com o aviso de que ela é automática e não substitui a leitura do aviso oficial, o link para o aviso no PNCP e o botão que leva ao sistema de origem para enviar a proposta. Dispensa encerrada continua visível, marcada como encerrada e sem o botão de salvar.

### Minhas candidaturas

Igual à aba atual: filtro por status com contadores, seletor de status, campo de notas e remover. Os formulários funcionam sem JavaScript; com JavaScript, status e notas são gravados ao alterar, sem recarregar a página.

## Indexação por buscadores

O tráfego orgânico é a fonte de audiência do produto, então as páginas públicas já nascem preparadas:

- Cada página de dispensa tem `<title>` e descrição próprios, montados a partir do objeto, do órgão e do município.
- O radar sem filtros e o radar por UF (`/?uf=SC`) são indexáveis e têm URL canônica.
- Qualquer outra combinação de filtros e as páginas 2 em diante recebem `noindex`, para não gerar conteúdo duplicado.
- `/sitemap.xml` lista o radar, o radar de cada UF e as dispensas abertas. `robots.txt` aponta para ele e bloqueia as rotas de conta.

## Regras de conta

- Visitante que clica em "Salvar" é levado ao login e, depois de entrar, volta para a página da dispensa.
- Quem se cadastra por e-mail precisa confirmar o endereço antes de salvar. Até lá, navega normalmente e vê o pedido de confirmação.
- Login pelo Google cria a conta já confirmada. Se já existir conta com o mesmo e-mail, o Google é vinculado a ela e o e-mail passa a constar como confirmado.
- Conta criada só pelo Google não tem senha; a pessoa pode definir uma pelo fluxo de recuperação de senha.
- Se `GOOGLE_CLIENT_ID` não estiver configurado, o botão do Google não aparece e o resto funciona.
- Excluir a conta apaga as candidaturas da pessoa.

## Segurança

- CSRF em todos os formulários (padrão do Laravel).
- Limites: login, 5 tentativas por minuto por e-mail e IP; cadastro, 10 por hora por IP; gravações de candidatura, 60 por minuto por usuário.
- `CandidaturaPolicy` em toda alteração e remoção. Candidatura de outro usuário responde 404, para não confirmar que existe.
- Links externos vindos do PNCP só são renderizados se começarem com `http://` ou `https://`.

## Testes

| Alvo | Tipo | O que cobre |
|---|---|---|
| `ClassificadorPf` | unitário | Cada ramo da regra de aderência (engenharia, bens, PF explícito, categoria sem "empresa", categoria com "empresa", sem categoria), cada categoria, limpeza do prefixo do objeto, corte do complemento |
| `pncp:sync` | integração, HTTP simulado | Múltiplas páginas, atualização de registro existente, resposta 204, falha no meio preservando o que foi gravado |
| Radar | fluxo | Cada filtro, ocultação de encerradas e de não sincronizadas, paginação, resumo |
| Indexação | fluxo | `noindex` nas combinações de filtro e nas páginas seguintes, conteúdo do sitemap |
| Contas | fluxo | Cadastro, confirmação, login, recuperação de senha, login Google novo e vinculado a conta existente |
| Candidaturas | fluxo | Salvar exige login e confirmação, duplicata não cria segundo registro, alterar e remover, 404 para candidatura de outro usuário |

## Integração contínua

Um workflow do GitHub Actions roda a suíte de testes a cada push e pull request.

## Fora desta etapa

- Montador de proposta e dados pessoais que ele exige (CPF, endereço)
- Espaços de anúncio, política de privacidade, termos de uso e consentimento de cookies (etapa 4)
- Hospedagem, domínio, HTTPS, provedor de e-mail de produção, captcha no cadastro, backup e monitoramento (etapa 5)
- Alertas por e-mail de novas dispensas (etapa 3)
- Limpeza de dispensas antigas

## Dependência externa

O login com Google precisa de um cliente OAuth criado pelo dono do projeto no Google Cloud, com `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` e a URL de retorno `/auth/google/callback`. Em desenvolvimento, o restante funciona sem isso.

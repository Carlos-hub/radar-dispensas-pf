# Radar de Dispensas PF

Consulta as dispensas de licitação com propostas abertas no [PNCP](https://pncp.gov.br), classifica quais uma pessoa física consegue executar sem CNPJ e guarda as escolhidas para acompanhar a candidatura (status e notas).

## Como rodar

Requisitos: Docker com o plugin Compose.

```bash
cp .env.example .env
docker compose up -d --build
```

Abra **http://localhost:8081**.

Na primeira subida o PostgreSQL cria a tabela `candidaturas` a partir de `db/init.sql`. Os dados ficam no volume `dbdata` e sobrevivem a restarts.

| Comando | O que faz |
|---|---|
| `docker compose up -d` | Sobe o app e o banco |
| `docker compose logs -f app` | Acompanha os logs do PHP/Apache |
| `docker compose down` | Para tudo e mantém os dados |
| `docker compose down -v` | Para tudo e **apaga o banco** |

O código de `public/` e `src/` é montado como volume: editar PHP, JS ou CSS não exige rebuild. Só refaça o build (`--build`) se mudar o `Dockerfile`.

## Configuração

Variáveis do `.env`:

| Variável | Padrão | Uso |
|---|---|---|
| `APP_PORT` | `8081` | Porta do app na sua máquina |
| `DB_PORT` | `5433` | Porta do PostgreSQL na sua máquina (só em `127.0.0.1`) |
| `DB_NAME` | `radar` | Nome do banco |
| `DB_USER` | `radar` | Usuário do banco |
| `DB_PASSWORD` | `radar` | Senha do banco |

`DB_NAME`, `DB_USER` e `DB_PASSWORD` só valem na criação do volume. Para trocá-los depois, recrie o banco com `docker compose down -v`.

Opcionalmente, `PNCP_BASE` (variável de ambiente do serviço `app`) aponta para outra base da API do PNCP.

## Como usar

- **Radar:** escolha a UF e o prazo, filtre por categoria, valor ou texto, e clique em **Salvar** nas dispensas que quer disputar. A consulta ao PNCP fica em cache por 15 minutos; **Atualizar** força uma nova.
- **Minhas candidaturas:** mude o status (`Salva`, `Proposta enviada`, `Ganhei`, `Perdi`, `Descartada`), escreva notas (gravadas ao sair do campo) ou remova. A ficha guarda uma cópia do aviso, então continua legível depois que ele sai do PNCP.

A aderência de cada dispensa é uma heurística sobre o texto do objeto. Sempre confira o aviso e o termo de referência antes de enviar proposta.

## Consultar o banco

```bash
docker compose exec db psql -U radar -d radar
```

Ou conecte um cliente (DBeaver, TablePlus) em `localhost:5433`, banco, usuário e senha `radar`.

```sql
SELECT id, objeto, orgao, valor, encerra_em, status, notas
FROM candidaturas
ORDER BY encerra_em;
```

A coluna `aviso` (JSONB) guarda a ficha completa, com links, categorias e motivo da classificação.

## Estrutura

```
Dockerfile, docker-compose.yml   PHP 8.3 + Apache e PostgreSQL 16
db/init.sql                      Criação da tabela candidaturas
src/pncp.php                     Cliente da API do PNCP, com cache
src/classificacao.php            Categorias e regra de aderência PF
src/candidaturas.php             Leitura e escrita no banco
public/api.php                   API JSON
public/index.php                 HTML da página
public/assets/                   app.js e app.css
```

## API

| Método | Rota | Descrição |
|---|---|---|
| `GET` | `api.php?r=dispensas&uf=SC&dias=30` | Dispensas abertas, já classificadas. `uf=BR` traz o país todo; `fresh=1` ignora o cache |
| `GET` | `api.php?r=candidaturas` | Lista as candidaturas salvas |
| `POST` | `api.php?r=candidaturas` | Salva uma ficha (corpo: o item devolvido por `dispensas`) |
| `PATCH` | `api.php?r=candidaturas&id=1` | Altera `status` e/ou `notas` |
| `DELETE` | `api.php?r=candidaturas&id=1` | Remove a candidatura |

Não há autenticação: o projeto é para uso local, por uma pessoa. Não exponha a porta do app na internet.

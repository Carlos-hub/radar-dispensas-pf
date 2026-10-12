# Radar de Dispensas PF

Mostra as dispensas de licitação com propostas abertas no [PNCP](https://pncp.gov.br), classifica quais uma pessoa física consegue executar sem CNPJ e permite que cada pessoa crie uma conta para salvar dispensas e acompanhar as suas candidaturas. A proposta é sempre enviada no sistema oficial de cada órgão; o site só direciona.

## Como rodar

Requisitos: Docker com o plugin Compose.

```bash
cp .env.example .env
docker compose up -d --build
```

Abra **http://localhost:8081**. A primeira subida instala as dependências, cria as tabelas e busca as dispensas no PNCP. O aplicativo responde assim que os contêineres estão saudáveis, e o radar vai se preenchendo conforme a sincronização avança; ela leva cerca de seis minutos para ficar completa.

| Endereço | O que é |
|---|---|
| http://localhost:8081 | O aplicativo |
| http://localhost:8025 | Mailpit: caixa de entrada com os e-mails enviados em desenvolvimento |
| `localhost:5433` | PostgreSQL (banco, usuário e senha `radar`) |

| Comando | O que faz |
|---|---|
| `docker compose up -d` | Sobe tudo |
| `docker compose logs -f app scheduler` | Acompanha os logs |
| `docker compose exec -u www-data app php artisan test` | Roda os testes |
| `docker compose exec -u www-data app php artisan pncp:sync` | Sincroniza com o PNCP agora |
| `docker compose down` | Para tudo e mantém os dados |
| `docker compose down -v` | Para tudo e **apaga o banco** |

Rode sempre `php`, `composer` e `artisan` com `-u www-data`. Como root, os arquivos criados ficam sem permissão de escrita para o Apache.

## Serviços

| Serviço | Função |
|---|---|
| `app` | Apache + PHP 8.3 servindo o Laravel; aplica as migrations ao subir |
| `scheduler` | Sincroniza com o PNCP ao subir e depois a cada 15 minutos |
| `db` | PostgreSQL 16 |
| `mailpit` | Captura os e-mails de confirmação e de recuperação de senha |

## Login com Google

É opcional. Sem configurar, o botão não aparece e o resto funciona.

1. No [Google Cloud Console](https://console.cloud.google.com/apis/credentials), crie um ID do cliente OAuth do tipo "Aplicativo da Web".
2. Em URIs de redirecionamento autorizados, informe `http://localhost:8081/auth/google/callback`.
3. Preencha `GOOGLE_CLIENT_ID` e `GOOGLE_CLIENT_SECRET` no `.env`.

## Como funciona

- **Sincronização:** o comando `pncp:sync` busca as dispensas do Brasil todo que encerram em até 90 dias, classifica cada uma e grava na tabela `dispensas`. Como o PNCP limita a taxa de requisições, a sincronização faz poucas consultas em paralelo, com pausas, e tenta de novo quando a resposta é HTTP 429. Uma dispensa que o PNCP para de devolver sai do radar depois de 24 horas.
- **Classificação:** `app/Services/ClassificadorPf.php` é uma heurística sobre o texto do aviso. Ela não substitui a leitura do aviso oficial.
- **Radar:** público. Os filtros ficam na URL. Só o radar sem filtro e o radar por UF são indexados pelos buscadores. Quem clica em "Salvar" sem estar logado passa pelo login e volta para a página da dispensa.
- **Contas:** e-mail e senha (com confirmação de e-mail) ou Google. Só contas confirmadas salvam dispensas. O e-mail do Google só vale quando o Google o informa como verificado, e a conta não é vinculada se já pertencer a outra identidade do Google.
- **Candidaturas:** cada pessoa vê só as suas, com status e notas.

## Estrutura

```
app/Services/Pncp/PncpClient.php            Cliente da API do PNCP
app/Services/ClassificadorPf.php            Categorias e regra de aderência PF
app/Console/Commands/SincronizarPncp.php    Comando pncp:sync
app/Support/FiltroRadar.php                 Filtros do radar
app/Http/Controllers/                       Radar, dispensa, candidaturas, perfil, sitemap, Google
app/Models/                                 User, Dispensa, Candidatura
resources/views/                            Telas em Blade
public/css/app.css, public/js/app.js        Estilos e JavaScript
docs/superpowers/                           Specs e planos
```

## Antes de abrir ao público

Este projeto ainda não tem política de privacidade, termos de uso, consentimento de cookies, proteção contra robôs no cadastro, nem configuração de produção. Não exponha na internet antes dessas etapas.

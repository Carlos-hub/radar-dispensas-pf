# Base em Laravel com contas: plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Transformar o Radar de Dispensas PF num aplicativo Laravel multiusuário, com radar público alimentado por sincronização com o PNCP e candidaturas por conta.

**Architecture:** Laravel renderiza tudo no servidor com Blade e o CSS atual. Um comando agendado copia as dispensas do PNCP para o PostgreSQL e as classifica; as páginas públicas leem do banco. O Fortify cuida de cadastro, login e senha; o Socialite, do login com Google.

**Tech Stack:** PHP 8.3, Laravel (12 ou superior), Laravel Fortify, Laravel Socialite, PostgreSQL 16 (extensão `unaccent`), Apache, Docker Compose, Mailpit, PHPUnit, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-10-12-base-laravel-contas-design.md`

## Global Constraints

- PHP 8.3, Laravel versão estável atual (12 ou superior), PostgreSQL 16.
- Fuso da aplicação: `America/Sao_Paulo`. O PNCP devolve datas no horário de Brasília sem fuso.
- Sessão e cache no driver `database`; e-mails síncronos (`QUEUE_CONNECTION=sync`); sem worker de fila.
- Interface em Blade renderizado no servidor, sem Node, sem Tailwind, sem build de front. CSS em `public/css/app.css`, JS em `public/js/app.js`.
- Textos da interface e mensagens de validação em português (`pt_BR`).
- As regras de classificação PF não mudam: o `ClassificadorPf` porta a função `normalize` atual.
- Valores de aderência: `alta`, `verificar`, `baixa`, `fora`. Valores de status: `salva`, `enviada`, `ganhei`, `perdi`, `descartada`. Categorias: `ti`, `ensino`, `consultoria`, `conteudo`, `eventos`.
- Nenhuma dispensa é apagada nesta etapa.
- Os testes rodam em PostgreSQL (banco `radar_test`), não em SQLite: as consultas usam `unaccent`, `ILIKE` e `jsonb`.
- Todo comando `php`, `composer` ou `artisan` roda no container, como o usuário `www-data`: `docker compose exec -u www-data app <comando>`. Rodar como root deixa arquivos que o Apache não consegue gravar.
- App em `http://localhost:8081` (a 8080 está ocupada na máquina do dono do projeto). Mailpit em `http://localhost:8025`.
- Commits em português, terminando com `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Não fazer push sem pedido explícito.

## Review Focus

1. **O PNCP devolve o mesmo `numeroControlePNCP` duas vezes na mesma página.** A sincronização não pode falhar; grava uma linha só. Teste na Task 4.
2. **Registro do PNCP com campos nulos ou ausentes** (`objetoCompra` nulo, sem `orgaoEntidade`, sem `amparoLegal`, valor nulo). O classificador devolve uma linha válida em vez de quebrar. Teste na Task 3.
3. **URL do radar com parâmetros hostis** (`uf=XYZ`, `uf[]=a`, `dias=abc`, `vmax=-5`, `cat=texto`, `page=999`, `q` com `%`, `_` e aspas). A página responde 200 com os padrões, e `%` e `_` são buscados literalmente. Teste na Task 5.
4. **Salvar uma dispensa que encerrou enquanto a página estava aberta.** O pedido é recusado com mensagem e nada é criado. Teste na Task 7.
5. **Conta criada por terceiro com o e-mail de outra pessoa, nunca confirmada, e depois login Google do dono real.** A senha antiga é anulada e as sessões existentes são encerradas, para o terceiro não manter acesso. Teste na Task 8.

---

## Estrutura de arquivos

| Arquivo | Responsabilidade | Task |
|---|---|---|
| `Dockerfile`, `docker/entrypoint.sh`, `docker-compose.yml`, `db/init.sql` | Ambiente: app, scheduler, db, mailpit | 1 |
| `config/fortify.php`, `app/Providers/FortifyServiceProvider.php`, `app/Actions/Fortify/CreateNewUser.php` | Autenticação | 2 |
| `app/Http/Controllers/PerfilController.php` | Página de perfil e exclusão de conta | 2 |
| `resources/views/layouts/app.blade.php`, `resources/views/auth/*.blade.php`, `resources/views/perfil.blade.php` | Layout e telas de conta | 2 |
| `public/css/app.css` | Estilos | 2, 5, 7 |
| `app/Enums/Fit.php`, `app/Services/ClassificadorPf.php` | Classificação PF | 3 |
| `app/Models/Dispensa.php`, migration, factory | Dispensa | 4 |
| `app/Services/Pncp/PncpClient.php`, `app/Console/Commands/SincronizarPncp.php`, `routes/console.php` | Sincronização | 4 |
| `app/Support/FiltroRadar.php`, `app/Http/Controllers/RadarController.php`, `app/Http/Controllers/DispensaController.php` | Radar e página da dispensa | 5 |
| `resources/views/radar.blade.php`, `resources/views/dispensas/show.blade.php`, `resources/views/partials/{ficha,paginacao}.blade.php`, `public/js/app.js` | Telas públicas | 5 |
| `app/Http/Controllers/SitemapController.php` | Sitemap e robots | 6 |
| `app/Enums/StatusCandidatura.php`, `app/Models/Candidatura.php`, `app/Policies/CandidaturaPolicy.php`, `app/Http/Controllers/CandidaturaController.php`, `resources/views/candidaturas/index.blade.php` | Candidaturas | 7 |
| `app/Http/Controllers/Auth/GoogleController.php` | Login com Google | 8 |
| `.github/workflows/testes.yml`, `README.md` | CI e documentação | 9 |

---

### Task 1: Laravel rodando no Docker com PostgreSQL de teste

**Files:**
- Create: `Dockerfile` (substitui o atual), `docker/entrypoint.sh`, `docker-compose.yml` (substitui o atual), `db/init.sql` (substitui o atual), `.dockerignore` (substitui o atual)
- Create: todo o esqueleto do Laravel na raiz
- Modify: `.env.example`, `config/app.php`, `phpunit.xml`
- Delete: `public/index.php`, `public/api.php`, `public/assets/`, `src/` (versão em PHP puro; fica no histórico do git)

**Interfaces:**
- Produces: `docker compose up -d --build` sobe `app`, `scheduler`, `db`, `mailpit`; `docker compose exec -u www-data app php artisan test` roda a suíte no banco `radar_test`.

- [ ] **Step 1: Criar a branch e derrubar o ambiente antigo**

O volume do banco atual é local e está vazio (a spec define que não é migrado). `down -v` o apaga.

```bash
git checkout -b etapa-1-laravel-contas
docker compose down -v
```

- [ ] **Step 2: Escrever o `Dockerfile`**

```dockerfile
FROM php:8.3-apache

ARG UID=1000
ARG GID=1000

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev libzip-dev unzip git \
 && docker-php-ext-install pdo_pgsql zip pcntl \
 && rm -rf /var/lib/apt/lists/* \
 && a2enmod rewrite \
 && groupmod -g ${GID} www-data && usermod -u ${UID} www-data

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Datas do PNCP vêm no horário de Brasília, sem fuso
RUN echo "date.timezone=America/Sao_Paulo" > /usr/local/etc/php/conf.d/timezone.ini

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public \
    COMPOSER_HOME=/tmp/composer
RUN sed -ri "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" \
      /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
 && sed -ri "s/AllowOverride None/AllowOverride All/g" /etc/apache2/apache2.conf

COPY docker/entrypoint.sh /usr/local/bin/radar-entrypoint
RUN chmod +x /usr/local/bin/radar-entrypoint

WORKDIR /var/www/html
ENTRYPOINT ["radar-entrypoint"]
CMD ["apache2-foreground"]
```

- [ ] **Step 3: Escrever `docker/entrypoint.sh`**

```sh
#!/bin/sh
# Prepara o projeto montado em /var/www/html antes de iniciar o processo do container.
set -e
cd /var/www/html

como_app() { runuser -u www-data -- "$@"; }

if [ -f composer.json ] && [ ! -d vendor ]; then
    como_app composer install --no-interaction
fi

if [ -f artisan ] && [ "${PREPARAR_APP:-0}" = "1" ]; then
    [ -f .env ] || como_app cp .env.example .env
    grep -q '^APP_KEY=.\+' .env || como_app php artisan key:generate --force
    como_app php artisan migrate --force
fi

exec docker-php-entrypoint "$@"
```

- [ ] **Step 4: Escrever `docker-compose.yml`**

```yaml
x-app: &app
  build:
    context: .
    args:
      UID: ${UID:-1000}
      GID: ${GID:-1000}
  volumes:
    - .:/var/www/html
  restart: unless-stopped

services:
  app:
    <<: *app
    environment:
      PREPARAR_APP: "1"
    ports:
      - "${APP_PORT:-8081}:80"
    depends_on:
      db:
        condition: service_healthy
    healthcheck:
      test: ["CMD-SHELL", "curl -fsS http://localhost/up > /dev/null"]
      interval: 5s
      timeout: 5s
      retries: 60

  scheduler:
    <<: *app
    # sincroniza na subida para o radar não nascer vazio, depois segue o agendamento
    command: ["sh", "-c", "runuser -u www-data -- php artisan pncp:sync; exec runuser -u www-data -- php artisan schedule:work"]
    depends_on:
      app:
        condition: service_healthy

  db:
    image: postgres:16-alpine
    environment:
      POSTGRES_DB: ${DB_DATABASE:-radar}
      POSTGRES_USER: ${DB_USERNAME:-radar}
      POSTGRES_PASSWORD: ${DB_PASSWORD:-radar}
      TZ: America/Sao_Paulo
    ports:
      # só na máquina local, para consultar com psql/DBeaver
      - "127.0.0.1:${FORWARD_DB_PORT:-5433}:5432"
    volumes:
      - dbdata:/var/lib/postgresql/data
      - ./db/init.sql:/docker-entrypoint-initdb.d/init.sql:ro
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U $${POSTGRES_USER} -d $${POSTGRES_DB}"]
      interval: 3s
      timeout: 3s
      retries: 20
    restart: unless-stopped

  mailpit:
    image: axllent/mailpit:latest
    ports:
      - "127.0.0.1:8025:8025"
    restart: unless-stopped

volumes:
  dbdata:
```

- [ ] **Step 5: Escrever `db/init.sql` e `.dockerignore`**

`db/init.sql`:

```sql
-- Banco usado só pela suíte de testes. As tabelas são criadas pelas migrations do Laravel.
CREATE DATABASE radar_test;
```

`.dockerignore`:

```
.git
.env
vendor
node_modules
storage/logs
```

- [ ] **Step 6: Remover o código em PHP puro e instalar o Laravel**

```bash
git rm -r -q public src
docker build -t radar-pf-dev --build-arg UID=$(id -u) --build-arg GID=$(id -g) .
docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer -v "$PWD":/work -w /work \
  --entrypoint composer radar-pf-dev create-project laravel/laravel laravel-tmp
rm laravel-tmp/README.md
cp -a laravel-tmp/. . && rm -rf laravel-tmp
rm -f database/database.sqlite
```

Run: `ls artisan composer.json public/index.php && grep '"laravel/framework"' composer.json`
Expected: os três arquivos existem e a versão do framework é `^12` ou superior.

- [ ] **Step 7: Ajustar `.env.example`**

Edite `.env.example` para que estas chaves tenham estes valores (substitua as linhas existentes, descomente as que estiverem comentadas e acrescente as que faltarem ao final):

```dotenv
APP_NAME="Radar de Dispensas PF"
APP_URL=http://localhost:8081
APP_LOCALE=pt_BR
APP_FALLBACK_LOCALE=pt_BR
APP_FAKER_LOCALE=pt_BR

DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=radar
DB_USERNAME=radar
DB_PASSWORD=radar

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=sync

MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_FROM_ADDRESS="nao-responda@radar.local"

# Portas na máquina local (docker compose)
APP_PORT=8081
FORWARD_DB_PORT=5433

PNCP_BASE=https://pncp.gov.br/api/consulta/v1

GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
```

Depois: `cp .env.example .env` (o `.gitignore` do Laravel já ignora `.env`).

- [ ] **Step 8: Fixar o fuso e apontar os testes para o PostgreSQL**

Em `config/app.php`, deixe a linha do fuso assim:

```php
    'timezone' => 'America/Sao_Paulo',
```

Em `phpunit.xml`, dentro de `<php>`, troque as duas linhas de banco (`DB_CONNECTION` e `DB_DATABASE`, que vêm apontando para SQLite) por:

```xml
        <env name="DB_CONNECTION" value="pgsql"/>
        <env name="DB_DATABASE" value="radar_test"/>
```

- [ ] **Step 9: Instalar a tradução `pt_BR`**

```bash
docker compose up -d --build
docker compose exec -u www-data app composer require --dev laravel-lang/common
docker compose exec -u www-data app php artisan lang:add pt_BR
```

Run: `ls lang/pt_BR/validation.php lang/pt_BR.json`
Expected: os dois arquivos existem.

- [ ] **Step 10: Verificar que sobe e que a suíte roda no PostgreSQL**

Run: `docker compose ps --format '{{.Name}} {{.Status}}'`
Expected: `app` como `healthy`, `db` como `healthy`, `mailpit` e `scheduler` de pé. O log do `scheduler` mostra um erro de comando `pncp:sync` inexistente; isso é esperado até a Task 4.

Run: `curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8081/`
Expected: `200`

Run: `docker compose exec -u www-data app php artisan test`
Expected: os testes de exemplo do Laravel passam.

Run: `docker compose exec -T db psql -U radar -d radar_test -c '\dt' | grep -c migrations`
Expected: `1` (a suíte criou as tabelas no banco de teste, o que prova que não está em SQLite).

- [ ] **Step 11: Commit**

```bash
git add -A
git commit -m "Migra o projeto para Laravel com Docker, PostgreSQL e Mailpit

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Contas com Fortify, layout e telas

**Files:**
- Create: `app/Http/Controllers/PerfilController.php`
- Create: `resources/views/layouts/app.blade.php`, `resources/views/auth/{login,register,forgot-password,reset-password,verify-email}.blade.php`, `resources/views/perfil.blade.php`
- Create: `public/css/app.css`
- Create: `tests/Feature/ContasTest.php`
- Modify: `config/fortify.php`, `app/Providers/FortifyServiceProvider.php`, `app/Actions/Fortify/CreateNewUser.php`, `app/Models/User.php`, `routes/web.php`
- Delete: a migration de dois fatores publicada pelo Fortify

**Interfaces:**
- Produces: rotas nomeadas do Fortify (`login`, `logout`, `register`, `password.request`, `password.email`, `password.reset`, `password.update`, `verification.notice`, `verification.send`, `verification.verify`, `user-profile-information.update`, `user-password.update`); rotas `perfil` (GET) e `perfil.destroy` (DELETE); layout `layouts.app` com as seções `title`, `description`, `content` e a pilha `head`; middlewares `auth` e `verified` prontos para uso.

- [ ] **Step 1: Instalar o Fortify**

```bash
docker compose exec -u www-data app composer require laravel/fortify
docker compose exec -u www-data app php artisan fortify:install
rm database/migrations/*two_factor*
```

- [ ] **Step 2: Escrever os testes de conta (falhando)**

`tests/Feature/ContasTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ContasTest extends TestCase
{
    use RefreshDatabase;

    private function dadosCadastro(array $extra = []): array
    {
        return $extra + [
            'name' => 'Ana Souza',
            'email' => 'ana@example.com',
            'password' => 'senha-segura-1',
            'password_confirmation' => 'senha-segura-1',
        ];
    }

    public function test_telas_de_conta_abrem(): void
    {
        foreach (['/login', '/register', '/forgot-password'] as $rota) {
            $this->get($rota)->assertOk();
        }
    }

    public function test_cadastro_cria_usuario_nao_confirmado_e_envia_confirmacao(): void
    {
        Notification::fake();

        $this->post('/register', $this->dadosCadastro())->assertRedirect('/');

        $user = User::where('email', 'ana@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_cadastro_recusa_email_repetido(): void
    {
        User::factory()->create(['email' => 'ana@example.com']);

        $this->post('/register', $this->dadosCadastro())->assertSessionHasErrors('email');
        $this->assertSame(1, User::count());
    }

    public function test_cadastro_tem_limite_de_10_por_hora_por_ip(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post('/register', $this->dadosCadastro(['email' => "p$i@example.com"]));
            $this->post('/logout');
        }

        $this->post('/register', $this->dadosCadastro(['email' => 'onze@example.com']))
            ->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('users', ['email' => 'onze@example.com']);
    }

    public function test_link_de_confirmacao_confirma_o_email(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect();

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_login_com_senha_certa_entra_e_com_senha_errada_nao(): void
    {
        $user = User::factory()->create(['password' => Hash::make('senha-segura-1')]);

        $this->post('/login', ['email' => $user->email, 'password' => 'errada'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/login', ['email' => $user->email, 'password' => 'senha-segura-1'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_bloqueia_apos_5_tentativas_por_minuto(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'errada']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'errada'])->assertStatus(429);
    }

    public function test_logout_encerra_a_sessao(): void
    {
        $this->actingAs(User::factory()->create())->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_recuperacao_de_senha_troca_a_senha(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors();

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($n) use (&$token) {
            $token = $n->token;
            return true;
        });

        $this->get("/reset-password/$token?email=" . urlencode($user->email))->assertOk();
        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'nova-senha-22',
            'password_confirmation' => 'nova-senha-22',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('nova-senha-22', $user->fresh()->password));
    }

    public function test_perfil_exige_login(): void
    {
        $this->get('/perfil')->assertRedirect('/login');
    }

    public function test_perfil_altera_nome(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/perfil')->assertOk()->assertSee($user->email);
        $this->actingAs($user)
            ->put('/user/profile-information', ['name' => 'Novo Nome', 'email' => $user->email])
            ->assertSessionHasNoErrors();

        $this->assertSame('Novo Nome', $user->fresh()->name);
    }

    public function test_excluir_conta_exige_digitar_o_email(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->delete('/perfil', ['email_confirmacao' => 'outro@example.com'])
            ->assertSessionHasErrors('email_confirmacao');
        $this->assertDatabaseHas('users', ['id' => $user->id]);

        $this->actingAs($user)->delete('/perfil', ['email_confirmacao' => $user->email])->assertRedirect('/');
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertGuest();
    }
}
```

- [ ] **Step 3: Rodar e ver falhar**

Run: `docker compose exec -u www-data app php artisan test --filter=ContasTest`
Expected: FAIL. As telas respondem erro porque as views não foram registradas, e `/perfil` não existe.

- [ ] **Step 4: Configurar o Fortify**

Em `config/fortify.php`, deixe estas duas chaves assim (mantenha o resto como foi publicado):

```php
    'home' => '/',

    'features' => [
        Features::registration(),
        Features::resetPasswords(),
        Features::emailVerification(),
        Features::updateProfileInformation(),
        Features::updatePasswords(),
    ],
```

Em `app/Providers/FortifyServiceProvider.php`, acrescente ao final do método `boot()` (o limitador `login` de 5 por minuto por e-mail e IP já vem publicado nesse método; não o remova):

```php
        Fortify::loginView(fn () => view('auth.login'));
        Fortify::registerView(fn () => view('auth.register'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn ($request) => view('auth.reset-password', ['request' => $request]));
        Fortify::verifyEmailView(fn () => view('auth.verify-email'));
```

Confira no mesmo arquivo que o limitador publicado é `Limit::perMinute(5)`. Se o método `boot()` registrar um limitador `two-factor`, pode deixá-lo.

- [ ] **Step 5: Limitar o cadastro em `app/Actions/Fortify/CreateNewUser.php`**

Substitua o arquivo inteiro:

```php
<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    public const LIMITE_POR_HORA = 10;

    /**
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $chave = 'cadastro:' . request()->ip();
        if (RateLimiter::tooManyAttempts($chave, self::LIMITE_POR_HORA)) {
            throw ValidationException::withMessages([
                'email' => 'Muitos cadastros a partir deste endereço. Tente de novo mais tarde.',
            ]);
        }

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'password' => $this->passwordRules(),
        ])->validate();

        RateLimiter::hit($chave, 3600);

        return User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => Hash::make($input['password']),
        ]);
    }
}
```

- [ ] **Step 6: Exigir confirmação de e-mail no modelo**

Em `app/Models/User.php`, importe o contrato e implemente-o na classe:

```php
use Illuminate\Contracts\Auth\MustVerifyEmail;

class User extends Authenticatable implements MustVerifyEmail
```

- [ ] **Step 7: Controller de perfil e rotas**

`app/Http/Controllers/PerfilController.php`:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PerfilController extends Controller
{
    public function show(Request $request): View
    {
        return view('perfil', ['user' => $request->user()]);
    }

    /** Exclui a conta. A confirmação é digitar o e-mail, porque contas criadas pelo Google não têm senha. */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        $request->validate(
            ['email_confirmacao' => ['required', Rule::in([$user->email])]],
            ['email_confirmacao.*' => 'Digite o e-mail da conta para confirmar a exclusão.'],
        );

        Auth::logout();
        $user->delete();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('status', 'Conta excluída.');
    }
}
```

Em `routes/web.php`, acrescente (mantenha a rota `/` de boas-vindas por enquanto; a Task 5 a substitui):

```php
use App\Http\Controllers\PerfilController;

Route::middleware('auth')->group(function () {
    Route::get('/perfil', [PerfilController::class, 'show'])->name('perfil');
    Route::delete('/perfil', [PerfilController::class, 'destroy'])->name('perfil.destroy');
});
```

- [ ] **Step 8: CSS**

Recupere o CSS da versão anterior e acrescente os estilos de navegação, formulários e avisos:

```bash
mkdir -p public/css
git show main:public/assets/app.css > public/css/app.css
```

Acrescente ao final de `public/css/app.css`:

```css
/* ----------------------------------------------------------- navegação */
.nav { display: flex; flex-wrap: wrap; gap: 6px 14px; align-items: center; justify-content: space-between; margin-bottom: 22px; font-size: 14px; }
.nav .links { display: flex; flex-wrap: wrap; gap: 4px; padding: 4px; background: var(--off-soft); border-radius: 999px; }
.nav .links a { color: var(--muted); text-decoration: none; font-weight: 500; padding: 7px 14px; border-radius: 999px; }
.nav .links a:hover { color: var(--ink); }
.nav .links a[aria-current=page] { background: var(--surface); color: var(--ink); box-shadow: var(--shadow); }
.nav .conta { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; color: var(--muted); }
.nav form { display: inline; }
.linkbtn { background: none; border: 0; padding: 0; font: inherit; color: var(--accent); cursor: pointer; text-decoration: underline; text-underline-offset: 3px; }

/* --------------------------------------------------- formulários de conta */
.painel { max-width: 440px; margin: 24px auto; background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); box-shadow: var(--shadow); padding: 24px; display: grid; gap: 14px; }
.painel.largo { max-width: 640px; }
.painel h1, .painel h2 { font: 600 24px/1.2 var(--display); margin: 0; }
.painel h2 { font-size: 19px; }
.painel p { margin: 0; color: var(--muted); }
.painel form { display: grid; gap: 12px; }
.painel input[type=text], .painel input[type=email], .painel input[type=password] {
  font: 15px var(--body); color: var(--ink); background: var(--surface-2); border: 1px solid var(--line); border-radius: 8px; padding: 10px 12px; width: 100%; }
.painel .btn { padding: 10px 14px; font-size: 14px; text-align: center; text-decoration: none; }
.painel hr { border: 0; border-top: 1px dashed var(--line); margin: 4px 0; width: 100%; }
.erro { color: var(--urgent); font-size: 13px; text-transform: none; letter-spacing: 0; font-weight: 500; }
.flash { background: var(--accent-soft); color: var(--accent); border-radius: 10px; padding: 10px 14px; margin-bottom: 16px; font-size: 14px; }
.flash.aviso { background: var(--warn-soft); color: var(--warn); }
.flash.erro-geral { background: var(--urgent-soft); color: var(--urgent); }
.btn.google { display: flex; gap: 8px; justify-content: center; align-items: center; }

/* ------------------------------------------------------------ paginação */
.paginacao { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-top: 20px; font-size: 14px; color: var(--muted); }
.paginacao .btn { text-decoration: none; }
.paginacao .btn[aria-disabled=true] { opacity: .45; pointer-events: none; }

.chip:has(input:checked) { background: var(--accent-soft); border-color: var(--accent); color: var(--accent); }
.obj a { color: inherit; text-decoration: none; }
.obj a:hover { text-decoration: underline; text-underline-offset: 3px; }
.js .so-sem-js { display: none; }
```

- [ ] **Step 9: Layout**

`resources/views/layouts/app.blade.php`:

```blade
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', 'Radar de Dispensas PF')</title>
<meta name="description" content="@yield('description', 'Dispensas de licitação com propostas abertas no PNCP, filtradas pelo que uma pessoa física consegue executar sem CNPJ.')">
@stack('head')
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600&family=Public+Sans:wght@400;500;600&family=JetBrains+Mono:wght@500&display=swap">
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
<script>document.documentElement.classList.add('js');</script>
</head>
<body>
<div class="wrap">
  <nav class="nav" aria-label="Principal">
    <div class="links">
      <a href="{{ url('/') }}" @if (request()->is('/') || request()->is('dispensas/*')) aria-current="page" @endif>Radar</a>
      <a href="{{ url('/candidaturas') }}" @if (request()->is('candidaturas')) aria-current="page" @endif>Minhas candidaturas</a>
    </div>
    <div class="conta">
      @auth
        <a href="{{ route('perfil') }}">{{ auth()->user()->name }}</a>
        <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="linkbtn">Sair</button></form>
      @else
        <a href="{{ route('login') }}">Entrar</a>
        <a class="btn primary" href="{{ route('register') }}">Criar conta grátis</a>
      @endauth
    </div>
  </nav>

  @php
    $mensagens = [
        'verification-link-sent' => 'Enviamos um novo link de confirmação para o seu e-mail.',
        'profile-information-updated' => 'Dados atualizados.',
        'password-updated' => 'Senha alterada.',
    ];
  @endphp
  @if (session('status'))
    <div class="flash" role="status">{{ $mensagens[session('status')] ?? session('status') }}</div>
  @endif
  @auth
    @if (! auth()->user()->hasVerifiedEmail() && ! request()->routeIs('verification.notice'))
      <div class="flash aviso">Confirme o seu e-mail para salvar dispensas. <a href="{{ route('verification.notice') }}">Reenviar o link</a></div>
    @endif
  @endauth

  @yield('content')
</div>
@if (file_exists(public_path('js/app.js')))
<script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}" defer></script>
@endif
</body>
</html>
```

- [ ] **Step 10: Telas de conta**

`resources/views/auth/login.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Entrar · Radar de Dispensas PF')
@push('head')<meta name="robots" content="noindex">@endpush
@section('content')
<div class="painel">
  <h1>Entrar</h1>
  <form method="POST" action="{{ route('login') }}">
    @csrf
    <label class="f">E-mail
      <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
      @error('email')<span class="erro">{{ $message }}</span>@enderror
    </label>
    <label class="f">Senha
      <input type="password" name="password" required autocomplete="current-password">
      @error('password')<span class="erro">{{ $message }}</span>@enderror
    </label>
    <label class="toggle"><input type="checkbox" name="remember"> Manter conectado</label>
    <button type="submit" class="btn primary">Entrar</button>
  </form>
  <p><a href="{{ route('password.request') }}">Esqueci a senha</a> · <a href="{{ route('register') }}">Criar conta grátis</a></p>
</div>
@endsection
```

`resources/views/auth/register.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Criar conta · Radar de Dispensas PF')
@push('head')<meta name="robots" content="noindex">@endpush
@section('content')
<div class="painel">
  <h1>Criar conta grátis</h1>
  <p>Salve dispensas e acompanhe as suas candidaturas.</p>
  <form method="POST" action="{{ route('register') }}">
    @csrf
    <label class="f">Nome
      <input type="text" name="name" value="{{ old('name') }}" required autofocus autocomplete="name">
      @error('name')<span class="erro">{{ $message }}</span>@enderror
    </label>
    <label class="f">E-mail
      <input type="email" name="email" value="{{ old('email') }}" required autocomplete="username">
      @error('email')<span class="erro">{{ $message }}</span>@enderror
    </label>
    <label class="f">Senha
      <input type="password" name="password" required autocomplete="new-password">
      @error('password')<span class="erro">{{ $message }}</span>@enderror
    </label>
    <label class="f">Repita a senha
      <input type="password" name="password_confirmation" required autocomplete="new-password">
    </label>
    <button type="submit" class="btn primary">Criar conta</button>
  </form>
  <p>Já tem conta? <a href="{{ route('login') }}">Entrar</a></p>
</div>
@endsection
```

`resources/views/auth/forgot-password.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Recuperar senha · Radar de Dispensas PF')
@push('head')<meta name="robots" content="noindex">@endpush
@section('content')
<div class="painel">
  <h1>Recuperar senha</h1>
  <p>Informe o e-mail da conta e enviaremos um link para definir uma nova senha.</p>
  <form method="POST" action="{{ route('password.email') }}">
    @csrf
    <label class="f">E-mail
      <input type="email" name="email" value="{{ old('email') }}" required autofocus>
      @error('email')<span class="erro">{{ $message }}</span>@enderror
    </label>
    <button type="submit" class="btn primary">Enviar link</button>
  </form>
  <p><a href="{{ route('login') }}">Voltar para o login</a></p>
</div>
@endsection
```

`resources/views/auth/reset-password.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Nova senha · Radar de Dispensas PF')
@push('head')<meta name="robots" content="noindex">@endpush
@section('content')
<div class="painel">
  <h1>Definir nova senha</h1>
  <form method="POST" action="{{ route('password.update') }}">
    @csrf
    <input type="hidden" name="token" value="{{ $request->route('token') }}">
    <label class="f">E-mail
      <input type="email" name="email" value="{{ old('email', $request->email) }}" required>
      @error('email')<span class="erro">{{ $message }}</span>@enderror
    </label>
    <label class="f">Nova senha
      <input type="password" name="password" required autofocus autocomplete="new-password">
      @error('password')<span class="erro">{{ $message }}</span>@enderror
    </label>
    <label class="f">Repita a nova senha
      <input type="password" name="password_confirmation" required autocomplete="new-password">
    </label>
    <button type="submit" class="btn primary">Salvar senha</button>
  </form>
</div>
@endsection
```

`resources/views/auth/verify-email.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Confirme o seu e-mail · Radar de Dispensas PF')
@push('head')<meta name="robots" content="noindex">@endpush
@section('content')
<div class="painel">
  <h1>Confirme o seu e-mail</h1>
  <p>Enviamos um link de confirmação para <strong>{{ auth()->user()->email }}</strong>. Clique nele para poder salvar dispensas.</p>
  <form method="POST" action="{{ route('verification.send') }}">
    @csrf
    <button type="submit" class="btn primary">Reenviar o link</button>
  </form>
  <p><a href="{{ url('/') }}">Continuar navegando no radar</a></p>
</div>
@endsection
```

`resources/views/perfil.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Meu perfil · Radar de Dispensas PF')
@push('head')<meta name="robots" content="noindex">@endpush
@section('content')
<div class="painel largo">
  <h1>Meu perfil</h1>

  <h2>Dados</h2>
  <form method="POST" action="{{ route('user-profile-information.update') }}">
    @csrf @method('PUT')
    <label class="f">Nome
      <input type="text" name="name" value="{{ old('name', $user->name) }}" required>
      @error('name', 'updateProfileInformation')<span class="erro">{{ $message }}</span>@enderror
    </label>
    <label class="f">E-mail
      <input type="email" name="email" value="{{ old('email', $user->email) }}" required>
      @error('email', 'updateProfileInformation')<span class="erro">{{ $message }}</span>@enderror
    </label>
    <button type="submit" class="btn primary">Salvar dados</button>
  </form>

  <hr>
  <h2>Senha</h2>
  @if ($user->password)
    <form method="POST" action="{{ route('user-password.update') }}">
      @csrf @method('PUT')
      <label class="f">Senha atual
        <input type="password" name="current_password" required autocomplete="current-password">
        @error('current_password', 'updatePassword')<span class="erro">{{ $message }}</span>@enderror
      </label>
      <label class="f">Nova senha
        <input type="password" name="password" required autocomplete="new-password">
        @error('password', 'updatePassword')<span class="erro">{{ $message }}</span>@enderror
      </label>
      <label class="f">Repita a nova senha
        <input type="password" name="password_confirmation" required autocomplete="new-password">
      </label>
      <button type="submit" class="btn">Alterar senha</button>
    </form>
  @else
    <p>Esta conta entra pelo Google e não tem senha. Para criar uma, saia e use <a href="{{ route('password.request') }}">Esqueci a senha</a>.</p>
  @endif

  <hr>
  <h2>Excluir conta</h2>
  <p>Apaga a conta e todas as suas candidaturas. Não pode ser desfeito.</p>
  <form method="POST" action="{{ route('perfil.destroy') }}">
    @csrf @method('DELETE')
    <label class="f">Digite o seu e-mail para confirmar
      <input type="email" name="email_confirmacao" required autocomplete="off">
      @error('email_confirmacao')<span class="erro">{{ $message }}</span>@enderror
    </label>
    <button type="submit" class="btn danger">Excluir minha conta</button>
  </form>
</div>
@endsection
```

- [ ] **Step 11: Rodar e ver passar**

Run: `docker compose exec -u www-data app php artisan test --filter=ContasTest`
Expected: PASS, 12 testes.

- [ ] **Step 12: Conferir o e-mail de verdade no Mailpit**

Abra `http://localhost:8081/register`, crie uma conta e confira em `http://localhost:8025` que chegou o e-mail de confirmação em português. Clique no link e confira que o aviso "Confirme o seu e-mail" some.

- [ ] **Step 13: Commit**

```bash
git add -A
git commit -m "Adiciona contas com Fortify: cadastro, login, confirmação, senha e perfil

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Classificador PF

**Files:**
- Create: `app/Enums/Fit.php`, `app/Services/ClassificadorPf.php`
- Test: `tests/Unit/ClassificadorPfTest.php`

**Interfaces:**
- Produces: `App\Enums\Fit` (casos `Alta='alta'`, `Verificar='verificar'`, `Baixa='baixa'`, `Fora='fora'`; método `label(): string`).
- Produces: `App\Services\ClassificadorPf::CATEGORIAS` (`array<string, array{label: string, re: string}>`, chaves `ti`, `ensino`, `consultoria`, `conteudo`, `eventos`).
- Produces: `ClassificadorPf::classificar(array $registro): array`, que recebe um registro bruto do PNCP e devolve as chaves `pncp_id`, `objeto`, `complemento`, `orgao`, `unidade`, `municipio`, `uf`, `esfera`, `valor` (`?float`), `abre_em` (`?string`), `encerra_em` (`?string`), `amparo`, `disputa`, `link_pncp` (`?string`), `link_origem` (`?string`), `categorias` (`list<string>`), `fit` (`string`), `motivo` (`string`). São os nomes das colunas da tabela `dispensas`.

- [ ] **Step 1: Escrever os testes (falhando)**

`tests/Unit/ClassificadorPfTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\ClassificadorPf;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ClassificadorPfTest extends TestCase
{
    private function registro(array $extra = []): array
    {
        return array_replace_recursive([
            'numeroControlePNCP' => '12345678000199-1-000010/2026',
            'objetoCompra' => 'Contratação de serviço de limpeza de calhas',
            'informacaoComplementar' => '',
            'orgaoEntidade' => ['cnpj' => '12345678000199', 'razaoSocial' => 'MUNICIPIO DE CHAPECO', 'esferaId' => 'M'],
            'unidadeOrgao' => ['nomeUnidade' => 'Secretaria de Obras', 'municipioNome' => 'Chapecó', 'ufSigla' => 'SC'],
            'valorTotalEstimado' => 12500.5,
            'dataAberturaProposta' => '2026-10-10T08:00:00',
            'dataEncerramentoProposta' => '2026-10-20T08:00:00',
            'amparoLegal' => ['codigo' => 19, 'nome' => 'Lei 14.133/2021, Art. 75, II'],
            'modoDisputaNome' => 'Dispensa Com Disputa',
            'anoCompra' => 2026,
            'sequencialCompra' => 10,
            'linkSistemaOrigem' => 'https://compras.example.gov.br/10',
        ], $extra);
    }

    private function classificar(array $extra = []): array
    {
        return (new ClassificadorPf)->classificar($this->registro($extra));
    }

    public function test_normaliza_os_campos_do_registro(): void
    {
        $l = $this->classificar();

        $this->assertSame('12345678000199-1-000010/2026', $l['pncp_id']);
        $this->assertSame('Municipio De Chapeco', $l['orgao']);
        $this->assertSame('Secretaria de Obras', $l['unidade']);
        $this->assertSame('Chapecó', $l['municipio']);
        $this->assertSame('SC', $l['uf']);
        $this->assertSame('Municipal', $l['esfera']);
        $this->assertSame(12500.5, $l['valor']);
        $this->assertSame('2026-10-10T08:00:00', $l['abre_em']);
        $this->assertSame('2026-10-20T08:00:00', $l['encerra_em']);
        $this->assertSame('https://pncp.gov.br/app/editais/12345678000199/2026/10', $l['link_pncp']);
        $this->assertSame('https://compras.example.gov.br/10', $l['link_origem']);
        $this->assertSame('Dispensa Com Disputa', $l['disputa']);
    }

    public function test_engenharia_fica_fora(): void
    {
        $l = $this->classificar(['amparoLegal' => ['nome' => 'Lei 14.133/2021, Art. 75, I'], 'objetoCompra' => 'Desenvolvimento de software']);

        $this->assertSame('fora', $l['fit']);
        $this->assertStringContainsString('engenharia', $l['motivo']);
    }

    public function test_art_75_ii_e_iii_nao_sao_engenharia(): void
    {
        $this->assertNotSame('fora', $this->classificar(['amparoLegal' => ['nome' => 'Lei 14.133/2021, Art. 75, II']])['fit']);
        $this->assertNotSame('fora', $this->classificar(['amparoLegal' => ['nome' => 'Lei 14.133/2021, Art. 75, III']])['fit']);
    }

    public function test_compra_de_bens_fica_fora(): void
    {
        $l = $this->classificar(['objetoCompra' => 'Aquisição de material de expediente']);

        $this->assertSame('fora', $l['fit']);
        $this->assertStringContainsString('bens', $l['motivo']);
    }

    public function test_bem_com_servico_no_objeto_nao_e_tratado_como_bem(): void
    {
        $this->assertSame('baixa', $this->classificar(['objetoCompra' => 'Aquisição e prestação de serviço de instalação de toldos'])['fit']);
    }

    public function test_mencao_a_pessoa_fisica_da_fit_alto(): void
    {
        $l = $this->classificar(['informacaoComplementar' => 'Admite-se a contratação de pessoa física.']);

        $this->assertSame('alta', $l['fit']);
        $this->assertStringContainsString('pessoa física', $l['motivo']);
    }

    public function test_categoria_sem_empresa_da_fit_alto(): void
    {
        $l = $this->classificar(['objetoCompra' => 'Palestra sobre gestão pública']);

        $this->assertSame('alta', $l['fit']);
        $this->assertSame(['ensino'], $l['categorias']);
    }

    public function test_categoria_com_empresa_pede_verificacao(): void
    {
        $l = $this->classificar(['objetoCompra' => 'Contratação de empresa para desenvolvimento de software']);

        $this->assertSame('verificar', $l['fit']);
        $this->assertContains('ti', $l['categorias']);
    }

    public function test_servico_sem_categoria_tem_aderencia_baixa(): void
    {
        $l = $this->classificar();

        $this->assertSame('baixa', $l['fit']);
        $this->assertSame([], $l['categorias']);
    }

    #[DataProvider('categorias')]
    public function test_reconhece_cada_categoria(string $objeto, string $categoria): void
    {
        $this->assertContains($categoria, $this->classificar(['objetoCompra' => $objeto])['categorias']);
    }

    public static function categorias(): array
    {
        return [
            'ti' => ['Hospedagem de site institucional', 'ti'],
            'ensino' => ['Curso de capacitação de servidores', 'ensino'],
            'consultoria' => ['Consultoria em planejamento estratégico', 'consultoria'],
            'conteudo' => ['Serviço de tradução e intérprete de Libras', 'conteudo'],
            'eventos' => ['Apresentação artística de banda local', 'eventos'],
        ];
    }

    public function test_remove_o_prefixo_do_portal_e_espacos_repetidos(): void
    {
        $l = $this->classificar(['objetoCompra' => "[Portal de Compras Públicas] -  Serviço   de\nlimpeza"]);

        $this->assertSame('Serviço de limpeza', $l['objeto']);
    }

    public function test_corta_o_complemento_em_400_caracteres(): void
    {
        $l = $this->classificar(['informacaoComplementar' => str_repeat('a', 900)]);

        $this->assertSame(400, mb_strwidth($l['complemento']));
        $this->assertStringEndsWith('…', $l['complemento']);
    }

    public function test_valor_zero_ou_ausente_vira_nulo(): void
    {
        $this->assertNull($this->classificar(['valorTotalEstimado' => 0])['valor']);
        $this->assertNull($this->classificar(['valorTotalEstimado' => null])['valor']);
    }

    public function test_registro_com_campos_nulos_ou_ausentes_nao_quebra(): void
    {
        $l = (new ClassificadorPf)->classificar([
            'numeroControlePNCP' => 'x-1',
            'objetoCompra' => null,
            'informacaoComplementar' => null,
            'valorTotalEstimado' => null,
            'dataEncerramentoProposta' => null,
        ]);

        $this->assertSame('x-1', $l['pncp_id']);
        $this->assertSame('', $l['objeto']);
        $this->assertSame('', $l['orgao']);
        $this->assertSame('', $l['uf']);
        $this->assertSame('Não informada', $l['esfera']);
        $this->assertNull($l['valor']);
        $this->assertNull($l['encerra_em']);
        $this->assertNull($l['link_pncp']);
        $this->assertNull($l['link_origem']);
        $this->assertSame('baixa', $l['fit']);
    }

    public function test_sem_numero_de_controle_usa_cnpj_e_sequencial(): void
    {
        $l = $this->classificar(['numeroControlePNCP' => null]);

        $this->assertSame('12345678000199-10', $l['pncp_id']);
    }

    public function test_sem_numero_de_controle_nem_cnpj_devolve_id_vazio(): void
    {
        $l = (new ClassificadorPf)->classificar(['objetoCompra' => 'Serviço']);

        $this->assertSame('', $l['pncp_id']);
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `docker compose exec -u www-data app php artisan test --filter=ClassificadorPfTest`
Expected: FAIL com `Class "App\Services\ClassificadorPf" not found`.

- [ ] **Step 3: Implementar**

`app/Enums/Fit.php`:

```php
<?php

namespace App\Enums;

enum Fit: string
{
    case Alta = 'alta';
    case Verificar = 'verificar';
    case Baixa = 'baixa';
    case Fora = 'fora';

    public function label(): string
    {
        return match ($this) {
            self::Alta => 'Fit PF',
            self::Verificar => 'Verificar',
            self::Baixa => 'Baixa',
            self::Fora => 'Fora do perfil',
        };
    }
}
```

`app/Services/ClassificadorPf.php`:

```php
<?php

namespace App\Services;

use App\Enums\Fit;

/** Classifica cada dispensa pelo que uma pessoa física consegue executar. */
class ClassificadorPf
{
    public const CATEGORIAS = [
        'ti' => ['label' => 'TI e software', 're' => '/\b(software|sistemas?|desenvolviment\w*|aplicativo|app\b|site|website|portal|p[aá]gina web|api\b|banco de dados|hospedagem|programa[cç][aã]o|inform[aá]tica|tecnologia da informa[cç][aã]o|\bti\b|suporte t[eé]cnico|manuten[cç][aã]o de computador|rede l[oó]gica|cabeamento|licen[cç]a|ciberseguran[cç]a|automa[cç][aã]o de processos|chatbot|intelig[eê]ncia artificial)/iu'],
        'ensino' => ['label' => 'Cursos e palestras', 're' => '/\b(treinamento|curso|capacita[cç][aã]o|palestra|oficina|workshop|instrutor|ministr\w+|forma[cç][aã]o continuada|aula|mentoria|semin[aá]rio)/iu'],
        'consultoria' => ['label' => 'Consultoria', 're' => '/\b(consultoria|assessoria|diagn[oó]stico|parecer|mapeamento de processos|plano diretor|planejamento estrat[eé]gico|estudo t[eé]cnico|levantamento)/iu'],
        'conteudo' => ['label' => 'Conteúdo e design', 're' => '/\b(design|identidade visual|fotograf\w*|v[ií]deo|filmagem|edi[cç][aã]o|reda[cç][aã]o|revis[aã]o de texto|tradu[cç][aã]o|int[eé]rprete|libras|comunica[cç][aã]o|m[ií]dias? socia\w*|arte gr[aá]fica|diagrama[cç][aã]o|ilustra[cç][aã]o|transcri[cç][aã]o)/iu'],
        'eventos' => ['label' => 'Eventos e arte', 're' => '/\b(show|apresenta[cç][aã]o art[ií]stica|m[uú]sic\w+|banda|cantor|anima[cç][aã]o|recrea[cç][aã]o|mestre de cerim[oô]nias|cerimonial|artista|teatro|dan[cç]a)/iu'],
    ];

    private const RE_BENS = '/\b(aquisi[cç][aã]o|compra de|fornecimento de (materia|equipamento|produto|ite|gênero|genero)|materia(l|is) de (consumo|expediente|limpeza)|g[eê]neros aliment\w+|medicamento|pe[cç]as)/iu';
    private const RE_SERVICO = '/\b(servi[cç]o|presta[cç][aã]o|contrata[cç][aã]o de (empresa|profissional|pessoa)|execu[cç][aã]o de)/iu';
    private const RE_EMPRESA = '/\bempresa\b/iu';
    private const RE_PF_EXPLICITO = '/\b(pessoa f[ií]sica|profissional aut[oô]nomo|profissional liberal)/iu';

    private const ESFERAS = ['F' => 'Federal', 'E' => 'Estadual', 'M' => 'Municipal', 'D' => 'Distrital', 'N' => 'Não informada'];

    /** Recebe um registro bruto do PNCP e devolve uma linha da tabela dispensas. */
    public function classificar(array $c): array
    {
        $objeto = trim((string) preg_replace('/\s+/u', ' ', (string) ($c['objetoCompra'] ?? '')));
        $objeto = (string) preg_replace('/^\[[^\]]+\]\s*-\s*/u', '', $objeto);   // remove prefixo "[Portal de Compras Públicas] -"
        $complemento = (string) ($c['informacaoComplementar'] ?? '');
        $texto = $objeto . ' ' . $complemento;
        $amparo = (string) ($c['amparoLegal']['nome'] ?? '');

        $categorias = [];
        foreach (self::CATEGORIAS as $chave => $cat) {
            if (preg_match($cat['re'], $texto)) {
                $categorias[] = $chave;
            }
        }

        $ehEngenharia = str_contains($amparo, 'Art. 75, I') && ! str_contains($amparo, 'Art. 75, II') && ! str_contains($amparo, 'Art. 75, III');
        $ehBem = preg_match(self::RE_BENS, $objeto) && ! preg_match(self::RE_SERVICO, $objeto) && ! $categorias;
        $citaEmpresa = (bool) preg_match(self::RE_EMPRESA, $objeto);
        $aceitaPf = (bool) preg_match(self::RE_PF_EXPLICITO, $texto);

        [$fit, $motivo] = match (true) {
            $ehEngenharia => [Fit::Fora, 'Obra ou serviço de engenharia: exige responsável técnico com registro no CREA/CAU.'],
            $ehBem => [Fit::Fora, 'Compra de bens: depende de emissão de nota de produto e estoque.'],
            $aceitaPf => [Fit::Alta, 'O texto menciona pessoa física ou profissional autônomo.'],
            $categorias && ! $citaEmpresa => [Fit::Alta, 'Serviço intelectual sem exigência de empresa no objeto.'],
            (bool) $categorias => [Fit::Verificar, 'O objeto cita "empresa". Confira no aviso se a habilitação aceita pessoa física.'],
            default => [Fit::Baixa, 'Serviço fora das categorias típicas de PF. Avalie pelo termo de referência.'],
        };

        $cnpj = (string) ($c['orgaoEntidade']['cnpj'] ?? '');
        $sequencial = (string) ($c['sequencialCompra'] ?? '');
        $valor = (float) ($c['valorTotalEstimado'] ?? 0);

        return [
            'pncp_id' => (string) ($c['numeroControlePNCP'] ?? ($cnpj !== '' ? "$cnpj-$sequencial" : '')),
            'objeto' => $objeto,
            'complemento' => mb_strimwidth(trim($complemento), 0, 400, '…'),
            'orgao' => mb_convert_case(mb_strtolower((string) ($c['orgaoEntidade']['razaoSocial'] ?? '')), MB_CASE_TITLE),
            'unidade' => (string) ($c['unidadeOrgao']['nomeUnidade'] ?? ''),
            'municipio' => (string) ($c['unidadeOrgao']['municipioNome'] ?? ''),
            'uf' => mb_substr((string) ($c['unidadeOrgao']['ufSigla'] ?? ''), 0, 2),
            'esfera' => self::ESFERAS[$c['orgaoEntidade']['esferaId'] ?? 'N'] ?? 'Não informada',
            'valor' => $valor > 0 ? $valor : null,
            'abre_em' => ($c['dataAberturaProposta'] ?? null) ?: null,
            'encerra_em' => ($c['dataEncerramentoProposta'] ?? null) ?: null,
            'amparo' => $amparo,
            'disputa' => (string) ($c['modoDisputaNome'] ?? ''),
            'link_pncp' => $cnpj !== '' ? "https://pncp.gov.br/app/editais/$cnpj/" . ($c['anoCompra'] ?? '') . "/$sequencial" : null,
            'link_origem' => ($c['linkSistemaOrigem'] ?? '') ?: null,
            'categorias' => $categorias,
            'fit' => $fit->value,
            'motivo' => $motivo,
        ];
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `docker compose exec -u www-data app php artisan test --filter=ClassificadorPfTest`
Expected: PASS, 20 testes (16 métodos, um deles com 5 casos).

- [ ] **Step 5: Commit**

```bash
git add app/Enums/Fit.php app/Services/ClassificadorPf.php tests/Unit/ClassificadorPfTest.php
git commit -m "Porta a classificação PF para o serviço ClassificadorPf

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Tabela de dispensas e sincronização com o PNCP

**Files:**
- Create: `database/migrations/2026_10_12_000001_habilitar_unaccent.php`, `database/migrations/2026_10_12_000002_create_dispensas_table.php`
- Create: `app/Models/Dispensa.php`, `database/factories/DispensaFactory.php`
- Create: `app/Services/Pncp/PncpClient.php`, `app/Console/Commands/SincronizarPncp.php`
- Modify: `config/services.php`, `routes/console.php`
- Test: `tests/Feature/SincronizarPncpTest.php`

**Interfaces:**
- Consumes: `ClassificadorPf::classificar(array): array` e `App\Enums\Fit` (Task 3).
- Produces: `App\Models\Dispensa` com os atributos da tabela; casts `categorias` (array), `fit` (`Fit`), `valor` (float), `abre_em`, `encerra_em`, `sincronizado_em` (datetime); escopo `abertas()`; métodos `encerrada(): bool`, `prazo(): array{txt: string, cls: string}`, `valorTexto(): string`, `urlPncp(): ?string`, `urlOrigem(): ?string`.
- Produces: `Dispensa::factory()`, que por padrão cria uma dispensa aberta, sincronizada agora, `uf` `SC`, `fit` `alta`, `categorias` `['ti']`, `valor` 10000.
- Produces: `App\Services\Pncp\PncpClient::paginas(int $dias = 90): \Generator` (cada item é a lista de registros de uma página) e a propriedade pública `bool $truncado`.
- Produces: comando `pncp:sync {--dias=90}` e a constante `SincronizarPncp::CHAVE_ULTIMA` (chave de cache com a data ISO 8601 da última sincronização bem-sucedida).

- [ ] **Step 1: Migrations**

`database/migrations/2026_10_12_000001_habilitar_unaccent.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
    }

    public function down(): void
    {
        DB::statement('DROP EXTENSION IF EXISTS unaccent');
    }
};
```

`database/migrations/2026_10_12_000002_create_dispensas_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispensas', function (Blueprint $table) {
            $table->id();
            $table->text('pncp_id')->unique();
            $table->text('objeto');
            $table->text('complemento')->default('');
            $table->text('orgao')->default('');
            $table->text('unidade')->default('');
            $table->text('municipio')->default('');
            $table->string('uf', 2)->default('')->index();
            $table->text('esfera')->default('');
            $table->decimal('valor', 15, 2)->nullable();
            $table->timestamp('abre_em')->nullable();
            $table->timestamp('encerra_em')->nullable()->index();
            $table->text('amparo')->default('');
            $table->text('disputa')->default('');
            $table->text('link_pncp')->nullable();
            $table->text('link_origem')->nullable();
            $table->jsonb('categorias');
            $table->text('fit')->index();
            $table->text('motivo')->default('');
            $table->timestamp('sincronizado_em');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispensas');
    }
};
```

- [ ] **Step 2: Modelo e factory**

`app/Models/Dispensa.php`:

```php
<?php

namespace App\Models;

use App\Enums\Fit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Dispensa extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'categorias' => 'array',
            'fit' => Fit::class,
            'valor' => 'float',
            'abre_em' => 'datetime',
            'encerra_em' => 'datetime',
            'sincronizado_em' => 'datetime',
        ];
    }

    /** Com prazo em aberto e devolvidas pelo PNCP nas últimas 24 horas. */
    public function scopeAbertas(Builder $query): void
    {
        $query
            ->where(fn (Builder $q) => $q->whereNull('encerra_em')->orWhere('encerra_em', '>', now()))
            ->where('sincronizado_em', '>=', now()->subDay());
    }

    public function encerrada(): bool
    {
        return $this->encerra_em !== null && $this->encerra_em->isPast();
    }

    /** @return array{txt: string, cls: string} */
    public function prazo(): array
    {
        if ($this->encerra_em === null) {
            return ['txt' => 'sem prazo', 'cls' => ''];
        }
        $data = $this->encerra_em->format('d/m H:i');
        $horas = now()->diffInHours($this->encerra_em, false);

        return match (true) {
            $horas <= 0 => ['txt' => "encerrou $data", 'cls' => 'encerrada'],
            $horas < 48 => ['txt' => "encerra $data (" . (int) round($horas) . 'h)', 'cls' => 'urgente'],
            default => ['txt' => "encerra $data (" . (int) round($horas / 24) . ' dias)', 'cls' => ''],
        };
    }

    public function valorTexto(): string
    {
        return $this->valor ? 'R$ ' . number_format($this->valor, 2, ',', '.') : 'valor sigiloso';
    }

    public function urlPncp(): ?string
    {
        return self::urlSegura($this->link_pncp);
    }

    public function urlOrigem(): ?string
    {
        return self::urlSegura($this->link_origem);
    }

    /** Links vêm do PNCP: só são exibidos se forem http ou https. */
    private static function urlSegura(?string $url): ?string
    {
        return $url !== null && preg_match('#^https?://#i', $url) ? $url : null;
    }
}
```

`database/factories/DispensaFactory.php`:

```php
<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Dispensa>
 */
class DispensaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'pncp_id' => fake()->unique()->numerify('##############-1-######/2026'),
            'objeto' => 'Serviço de ' . fake()->unique()->words(3, true),
            'complemento' => '',
            'orgao' => 'Prefeitura De Teste',
            'unidade' => 'Secretaria de Administração',
            'municipio' => 'Chapecó',
            'uf' => 'SC',
            'esfera' => 'Municipal',
            'valor' => 10000,
            'abre_em' => now()->subDay(),
            'encerra_em' => now()->addDays(5),
            'amparo' => 'Lei 14.133/2021, Art. 75, II',
            'disputa' => 'Dispensa Com Disputa',
            'link_pncp' => 'https://pncp.gov.br/app/editais/12345678000199/2026/1',
            'link_origem' => 'https://compras.example.gov.br/1',
            'categorias' => ['ti'],
            'fit' => 'alta',
            'motivo' => 'Serviço intelectual sem exigência de empresa no objeto.',
            'sincronizado_em' => now(),
        ];
    }

    public function encerrada(): static
    {
        return $this->state(['encerra_em' => now()->subDay()]);
    }
}
```

- [ ] **Step 3: Escrever os testes da sincronização (falhando)**

`tests/Feature/SincronizarPncpTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Console\Commands\SincronizarPncp;
use App\Models\Dispensa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SincronizarPncpTest extends TestCase
{
    use RefreshDatabase;

    private function registro(string $id, string $objeto = 'Palestra sobre gestão'): array
    {
        return [
            'numeroControlePNCP' => $id,
            'objetoCompra' => $objeto,
            'orgaoEntidade' => ['cnpj' => '12345678000199', 'razaoSocial' => 'MUNICIPIO X', 'esferaId' => 'M'],
            'unidadeOrgao' => ['municipioNome' => 'Chapecó', 'ufSigla' => 'SC'],
            'valorTotalEstimado' => 5000,
            'dataEncerramentoProposta' => now()->addDays(3)->format('Y-m-d\TH:i:s'),
            'amparoLegal' => ['nome' => 'Lei 14.133/2021, Art. 75, II'],
            'anoCompra' => 2026,
            'sequencialCompra' => 1,
        ];
    }

    /** Simula o PNCP: $paginas[n] é a resposta da página n (array de registros, int de status ou string de corpo cru). */
    private function simularPncp(array $paginas): void
    {
        Http::fake(function (Request $request) use ($paginas) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $resposta = $paginas[(int) ($query['pagina'] ?? 1)] ?? 204;

            return match (true) {
                is_int($resposta) => Http::response('', $resposta),
                is_string($resposta) => Http::response($resposta, 200),
                default => Http::response(['data' => $resposta, 'totalPaginas' => count($paginas), 'totalRegistros' => 0], 200),
            };
        });
    }

    public function test_grava_os_registros_de_todas_as_paginas_ja_classificados(): void
    {
        $this->simularPncp([1 => [$this->registro('a-1')], 2 => [$this->registro('a-2')], 3 => [$this->registro('a-3')]]);

        $this->artisan('pncp:sync')->assertSuccessful();

        $this->assertSame(3, Dispensa::count());
        $d = Dispensa::where('pncp_id', 'a-1')->firstOrFail();
        $this->assertSame('alta', $d->fit->value);
        $this->assertSame(['ensino'], $d->categorias);
        $this->assertSame('SC', $d->uf);
        $this->assertNotNull($d->sincronizado_em);
    }

    public function test_consulta_dispensas_do_brasil_todo_com_janela_de_90_dias(): void
    {
        $this->simularPncp([1 => [$this->registro('a-1')]]);

        $this->artisan('pncp:sync')->assertSuccessful();

        Http::assertSent(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return str_contains($request->url(), '/contratacoes/proposta')
                && $q['codigoModalidadeContratacao'] === '8'
                && $q['tamanhoPagina'] === '50'
                && $q['dataFinal'] === now()->addDays(90)->format('Ymd')
                && ! isset($q['uf']);
        });
    }

    public function test_atualiza_o_registro_existente_em_vez_de_duplicar(): void
    {
        Dispensa::factory()->create(['pncp_id' => 'a-1', 'objeto' => 'Texto antigo', 'sincronizado_em' => now()->subDays(3)]);
        $this->simularPncp([1 => [$this->registro('a-1', 'Curso de capacitação')]]);

        $this->artisan('pncp:sync')->assertSuccessful();

        $this->assertSame(1, Dispensa::count());
        $d = Dispensa::firstOrFail();
        $this->assertSame('Curso de capacitação', $d->objeto);
        $this->assertTrue($d->sincronizado_em->isToday());
    }

    public function test_mesmo_id_repetido_na_pagina_grava_uma_linha_so(): void
    {
        $this->simularPncp([1 => [$this->registro('a-1', 'Primeiro'), $this->registro('a-1', 'Segundo')]]);

        $this->artisan('pncp:sync')->assertSuccessful();

        $this->assertSame(1, Dispensa::count());
        $this->assertSame('Segundo', Dispensa::firstOrFail()->objeto);
    }

    public function test_ignora_registro_sem_identificador(): void
    {
        $this->simularPncp([1 => [['objetoCompra' => 'Sem id'], $this->registro('a-1')]]);

        $this->artisan('pncp:sync')->assertSuccessful();

        $this->assertSame(1, Dispensa::count());
    }

    public function test_resposta_204_significa_nenhuma_dispensa(): void
    {
        $this->simularPncp([1 => 204]);

        $this->artisan('pncp:sync')->assertSuccessful();

        $this->assertSame(0, Dispensa::count());
        $this->assertNotNull(Cache::get(SincronizarPncp::CHAVE_ULTIMA));
    }

    public function test_falha_no_meio_preserva_o_que_ja_foi_gravado_e_termina_com_erro(): void
    {
        $this->simularPncp([1 => [$this->registro('a-1')], 2 => 500, 3 => [$this->registro('a-3')]]);

        $this->artisan('pncp:sync')->assertFailed();

        $this->assertDatabaseHas('dispensas', ['pncp_id' => 'a-1']);
        $this->assertNull(Cache::get(SincronizarPncp::CHAVE_ULTIMA));
    }

    public function test_corpo_que_nao_e_json_termina_com_erro(): void
    {
        $this->simularPncp([1 => '<html>manutenção</html>']);

        $this->artisan('pncp:sync')->assertFailed();
    }

    public function test_sucesso_registra_a_data_da_ultima_sincronizacao(): void
    {
        $this->simularPncp([1 => [$this->registro('a-1')]]);

        $this->artisan('pncp:sync')->assertSuccessful();

        $this->assertTrue(now()->parse(Cache::get(SincronizarPncp::CHAVE_ULTIMA))->isToday());
    }
}
```

- [ ] **Step 4: Rodar e ver falhar**

Run: `docker compose exec -u www-data app php artisan test --filter=SincronizarPncpTest`
Expected: FAIL com `Class "App\Console\Commands\SincronizarPncp" not found`.

- [ ] **Step 5: Cliente do PNCP**

Em `config/services.php`, acrescente ao array:

```php
    'pncp' => [
        'base' => rtrim(env('PNCP_BASE', 'https://pncp.gov.br/api/consulta/v1'), '/'),
    ],
```

`app/Services/Pncp/PncpClient.php`:

```php
<?php

namespace App\Services\Pncp;

use Generator;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/** Cliente da API de consulta do PNCP: dispensas com propostas abertas. */
class PncpClient
{
    public const POR_PAGINA = 50;
    public const TETO_PAGINAS = 400;      // teto de segurança: 20.000 registros
    private const PARALELAS = 6;          // conexões simultâneas, para não estressar a API
    private const MODALIDADE_DISPENSA = 8;

    /** Verdadeiro quando a última consulta tinha mais páginas que o teto. */
    public bool $truncado = false;

    /**
     * Percorre as páginas de dispensas do Brasil todo que encerram em até $dias dias.
     *
     * @return Generator<int, list<array>> a lista de registros brutos de cada página
     */
    public function paginas(int $dias = 90): Generator
    {
        $this->truncado = false;
        $url = config('services.pncp.base') . '/contratacoes/proposta';
        $query = fn (int $pagina) => [
            'dataFinal' => now()->addDays($dias)->format('Ymd'),
            'codigoModalidadeContratacao' => self::MODALIDADE_DISPENSA,
            'pagina' => $pagina,
            'tamanhoPagina' => self::POR_PAGINA,
        ];

        $primeira = $this->decodificar($this->tentar(fn () => $this->requisicao()->get($url, $query(1))));
        yield $primeira['data'] ?? [];

        $total = (int) ($primeira['totalPaginas'] ?? 1);
        $this->truncado = $total > self::TETO_PAGINAS;
        $ultima = min($total, self::TETO_PAGINAS);
        if ($ultima < 2) {
            return;
        }

        foreach (array_chunk(range(2, $ultima), self::PARALELAS) as $lote) {
            $respostas = Http::pool(fn (Pool $pool) => array_map(
                fn (int $pagina) => $pool->acceptJson()->withUserAgent('radar-pf/1.0')->timeout(30)->connectTimeout(10)->get($url, $query($pagina)),
                $lote
            ));
            foreach ($respostas as $resposta) {
                yield $this->decodificar($resposta)['data'] ?? [];
            }
        }
    }

    private function requisicao()
    {
        return Http::acceptJson()->withUserAgent('radar-pf/1.0')->timeout(30)->connectTimeout(10);
    }

    private function tentar(callable $fn): Response|Throwable
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            return $e;
        }
    }

    /** No pool, falha de conexão chega como exceção no lugar da resposta. */
    private function decodificar(Response|Throwable $resposta): array
    {
        if ($resposta instanceof Throwable) {
            throw new RuntimeException('Falha ao consultar o PNCP: ' . $resposta->getMessage(), 0, $resposta);
        }
        if ($resposta->status() === 204 || $resposta->body() === '') {
            return ['data' => []];
        }
        if ($resposta->status() !== 200) {
            throw new RuntimeException('PNCP respondeu HTTP ' . $resposta->status() . '.');
        }
        $json = $resposta->json();
        if (! is_array($json)) {
            throw new RuntimeException('Resposta inválida do PNCP.');
        }

        return $json;
    }
}
```

- [ ] **Step 6: Comando e agendamento**

`app/Console/Commands/SincronizarPncp.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\Dispensa;
use App\Services\ClassificadorPf;
use App\Services\Pncp\PncpClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SincronizarPncp extends Command
{
    public const CHAVE_ULTIMA = 'pncp.ultima_sincronizacao';

    protected $signature = 'pncp:sync {--dias=90 : Janela de encerramento, em dias}';

    protected $description = 'Busca no PNCP as dispensas com propostas abertas, classifica e grava no banco';

    public function handle(PncpClient $pncp, ClassificadorPf $classificador): int
    {
        $agora = now();
        $total = 0;

        try {
            foreach ($pncp->paginas((int) $this->option('dias')) as $registros) {
                $linhas = [];
                foreach ($registros as $registro) {
                    $linha = $classificador->classificar($registro);
                    if ($linha['pncp_id'] === '') {
                        continue;
                    }
                    $linha['categorias'] = json_encode($linha['categorias']);
                    $linha['sincronizado_em'] = $agora;
                    $linhas[$linha['pncp_id']] = $linha;   // a chave evita o mesmo id duas vezes no mesmo upsert
                }
                if ($linhas) {
                    $colunas = array_diff(array_keys(reset($linhas)), ['pncp_id']);
                    Dispensa::upsert(array_values($linhas), ['pncp_id'], array_values($colunas));
                    $total += count($linhas);
                }
            }
        } catch (Throwable $e) {
            Log::error('pncp:sync falhou', ['erro' => $e->getMessage(), 'gravadas_antes_da_falha' => $total]);
            $this->error('Sincronização interrompida: ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($pncp->truncado) {
            Log::warning('pncp:sync atingiu o teto de páginas; há dispensas não sincronizadas', ['teto' => PncpClient::TETO_PAGINAS]);
        }

        Cache::forever(self::CHAVE_ULTIMA, $agora->toIso8601String());
        $this->info("$total dispensas sincronizadas.");

        return self::SUCCESS;
    }
}
```

Em `routes/console.php`, acrescente:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('pncp:sync')->everyFifteenMinutes()->withoutOverlapping();
```

- [ ] **Step 7: Rodar e ver passar**

Run: `docker compose exec -u www-data app php artisan test --filter=SincronizarPncpTest`
Expected: PASS, 9 testes.

- [ ] **Step 8: Sincronizar de verdade**

```bash
docker compose exec -u www-data app php artisan migrate
docker compose exec -u www-data app php artisan pncp:sync
docker compose exec -T db psql -U radar -d radar -c "select fit, count(*) from dispensas group by fit order by 2 desc"
docker compose restart scheduler
docker compose logs --tail 5 scheduler
```

Expected: o comando informa alguns milhares de dispensas sincronizadas (em 2026-10-12 eram 3.411), a consulta mostra as quatro aderências e o log do `scheduler` mostra uma sincronização concluída, sem erro de comando inexistente.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "Sincroniza as dispensas do PNCP para o banco a cada 15 minutos

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Radar público e página da dispensa

**Files:**
- Create: `app/Support/FiltroRadar.php`, `app/Http/Controllers/RadarController.php`, `app/Http/Controllers/DispensaController.php`
- Create: `resources/views/radar.blade.php`, `resources/views/dispensas/show.blade.php`, `resources/views/partials/ficha.blade.php`, `resources/views/partials/paginacao.blade.php`
- Create: `public/js/app.js`
- Modify: `routes/web.php` (substitui a rota `/` de boas-vindas)
- Delete: `resources/views/welcome.blade.php`
- Test: `tests/Feature/RadarTest.php`, `tests/Feature/DispensaPaginaTest.php`

**Interfaces:**
- Consumes: `Dispensa`, `Dispensa::factory()`, `SincronizarPncp::CHAVE_ULTIMA` (Task 4); `ClassificadorPf::CATEGORIAS`, `Fit` (Task 3); layout `layouts.app` (Task 2).
- Produces: rotas nomeadas `radar` (`GET /`) e `dispensas.show` (`GET /dispensas/{dispensa}`).
- Produces: `App\Support\FiltroRadar` com `UFS`, `DIAS`, propriedades públicas `?string $uf`, `int $dias`, `?float $vmax`, `string $q`, `array $cats`, `array $fits`, e os métodos `deRequest(Request): self`, `recorte(): Builder`, `aplicar(): Builder`.
- Produces: partial `partials.ficha`, que recebe `$d` (`Dispensa`).

- [ ] **Step 1: Escrever os testes do radar (falhando)**

`tests/Feature/RadarTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Console\Commands\SincronizarPncp;
use App\Models\Dispensa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RadarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forever(SincronizarPncp::CHAVE_ULTIMA, now()->toIso8601String());
    }

    private function dispensa(string $objeto, array $extra = []): Dispensa
    {
        return Dispensa::factory()->create(['objeto' => $objeto] + $extra);
    }

    public function test_visitante_ve_as_dispensas_abertas(): void
    {
        $this->dispensa('Palestra aberta');

        $this->get('/')->assertOk()->assertSee('Palestra aberta')->assertSee('Radar de Dispensas PF');
    }

    public function test_esconde_as_encerradas_e_as_que_o_pncp_parou_de_devolver(): void
    {
        $this->dispensa('Dispensa encerrada', ['encerra_em' => now()->subHour()]);
        $this->dispensa('Dispensa retirada', ['sincronizado_em' => now()->subDays(2)]);
        $this->dispensa('Dispensa sem prazo', ['encerra_em' => null]);

        $this->get('/')
            ->assertDontSee('Dispensa encerrada')
            ->assertDontSee('Dispensa retirada')
            ->assertSee('Dispensa sem prazo');
    }

    public function test_filtra_por_uf(): void
    {
        $this->dispensa('Curso em SC', ['uf' => 'SC']);
        $this->dispensa('Curso no PR', ['uf' => 'PR']);

        $this->get('/?uf=PR')->assertSee('Curso no PR')->assertDontSee('Curso em SC');
        $this->get('/?uf=pr')->assertSee('Curso no PR')->assertDontSee('Curso em SC');
    }

    public function test_filtra_por_prazo_com_padrao_de_30_dias(): void
    {
        $this->dispensa('Encerra em 5 dias', ['encerra_em' => now()->addDays(5)]);
        $this->dispensa('Encerra em 20 dias', ['encerra_em' => now()->addDays(20)]);
        $this->dispensa('Encerra em 45 dias', ['encerra_em' => now()->addDays(45)]);

        $this->get('/')->assertSee('Encerra em 20 dias')->assertDontSee('Encerra em 45 dias');
        $this->get('/?dias=7')->assertSee('Encerra em 5 dias')->assertDontSee('Encerra em 20 dias');
        $this->get('/?dias=60')->assertSee('Encerra em 45 dias');
    }

    public function test_filtra_por_valor_maximo_e_mantem_as_de_valor_sigiloso(): void
    {
        $this->dispensa('Servico barato', ['valor' => 5000]);
        $this->dispensa('Servico caro', ['valor' => 50000]);
        $this->dispensa('Servico sigiloso', ['valor' => null]);

        $this->get('/?vmax=10000')
            ->assertSee('Servico barato')
            ->assertSee('Servico sigiloso')
            ->assertDontSee('Servico caro');
    }

    public function test_filtra_por_categoria(): void
    {
        $this->dispensa('Item de TI', ['categorias' => ['ti']]);
        $this->dispensa('Item de ensino', ['categorias' => ['ensino']]);
        $this->dispensa('Item de eventos', ['categorias' => ['eventos', 'conteudo']]);

        $this->get('/?cat[]=ti&cat[]=conteudo')
            ->assertSee('Item de TI')
            ->assertSee('Item de eventos')
            ->assertDontSee('Item de ensino');
    }

    public function test_busca_ignora_acento_e_maiusculas_em_objeto_orgao_e_municipio(): void
    {
        $this->dispensa('Capacitação de servidores');
        $this->dispensa('Outro objeto', ['municipio' => 'São José']);
        $this->dispensa('Terceiro objeto', ['orgao' => 'Câmara Municipal']);
        $this->dispensa('Nada a ver', ['municipio' => 'Lages']);

        $this->get('/?q=capacitacao')->assertSee('Capacitação de servidores')->assertDontSee('Nada a ver');
        $this->get('/?q=SAO+JOSE')->assertSee('Outro objeto')->assertDontSee('Nada a ver');
        $this->get('/?q=camara')->assertSee('Terceiro objeto')->assertDontSee('Nada a ver');
    }

    public function test_mostra_alta_e_verificar_por_padrao_e_as_demais_sob_pedido(): void
    {
        $this->dispensa('Item alta', ['fit' => 'alta']);
        $this->dispensa('Item verificar', ['fit' => 'verificar']);
        $this->dispensa('Item baixa', ['fit' => 'baixa']);
        $this->dispensa('Item fora', ['fit' => 'fora']);

        $this->get('/')->assertSee('Item alta')->assertSee('Item verificar')->assertDontSee('Item baixa')->assertDontSee('Item fora');
        $this->get('/?verificar=0')->assertSee('Item alta')->assertDontSee('Item verificar');
        $this->get('/?baixa=1&fora=1')->assertSee('Item baixa')->assertSee('Item fora');
    }

    public function test_ordena_pelo_prazo_mais_proximo(): void
    {
        $this->dispensa('Encerra depois', ['encerra_em' => now()->addDays(9)]);
        $this->dispensa('Encerra antes', ['encerra_em' => now()->addDays(2)]);
        $this->dispensa('Nunca encerra', ['encerra_em' => null]);

        $this->get('/')->assertSeeInOrder(['Encerra antes', 'Encerra depois', 'Nunca encerra']);
    }

    public function test_pagina_de_30_em_30_e_preserva_os_filtros_nos_links(): void
    {
        Dispensa::factory()->count(31)->create(['uf' => 'PR']);

        $pagina1 = $this->get('/?uf=PR')->assertOk();
        $this->assertSame(30, substr_count($pagina1->getContent(), '<article class="card'));
        $pagina1->assertSee('uf=PR&amp;page=2', false);

        $pagina2 = $this->get('/?uf=PR&page=2')->assertOk();
        $this->assertSame(1, substr_count($pagina2->getContent(), '<article class="card'));
    }

    public function test_resumo_e_calculado_sobre_o_recorte_inteiro_e_nao_so_sobre_a_pagina(): void
    {
        Dispensa::factory()->count(31)->create(['fit' => 'alta', 'valor' => 1000]);
        Dispensa::factory()->count(2)->create(['fit' => 'verificar', 'valor' => 500]);
        Dispensa::factory()->count(4)->create(['fit' => 'baixa', 'valor' => 9999]);

        $r = $this->get('/')->assertOk();

        $r->assertViewHas('resumo', [
            'exibidas' => 33,
            'alta' => 31,
            'verificar' => 2,
            'abertas' => 37,
            'estimado' => 32000.0,
        ]);
        $r->assertSee('R$ 32.000,00');
    }

    public function test_sem_resultado_mostra_orientacao(): void
    {
        $this->get('/?q=inexistente')->assertOk()->assertSee('Nenhuma dispensa neste filtro');
    }

    public function test_parametros_hostis_caem_nos_padroes_sem_erro(): void
    {
        $this->dispensa('Item visivel', ['encerra_em' => now()->addDays(20)]);

        foreach ([
            '/?uf=XYZ', '/?uf[]=a', '/?dias=abc', '/?dias=9999', '/?vmax=-5', '/?vmax=abc',
            '/?cat=texto', '/?cat[]=inexistente', '/?cat[x][y]=1', '/?q[]=a', '/?verificar[]=1', '/?page=abc',
        ] as $url) {
            $this->get($url)->assertOk()->assertSee('Item visivel');
        }

        $this->get('/?page=999')->assertOk()->assertSee('Nenhuma dispensa neste filtro');
    }

    public function test_busca_trata_porcentagem_sublinhado_e_aspas_como_texto_comum(): void
    {
        $this->dispensa('Desconto de 50% no servico');
        $this->dispensa('Arquivo relatorio_final');
        $this->dispensa('Texto comum');

        $this->get('/?q=' . urlencode('50%'))->assertOk()->assertSee('Desconto de 50%')->assertDontSee('Texto comum');
        $this->get('/?q=' . urlencode('o_f'))->assertOk()->assertSee('relatorio_final')->assertDontSee('Texto comum');
        $this->get('/?q=' . urlencode("'; drop table dispensas; --"))->assertOk()->assertSee('Nenhuma dispensa neste filtro');
        $this->get('/?q=' . urlencode('\\'))->assertOk();
        $this->assertSame(3, Dispensa::count());
    }

    public function test_mostra_a_hora_da_ultima_sincronizacao(): void
    {
        Cache::forever(SincronizarPncp::CHAVE_ULTIMA, now()->subMinutes(10)->toIso8601String());

        $this->get('/')->assertSee('Atualizado')->assertDontSee('podem estar desatualizados');
    }

    public function test_avisa_quando_a_sincronizacao_tem_mais_de_1_hora_ou_nunca_rodou(): void
    {
        Cache::forever(SincronizarPncp::CHAVE_ULTIMA, now()->subHours(2)->toIso8601String());
        $this->get('/')->assertSee('podem estar desatualizados');

        Cache::forget(SincronizarPncp::CHAVE_ULTIMA);
        $this->get('/')->assertSee('podem estar desatualizados');
    }
}
```

`tests/Feature/DispensaPaginaTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Dispensa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DispensaPaginaTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitante_ve_a_pagina_da_dispensa_com_os_links_oficiais(): void
    {
        $d = Dispensa::factory()->create([
            'objeto' => 'Desenvolvimento de portal',
            'complemento' => 'Detalhes do termo de referência',
            'orgao' => 'Prefeitura De Xanxerê',
        ]);

        $this->get("/dispensas/$d->id")
            ->assertOk()
            ->assertSee('Desenvolvimento de portal')
            ->assertSee('Prefeitura De Xanxerê')
            ->assertSee('Detalhes do termo de referência')
            ->assertSee($d->motivo)
            ->assertSee('classificação é automática')
            ->assertSee($d->link_pncp, false)
            ->assertSee($d->link_origem, false);
    }

    public function test_dispensa_encerrada_continua_visivel_e_marcada(): void
    {
        $d = Dispensa::factory()->encerrada()->create(['objeto' => 'Dispensa antiga']);

        $this->get("/dispensas/$d->id")->assertOk()->assertSee('Dispensa antiga')->assertSee('Encerrada');
    }

    public function test_dispensa_inexistente_responde_404(): void
    {
        $this->get('/dispensas/999999')->assertNotFound();
        $this->get('/dispensas/abc')->assertNotFound();
    }

    public function test_link_que_nao_e_http_nao_e_renderizado(): void
    {
        $d = Dispensa::factory()->create(['link_origem' => 'javascript:alert(1)', 'link_pncp' => 'data:text/html,x']);

        $this->get("/dispensas/$d->id")->assertOk()->assertDontSee('javascript:alert', false)->assertDontSee('data:text/html', false);
        $this->get('/')->assertOk()->assertDontSee('javascript:alert', false);
    }

    public function test_texto_do_pncp_e_escapado(): void
    {
        $d = Dispensa::factory()->create(['objeto' => 'Serviço <script>alert(1)</script>']);

        $this->get("/dispensas/$d->id")->assertOk()->assertDontSee('<script>alert(1)</script>', false);
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `docker compose exec -u www-data app php artisan test --filter='RadarTest|DispensaPaginaTest'`
Expected: FAIL. `/` ainda mostra a página de boas-vindas e `/dispensas/{id}` responde 404.

- [ ] **Step 3: Filtro do radar**

`app/Support/FiltroRadar.php`:

```php
<?php

namespace App\Support;

use App\Models\Dispensa;
use App\Services\ClassificadorPf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Filtros do radar lidos da query string. Valor inválido cai no padrão em vez de gerar erro, porque a URL é pública. */
final class FiltroRadar
{
    public const UFS = ['AC', 'AL', 'AM', 'AP', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MG', 'MS', 'MT', 'PA', 'PB', 'PE', 'PI', 'PR', 'RJ', 'RN', 'RO', 'RR', 'RS', 'SC', 'SE', 'SP', 'TO'];
    public const DIAS = [7, 15, 30, 60];
    private const DIAS_PADRAO = 30;

    /**
     * @param  list<string>  $cats
     * @param  list<string>  $fits
     */
    public function __construct(
        public readonly ?string $uf,
        public readonly int $dias,
        public readonly ?float $vmax,
        public readonly string $q,
        public readonly array $cats,
        public readonly array $fits,
    ) {}

    public static function deRequest(Request $request): self
    {
        $texto = function (string $chave) use ($request): string {
            $valor = $request->query($chave);

            return is_string($valor) ? trim($valor) : '';
        };

        $uf = strtoupper($texto('uf'));
        $dias = (int) $texto('dias');
        $vmax = $texto('vmax');
        $cat = $request->query('cat');

        $fits = ['alta'];
        if ($texto('verificar') !== '0') {
            $fits[] = 'verificar';
        }
        if ($texto('baixa') === '1') {
            $fits[] = 'baixa';
        }
        if ($texto('fora') === '1') {
            $fits[] = 'fora';
        }

        return new self(
            uf: in_array($uf, self::UFS, true) ? $uf : null,
            dias: in_array($dias, self::DIAS, true) ? $dias : self::DIAS_PADRAO,
            vmax: is_numeric($vmax) && (float) $vmax > 0 ? (float) $vmax : null,
            q: mb_substr($texto('q'), 0, 100),
            cats: array_values(array_intersect(
                array_keys(ClassificadorPf::CATEGORIAS),
                is_array($cat) ? array_filter($cat, 'is_string') : [],
            )),
            fits: $fits,
        );
    }

    /** Dispensas abertas na UF e no prazo escolhidos, antes dos demais filtros. */
    public function recorte(): Builder
    {
        return Dispensa::query()
            ->abertas()
            ->when($this->uf, fn (Builder $q) => $q->where('uf', $this->uf))
            ->where(fn (Builder $q) => $q->whereNull('encerra_em')->orWhere('encerra_em', '<=', now()->addDays($this->dias)));
    }

    public function aplicar(): Builder
    {
        return $this->recorte()
            ->whereIn('fit', $this->fits)
            ->when($this->vmax, fn (Builder $q) => $q->where(
                fn (Builder $w) => $w->whereNull('valor')->orWhere('valor', '<=', $this->vmax)
            ))
            ->when($this->cats, fn (Builder $q) => $q->where(function (Builder $w) {
                foreach ($this->cats as $cat) {
                    $w->orWhereJsonContains('categorias', $cat);
                }
            }))
            ->when($this->q !== '', fn (Builder $q) => $q->whereRaw(
                "unaccent(objeto || ' ' || orgao || ' ' || municipio || ' ' || complemento) ILIKE unaccent(?)",
                ['%' . addcslashes($this->q, '%_\\') . '%'],
            ));
    }
}
```

- [ ] **Step 4: Controllers e rotas**

`app/Http/Controllers/RadarController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Console\Commands\SincronizarPncp;
use App\Services\ClassificadorPf;
use App\Support\FiltroRadar;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class RadarController extends Controller
{
    private const POR_PAGINA = 30;

    public function __invoke(Request $request): View
    {
        $filtro = FiltroRadar::deRequest($request);
        $recorte = $filtro->recorte();
        $filtrado = $filtro->aplicar();

        $resumo = [
            'exibidas' => (clone $filtrado)->count(),
            'alta' => (clone $recorte)->where('fit', 'alta')->count(),
            'verificar' => (clone $recorte)->where('fit', 'verificar')->count(),
            'abertas' => (clone $recorte)->count(),
            'estimado' => (float) (clone $filtrado)->sum('valor'),
        ];

        $dispensas = $filtrado
            ->orderByRaw('encerra_em asc nulls last')
            ->orderBy('id')
            ->paginate(self::POR_PAGINA)
            ->withQueryString();

        $ultima = Cache::get(SincronizarPncp::CHAVE_ULTIMA);
        $ultimaSync = $ultima ? Carbon::parse($ultima) : null;

        return view('radar', [
            'filtro' => $filtro,
            'dispensas' => $dispensas,
            'resumo' => $resumo,
            'categorias' => array_map(fn (array $c) => $c['label'], ClassificadorPf::CATEGORIAS),
            'ultimaSync' => $ultimaSync,
            'desatualizado' => $ultimaSync === null || $ultimaSync->lt(now()->subHour()),
        ]);
    }
}
```

`app/Http/Controllers/DispensaController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Dispensa;
use Illuminate\View\View;

class DispensaController extends Controller
{
    public function show(Dispensa $dispensa): View
    {
        return view('dispensas.show', ['d' => $dispensa]);
    }
}
```

Em `routes/web.php`, remova a rota `/` que devolve `view('welcome')` e acrescente:

```php
use App\Http\Controllers\DispensaController;
use App\Http\Controllers\RadarController;

Route::get('/', RadarController::class)->name('radar');
Route::get('/dispensas/{dispensa}', [DispensaController::class, 'show'])->whereNumber('dispensa')->name('dispensas.show');
```

```bash
rm resources/views/welcome.blade.php
```

- [ ] **Step 5: Ficha e paginação**

`resources/views/partials/ficha.blade.php`:

```blade
@php
    $p = $d->prazo();
@endphp
<article class="card fit-{{ $d->fit->value }}">
  <div>
    <div class="meta"><span>{{ $d->orgao }}</span><span>{{ $d->municipio }}/{{ $d->uf }}</span><span>{{ $d->esfera }}</span></div>
    <h2 class="obj"><a href="{{ route('dispensas.show', $d) }}">{{ $d->objeto }}</a></h2>
    <p class="why">{{ $d->motivo }}</p>
    @if ($d->categorias)
      <div class="tags">
        @foreach ($d->categorias as $c)
          <span class="tag">{{ \App\Services\ClassificadorPf::CATEGORIAS[$c]['label'] ?? $c }}</span>
        @endforeach
      </div>
    @endif
  </div>
  <div class="side">
    <span class="valor {{ $d->valor ? '' : 'sigiloso' }}">{{ $d->valorTexto() }}</span>
    <span class="prazo {{ $p['cls'] }}">{{ $p['txt'] }}</span>
    <span class="pill fit-{{ $d->fit->value }}">{{ $d->fit->label() }}</span>
  </div>
  <div class="actions">
    @if ($d->urlPncp())<a href="{{ $d->urlPncp() }}" target="_blank" rel="noopener">Aviso no PNCP</a>@endif
    @if ($d->urlOrigem())<a href="{{ $d->urlOrigem() }}" target="_blank" rel="noopener">Enviar proposta</a>@endif
    <span class="spacer"></span>
    <a class="btn" href="{{ route('dispensas.show', $d) }}">Ver detalhes</a>
  </div>
</article>
```

`resources/views/partials/paginacao.blade.php`:

```blade
@if ($paginator->hasPages())
<nav class="paginacao" aria-label="Paginação">
  <a class="btn" @if ($paginator->onFirstPage()) aria-disabled="true" @else href="{{ $paginator->previousPageUrl() }}" rel="prev" @endif>← Anteriores</a>
  <span>Página {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}</span>
  <a class="btn" @if ($paginator->hasMorePages()) href="{{ $paginator->nextPageUrl() }}" rel="next" @else aria-disabled="true" @endif>Próximas →</a>
</nav>
@endif
```

- [ ] **Step 6: Tela do radar**

`resources/views/radar.blade.php`:

```blade
@extends('layouts.app')
@section('title', ($filtro->uf ? "Dispensas de licitação para pessoa física em {$filtro->uf}" : 'Dispensas de licitação para pessoa física') . ' · Radar de Dispensas PF')
@section('content')
<header class="top">
  <div class="brand">
    <svg class="logo" viewBox="0 0 40 40" aria-hidden="true">
      <circle cx="20" cy="20" r="17" fill="none" stroke="currentColor" stroke-width="2" opacity=".35"/>
      <circle cx="20" cy="20" r="10" fill="none" stroke="currentColor" stroke-width="2" opacity=".6"/>
      <path d="M20 20 L32 8" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
      <circle cx="20" cy="20" r="3" fill="currentColor"/>
    </svg>
    <div>
      <h1>Radar de Dispensas PF</h1>
      <p>Dispensas de licitação com propostas abertas no PNCP, filtradas pelo que uma pessoa física consegue executar sem CNPJ.</p>
    </div>
  </div>
</header>

<form class="bar" method="GET" action="{{ route('radar') }}" data-auto-enviar>
  <div class="row">
    <label class="f">UF
      <select name="uf">
        <option value="">Brasil todo</option>
        @foreach (\App\Support\FiltroRadar::UFS as $uf)
          <option value="{{ $uf }}" @selected($filtro->uf === $uf)>{{ $uf }}</option>
        @endforeach
      </select>
    </label>
    <label class="f">Encerra em até
      <select name="dias">
        @foreach (\App\Support\FiltroRadar::DIAS as $dias)
          <option value="{{ $dias }}" @selected($filtro->dias === $dias)>{{ $dias }} dias</option>
        @endforeach
      </select>
    </label>
    <label class="f">Valor máximo (R$)
      <input name="vmax" type="number" min="0" step="1000" placeholder="sem limite" value="{{ $filtro->vmax }}">
    </label>
    <label class="f grow">Buscar no objeto
      <input name="q" type="search" maxlength="100" placeholder="ex.: Laravel, palestra, Chapecó" value="{{ $filtro->q }}">
    </label>
    <button class="btn primary" type="submit">Filtrar</button>
  </div>
  <div class="row">
    @foreach ($categorias as $chave => $label)
      <label class="chip" aria-pressed="{{ in_array($chave, $filtro->cats, true) ? 'true' : 'false' }}">
        <input type="checkbox" name="cat[]" value="{{ $chave }}" @checked(in_array($chave, $filtro->cats, true)) hidden> {{ $label }}
      </label>
    @endforeach
  </div>
  <div class="row">
    <input type="hidden" name="verificar" value="0">
    <label class="toggle"><input type="checkbox" name="verificar" value="1" @checked(in_array('verificar', $filtro->fits, true))> Incluir "verificar"</label>
    <label class="toggle"><input type="checkbox" name="baixa" value="1" @checked(in_array('baixa', $filtro->fits, true))> Incluir baixa aderência</label>
    <label class="toggle"><input type="checkbox" name="fora" value="1" @checked(in_array('fora', $filtro->fits, true))> Incluir bens e engenharia</label>
  </div>
</form>

@if ($desatualizado)
  <div class="flash aviso" style="margin-top:16px">Não conseguimos atualizar com o PNCP na última hora. Os dados podem estar desatualizados.</div>
@endif

<div class="summary">
  <span class="stat"><b>{{ $resumo['exibidas'] }}</b>exibidas</span>
  <span class="stat"><b>{{ $resumo['alta'] }}</b>fit PF</span>
  <span class="stat"><b>{{ $resumo['verificar'] }}</b>a verificar</span>
  <span class="stat"><b>{{ $resumo['abertas'] }}</b>abertas no recorte</span>
  <span class="stat"><b>R$ {{ number_format($resumo['estimado'], 2, ',', '.') }}</b>estimado exibido</span>
  @if ($ultimaSync)<span class="when">Atualizado {{ $ultimaSync->format('d/m H:i') }}</span>@endif
</div>

<div class="list">
  @forelse ($dispensas as $d)
    @include('partials.ficha', ['d' => $d])
  @empty
    <div class="state"><strong>Nenhuma dispensa neste filtro</strong>Amplie o prazo, troque a UF ou inclua os itens a verificar.</div>
  @endforelse
</div>

{{ $dispensas->links('partials.paginacao') }}
@endsection
```

- [ ] **Step 7: Página da dispensa**

`resources/views/dispensas/show.blade.php`:

```blade
@extends('layouts.app')
@php
    $p = $d->prazo();
@endphp
@section('title', \Illuminate\Support\Str::limit($d->objeto, 60) . ' · ' . $d->orgao)
@section('description', \Illuminate\Support\Str::limit("Dispensa de licitação em {$d->municipio}/{$d->uf}, {$d->orgao}. {$d->valorTexto()}, {$p['txt']}. {$d->objeto}", 155))
@section('content')
<p><a href="{{ route('radar') }}">← Voltar ao radar</a></p>

<article class="card fit-{{ $d->fit->value }}">
  <div>
    <div class="meta"><span>{{ $d->orgao }}</span>@if ($d->unidade)<span>{{ $d->unidade }}</span>@endif<span>{{ $d->municipio }}/{{ $d->uf }}</span><span>{{ $d->esfera }}</span></div>
    <h1 class="obj">{{ $d->objeto }}</h1>
    @if ($d->categorias)
      <div class="tags">
        @foreach ($d->categorias as $c)
          <span class="tag">{{ \App\Services\ClassificadorPf::CATEGORIAS[$c]['label'] ?? $c }}</span>
        @endforeach
      </div>
    @endif
  </div>
  <div class="side">
    <span class="valor {{ $d->valor ? '' : 'sigiloso' }}">{{ $d->valorTexto() }}</span>
    <span class="prazo {{ $p['cls'] }}">{{ $p['txt'] }}</span>
    @if ($d->encerrada())
      <span class="pill fit-fora">Encerrada</span>
    @else
      <span class="pill fit-{{ $d->fit->value }}">{{ $d->fit->label() }}</span>
    @endif
  </div>

  <div class="track">
    <div>
      <p class="why"><strong>Aderência para pessoa física:</strong> {{ $d->motivo }}</p>
      <p class="why">A classificação é automática, feita a partir do texto do aviso, e não substitui a leitura do aviso oficial e do termo de referência.</p>
    </div>
  </div>

  @if ($d->complemento)
    <details open><summary>Informação complementar</summary><p>{{ $d->complemento }}</p></details>
  @endif

  <details open>
    <summary>Dados do aviso</summary>
    <p>
      Amparo legal: {{ $d->amparo ?: 'não informado' }}<br>
      Modo de disputa: {{ $d->disputa ?: 'não informado' }}<br>
      Abertura das propostas: {{ $d->abre_em?->format('d/m/Y H:i') ?? 'não informada' }}<br>
      Encerramento das propostas: {{ $d->encerra_em?->format('d/m/Y H:i') ?? 'não informado' }}<br>
      Número de controle PNCP: {{ $d->pncp_id }}
    </p>
  </details>

  <div class="actions">
    @if ($d->urlPncp())<a href="{{ $d->urlPncp() }}" target="_blank" rel="noopener">Aviso no PNCP</a>@endif
    <span class="spacer"></span>
    @if ($d->urlOrigem() && ! $d->encerrada())
      <a class="btn primary" href="{{ $d->urlOrigem() }}" target="_blank" rel="noopener">Enviar proposta no sistema oficial</a>
    @endif
  </div>
</article>
@endsection
```

- [ ] **Step 8: JavaScript do radar**

`public/js/app.js`:

```js
/* Radar de Dispensas PF: melhorias progressivas. Tudo funciona sem este arquivo. */

// Filtros do radar: envia ao mudar um seletor, uma categoria ou uma opção de aderência.
const filtros = document.querySelector('form[data-auto-enviar]');
if (filtros) {
  filtros.addEventListener('change', e => {
    if (e.target.matches('select, input[type=checkbox]')) filtros.requestSubmit();
  });
}
```

- [ ] **Step 9: Rodar e ver passar**

Run: `docker compose exec -u www-data app php artisan test --filter='RadarTest|DispensaPaginaTest'`
Expected: PASS, 16 e 5 testes.

Run: `docker compose exec -u www-data app php artisan test`
Expected: PASS em tudo. Se o teste de exemplo `tests/Feature/ExampleTest.php` falhar por depender da página de boas-vindas, apague-o: `RadarTest` já cobre a rota `/`.

- [ ] **Step 10: Conferir no navegador**

Abra `http://localhost:8081/`. Confira que as fichas aparecem com o visual anterior, que trocar a UF recarrega a lista, que as categorias funcionam como botões e que "Ver detalhes" abre a página da dispensa.

- [ ] **Step 11: Commit**

```bash
git add -A
git commit -m "Adiciona o radar público com filtros e a página de cada dispensa

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Indexação por buscadores

**Files:**
- Create: `app/Http/Controllers/SitemapController.php`
- Modify: `app/Http/Controllers/RadarController.php`, `resources/views/radar.blade.php`, `resources/views/dispensas/show.blade.php`, `routes/web.php`
- Delete: `public/robots.txt` (o arquivo estático seria servido pelo Apache no lugar da rota)
- Test: `tests/Feature/IndexacaoTest.php`

**Interfaces:**
- Consumes: `FiltroRadar::UFS`, rotas `radar` e `dispensas.show` (Task 5); `Dispensa::abertas()` (Task 4).
- Produces: rotas `GET /sitemap.xml` e `GET /robots.txt`; variáveis `indexavel` (bool) e `canonica` (string) na view `radar`.

- [ ] **Step 1: Escrever os testes (falhando)**

`tests/Feature/IndexacaoTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Dispensa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndexacaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_radar_sem_filtro_e_por_uf_sao_indexaveis_com_url_canonica(): void
    {
        $this->get('/')
            ->assertDontSee('noindex', false)
            ->assertSee('<link rel="canonical" href="' . url('/') . '">', false);

        $this->get('/?uf=SC')
            ->assertDontSee('noindex', false)
            ->assertSee('<link rel="canonical" href="' . url('/') . '?uf=SC">', false);
    }

    public function test_outros_filtros_paginas_seguintes_e_uf_invalida_recebem_noindex(): void
    {
        foreach (['/?q=curso', '/?uf=SC&dias=7', '/?cat[]=ti', '/?page=2', '/?uf=SC&page=2', '/?uf=XYZ', '/?verificar=0'] as $url) {
            $this->get($url)->assertOk()->assertSee('<meta name="robots" content="noindex', false);
        }
    }

    public function test_pagina_da_dispensa_tem_titulo_descricao_e_canonica_proprios(): void
    {
        $d = Dispensa::factory()->create(['objeto' => 'Oficina de fotografia', 'orgao' => 'Prefeitura De Lages', 'municipio' => 'Lages']);

        $this->get("/dispensas/$d->id?origem=x")
            ->assertSee('<title>Oficina de fotografia · Prefeitura De Lages</title>', false)
            ->assertSee('Dispensa de licitação em Lages/SC', false)
            ->assertSee('<link rel="canonical" href="' . route('dispensas.show', $d) . '">', false)
            ->assertDontSee('noindex', false);
    }

    public function test_sitemap_lista_o_radar_cada_uf_e_as_dispensas_abertas(): void
    {
        $aberta = Dispensa::factory()->create();
        $encerrada = Dispensa::factory()->encerrada()->create();

        $r = $this->get('/sitemap.xml')->assertOk();

        $this->assertStringContainsString('xml', $r->headers->get('Content-Type'));
        $xml = simplexml_load_string($r->getContent());
        $locs = [];
        foreach ($xml->url as $url) {
            $locs[] = (string) $url->loc;
        }

        $this->assertContains(url('/'), $locs);
        $this->assertContains(url('/') . '?uf=SC', $locs);
        $this->assertContains(route('dispensas.show', $aberta), $locs);
        $this->assertNotContains(route('dispensas.show', $encerrada), $locs);
        $this->assertCount(1 + 27 + 1, $locs);
    }

    public function test_robots_aponta_o_sitemap_e_bloqueia_as_rotas_de_conta(): void
    {
        $r = $this->get('/robots.txt')->assertOk();

        $this->assertStringContainsString('text/plain', $r->headers->get('Content-Type'));
        $r->assertSee('Sitemap: ' . url('/sitemap.xml'), false)
            ->assertSee('Disallow: /candidaturas', false)
            ->assertSee('Disallow: /perfil', false)
            ->assertSee('Disallow: /login', false);
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `docker compose exec -u www-data app php artisan test --filter=IndexacaoTest`
Expected: FAIL. Não há tag canônica nem as rotas `/sitemap.xml` e `/robots.txt`.

- [ ] **Step 3: Radar indexável só sem filtro ou por UF**

Em `app/Http/Controllers/RadarController.php`, no array passado para `view('radar', [...])`, acrescente duas chaves:

```php
            // Só o radar sem filtro e o radar por UF entram no índice; o resto seria conteúdo duplicado.
            'indexavel' => array_diff(array_keys($request->query()), ['uf']) === []
                && (! $request->has('uf') || $filtro->uf !== null),
            'canonica' => route('radar') . ($filtro->uf ? '?uf=' . $filtro->uf : ''),
```

Em `resources/views/radar.blade.php`, logo abaixo da linha `@section('title', ...)`, acrescente:

```blade
@push('head')
  @if ($indexavel)
    <link rel="canonical" href="{{ $canonica }}">
  @else
    <meta name="robots" content="noindex, follow">
  @endif
@endpush
```

Em `resources/views/dispensas/show.blade.php`, logo abaixo da linha `@section('description', ...)`, acrescente:

```blade
@push('head')<link rel="canonical" href="{{ route('dispensas.show', $d) }}">@endpush
```

- [ ] **Step 4: Sitemap e robots**

`app/Http/Controllers/SitemapController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Dispensa;
use App\Support\FiltroRadar;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function sitemap(): Response
    {
        $urls = [route('radar')];
        foreach (FiltroRadar::UFS as $uf) {
            $urls[] = route('radar') . '?uf=' . $uf;
        }
        foreach (Dispensa::query()->abertas()->orderBy('id')->pluck('id') as $id) {
            $urls[] = route('dispensas.show', $id);
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            . implode('', array_map(fn (string $u) => '<url><loc>' . e($u) . '</loc></url>', $urls))
            . '</urlset>';

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    public function robots(): Response
    {
        $linhas = [
            'User-agent: *',
            'Disallow: /candidaturas',
            'Disallow: /perfil',
            'Disallow: /login',
            'Disallow: /register',
            'Disallow: /forgot-password',
            'Disallow: /reset-password',
            'Disallow: /email',
            'Disallow: /auth',
            '',
            'Sitemap: ' . url('/sitemap.xml'),
        ];

        return response(implode("\n", $linhas) . "\n", 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
```

Em `routes/web.php`, acrescente:

```php
use App\Http\Controllers\SitemapController;

Route::get('/sitemap.xml', [SitemapController::class, 'sitemap']);
Route::get('/robots.txt', [SitemapController::class, 'robots']);
```

```bash
rm public/robots.txt
```

- [ ] **Step 5: Rodar e ver passar**

Run: `docker compose exec -u www-data app php artisan test --filter=IndexacaoTest`
Expected: PASS, 5 testes.

Run: `curl -s http://localhost:8081/robots.txt | tail -1`
Expected: `Sitemap: http://localhost:8081/sitemap.xml` (prova que o Apache não está servindo um arquivo estático).

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "Prepara as páginas públicas para buscadores: canônica, noindex, sitemap e robots

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Candidaturas por usuário

**Files:**
- Create: `database/migrations/2026_10_12_000003_create_candidaturas_table.php`
- Create: `app/Enums/StatusCandidatura.php`, `app/Models/Candidatura.php`, `database/factories/CandidaturaFactory.php`, `app/Policies/CandidaturaPolicy.php`, `app/Http/Controllers/CandidaturaController.php`
- Create: `resources/views/candidaturas/index.blade.php`
- Modify: `app/Models/User.php`, `app/Providers/AppServiceProvider.php`, `app/Http/Controllers/RadarController.php`, `app/Http/Controllers/DispensaController.php`, `resources/views/radar.blade.php`, `resources/views/partials/ficha.blade.php`, `resources/views/dispensas/show.blade.php`, `routes/web.php`, `public/js/app.js`
- Test: `tests/Feature/CandidaturasTest.php`

**Interfaces:**
- Consumes: `Dispensa`, `Dispensa::encerrada()` (Task 4); middlewares `auth` e `verified`, rota `login` (Task 2); partial `partials.ficha` (Task 5).
- Produces: `App\Enums\StatusCandidatura` (casos `Salva='salva'`, `Enviada='enviada'`, `Ganhei='ganhei'`, `Perdi='perdi'`, `Descartada='descartada'`; `label(): string`).
- Produces: `App\Models\Candidatura` (`user_id`, `dispensa_id`, `status` como `StatusCandidatura`, `notas`; relações `user()` e `dispensa()`), `User::candidaturas()`.
- Produces: rotas `candidaturas.index` (GET `/candidaturas`), `candidaturas.store` (POST `/dispensas/{dispensa}/candidatura`), `candidaturas.update` (PATCH `/candidaturas/{candidatura}`), `candidaturas.destroy` (DELETE `/candidaturas/{candidatura}`), `dispensas.entrar` (GET `/dispensas/{dispensa}/entrar`).
- Produces: o partial `partials.ficha` passa a aceitar `$salva` (bool) e `$cand` (`?Candidatura`).

- [ ] **Step 1: Escrever os testes (falhando)**

`tests/Feature/CandidaturasTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Candidatura;
use App\Models\Dispensa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CandidaturasTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitante_que_tenta_salvar_vai_para_o_login(): void
    {
        $d = Dispensa::factory()->create();

        $this->post("/dispensas/$d->id/candidatura")->assertRedirect('/login');
        $this->get('/candidaturas')->assertRedirect('/login');
        $this->assertSame(0, Candidatura::count());
    }

    public function test_visitante_que_clica_em_salvar_volta_para_a_dispensa_depois_do_login(): void
    {
        $d = Dispensa::factory()->create();
        $user = User::factory()->create();

        $this->get("/dispensas/$d->id/entrar")->assertRedirect('/login');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect("/dispensas/$d->id/entrar");
        $this->get("/dispensas/$d->id/entrar")->assertRedirect("/dispensas/$d->id");
    }

    public function test_usuario_sem_email_confirmado_nao_salva(): void
    {
        $d = Dispensa::factory()->create();

        $this->actingAs(User::factory()->unverified()->create())
            ->post("/dispensas/$d->id/candidatura")
            ->assertRedirect('/email/verify');
        $this->assertSame(0, Candidatura::count());
    }

    public function test_usuario_confirmado_salva_com_status_inicial_salva(): void
    {
        $d = Dispensa::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)->from("/dispensas/$d->id")->post("/dispensas/$d->id/candidatura")
            ->assertRedirect("/dispensas/$d->id");

        $c = Candidatura::firstOrFail();
        $this->assertSame($user->id, $c->user_id);
        $this->assertSame($d->id, $c->dispensa_id);
        $this->assertSame('salva', $c->status->value);
        $this->assertSame('', $c->notas);
    }

    public function test_salvar_duas_vezes_nao_duplica_nem_apaga_status_e_notas(): void
    {
        $d = Dispensa::factory()->create();
        $user = User::factory()->create();
        Candidatura::factory()->for($user)->for($d)->create(['status' => 'enviada', 'notas' => 'já enviei']);

        $this->actingAs($user)->post("/dispensas/$d->id/candidatura")->assertRedirect();

        $this->assertSame(1, Candidatura::count());
        $c = Candidatura::firstOrFail();
        $this->assertSame('enviada', $c->status->value);
        $this->assertSame('já enviei', $c->notas);
    }

    public function test_dois_usuarios_salvam_a_mesma_dispensa(): void
    {
        $d = Dispensa::factory()->create();

        $this->actingAs(User::factory()->create())->post("/dispensas/$d->id/candidatura");
        $this->actingAs(User::factory()->create())->post("/dispensas/$d->id/candidatura");

        $this->assertSame(2, Candidatura::count());
    }

    public function test_dispensa_encerrada_nao_pode_ser_salva(): void
    {
        $d = Dispensa::factory()->encerrada()->create();

        $this->actingAs(User::factory()->create())->from("/dispensas/$d->id")
            ->post("/dispensas/$d->id/candidatura")
            ->assertRedirect("/dispensas/$d->id")
            ->assertSessionHasErrors('candidatura');
        $this->assertSame(0, Candidatura::count());
    }

    public function test_lista_so_as_candidaturas_do_proprio_usuario_e_filtra_por_status(): void
    {
        $user = User::factory()->create();
        Candidatura::factory()->for($user)->for(Dispensa::factory()->create(['objeto' => 'Minha salva']))->create();
        Candidatura::factory()->for($user)->for(Dispensa::factory()->create(['objeto' => 'Minha enviada']))->create(['status' => 'enviada']);
        Candidatura::factory()->for(Dispensa::factory()->create(['objeto' => 'De outra pessoa']))->create();

        $this->actingAs($user)->get('/candidaturas')
            ->assertOk()->assertSee('Minha salva')->assertSee('Minha enviada')->assertDontSee('De outra pessoa');
        $this->actingAs($user)->get('/candidaturas?status=enviada')
            ->assertSee('Minha enviada')->assertDontSee('Minha salva');
        $this->actingAs($user)->get('/candidaturas?status=invalido')
            ->assertOk()->assertSee('Minha salva')->assertSee('Minha enviada');
    }

    public function test_candidatura_de_dispensa_encerrada_continua_na_lista(): void
    {
        $user = User::factory()->create();
        Candidatura::factory()->for($user)->for(Dispensa::factory()->encerrada()->create(['objeto' => 'Ja encerrou']))->create();

        $this->actingAs($user)->get('/candidaturas')->assertSee('Ja encerrou');
    }

    public function test_dono_altera_status_e_notas(): void
    {
        $c = Candidatura::factory()->create();

        $this->actingAs($c->user)->patch("/candidaturas/$c->id", ['status' => 'ganhei'])->assertRedirect();
        $this->assertSame('ganhei', $c->fresh()->status->value);

        $this->actingAs($c->user)->patchJson("/candidaturas/$c->id", ['notas' => 'Falta a certidão'])
            ->assertOk()->assertJson(['status' => 'ganhei', 'notas' => 'Falta a certidão']);
        $this->assertSame('Falta a certidão', $c->fresh()->notas);
        $this->assertSame('ganhei', $c->fresh()->status->value);
    }

    public function test_notas_vazias_sao_aceitas_e_gravadas_como_texto_vazio(): void
    {
        $c = Candidatura::factory()->create(['notas' => 'algo']);

        $this->actingAs($c->user)->patchJson("/candidaturas/$c->id", ['notas' => ''])->assertOk();

        $this->assertSame('', $c->fresh()->notas);
    }

    public function test_recusa_status_invalido_e_notas_acima_de_5000_caracteres(): void
    {
        $c = Candidatura::factory()->create();

        $this->actingAs($c->user)->patchJson("/candidaturas/$c->id", ['status' => 'inventado'])->assertStatus(422);
        $this->actingAs($c->user)->patchJson("/candidaturas/$c->id", ['notas' => str_repeat('a', 5001)])->assertStatus(422);
        $this->actingAs($c->user)->patchJson("/candidaturas/$c->id", ['notas' => str_repeat('ã', 5000)])->assertOk();
        $this->assertSame('salva', $c->fresh()->status->value);
    }

    public function test_dono_remove(): void
    {
        $c = Candidatura::factory()->create();

        $this->actingAs($c->user)->delete("/candidaturas/$c->id")->assertRedirect();

        $this->assertSame(0, Candidatura::count());
        $this->assertDatabaseHas('dispensas', ['id' => $c->dispensa_id]);
    }

    public function test_candidatura_de_outro_usuario_responde_404(): void
    {
        $c = Candidatura::factory()->create(['notas' => 'original']);
        $intruso = User::factory()->create();

        $this->actingAs($intruso)->patch("/candidaturas/$c->id", ['status' => 'perdi'])->assertNotFound();
        $this->actingAs($intruso)->patchJson("/candidaturas/$c->id", ['notas' => 'x'])->assertNotFound();
        $this->actingAs($intruso)->delete("/candidaturas/$c->id")->assertNotFound();

        $this->assertSame('original', $c->fresh()->notas);
        $this->assertSame('salva', $c->fresh()->status->value);
    }

    public function test_excluir_a_conta_apaga_as_candidaturas_e_mantem_a_dispensa(): void
    {
        $c = Candidatura::factory()->create();

        $this->actingAs($c->user)->delete('/perfil', ['email_confirmacao' => $c->user->email]);

        $this->assertSame(0, Candidatura::count());
        $this->assertDatabaseHas('dispensas', ['id' => $c->dispensa_id]);
    }

    public function test_radar_e_pagina_mostram_o_estado_de_salva_para_quem_esta_logado(): void
    {
        $user = User::factory()->create();
        $salva = Dispensa::factory()->create();
        $outra = Dispensa::factory()->create();
        Candidatura::factory()->for($user)->for($salva)->create();

        $this->actingAs($user)->get('/')->assertSee('✓ Salva')->assertSee('Salvar');
        $this->actingAs($user)->get("/dispensas/$salva->id")->assertSee('✓ Salva');
        $this->actingAs($user)->get("/dispensas/$outra->id")->assertDontSee('✓ Salva')->assertSee('Salvar');
    }

    public function test_pagina_de_dispensa_encerrada_nao_oferece_salvar(): void
    {
        $d = Dispensa::factory()->encerrada()->create();

        $this->actingAs(User::factory()->create())->get("/dispensas/$d->id")
            ->assertDontSee(route('candidaturas.store', $d), false);
    }

    public function test_gravacoes_tem_limite_de_60_por_minuto_por_usuario(): void
    {
        $c = Candidatura::factory()->create();

        for ($i = 0; $i < 60; $i++) {
            $this->actingAs($c->user)->patchJson("/candidaturas/$c->id", ['notas' => "n$i"])->assertOk();
        }

        $this->actingAs($c->user)->patchJson("/candidaturas/$c->id", ['notas' => 'demais'])->assertStatus(429);
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `docker compose exec -u www-data app php artisan test --filter=CandidaturasTest`
Expected: FAIL com `Class "App\Models\Candidatura" not found`.

- [ ] **Step 3: Migration, enum, modelo, factory**

`database/migrations/2026_10_12_000003_create_candidaturas_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidaturas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dispensa_id')->constrained()->restrictOnDelete();
            $table->text('status')->default('salva');
            $table->text('notas')->default('');
            $table->timestamps();
            $table->unique(['user_id', 'dispensa_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidaturas');
    }
};
```

`app/Enums/StatusCandidatura.php`:

```php
<?php

namespace App\Enums;

enum StatusCandidatura: string
{
    case Salva = 'salva';
    case Enviada = 'enviada';
    case Ganhei = 'ganhei';
    case Perdi = 'perdi';
    case Descartada = 'descartada';

    public function label(): string
    {
        return match ($this) {
            self::Salva => 'Salva',
            self::Enviada => 'Proposta enviada',
            self::Ganhei => 'Ganhei',
            self::Perdi => 'Perdi',
            self::Descartada => 'Descartada',
        };
    }
}
```

`app/Models/Candidatura.php`:

```php
<?php

namespace App\Models;

use App\Enums\StatusCandidatura;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Candidatura extends Model
{
    use HasFactory;

    public const NOTAS_MAX = 5000;

    protected $fillable = ['user_id', 'dispensa_id', 'status', 'notas'];

    protected $attributes = ['status' => 'salva', 'notas' => ''];

    protected function casts(): array
    {
        return ['status' => StatusCandidatura::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dispensa(): BelongsTo
    {
        return $this->belongsTo(Dispensa::class);
    }
}
```

`database/factories/CandidaturaFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Dispensa;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Candidatura>
 */
class CandidaturaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'dispensa_id' => Dispensa::factory(),
            'status' => 'salva',
            'notas' => '',
        ];
    }
}
```

Em `app/Models/User.php`, acrescente a relação:

```php
use Illuminate\Database\Eloquent\Relations\HasMany;

    public function candidaturas(): HasMany
    {
        return $this->hasMany(Candidatura::class);
    }
```

- [ ] **Step 4: Policy e limite de gravações**

`app/Policies/CandidaturaPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\Candidatura;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CandidaturaPolicy
{
    public function update(User $user, Candidatura $candidatura): Response
    {
        return $this->dono($user, $candidatura);
    }

    public function delete(User $user, Candidatura $candidatura): Response
    {
        return $this->dono($user, $candidatura);
    }

    /** Responde 404 para não confirmar a quem não é dono que a candidatura existe. */
    private function dono(User $user, Candidatura $candidatura): Response
    {
        return $user->id === $candidatura->user_id ? Response::allow() : Response::denyAsNotFound();
    }
}
```

Em `app/Providers/AppServiceProvider.php`, dentro de `boot()`:

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

        RateLimiter::for('candidaturas', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));
```

- [ ] **Step 5: Controller e rotas**

`app/Http/Controllers/CandidaturaController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\StatusCandidatura;
use App\Models\Candidatura;
use App\Models\Dispensa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CandidaturaController extends Controller
{
    public function index(Request $request): View
    {
        $pedido = $request->query('status');
        $status = is_string($pedido) ? StatusCandidatura::tryFrom($pedido) : null;

        $todas = $request->user()->candidaturas()
            ->with('dispensa')
            ->join('dispensas', 'dispensas.id', '=', 'candidaturas.dispensa_id')
            ->orderByRaw('dispensas.encerra_em asc nulls last')
            ->orderBy('candidaturas.id')
            ->select('candidaturas.*')
            ->get();

        $emAndamento = $todas->filter(fn (Candidatura $c) => in_array($c->status, [StatusCandidatura::Salva, StatusCandidatura::Enviada], true));

        return view('candidaturas.index', [
            'candidaturas' => $status ? $todas->where('status', $status)->values() : $todas,
            'statusAtivo' => $status,
            'contagem' => $todas->countBy(fn (Candidatura $c) => $c->status->value),
            'total' => $todas->count(),
            'emAndamento' => $emAndamento->count(),
            'estimado' => (float) $emAndamento->sum(fn (Candidatura $c) => $c->dispensa->valor ?? 0),
        ]);
    }

    public function store(Request $request, Dispensa $dispensa): RedirectResponse
    {
        if ($dispensa->encerrada()) {
            return back()->withErrors(['candidatura' => 'Esta dispensa já encerrou e não pode mais ser salva.']);
        }

        $request->user()->candidaturas()->firstOrCreate(['dispensa_id' => $dispensa->id]);

        return back()->with('status', 'Salva em Minhas candidaturas.');
    }

    public function update(Request $request, Candidatura $candidatura): RedirectResponse|JsonResponse
    {
        Gate::authorize('update', $candidatura);

        $dados = $request->validate([
            'status' => ['sometimes', 'required', Rule::enum(StatusCandidatura::class)],
            'notas' => ['sometimes', 'nullable', 'string', 'max:' . Candidatura::NOTAS_MAX],
        ]);
        if (array_key_exists('notas', $dados)) {
            $dados['notas'] = trim((string) $dados['notas']);
        }
        $candidatura->update($dados);

        return $request->expectsJson()
            ? response()->json(['status' => $candidatura->status->value, 'notas' => $candidatura->notas])
            : back()->with('status', 'Candidatura atualizada.');
    }

    public function destroy(Candidatura $candidatura): RedirectResponse
    {
        Gate::authorize('delete', $candidatura);
        $candidatura->delete();

        return back()->with('status', 'Candidatura removida.');
    }
}
```

O Laravel converte strings vazias em `null` nas requisições; por isso `notas` aceita `nullable` e é gravada como texto vazio.

Em `routes/web.php`, acrescente:

```php
use App\Http\Controllers\CandidaturaController;

Route::middleware('auth')->group(function () {
    // Visitante clica em "Salvar": passa pelo login e cai de volta na página da dispensa.
    Route::get('/dispensas/{dispensa}/entrar', fn (\App\Models\Dispensa $dispensa) => redirect()->route('dispensas.show', $dispensa))
        ->whereNumber('dispensa')->name('dispensas.entrar');

    Route::get('/candidaturas', [CandidaturaController::class, 'index'])->name('candidaturas.index');

    Route::middleware('throttle:candidaturas')->group(function () {
        Route::post('/dispensas/{dispensa}/candidatura', [CandidaturaController::class, 'store'])
            ->whereNumber('dispensa')->middleware('verified')->name('candidaturas.store');
        Route::patch('/candidaturas/{candidatura}', [CandidaturaController::class, 'update'])
            ->whereNumber('candidatura')->name('candidaturas.update');
        Route::delete('/candidaturas/{candidatura}', [CandidaturaController::class, 'destroy'])
            ->whereNumber('candidatura')->name('candidaturas.destroy');
    });
});
```

- [ ] **Step 6: Radar e página da dispensa sabem o que está salvo**

Em `app/Http/Controllers/RadarController.php`, no array passado para a view, acrescente:

```php
            'salvas' => $request->user()
                ? $request->user()->candidaturas()->whereIn('dispensa_id', $dispensas->pluck('id'))->pluck('dispensa_id')->all()
                : [],
```

Em `resources/views/radar.blade.php`, troque a linha do `@include` por:

```blade
    @include('partials.ficha', ['d' => $d, 'salva' => in_array($d->id, $salvas, true)])
```

Em `app/Http/Controllers/DispensaController.php`, substitua o método `show`:

```php
    public function show(Request $request, Dispensa $dispensa): View
    {
        return view('dispensas.show', [
            'd' => $dispensa,
            'salva' => (bool) $request->user()?->candidaturas()->where('dispensa_id', $dispensa->id)->exists(),
        ]);
    }
```

e acrescente `use Illuminate\Http\Request;` aos imports.

- [ ] **Step 7: Ficha com as ações de candidatura**

Substitua `resources/views/partials/ficha.blade.php` inteiro:

```blade
@php
    $p = $d->prazo();
    $cand = $cand ?? null;
    $salva = $salva ?? false;
@endphp
<article class="card {{ $cand ? 'st-' . $cand->status->value : 'fit-' . $d->fit->value }}">
  <div>
    <div class="meta"><span>{{ $d->orgao }}</span><span>{{ $d->municipio }}/{{ $d->uf }}</span><span>{{ $d->esfera }}</span></div>
    <h2 class="obj"><a href="{{ route('dispensas.show', $d) }}">{{ $d->objeto }}</a></h2>
    <p class="why">{{ $d->motivo }}</p>
    @if ($d->categorias)
      <div class="tags">
        @foreach ($d->categorias as $c)
          <span class="tag">{{ \App\Services\ClassificadorPf::CATEGORIAS[$c]['label'] ?? $c }}</span>
        @endforeach
      </div>
    @endif
  </div>
  <div class="side">
    <span class="valor {{ $d->valor ? '' : 'sigiloso' }}">{{ $d->valorTexto() }}</span>
    <span class="prazo {{ $p['cls'] }}">{{ $p['txt'] }}</span>
    @if ($cand)
      <span class="pill st-{{ $cand->status->value }}" data-pill>{{ $cand->status->label() }}</span>
    @else
      <span class="pill fit-{{ $d->fit->value }}">{{ $d->fit->label() }}</span>
    @endif
  </div>

  @if ($cand)
    <form class="track" method="POST" action="{{ route('candidaturas.update', $cand) }}" data-candidatura>
      @csrf @method('PATCH')
      <label class="f">Status
        <select name="status">
          @foreach (\App\Enums\StatusCandidatura::cases() as $s)
            <option value="{{ $s->value }}" @selected($cand->status === $s)>{{ $s->label() }}</option>
          @endforeach
        </select>
      </label>
      <label class="f grow">Notas
        <textarea name="notas" rows="2" maxlength="{{ \App\Models\Candidatura::NOTAS_MAX }}" placeholder="Documentos pendentes, valor da proposta, contato do órgão…">{{ $cand->notas }}</textarea>
      </label>
      <button type="submit" class="btn so-sem-js">Guardar</button>
    </form>
  @endif

  <div class="actions">
    @if ($d->urlPncp())<a href="{{ $d->urlPncp() }}" target="_blank" rel="noopener">Aviso no PNCP</a>@endif
    @if ($d->urlOrigem())<a href="{{ $d->urlOrigem() }}" target="_blank" rel="noopener">Enviar proposta</a>@endif
    <span class="spacer"></span>
    @if ($cand)
      <form method="POST" action="{{ route('candidaturas.destroy', $cand) }}" data-confirmar="Remover esta candidatura? As notas serão apagadas.">
        @csrf @method('DELETE')
        <button type="submit" class="btn danger">Remover</button>
      </form>
    @elseif ($salva)
      <a class="btn saved" href="{{ route('candidaturas.index') }}">✓ Salva</a>
    @elseif (! $d->encerrada())
      @auth
        <form method="POST" action="{{ route('candidaturas.store', $d) }}">
          @csrf
          <button type="submit" class="btn primary">Salvar</button>
        </form>
      @else
        <a class="btn primary" href="{{ route('dispensas.entrar', $d) }}" rel="nofollow">Salvar</a>
      @endauth
    @endif
  </div>
</article>
```

Em `resources/views/dispensas/show.blade.php`, substitua o bloco `<div class="actions">...</div>` por:

```blade
  @error('candidatura')<div class="flash erro-geral" style="grid-column:1/-1;margin:0">{{ $message }}</div>@enderror

  <div class="actions">
    @if ($d->urlPncp())<a href="{{ $d->urlPncp() }}" target="_blank" rel="noopener">Aviso no PNCP</a>@endif
    <span class="spacer"></span>
    @if ($salva)
      <a class="btn saved" href="{{ route('candidaturas.index') }}">✓ Salva</a>
    @elseif (! $d->encerrada())
      @auth
        <form method="POST" action="{{ route('candidaturas.store', $d) }}">
          @csrf
          <button type="submit" class="btn">Salvar</button>
        </form>
      @else
        <a class="btn" href="{{ route('dispensas.entrar', $d) }}" rel="nofollow">Salvar</a>
      @endauth
    @endif
    @if ($d->urlOrigem() && ! $d->encerrada())
      <a class="btn primary" href="{{ $d->urlOrigem() }}" target="_blank" rel="noopener">Enviar proposta no sistema oficial</a>
    @endif
  </div>
```

- [ ] **Step 8: Tela Minhas candidaturas**

`resources/views/candidaturas/index.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Minhas candidaturas · Radar de Dispensas PF')
@push('head')<meta name="robots" content="noindex">@endpush
@section('content')
<header class="top">
  <div class="brand"><div>
    <h1>Minhas candidaturas</h1>
    <p>Acompanhe as dispensas que você salvou. A proposta é enviada no sistema oficial de cada órgão.</p>
  </div></div>
</header>

<div class="bar">
  <div class="row">
    <a class="chip" href="{{ route('candidaturas.index') }}" aria-pressed="{{ $statusAtivo ? 'false' : 'true' }}">Todas <small>{{ $total }}</small></a>
    @foreach (\App\Enums\StatusCandidatura::cases() as $s)
      <a class="chip" href="{{ route('candidaturas.index', ['status' => $s->value]) }}" aria-pressed="{{ $statusAtivo === $s ? 'true' : 'false' }}">{{ $s->label() }} <small>{{ $contagem[$s->value] ?? 0 }}</small></a>
    @endforeach
  </div>
</div>

<div class="summary">
  <span class="stat"><b>{{ $emAndamento }}</b>em andamento</span>
  <span class="stat"><b>R$ {{ number_format($estimado, 2, ',', '.') }}</b>estimado em andamento</span>
  <span class="stat"><b>{{ $contagem['ganhei'] ?? 0 }}</b>ganhas</span>
</div>

<div class="list">
  @forelse ($candidaturas as $c)
    @include('partials.ficha', ['d' => $c->dispensa, 'cand' => $c])
  @empty
    @if ($total)
      <div class="state"><strong>Nada com este status</strong>Escolha outro filtro acima.</div>
    @else
      <div class="state"><strong>Nenhuma candidatura salva</strong>No <a href="{{ route('radar') }}">radar</a>, clique em "Salvar" nas dispensas que você quer disputar.</div>
    @endif
  @endforelse
</div>

<div class="toast" id="toast" role="status" aria-live="polite"></div>
@endsection
```

Acrescente ao final de `public/css/app.css` (links em formato de chip não devem parecer links):

```css
a.chip { text-decoration: none; }
.actions form { display: inline; }
```

- [ ] **Step 9: Gravação automática de status e notas**

Substitua `public/js/app.js` inteiro:

```js
/* Radar de Dispensas PF: melhorias progressivas. Tudo funciona sem este arquivo. */

// Filtros do radar: envia ao mudar um seletor, uma categoria ou uma opção de aderência.
const filtros = document.querySelector('form[data-auto-enviar]');
if (filtros) {
  filtros.addEventListener('change', e => {
    if (e.target.matches('select, input[type=checkbox]')) filtros.requestSubmit();
  });
}

// Confirmação antes de ações destrutivas.
document.addEventListener('submit', e => {
  const msg = e.target.dataset?.confirmar;
  if (msg && !confirm(msg)) e.preventDefault();
});

// Minhas candidaturas: grava status e notas ao alterar, sem recarregar a página.
let toastTimer;
function toast(msg, erro = false) {
  const t = document.getElementById('toast');
  if (!t) return;
  t.textContent = msg;
  t.className = 'toast show' + (erro ? ' err' : '');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { t.className = 'toast'; }, erro ? 5000 : 2200);
}

const csrf = document.querySelector('meta[name=csrf-token]')?.content;
document.querySelectorAll('form[data-candidatura]').forEach(form => {
  form.addEventListener('submit', e => e.preventDefault());
  form.addEventListener('change', async e => {
    const campo = e.target.name;
    if (campo !== 'status' && campo !== 'notas') return;
    try {
      const r = await fetch(form.action, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ [campo]: e.target.value }),
      });
      if (r.status === 419) throw new Error('A sessão expirou. Recarregue a página.');
      if (r.status === 429) throw new Error('Muitas alterações em pouco tempo. Aguarde um minuto.');
      const j = await r.json().catch(() => null);
      if (!r.ok) throw new Error(j?.message || 'Não foi possível salvar.');
      if (campo === 'status') {
        const card = form.closest('.card');
        const pill = card.querySelector('[data-pill]');
        card.className = card.className.replace(/\bst-\w+/, 'st-' + j.status);
        pill.className = 'pill st-' + j.status;
        pill.textContent = e.target.selectedOptions[0].textContent.trim();
      }
      toast(campo === 'status' ? 'Status atualizado.' : 'Notas salvas.');
    } catch (err) {
      toast(err.message, true);
    }
  });
});
```

- [ ] **Step 10: Rodar e ver passar**

```bash
docker compose exec -u www-data app php artisan migrate
docker compose exec -u www-data app php artisan test --filter=CandidaturasTest
```

Expected: PASS, 18 testes.

Run: `docker compose exec -u www-data app php artisan test`
Expected: PASS em tudo.

- [ ] **Step 11: Conferir no navegador**

Com a conta confirmada da Task 2: salve uma dispensa no radar, abra **Minhas candidaturas**, mude o status (a faixa colorida e a etiqueta mudam sem recarregar), escreva uma nota e saia do campo (aparece "Notas salvas."), recarregue e confira que ficou, e remova. Saia da conta, clique em "Salvar" numa ficha e confira que, depois do login, você cai na página daquela dispensa.

- [ ] **Step 12: Commit**

```bash
git add -A
git commit -m "Adiciona candidaturas por usuário com status, notas e limite de gravações

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Login com Google

**Files:**
- Create: `database/migrations/2026_10_12_000004_add_google_to_users_table.php`
- Create: `app/Http/Controllers/Auth/GoogleController.php`
- Modify: `config/services.php`, `routes/web.php`, `resources/views/auth/login.blade.php`, `resources/views/auth/register.blade.php`
- Test: `tests/Feature/LoginGoogleTest.php`

**Interfaces:**
- Consumes: `User` (Task 2); tabela `sessions` do Laravel.
- Produces: coluna `users.google_id` (texto, único, nulo) e `users.password` aceitando nulo; rotas `google.redirect` (GET `/auth/google`) e `google.callback` (GET `/auth/google/callback`).

- [ ] **Step 1: Instalar o Socialite e configurar o serviço**

```bash
docker compose exec -u www-data app composer require laravel/socialite
```

Em `config/services.php`, acrescente ao array:

```php
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', rtrim((string) env('APP_URL'), '/') . '/auth/google/callback'),
    ],
```

- [ ] **Step 2: Escrever os testes (falhando)**

`tests/Feature/LoginGoogleTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class LoginGoogleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => 'id-de-teste', 'services.google.client_secret' => 'segredo']);
    }

    private function googleDevolve(string $id, string $email, ?string $nome = 'Ana Souza'): void
    {
        $perfil = (new GoogleUser)->map(['id' => $id, 'name' => $nome, 'email' => $email]);
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($perfil);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_primeiro_login_cria_conta_confirmada_e_sem_senha(): void
    {
        $this->googleDevolve('g-1', 'ana@example.com');

        $this->get('/auth/google/callback')->assertRedirect('/');

        $user = User::where('email', 'ana@example.com')->firstOrFail();
        $this->assertSame('g-1', $user->google_id);
        $this->assertSame('Ana Souza', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->password);
        $this->assertAuthenticatedAs($user);
    }

    public function test_perfil_sem_nome_usa_a_parte_local_do_email(): void
    {
        $this->googleDevolve('g-1', 'ana.souza@example.com', null);

        $this->get('/auth/google/callback');

        $this->assertSame('ana.souza', User::firstOrFail()->name);
    }

    public function test_login_seguinte_entra_na_mesma_conta_mesmo_se_o_email_mudou_no_google(): void
    {
        $user = User::factory()->create(['google_id' => 'g-1', 'email' => 'antigo@example.com']);
        $this->googleDevolve('g-1', 'novo@example.com');

        $this->get('/auth/google/callback');

        $this->assertSame(1, User::count());
        $this->assertAuthenticatedAs($user);
        $this->assertSame('antigo@example.com', $user->fresh()->email);
    }

    public function test_vincula_a_conta_confirmada_com_o_mesmo_email_e_mantem_a_senha(): void
    {
        $user = User::factory()->create(['email' => 'ana@example.com', 'password' => Hash::make('senha-dela-1')]);
        $this->googleDevolve('g-1', 'ana@example.com');

        $this->get('/auth/google/callback');

        $this->assertSame(1, User::count());
        $this->assertSame('g-1', $user->fresh()->google_id);
        $this->assertTrue(Hash::check('senha-dela-1', $user->fresh()->password));
        $this->assertAuthenticatedAs($user);
    }

    public function test_vincular_a_conta_nao_confirmada_anula_a_senha_e_encerra_as_sessoes(): void
    {
        // Um terceiro cadastrou o e-mail da Ana com uma senha que ele conhece e nunca confirmou.
        $user = User::factory()->unverified()->create(['email' => 'ana@example.com', 'password' => Hash::make('senha-do-terceiro')]);
        DB::table('sessions')->insert([
            'id' => 'sessao-do-terceiro', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time(),
        ]);
        $this->googleDevolve('g-1', 'ana@example.com');

        $this->get('/auth/google/callback');

        $user->refresh();
        $this->assertNull($user->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('g-1', $user->google_id);
        $this->assertDatabaseMissing('sessions', ['id' => 'sessao-do-terceiro']);

        $this->post('/logout');
        $this->post('/login', ['email' => 'ana@example.com', 'password' => 'senha-do-terceiro']);
        $this->assertGuest();
    }

    public function test_falha_no_google_volta_para_o_login_com_mensagem(): void
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andThrow(new RuntimeException('access_denied'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_google_sem_email_nao_cria_conta(): void
    {
        $this->googleDevolve('g-1', '');

        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertSame(0, User::count());
    }

    public function test_volta_para_a_pagina_que_pediu_o_login(): void
    {
        $this->googleDevolve('g-1', 'ana@example.com');

        $this->withSession(['url.intended' => url('/dispensas/7/entrar')])
            ->get('/auth/google/callback')
            ->assertRedirect('/dispensas/7/entrar');
    }

    public function test_sem_credenciais_o_botao_some_e_as_rotas_respondem_404(): void
    {
        config(['services.google.client_id' => null]);

        $this->get('/login')->assertOk()->assertDontSee('Entrar com Google');
        $this->get('/auth/google')->assertNotFound();
        $this->get('/auth/google/callback')->assertNotFound();
    }

    public function test_com_credenciais_o_botao_aparece_no_login_e_no_cadastro(): void
    {
        $this->get('/login')->assertSee('Entrar com Google');
        $this->get('/register')->assertSee('Entrar com Google');
    }

    public function test_usuario_logado_nao_acessa_o_fluxo_do_google(): void
    {
        $this->actingAs(User::factory()->create())->get('/auth/google/callback')->assertRedirect('/');
    }
}
```

- [ ] **Step 3: Rodar e ver falhar**

Run: `docker compose exec -u www-data app php artisan test --filter=LoginGoogleTest`
Expected: FAIL com 404 em `/auth/google/callback`.

- [ ] **Step 4: Migration**

`database/migrations/2026_10_12_000004_add_google_to_users_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
            $table->string('google_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('google_id');
        });
    }
};
```

O `down` não volta `password` para obrigatório, porque contas criadas pelo Google ficariam inválidas.

- [ ] **Step 5: Controller e rotas**

`app/Http/Controllers/Auth/GoogleController.php`:

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

class GoogleController extends Controller
{
    public function redirect(): SymfonyRedirect
    {
        abort_unless(config('services.google.client_id'), 404);

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless(config('services.google.client_id'), 404);

        try {
            $perfil = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            Log::info('Login Google não concluído', ['erro' => $e->getMessage()]);

            return $this->falha();
        }

        $googleId = (string) $perfil->getId();
        $email = Str::lower(trim((string) $perfil->getEmail()));
        if ($googleId === '' || $email === '') {
            return $this->falha();
        }

        $user = User::where('google_id', $googleId)->first() ?? User::where('email', $email)->first();

        if ($user === null) {
            $user = new User;
            $user->forceFill([
                'name' => trim((string) $perfil->getName()) ?: Str::before($email, '@'),
                'email' => $email,
                'google_id' => $googleId,
                'email_verified_at' => now(),
                'password' => null,
            ])->save();
        } elseif ($user->google_id === null) {
            // Conta por e-mail nunca confirmada pode ter sido criada por outra pessoa:
            // quem prova ser dono do e-mail pelo Google fica com ela, e a senha e as sessões antigas deixam de valer.
            if (! $user->hasVerifiedEmail()) {
                $user->password = null;
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            $user->forceFill(['google_id' => $googleId, 'email_verified_at' => $user->email_verified_at ?? now()])->save();
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    private function falha(): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => 'Não foi possível entrar com o Google. Tente de novo ou use e-mail e senha.']);
    }
}
```

Em `routes/web.php`, acrescente:

```php
use App\Http\Controllers\Auth\GoogleController;

Route::middleware('guest')->group(function () {
    Route::get('/auth/google', [GoogleController::class, 'redirect'])->name('google.redirect');
    Route::get('/auth/google/callback', [GoogleController::class, 'callback'])->name('google.callback');
});
```

- [ ] **Step 6: Botão nas telas de login e cadastro**

Em `resources/views/auth/login.blade.php` e em `resources/views/auth/register.blade.php`, logo depois do `</form>` do formulário principal, acrescente:

```blade
  @if (config('services.google.client_id'))
    <hr>
    <a class="btn google" href="{{ route('google.redirect') }}">Entrar com Google</a>
  @endif
```

- [ ] **Step 7: Rodar e ver passar**

```bash
docker compose exec -u www-data app php artisan migrate
docker compose exec -u www-data app php artisan test --filter=LoginGoogleTest
```

Expected: PASS, 11 testes.

Run: `docker compose exec -u www-data app php artisan test`
Expected: PASS em tudo.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "Adiciona login com Google, vinculando a contas existentes pelo e-mail

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Integração contínua e README

**Files:**
- Create: `.github/workflows/testes.yml`
- Modify: `README.md` (substitui o atual)

**Interfaces:**
- Consumes: a suíte completa das Tasks 1 a 8.
- Produces: workflow `Testes`, que roda em todo push e pull request.

- [ ] **Step 1: Workflow**

`.github/workflows/testes.yml`:

```yaml
name: Testes

on:
  push:
  pull_request:

jobs:
  testes:
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgres:16-alpine
        env:
          POSTGRES_DB: radar_test
          POSTGRES_USER: radar
          POSTGRES_PASSWORD: radar
        ports:
          - 5432:5432
        options: >-
          --health-cmd "pg_isready -U radar -d radar_test"
          --health-interval 5s
          --health-timeout 5s
          --health-retries 10
    env:
      DB_HOST: 127.0.0.1
      DB_PORT: 5432
      DB_USERNAME: radar
      DB_PASSWORD: radar
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: pdo_pgsql, mbstring, zip
          coverage: none
      - run: composer install --no-interaction --prefer-dist
      - run: cp .env.example .env && php artisan key:generate
      - run: php artisan test
```

- [ ] **Step 2: README**

Substitua `README.md` inteiro:

````markdown
# Radar de Dispensas PF

Mostra as dispensas de licitação com propostas abertas no [PNCP](https://pncp.gov.br), classifica quais uma pessoa física consegue executar sem CNPJ e permite que cada pessoa crie uma conta para salvar dispensas e acompanhar as suas candidaturas. A proposta é sempre enviada no sistema oficial de cada órgão; o site só direciona.

## Como rodar

Requisitos: Docker com o plugin Compose.

```bash
cp .env.example .env
docker compose up -d --build
```

Abra **http://localhost:8081**. A primeira subida instala as dependências, cria as tabelas e busca as dispensas no PNCP, o que leva cerca de dois minutos.

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

- **Sincronização:** o comando `pncp:sync` busca as dispensas do Brasil todo que encerram em até 90 dias, classifica cada uma e grava na tabela `dispensas`. Uma dispensa que o PNCP para de devolver sai do radar depois de 24 horas.
- **Classificação:** `app/Services/ClassificadorPf.php` é uma heurística sobre o texto do aviso. Ela não substitui a leitura do aviso oficial.
- **Radar:** público. Os filtros ficam na URL. Só o radar sem filtro e o radar por UF são indexados pelos buscadores.
- **Contas:** e-mail e senha (com confirmação de e-mail) ou Google. Só contas confirmadas salvam dispensas.
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
````

- [ ] **Step 3: Verificação final do ambiente do zero**

```bash
docker compose down -v
docker compose up -d --build
```

Run: `docker compose ps --format '{{.Name}} {{.Status}}'`
Expected: `app` e `db` como `healthy`, `scheduler` e `mailpit` de pé.

Run: `docker compose logs scheduler | grep -c "dispensas sincronizadas"`
Expected: `1` ou mais, depois de cerca de dois minutos.

Run: `docker compose exec -u www-data app php artisan test`
Expected: PASS em toda a suíte.

Run: `curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8081/ && curl -s http://localhost:8081/sitemap.xml | grep -c "<loc>"`
Expected: `200` e um número maior que 28.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "Adiciona testes no GitHub Actions e atualiza o README para o Laravel

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

O push e o pull request ficam para quando o dono do projeto pedir. O workflow só roda depois do push.

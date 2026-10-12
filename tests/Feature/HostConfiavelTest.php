<?php

namespace Tests\Feature;

use App\Console\Commands\SincronizarPncp;
use App\Models\Dispensa;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class HostConfiavelTest extends TestCase
{
    use RefreshDatabase;

    private const HOST_FALSO = 'evil.example';

    public function test_sitemap_nunca_usa_o_host_da_requisicao(): void
    {
        $resposta = $this->get('http://' . self::HOST_FALSO . '/sitemap.xml')->assertOk();

        $this->assertStringNotContainsString(self::HOST_FALSO, $resposta->getContent());
        $this->assertStringContainsString('<loc>' . rtrim(config('app.url'), '/') . '</loc>', $resposta->getContent());
    }

    public function test_robots_nunca_usa_o_host_da_requisicao(): void
    {
        $resposta = $this->get('http://' . self::HOST_FALSO . '/robots.txt')->assertOk();

        $this->assertStringNotContainsString(self::HOST_FALSO, $resposta->getContent());
        $this->assertStringContainsString('Sitemap: ' . rtrim(config('app.url'), '/') . '/sitemap.xml', $resposta->getContent());
    }

    public function test_pagina_inicial_nao_espalha_o_host_da_requisicao(): void
    {
        $this->assertStringNotContainsString(self::HOST_FALSO, $this->get('http://' . self::HOST_FALSO . '/')->getContent());
    }

    public function test_links_de_paginacao_nao_seguem_o_host_da_requisicao(): void
    {
        Cache::forever(SincronizarPncp::CHAVE_ULTIMA, now()->toIso8601String());
        Dispensa::factory()->count(31)->create();

        $html = $this->get('http://' . self::HOST_FALSO . '/')->assertOk()->getContent();

        $this->assertStringContainsString('page=2', $html);
        $this->assertStringNotContainsString(self::HOST_FALSO, $html);
    }

    public function test_link_de_redefinicao_de_senha_usa_o_host_do_app_mesmo_com_host_forjado(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post('http://' . self::HOST_FALSO . '/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user) {
            $url = $n->toMail($user)->actionUrl;

            return str_starts_with($url, rtrim(config('app.url'), '/') . '/reset-password/')
                && ! str_contains($url, self::HOST_FALSO);
        });
    }
}

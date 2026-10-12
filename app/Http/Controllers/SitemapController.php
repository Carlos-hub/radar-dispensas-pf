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

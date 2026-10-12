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

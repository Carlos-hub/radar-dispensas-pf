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

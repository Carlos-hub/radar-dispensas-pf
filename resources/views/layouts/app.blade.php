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

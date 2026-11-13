<header class="page-header">
    <div>
        <p class="eyebrow">CONTROLE DE MATERIAIS</p>
        <h1>{{ $title }}</h1>
        <p class="page-subtitle">{{ $subtitle }}</p>
    </div>
    <nav class="primary-nav" aria-label="Navegação principal">
        <a
            class="primary-nav-link{{ $activePage === 'materials' ? ' is-active' : '' }}"
            href="{{ route('materials.index') }}"
            @if ($activePage === 'materials') aria-current="page" @endif
        >Painel de materiais</a>
        <a
            class="primary-nav-link{{ $activePage === 'scraps' ? ' is-active' : '' }}"
            href="{{ route('materials.scraps.index') }}"
            @if ($activePage === 'scraps') aria-current="page" @endif
        >Apontamento de sobra</a>
        <a
            class="primary-nav-link{{ $activePage === 'dashboard' ? ' is-active' : '' }}"
            href="{{ route('dashboard') }}"
            @if ($activePage === 'dashboard') aria-current="page" @endif
        >Dashboard</a>
    </nav>
    <div class="timezone-badge">Horários em America/Sao_Paulo</div>
</header>

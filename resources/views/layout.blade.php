<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Slipstream') — {{ config('app.name', 'Laravel') }}</title>
    <link rel="icon" href="{{ asset('vendor/slipstream/favicon.png') }}" type="image/png" sizes="32x32">
    <link rel="icon" href="{{ asset('vendor/slipstream/favicon-16.png') }}" type="image/png" sizes="16x16">
    <link rel="apple-touch-icon" href="{{ asset('vendor/slipstream/apple-touch-icon.png') }}">
    {{-- Apply the stored theme before paint to avoid a flash. Default is light. --}}
    <script>
        (function () {
            try {
                var t = localStorage.getItem('slipstream-theme');
                if (t === 'dark' || t === 'light') {
                    document.documentElement.setAttribute('data-theme', t);
                }
            } catch (e) {}
        })();
    </script>
    <link rel="stylesheet" href="{{ asset('vendor/slipstream/app.css') }}">
    @livewireStyles
</head>
<body class="ss-body">
    <div class="ss-topstrip"></div>

    <header class="ss-topbar">
        <div class="ss-topbar-inner">
            <div class="ss-topbar-left">
                <a href="{{ route('slipstream.dashboard') }}" class="ss-brand">
                    <span class="ss-brand-mark" aria-hidden="true">
                        <img src="{{ asset('vendor/slipstream/slipstream-icon.png') }}" alt="" width="36" height="26">
                    </span>
                    <span class="ss-brand-text">Slipstream</span>
                </a>
                <nav class="ss-nav" aria-label="Dashboard sections">
                    @if(config('slipstream.views.deployments', true))
                        <a href="{{ route('slipstream.deployments') }}" class="ss-nav-link {{ in_array(($page ?? ''), ['deployments', 'deployment'], true) ? 'is-active' : '' }}">Deployments</a>
                    @endif
                    @if(config('slipstream.views.servers', true))
                        <a href="{{ route('slipstream.servers') }}" class="ss-nav-link {{ ($page ?? '') === 'servers' ? 'is-active' : '' }}">Servers</a>
                    @endif
                    @if(config('slipstream.views.pipelines', true))
                        <a href="{{ route('slipstream.pipelines') }}" class="ss-nav-link {{ ($page ?? '') === 'pipelines' ? 'is-active' : '' }}">Pipelines</a>
                    @endif
                    @if(config('slipstream.views.hooks', true))
                        <a href="{{ route('slipstream.hooks') }}" class="ss-nav-link {{ ($page ?? '') === 'hooks' ? 'is-active' : '' }}">Hooks</a>
                    @endif
                    @if(config('slipstream.views.webhook', true))
                        <a href="{{ route('slipstream.webhook') }}" class="ss-nav-link {{ ($page ?? '') === 'webhook' ? 'is-active' : '' }}">Webhook</a>
                    @endif
                    @if(config('slipstream.views.environment', true))
                        <a href="{{ route('slipstream.environment') }}" class="ss-nav-link {{ ($page ?? '') === 'environment' ? 'is-active' : '' }}">Environment</a>
                    @endif
                </nav>
            </div>
            <div class="ss-topbar-actions">
                <button type="button" class="ss-theme-toggle" onclick="slipstreamToggleTheme()" title="Toggle light / dark" aria-label="Toggle light or dark theme">
                    <svg class="ss-icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                    <svg class="ss-icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>
                </button>
            </div>
        </div>
    </header>

    @livewire('slipstream.project-chrome')

    <main class="ss-main">
        @yield('content')
    </main>

    <script>
        function slipstreamToggleTheme() {
            var current = document.documentElement.getAttribute('data-theme') || 'light';
            var next = current === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            try { localStorage.setItem('slipstream-theme', next); } catch (e) {}
        }
    </script>
    @livewireScripts
</body>
</html>

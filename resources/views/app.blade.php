<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'dark') !== 'light'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Dark-first product: only an explicit "light" preference removes dark mode --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "dark" }}';

                if (appearance !== 'light') {
                    document.documentElement.classList.add('dark');
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: #f4f6fb;
            }

            html.dark {
                background-color: #06060b;
            }
        </style>

        <title inertia>{{ config('app.name', 'AI Werewolf') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Permanent+Marker&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet" />

        @php
            $pusher = config('broadcasting.connections.pusher');
        @endphp
        <script>
            window.__SOKETI__ = {
                key: @json($pusher['key']),
                host: @json($pusher['client_host'] ?? $pusher['options']['host']),
                port: @json($pusher['options']['port']),
                scheme: @json($pusher['options']['scheme']),
                cluster: @json($pusher['options']['cluster']),
            };
        </script>
        @vite(['resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>

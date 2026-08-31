<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>API documentation &middot; {{ config('app.name') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">

        {{-- Swagger UI brings its own stylesheet and fights the app's, so this
             page stands on its own rather than sitting inside the layout. --}}
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui.css" crossorigin="anonymous">

        <style>
            body { margin: 0; background: #fff; }
            .docs-bar { display: flex; align-items: center; justify-content: space-between; padding: 0.75rem 1.25rem; border-bottom: 1px solid #e4e4e7; font: 500 0.875rem/1.25rem system-ui, sans-serif; color: #3f3f46; }
            .docs-bar a { color: inherit; text-decoration: none; }
            .docs-bar a:hover { text-decoration: underline; }
            .swagger-ui .topbar { display: none; }
        </style>
    </head>

    <body>
        <div class="docs-bar">
            <a href="{{ route('settings') }}">&larr; Back to settings</a>
            <a href="{{ route('docs.spec') }}">openapi.yaml</a>
        </div>

        <div id="swagger-ui"></div>

        <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui-bundle.js" crossorigin="anonymous"></script>
        <script>
            SwaggerUIBundle({
                url: @json(route('docs.spec')),
                dom_id: '#swagger-ui',
                deepLinking: true,
                persistAuthorization: true,
                tryItOutEnabled: true,
                displayRequestDuration: true,
            });
        </script>
    </body>
</html>

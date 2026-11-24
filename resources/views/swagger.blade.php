<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rush API — Swagger</title>
    <link rel="stylesheet" href="/vendor/swagger-ui/swagger-ui.css">
    <style>
        body { margin: 0; background: #fafafa; }
        .topbar { display: none !important; }
        #swagger-error {
            display: none;
            max-width: 720px;
            margin: 48px auto;
            padding: 20px 24px;
            border-radius: 12px;
            background: #fff1f0;
            color: #a8071a;
            font-family: system-ui, sans-serif;
            line-height: 1.5;
        }
    </style>
</head>
<body>
    <div id="swagger-error"></div>
    <div id="swagger-ui"></div>
    <script src="/vendor/swagger-ui/swagger-ui-bundle.js"></script>
    <script src="/vendor/swagger-ui/swagger-ui-standalone-preset.js"></script>
    <script>
        (function () {
            var errBox = document.getElementById('swagger-error');
            function showError(msg) {
                errBox.style.display = 'block';
                errBox.textContent = msg;
            }

            if (typeof SwaggerUIBundle === 'undefined') {
                showError('Swagger UI failed to load assets from /vendor/swagger-ui/.');
                return;
            }

            try {
                window.ui = SwaggerUIBundle({
                    url: '/docs/openapi.yaml',
                    dom_id: '#swagger-ui',
                    deepLinking: true,
                    docExpansion: 'list',
                    defaultModelsExpandDepth: 1,
                    tryItOutEnabled: true,
                    validatorUrl: null,
                    presets: [
                        SwaggerUIBundle.presets.apis,
                        SwaggerUIStandalonePreset
                    ],
                    plugins: [
                        SwaggerUIBundle.plugins.DownloadUrl
                    ],
                    layout: 'StandaloneLayout'
                });
            } catch (e) {
                showError('Swagger UI crashed: ' + (e && e.message ? e.message : String(e)));
            }
        })();
    </script>
</body>
</html>

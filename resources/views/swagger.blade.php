<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }}</title>
    <link rel="stylesheet" href="{{ $cdn['base'] }}/{{ $cdn['version'] }}/swagger-ui.min.css" integrity="{{ $cdn['css_integrity'] }}" crossorigin="anonymous" referrerpolicy="no-referrer">
    <style>body { margin: 0; }</style>
</head>
<body>
<div id="swagger-ui"></div>
<script src="{{ $cdn['base'] }}/{{ $cdn['version'] }}/swagger-ui-bundle.min.js" integrity="{{ $cdn['js_integrity'] }}" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
    window.ui = SwaggerUIBundle({
        url: @json($specUrl),
        dom_id: '#swagger-ui',
        deepLinking: true,
        persistAuthorization: false,
        withCredentials: true,
    });
</script>
</body>
</html>

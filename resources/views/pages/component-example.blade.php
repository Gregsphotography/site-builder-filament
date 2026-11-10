<!doctype html>
<html lang="{{ $locale }}" dir="{{ config('site-builder.locales.'.$locale.'.dir', 'ltr') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; font-src 'self' data:; form-action 'none'; base-uri 'none'; object-src 'none'">
    @if ($styleUrl)<link rel="stylesheet" href="{{ $styleUrl }}">@endif
    <style>
        html, body { margin: 0; min-height: 100%; }
        body { background: #fff; }
        a, button, input, select, textarea, summary { pointer-events: none; }
    </style>
</head>
<body>
    <div data-site-builder-theme="minimal" data-site-builder-theme-version="1">
        <main class="sb-main" data-site-builder-canvas>{{ $content }}</main>
    </div>
</body>
</html>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $shareText }}">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Fitness 365 Pro">
    <meta property="og:title" content="{{ $eventTitle }} — Fitness 365 Pro">
    <meta property="og:description" content="{{ $shareText }}">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    @if($imageUrl)
        <meta property="og:image" content="{{ $imageUrl }}">
        <meta property="og:image:secure_url" content="{{ $imageUrl }}">
        <meta property="og:image:alt" content="{{ $eventTitle }} event cover">
    @endif

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $eventTitle }} — Fitness 365 Pro">
    <meta name="twitter:description" content="{{ $shareText }}">
    @if($imageUrl)
        <meta name="twitter:image" content="{{ $imageUrl }}">
    @endif

    <link rel="canonical" href="{{ $canonicalUrl }}">
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0;
            font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
        }
        .card {
            width: min(100%, 440px);
            background: #111827;
            border: 1px solid #334155;
            border-radius: 16px;
            padding: 24px;
            text-align: center;
            box-shadow: 0 20px 40px rgba(0,0,0,.35);
        }
        .cover {
            width: 100%;
            max-height: 200px;
            object-fit: cover;
            border-radius: 12px;
            margin-bottom: 14px;
        }
        h1 { margin: 0 0 8px; font-size: 1.4rem; }
        p { margin: 0 0 10px; color: #94a3b8; line-height: 1.5; }
        .pill {
            display: inline-block;
            margin-bottom: 10px;
            padding: 4px 10px;
            border-radius: 999px;
            background: rgba(37, 99, 235, .2);
            color: #93c5fd;
            font-size: .75rem;
            font-weight: 700;
        }
        a.btn {
            display: inline-block;
            margin-top: 12px;
            padding: 10px 16px;
            border-radius: 10px;
            background: #2563eb;
            color: #fff;
            text-decoration: none;
            font-weight: 700;
        }
    </style>
</head>
<body>
    <main class="card">
        <div class="pill">Fitness 365 Pro Event</div>
        @if($imageUrl)
            <img class="cover" src="{{ $imageUrl }}" alt="{{ $eventTitle }}">
        @endif
        <h1>{{ $eventTitle }}</h1>
        <p>{{ $location }}</p>
        <p>{{ $timeline }} · {{ $feeLabel }}</p>
        <p>{{ $shareText }}</p>
        <a class="btn" href="{{ $clientAppUrl }}">View event &amp; register</a>
    </main>
</body>
</html>

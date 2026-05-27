<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $shareText }}">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Fitness 365 Pro">
    <meta property="og:title" content="{{ $badgeTitle }} — {{ $ownerName }}">
    <meta property="og:description" content="{{ $shareText }}">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    @if($imageUrl)
        <meta property="og:image" content="{{ $imageUrl }}">
        <meta property="og:image:secure_url" content="{{ $imageUrl }}">
        <meta property="og:image:alt" content="{{ $badgeTitle }} badge">
    @endif

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $badgeTitle }} — {{ $ownerName }}">
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
            width: min(100%, 420px);
            background: #111827;
            border: 1px solid #334155;
            border-radius: 16px;
            padding: 24px;
            text-align: center;
            box-shadow: 0 20px 40px rgba(0,0,0,.35);
        }
        .badge {
            width: 140px;
            height: 140px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #f97316;
            margin-bottom: 14px;
        }
        h1 { margin: 0 0 8px; font-size: 1.35rem; }
        p { margin: 0 0 12px; color: #94a3b8; line-height: 1.5; }
        .verified {
            display: inline-block;
            margin-bottom: 10px;
            padding: 4px 10px;
            border-radius: 999px;
            background: rgba(34,197,94,.15);
            color: #86efac;
            font-size: .75rem;
            font-weight: 700;
        }
        a.btn {
            display: inline-block;
            margin-top: 8px;
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
        <div class="verified">Verified achievement</div>
        @if($imageUrl)
            <img class="badge" src="{{ $imageUrl }}" alt="{{ $badgeTitle }}">
        @endif
        <h1>{{ $badgeTitle }}</h1>
        <p>{{ $eventTitle }}</p>
        <p>{{ $shareText }}</p>
        <a class="btn" href="{{ $clientAppUrl }}">View in Fitness 365 Pro</a>
    </main>
</body>
</html>

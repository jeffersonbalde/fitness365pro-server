<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $ogDescription }}">

    @include('share._og-meta', [
        'ogTitle' => $ogTitle,
        'ogDescription' => $ogDescription,
        'canonicalUrl' => $canonicalUrl,
        'imageUrl' => $imageUrl,
        'ogImageAlt' => $ogImageAlt ?? $ogTitle,
        'ogImageType' => $ogImageType ?? 'image/png',
    ])

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
            width: min(100%, 480px);
            background: #111827;
            border: 1px solid #334155;
            border-radius: 16px;
            padding: 24px;
            text-align: center;
            box-shadow: 0 20px 40px rgba(0,0,0,.35);
        }
        .cover {
            width: 100%;
            border-radius: 12px;
            margin-bottom: 14px;
        }
        .pill {
            display: inline-block;
            margin-bottom: 10px;
            padding: 4px 10px;
            border-radius: 999px;
            background: rgba(249, 115, 22, .2);
            color: #fdba74;
            font-size: .75rem;
            font-weight: 700;
        }
        .rank {
            font-size: 2.5rem;
            font-weight: 800;
            color: #f97316;
            margin: 0 0 8px;
        }
        h1 { margin: 0 0 8px; font-size: 1.35rem; }
        p { margin: 0 0 10px; color: #94a3b8; line-height: 1.5; }
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
        <div class="pill">Fitness 365 Pro Leaderboard</div>
        @if($imageUrl)
            <img class="cover" src="{{ $imageUrl }}" alt="{{ $ogImageAlt }}">
        @endif
        <div class="rank">#{{ $rank }}</div>
        <h1>{{ $displayName }}</h1>
        <p>{{ $eventTitle }}</p>
        @if($categoryLabel && $categoryLabel !== 'General')
            <p>{{ $categoryLabel }}</p>
        @endif
        <p>{{ $statsLine }}</p>
        <p>{{ $shareText }}</p>
        <a class="btn" href="{{ $clientAppUrl }}">View leaderboard</a>
    </main>
    <script>
        (function () {
            var ua = navigator.userAgent || '';
            if (/facebookexternalhit|Facebot|Twitterbot|LinkedInBot|WhatsApp|Slackbot|Discordbot/i.test(ua)) {
                return;
            }
            setTimeout(function () {
                window.location.replace(@json($clientAppUrl));
            }, 400);
        })();
    </script>
</body>
</html>

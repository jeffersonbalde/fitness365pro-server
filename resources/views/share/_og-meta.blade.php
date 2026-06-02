@php
    /** @var string $ogTitle */
    /** @var string $ogDescription */
    /** @var string $canonicalUrl */
    /** @var string|null $imageUrl */
    $fbAppId = \App\Support\ShareOpenGraph::facebookAppId();
@endphp
<meta property="og:type" content="website">
<meta property="og:site_name" content="Fitness 365 Pro">
<meta property="og:title" content="{{ $ogTitle }}">
<meta property="og:description" content="{{ $ogDescription }}">
<meta property="og:url" content="{{ $canonicalUrl }}">
@if($imageUrl)
    <meta property="og:image" content="{{ $imageUrl }}">
    <meta property="og:image:secure_url" content="{{ $imageUrl }}">
    <meta property="og:image:alt" content="{{ $ogImageAlt ?? $ogTitle }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:type" content="image/jpeg">
@endif
@if($fbAppId !== '')
    <meta property="fb:app_id" content="{{ $fbAppId }}">
@endif
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $ogTitle }}">
<meta name="twitter:description" content="{{ $ogDescription }}">
@if($imageUrl)
    <meta name="twitter:image" content="{{ $imageUrl }}">
@endif

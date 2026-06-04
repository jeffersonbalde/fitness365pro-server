<?php

namespace App\Support;

/**
 * Professional 1200×630 leaderboard share card as SVG (converted to PNG when Imagick is available).
 */
final class LeaderboardShareCardSvgBuilder
{
    private const W = 1200;

    private const H = 630;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function build(array $payload): string
    {
        $displayName = $this->escape($this->truncate((string) ($payload['display_name'] ?? 'Athlete'), 32));
        $eventTitle = $this->escape($this->truncate((string) ($payload['event_title'] ?? 'Fitness Event'), 48));
        $rank = max(1, (int) ($payload['rank'] ?? 1));
        $categoryLabel = (string) ($payload['category_label'] ?? '');
        $progress = is_array($payload['progress'] ?? null) ? $payload['progress'] : [];
        $logged = (float) ($progress['logged_distance_km'] ?? 0);
        $percent = $progress['progress_percent'] ?? null;
        $goalCompleted = (bool) ($progress['goal_completed'] ?? false);
        $tier = match ($rank) {
            1 => 'gold',
            2 => 'silver',
            3 => 'bronze',
            default => 'default',
        };

        [$rankGradA, $rankGradB] = match ($tier) {
            'gold' => ['#fde68a', '#f59e0b'],
            'silver' => ['#f1f5f9', '#94a3b8'],
            'bronze' => ['#fdba74', '#d97706'],
            default => ['#93c5fd', '#2563eb'],
        };

        $rankLabel = match ($rank) {
            1 => '1st Place',
            2 => '2nd Place',
            3 => '3rd Place',
            default => "#{$rank} Place",
        };

        $statsPrimary = number_format($logged, 1).' km logged';
        $statsSecondary = $goalCompleted
            ? 'Goal completed'
            : ($percent !== null ? number_format((float) $percent, 1).'% of goal' : 'Live ranking');

        $coverUrl = ShareOpenGraph::absoluteImageUrl((string) ($payload['event_image_url'] ?? ''));
        $hasCover = $coverUrl !== '' && $coverUrl !== ShareOpenGraph::defaultImageUrl();
        $coverBlock = $hasCover
            ? $this->bannerCoverBlock($coverUrl)
            : '<rect x="0" y="0" width="1200" height="248" fill="#1e293b"/>';

        $categoryBlock = ($categoryLabel !== '' && $categoryLabel !== 'General')
            ? '<text x="72" y="520" fill="#94a3b8" font-family="Arial,Helvetica,sans-serif" font-size="20" font-weight="600">'.$this->escape($categoryLabel).'</text>'
            : '';

        $w = self::W;
        $h = self::H;

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$w}" height="{$h}" viewBox="0 0 {$w} {$h}">
  <defs>
    <linearGradient id="bg" x1="0%" y1="0%" x2="0%" y2="100%">
      <stop offset="0%" stop-color="#0f172a"/>
      <stop offset="100%" stop-color="#0b1220"/>
    </linearGradient>
    <linearGradient id="rankGrad" x1="0%" y1="0%" x2="0%" y2="100%">
      <stop offset="0%" stop-color="{$rankGradA}"/>
      <stop offset="100%" stop-color="{$rankGradB}"/>
    </linearGradient>
    <linearGradient id="bannerScrim" x1="0%" y1="0%" x2="0%" y2="100%">
      <stop offset="0%" stop-color="rgba(15,23,42,0.1)"/>
      <stop offset="100%" stop-color="rgba(15,23,42,0.75)"/>
    </linearGradient>
    <clipPath id="bannerClip"><rect x="0" y="0" width="1200" height="248"/></clipPath>
  </defs>
  <rect width="{$w}" height="{$h}" fill="url(#bg)"/>
  {$coverBlock}
  <rect x="0" y="0" width="1200" height="248" fill="url(#bannerScrim)"/>
  <rect x="56" y="188" width="auto" height="44" rx="22" fill="rgba(15,23,42,0.85)"/>
  <text x="72" y="218" fill="url(#rankGrad)" font-family="Arial,Helvetica,sans-serif" font-size="28" font-weight="800">{$this->escape($rankLabel)}</text>
  <text x="72" y="292" fill="#64748b" font-family="Arial,Helvetica,sans-serif" font-size="16" font-weight="700" letter-spacing="2">FITNESS 365 PRO · LEADERBOARD</text>
  <text x="72" y="348" fill="#f8fafc" font-family="Arial,Helvetica,sans-serif" font-size="40" font-weight="800">{$displayName}</text>
  <text x="72" y="392" fill="#94a3b8" font-family="Arial,Helvetica,sans-serif" font-size="24" font-weight="600">{$eventTitle}</text>
  <rect x="72" y="416" width="220" height="52" rx="10" fill="rgba(30,41,59,0.9)" stroke="rgba(148,163,184,0.2)"/>
  <text x="92" y="450" fill="#f1f5f9" font-family="Arial,Helvetica,sans-serif" font-size="22" font-weight="700">{$this->escape($statsPrimary)}</text>
  <rect x="308" y="416" width="200" height="52" rx="10" fill="rgba(22,101,52,0.35)" stroke="rgba(34,197,94,0.35)"/>
  <text x="328" y="450" fill="#86efac" font-family="Arial,Helvetica,sans-serif" font-size="20" font-weight="700">{$this->escape($statsSecondary)}</text>
  {$categoryBlock}
  <text x="72" y="580" fill="#475569" font-family="Arial,Helvetica,sans-serif" font-size="16" font-weight="600">fitness365pro.com</text>
</svg>
SVG;
    }

    private function bannerCoverBlock(string $coverUrl): string
    {
        $href = $this->escape($coverUrl);

        return <<<SVG
<image href="{$href}" x="0" y="0" width="1200" height="248" preserveAspectRatio="xMidYMid slice" clip-path="url(#bannerClip)"/>
SVG;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function toPng(array $payload): ?string
    {
        try {
            // Skip Imagick — cloud hosts often block external SVG/image policies (HTTP 500).
            $viaGd = $this->toPngViaGd($payload);
            if ($viaGd !== null) {
                return $viaGd;
            }

            return $this->toPngViaGdBitmap($payload);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function toPngViaGd(array $payload): ?string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return null;
        }

        $fontBold = $this->resolveFontPath('bold');
        $fontReg = $this->resolveFontPath('regular');
        if ($fontBold === null) {
            return $this->toPngViaGdBitmap($payload);
        }

        $img = imagecreatetruecolor(self::W, self::H);
        if ($img === false) {
            return null;
        }

        imagealphablending($img, true);
        imagesavealpha($img, true);

        for ($y = 0; $y < self::H; $y++) {
            $ratio = $y / max(1, self::H - 1);
            $r = (int) (11 + (15 - 11) * $ratio);
            $g = (int) (18 + (23 - 18) * $ratio);
            $b = (int) (32 + (42 - 32) * $ratio);
            $c = imagecolorallocate($img, $r, $g, $b);
            imageline($img, 0, $y, self::W, $y, $c);
        }

        $white = imagecolorallocate($img, 248, 250, 252);
        $muted = imagecolorallocate($img, 148, 163, 184);
        $accent = imagecolorallocate($img, 249, 115, 22);
        $green = imagecolorallocate($img, 134, 239, 172);

        $displayName = $this->truncate((string) ($payload['display_name'] ?? 'Athlete'), 28);
        $eventTitle = $this->truncate((string) ($payload['event_title'] ?? 'Event'), 40);
        $rank = max(1, (int) ($payload['rank'] ?? 1));
        $progress = is_array($payload['progress'] ?? null) ? $payload['progress'] : [];
        $logged = (float) ($progress['logged_distance_km'] ?? 0);
        $goalCompleted = (bool) ($progress['goal_completed'] ?? false);
        $percent = $progress['progress_percent'] ?? null;

        $rankLabel = match ($rank) {
            1 => '1st Place',
            2 => '2nd Place',
            3 => '3rd Place',
            default => "#{$rank} Place",
        };

        imagettftext($img, 28, 0, 72, 218, $accent, $fontBold, $rankLabel);
        imagettftext($img, 14, 0, 72, 292, $muted, $fontReg ?? $fontBold, 'FITNESS 365 PRO · LEADERBOARD');
        imagettftext($img, 36, 0, 72, 348, $white, $fontBold, $displayName);
        imagettftext($img, 22, 0, 72, 392, $muted, $fontReg ?? $fontBold, $eventTitle);

        $stats = number_format($logged, 1).' km logged';
        imagettftext($img, 20, 0, 92, 450, $white, $fontBold, $stats);
        $sub = $goalCompleted ? 'Goal completed' : ($percent !== null ? number_format((float) $percent, 1).'% of goal' : '');
        if ($sub !== '') {
            imagettftext($img, 18, 0, 328, 450, $green, $fontReg ?? $fontBold, $sub);
        }

        $this->compositeEventBannerGd($img, (string) ($payload['event_image_url'] ?? ''));

        ob_start();
        imagepng($img);
        $binary = ob_get_clean();
        imagedestroy($img);

        return is_string($binary) && $binary !== '' ? $binary : null;
    }

    /**
     * Servers without TTF fonts (typical on cloud) — still return a real PNG for crawlers.
     *
     * @param  array<string, mixed>  $payload
     */
    private function toPngViaGdBitmap(array $payload): ?string
    {
        $img = imagecreatetruecolor(self::W, self::H);
        if ($img === false) {
            return null;
        }

        $bg = imagecolorallocate($img, 15, 23, 42);
        imagefill($img, 0, 0, $bg);
        $white = imagecolorallocate($img, 248, 250, 252);
        $muted = imagecolorallocate($img, 148, 163, 184);
        $accent = imagecolorallocate($img, 249, 115, 22);

        $rank = max(1, (int) ($payload['rank'] ?? 1));
        $displayName = $this->truncate((string) ($payload['display_name'] ?? 'Athlete'), 28);
        $eventTitle = $this->truncate((string) ($payload['event_title'] ?? 'Event'), 44);

        imagestring($img, 5, 48, 200, "#{$rank} on Fitness 365 Pro", $accent);
        imagestring($img, 4, 48, 240, $displayName, $white);
        imagestring($img, 3, 48, 270, $eventTitle, $muted);
        imagestring($img, 3, 48, 300, 'Leaderboard standing', $muted);

        $this->compositeEventBannerGd($img, (string) ($payload['event_image_url'] ?? ''));

        ob_start();
        imagepng($img);
        $binary = ob_get_clean();
        imagedestroy($img);

        return is_string($binary) && $binary !== '' ? $binary : null;
    }

    private function compositeEventBannerGd($img, string $url): void
    {
        if ($url === '') {
            return;
        }

        $absolute = ShareOpenGraph::absoluteImageUrl($url);
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(8)->get($absolute);
            if (! $response->successful()) {
                return;
            }
            $source = @imagecreatefromstring($response->body());
            if ($source === false) {
                return;
            }
            $boxW = self::W;
            $boxH = 248;
            $thumb = imagecreatetruecolor($boxW, $boxH);
            imagecopyresampled($thumb, $source, 0, 0, 0, 0, $boxW, $boxH, imagesx($source), imagesy($source));
            imagecopy($img, $thumb, 0, 0, 0, 0, $boxW, $boxH);
            imagedestroy($source);
            imagedestroy($thumb);
        } catch (\Throwable) {
            // ignore
        }
    }

    private function resolveFontPath(string $weight): ?string
    {
        $names = $weight === 'bold'
            ? ['Inter-Bold.ttf', 'arialbd.ttf', 'DejaVuSans-Bold.ttf']
            : ['Inter-Regular.ttf', 'arial.ttf', 'DejaVuSans.ttf'];

        $dirs = [
            public_path('fonts'),
            'C:\\Windows\\Fonts',
            '/usr/share/fonts/truetype/dejavu',
            '/usr/share/fonts/TTF',
        ];

        foreach ($dirs as $dir) {
            foreach ($names as $name) {
                $path = rtrim((string) $dir, '/\\').DIRECTORY_SEPARATOR.$name;
                if (is_file($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    private function truncate(string $value, int $maxLen): string
    {
        $value = trim($value);
        if (strlen($value) <= $maxLen) {
            return $value;
        }

        return substr($value, 0, max(0, $maxLen - 1)).'…';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

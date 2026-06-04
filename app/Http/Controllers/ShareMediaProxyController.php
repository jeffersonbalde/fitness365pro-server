<?php

namespace App\Http\Controllers;

use App\Support\ShareOpenGraph;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

class ShareMediaProxyController extends Controller
{
    public function show(Request $request): Response
    {
        $url = trim((string) $request->query('url', ''));
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return response('', 400);
        }

        if (! $this->isAllowedProxyUrl($url)) {
            return response('', 403);
        }

        try {
            $response = Http::timeout(15)->get($url);
            if (! $response->successful()) {
                return response('', 404);
            }

            $body = $response->body();
            if ($body === '') {
                return response('', 404);
            }

            return response($body, 200, [
                'Content-Type' => ShareOpenGraph::imageMimeTypeForUrl($url),
                'Cache-Control' => 'public, max-age=86400',
                'Access-Control-Allow-Origin' => '*',
            ]);
        } catch (\Throwable) {
            return response('', 502);
        }
    }

    private function isAllowedProxyUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }

        $allowedHosts = [
            'fitness365pro.com',
            'www.fitness365pro.com',
            'fitness365pro.sfo3.cdn.digitaloceanspaces.com',
            'fitness365pro.sfo3.digitaloceanspaces.com',
        ];

        foreach ($allowedHosts as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        $shareOrigin = ShareOpenGraph::shareOrigin();
        $shareHost = strtolower((string) parse_url($shareOrigin, PHP_URL_HOST));
        if ($shareHost !== '' && ($host === $shareHost || str_ends_with($host, '.'.$shareHost))) {
            return true;
        }

        return false;
    }
}

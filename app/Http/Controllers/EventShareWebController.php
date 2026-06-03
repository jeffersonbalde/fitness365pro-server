<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RendersLeaderboardShareOgPage;
use App\Models\AdminEvent;
use App\Services\EventLeaderboardShareService;
use App\Support\ShareOpenGraph;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class EventShareWebController extends Controller
{
    use RendersLeaderboardShareOgPage;

    public function __construct(
        private readonly EventLeaderboardShareService $leaderboardShare,
    ) {}

    /**
     * Public share landing page with Open Graph meta tags for Facebook/social crawlers.
     *
     * ?standing={clientId} serves leaderboard rank-card OG on the /share/event/ path
     * (Meta scrapes this reliably; /share/leaderboard/... alone often does not).
     */
    public function show(Request $request, string $eventId): View
    {
        $standingId = trim((string) $request->query('standing', ''));
        if ($standingId !== '') {
            $shareOrigin = ShareOpenGraph::shareOrigin();
            $query = ['standing' => $standingId];
            $category = trim((string) $request->query('category', 'all'));
            if ($category !== '' && $category !== 'all') {
                $query['category'] = $category;
            }
            $canonicalUrl = rtrim($shareOrigin, '/').'/share/event/'
                .rawurlencode($eventId).'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);

            return $this->renderLeaderboardShareOgPage(
                $request,
                $this->leaderboardShare,
                $eventId,
                $standingId,
                $canonicalUrl,
            );
        }

        $now = now('UTC');

        $event = AdminEvent::query()
            ->where('id', $eventId)
            ->active($now)
            ->first();

        if (! $event) {
            return view('share.event-not-found', [
                'pageTitle' => 'Event not found — Fitness 365 Pro',
                'frontendUrl' => rtrim((string) config('app.frontend_url'), '/'),
            ]);
        }

        $title = (string) $event->title;
        $location = $this->locationLabel($event);
        $timeline = $this->timelineLabel($event);
        $feeLabel = $this->feeLabel($event);

        $shareText = sprintf(
            'Join "%s" on Fitness 365 Pro! %s · %s · %s. Register in the app.',
            $title,
            $location,
            $timeline,
            $feeLabel,
        );

        $shareOrigin = ShareOpenGraph::shareOrigin();
        $canonicalUrl = rtrim($shareOrigin, '/').'/share/event/'.rawurlencode((string) $event->id);
        $imageUrl = ShareOpenGraph::absoluteImageUrl((string) ($event->image_url ?? ''));
        $frontendBase = rtrim((string) config('app.frontend_url'), '/');
        $clientAppUrl = $frontendBase.'/challenges/'.rawurlencode((string) $event->id);

        return view('share.event', [
            'pageTitle' => "{$title} | Fitness 365 Pro Events",
            'ogTitle' => "{$title} — Fitness 365 Pro",
            'ogDescription' => $shareText,
            'ogImageAlt' => "{$title} event cover",
            'eventTitle' => $title,
            'shareText' => $shareText,
            'location' => $location,
            'timeline' => $timeline,
            'feeLabel' => $feeLabel,
            'canonicalUrl' => $canonicalUrl,
            'imageUrl' => $imageUrl,
            'clientAppUrl' => $clientAppUrl,
            'frontendUrl' => $frontendBase,
        ]);
    }

    private function locationLabel(AdminEvent $event): string
    {
        $locationType = strtolower((string) ($event->location_type ?? 'online'));
        $venue = trim((string) ($event->venue ?? ''));
        $location = trim((string) ($event->location ?? ''));

        if ($locationType === 'onsite') {
            return $venue !== '' ? $venue : ($location !== '' ? $location : 'Onsite event');
        }

        if ($location !== '') {
            return $location;
        }

        return match ($locationType) {
            'global' => 'Global online event',
            default => 'Online event',
        };
    }

    private function timelineLabel(AdminEvent $event): string
    {
        $starts = $event->starts_at instanceof Carbon ? $event->starts_at : null;
        $ends = $event->ends_at instanceof Carbon ? $event->ends_at : null;

        if ($starts && $ends) {
            return $starts->format('M j, Y').' – '.$ends->format('M j, Y');
        }

        if ($starts) {
            return 'Starts '.$starts->format('M j, Y');
        }

        return 'Open for registration';
    }

    private function feeLabel(AdminEvent $event): string
    {
        $fee = (float) ($event->fee ?? 0);
        if (strtolower((string) ($event->fee_type ?? '')) === 'free' || $fee <= 0.00001) {
            return 'Free registration';
        }

        return 'PHP '.number_format($fee, 0);
    }
}

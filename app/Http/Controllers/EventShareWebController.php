<?php

namespace App\Http\Controllers;

use App\Models\AdminEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class EventShareWebController extends Controller
{
    /**
     * Public share landing page with Open Graph meta tags for Facebook/social crawlers.
     */
    public function show(Request $request, string $eventId): View
    {
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

        $canonicalUrl = $request->url();
        $imageUrl = $this->absolutePublicUrl((string) ($event->image_url ?? ''));
        $frontendBase = rtrim((string) config('app.frontend_url'), '/');
        $clientAppUrl = $frontendBase.'/challenges/'.rawurlencode((string) $event->id);

        return view('share.event', [
            'pageTitle' => "{$title} | Fitness 365 Pro Events",
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

    private function absolutePublicUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return rtrim((string) config('app.url'), '/').'/logo.jpg';
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
    }
}

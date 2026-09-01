<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Applies a strict, self-contained security policy to every response.
 *
 * The application loads no third-party scripts, styles, fonts or images, so the
 * CSP can be as tight as `'self'` with no escape hatches. Uploaded financial
 * data must never be cached by an intermediary, hence the no-store policy.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -256)]
final readonly class SecurityHeadersListener
{
    private const string CONTENT_SECURITY_POLICY =
        "default-src 'self'; "
        ."script-src 'self'; "
        ."style-src 'self'; "
        ."img-src 'self' data:; "
        ."font-src 'self'; "
        ."connect-src 'self'; "
        ."form-action 'self'; "
        ."frame-ancestors 'none'; "
        ."base-uri 'self'; "
        ."object-src 'none'";

    /**
     * One year, subdomains included. `preload` is deliberately omitted: getting
     * onto the preload list is effectively irreversible and is the deploying
     * operator's decision, not this application's.
     *
     * Behind a reverse proxy this only fires when the proxy's forwarded headers
     * are trusted - see the deployment notes in SECURITY.md.
     */
    private const string STRICT_TRANSPORT_SECURITY = 'max-age=31536000; includeSubDomains';

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;

        // HSTS is a one-way commitment: once a browser sees it, the host is
        // HTTPS-only until max-age expires. Sending it from a plain-HTTP local
        // server would lock the developer out of their own machine, and browsers
        // ignore it over HTTP anyway - so it goes out only on a secure request.
        if ($event->getRequest()->isSecure()) {
            $headers->set('Strict-Transport-Security', self::STRICT_TRANSPORT_SECURITY);
        }

        $headers->set('Content-Security-Policy', self::CONTENT_SECURITY_POLICY);
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('Permissions-Policy', 'geolocation=(), camera=(), microphone=(), payment=(), interest-cohort=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('Cross-Origin-Resource-Policy', 'same-origin');

        // Financial figures pass through these responses; keep them out of every cache.
        $headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $headers->set('Pragma', 'no-cache');
    }
}

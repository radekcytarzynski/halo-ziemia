<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Nagłówki bezpieczeństwa dla wszystkich odpowiedzi (niezależnie od serwera WWW).
 * CSP bez 'unsafe-inline': skrypty tylko z własnej domeny + nonce dla importmapy.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -10)]
final readonly class SecurityHeadersSubscriber
{
    public const int HSTS_MAX_AGE = 31536000;

    public function __construct(private CspNonce $nonce)
    {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $headers = $event->getResponse()->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'same-origin');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age='.self::HSTS_MAX_AGE);
        }

        if (str_starts_with($request->getPathInfo(), '/api/')) {
            $headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");

            return;
        }

        $scriptSrc = "'self'".($this->nonce->isUsed() ? " 'nonce-".$this->nonce->get()."'" : '');
        $headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            'script-src '.$scriptSrc,
            "style-src 'self'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]));
    }
}

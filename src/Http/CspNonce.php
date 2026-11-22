<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Nonce CSP dla bieżącego żądania (jedyne skrypty inline to importmapa AssetMappera).
 */
final readonly class CspNonce
{
    private const string ATTRIBUTE = '_csp_nonce';

    public function __construct(private RequestStack $requestStack)
    {
    }

    public function get(): string
    {
        $request = $this->requestStack->getMainRequest();
        if (null === $request) {
            return '';
        }

        $nonce = $request->attributes->get(self::ATTRIBUTE);
        if (!\is_string($nonce)) {
            $nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
            $request->attributes->set(self::ATTRIBUTE, $nonce);
        }

        return $nonce;
    }

    public function isUsed(): bool
    {
        return \is_string($this->requestStack->getMainRequest()?->attributes->get(self::ATTRIBUTE));
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Agent\AgentPackage;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Publiczne pobieranie paczki agenta (kod agenta nie jest tajny; token podaje się przy instalacji).
 */
#[AsController]
final readonly class AgentDownloadController
{
    public function __construct(private AgentPackage $package)
    {
    }

    #[Route('/agent/'.AgentPackage::FILENAME, name: 'agent_download', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $content = $this->package->build();

        $response = new Response($content, 200, ['Content-Type' => 'application/gzip']);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, AgentPackage::FILENAME));
        $response->setEtag(sha1($content));
        $response->setPublic();
        $response->setMaxAge(300);
        $response->isNotModified($request);

        return $response;
    }
}

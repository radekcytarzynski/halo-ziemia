<?php

declare(strict_types=1);

namespace App\Tests\Functional\Agent;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AgentDownloadTest extends WebTestCase
{
    public function testPackageIsPublicValidTarGzWithExecutableScripts(): void
    {
        $client = self::createClient();
        $client->request('GET', '/agent/halo-ziemia-agent.tar.gz');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/gzip');
        $content = (string) $client->getResponse()->getContent();

        $dir = sys_get_temp_dir().'/hz-agent-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/a.tar.gz', $content);
        exec(\sprintf('tar -xzf %s -C %s 2>&1', escapeshellarg($dir.'/a.tar.gz'), escapeshellarg($dir)), $out, $rc);

        self::assertSame(0, $rc, implode("\n", $out));
        $root = $dir.'/halo-ziemia-agent';
        self::assertFileExists($root.'/install.sh');
        self::assertFileExists($root.'/lib/collect.sh');
        self::assertFileExists($root.'/systemd/halo-ziemia-agent.timer');
        self::assertFileExists($root.'/checks.d/backup.sh.example');
        self::assertTrue(is_executable($root.'/install.sh'));
        self::assertTrue(is_executable($root.'/halo-ziemia-agent'));
        self::assertFalse(is_executable($root.'/lib/collect.sh'));
        self::assertDirectoryDoesNotExist($root.'/tests', 'testy nie trafiają do paczki');
        self::assertSame(file_get_contents(\dirname(__DIR__, 3).'/agent/halo-ziemia-agent'), file_get_contents($root.'/halo-ziemia-agent'));

        exec('rm -rf '.escapeshellarg($dir));
    }

    public function testNotModifiedWithEtag(): void
    {
        $client = self::createClient();
        $client->request('GET', '/agent/halo-ziemia-agent.tar.gz');
        $etag = (string) $client->getResponse()->headers->get('ETag');

        $client->request('GET', '/agent/halo-ziemia-agent.tar.gz', server: ['HTTP_IF_NONE_MATCH' => $etag]);

        self::assertResponseStatusCodeSame(304);
    }
}

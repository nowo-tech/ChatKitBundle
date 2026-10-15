<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Smoke tests for the NowoChatKitBundle demo (needs the seeded database: make setup-db).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class DemoControllerTest extends WebTestCase
{
    public function testRootRedirectsToEnglish(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseRedirects('/en');
    }

    public function testEnglishHomePage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/en');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Chat Kit Bundle');
        self::assertSelectorTextContains('html', 'default profile (default)');
        self::assertSelectorExists('script[src*="/build/"]');
        self::assertSelectorNotExists('[data-nowo-chat-config]');
    }

    public function testSpanishHomePage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/es');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('html', 'Mensajes directos entre usuarios');
    }

    public function testSignInAlice(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/en/login');
        self::assertSelectorExists('input[name="_csrf_token"]');

        $form = $crawler->selectButton('Sign in')->form([
            '_username' => 'alice',
            '_password' => 'password',
        ]);
        $client->submit($form);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('nav', 'alice');
        self::assertSelectorExists('[data-nowo-chat-config]');
        self::assertSelectorExists('template[data-nowo-chat-template]');
    }

    public function testFullPageChatAndApiForSignedInUser(): void
    {
        $client = static::createClient();
        $alice = static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'alice']);
        self::assertNotNull($alice, 'Run make setup-db (app:demo:seed) first.');
        $client->loginUser($alice);

        $client->request('GET', '/chat/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.nowo-chat-page__list', 'Bob Lee');
        self::assertSelectorTextContains('.nowo-chat-page__list', 'General');

        $client->request('GET', '/chat/api/conversations');
        self::assertResponseIsSuccessful();
        $titles = array_column(json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['conversations'], 'title');
        self::assertContains('Bob Lee', $titles);
    }

    public function testTwigTemplatesDoNotUseRawHtmlForms(): void
    {
        $templates = dirname(__DIR__, 2).'/templates';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($templates));
        foreach ($iterator as $file) {
            if (!$file->isFile() || 'twig' !== $file->getExtension()) {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            self::assertDoesNotMatchRegularExpression('/<form[\s>]/', $contents, $file->getPathname());
            self::assertDoesNotMatchRegularExpression('/<input[\s>]/', $contents, $file->getPathname());
        }
    }

    public function testViteEntrypointsAreBuilt(): void
    {
        self::bootKernel();
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($projectDir);
        self::assertFileExists($projectDir.'/public/build/entrypoints.json');
    }
}

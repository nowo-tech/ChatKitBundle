<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Attachment\ChatAttachmentFileContext;
use Nowo\ChatKitBundle\Attachment\ChatAttachments;
use Nowo\ChatKitBundle\Attachment\ChatAttachmentScan;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Repository\ChatAttachmentRepository;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Attachment\TestScanner;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\StorageKitBundle\Audit\AuditRecorder;
use Nowo\StorageKitBundle\Context\FileContexts;
use Nowo\StorageKitBundle\Context\GenericFileContextInterface;
use Nowo\StorageKitBundle\Context\SquareImageContextInterface;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Storage\FileStorage;
use Nowo\StorageKitBundle\Upload\ImageProcessor;
use Nowo\StorageKitBundle\Upload\ImageUploader;
use Nowo\StorageKitBundle\Upload\UploadRefused;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

use function assert;
use function base64_decode;
use function file_put_contents;
use function json_decode;
use function sys_get_temp_dir;
use function tempnam;

use const JSON_THROW_ON_ERROR;

/**
 * 2.7: audit ZCO fixes. L1: StorageKit's own HTTP image upload (`POST <prefix>/upload/chat/{uuid}`, which calls
 * `ImageUploader::uploadImage()`) refuses the chat context like an unknown one — its controller answers 404 for
 * `UploadRefused::CONTEXT` — while ChatKit's generic upload still takes it. L2: the dock's attachment remove spends the
 * person's action budget (429 when spent).
 */
final class ChatAttachmentsAuditZcoTest extends DatabaseTestCase
{
    protected function attachments(): bool
    {
        return true;
    }

    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => [
            'limits'      => ['actions_per_minute' => 1],
            'attachments' => ['enabled' => true, 'scanner' => TestScanner::class],
        ]]];
    }

    public function testStorageKitsHttpImageUploadRefusesTheChatContext(): void
    {
        $ana     = $this->user('Ana');
        $channel = $this->get(ChatService::class)->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $context = $this->get(ChatAttachmentFileContext::class);
        self::assertInstanceOf(SquareImageContextInterface::class, $context);
        self::assertInstanceOf(GenericFileContextInterface::class, $context);
        // The route's uploader, with collaborators the refusal never reaches.
        $uploader = new ImageUploader(new FileContexts([$context]), self::blank(StorageProfiles::class), self::blank(FileStorage::class), self::blank(ImageProcessor::class), self::blank(StorageEntityManager::class), self::blank(AuditRecorder::class));
        $png      = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true);
        try {
            $uploader->uploadImage($ana, 'chat', $channel->getUuid(), $png, 'a.png');
            self::fail('the chat context must be refused');
        } catch (UploadRefused $refused) {
            self::assertSame(UploadRefused::CONTEXT, $refused->reason, 'answered 404 by FileUploadController');
        }
        // ChatKit's own upload (StorageKit's generic port) still writes there.
        self::assertNotNull($context->writableTarget($ana, $channel->getUuid()));
        self::assertSame('uploaded', $this->get(ChatAttachments::class)->upload($ana, $channel->getUuid(), $this->file('hello world'), 'a.txt')->state->value);
    }

    public function testTheDockRemoveSpendsTheActionBudget(): void
    {
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $ana     = $this->user('Ana');
        $channel = $this->get(ChatService::class)->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $first   = $this->get(ChatAttachments::class)->upload($ana, $channel->getUuid(), $this->file('one file'), 'a.txt')->uuid;
        $second  = $this->get(ChatAttachments::class)->upload($ana, $channel->getUuid(), $this->file('two file'), 'b.txt')->uuid;
        $this->get(ChatAttachmentScan::class)->scan((int) $this->em->getConnection()->fetchOne('SELECT id FROM nowo_chat_attachment WHERE uuid = ?', [$first]));
        $client->loginUser($ana);
        $crawler = $client->request('GET', '/page');
        /** @var array{csrf: string} $config */
        $config = json_decode((string) $crawler->filter('[data-nowo-chat-config]')->attr('data-nowo-chat-config'), true, 512, JSON_THROW_ON_ERROR);
        $client->request('DELETE', '/chat/api/attachments/' . $first, server: ['HTTP_X_CSRF_TOKEN' => $config['csrf']]);
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $client->request('DELETE', '/chat/api/attachments/' . $second, server: ['HTTP_X_CSRF_TOKEN' => $config['csrf']]);
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('"error":"rate_limited"', (string) $client->getResponse()->getContent());
        self::assertSame('uploaded', (string) $this->em->getConnection()->fetchOne('SELECT state FROM nowo_chat_attachment WHERE uuid = ?', [$second]));
        // ZCO-I1: unknown uuids lock nothing.
        self::assertSame([], $this->get(ChatAttachmentRepository::class)->lockForSend(['01a1f09f-0000-7000-8000-00000000beef']));
    }

    /**
     * A collaborator the refusal never reaches.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private static function blank(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    private function file(string $bytes): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'chat-zco-');
        file_put_contents($path, $bytes);

        return $path;
    }
}

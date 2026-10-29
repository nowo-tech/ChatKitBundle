<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Form;

use Nowo\ChatKitBundle\Entity\ChatUserSettings;
use Nowo\ChatKitBundle\Form\ChatDockStatusType;
use Nowo\ChatKitBundle\Form\ChatPreferencesType;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Forms;

/**
 * The host-renderable chat preferences form (bound to ChatUserSettings) and the dock's custom-status fields.
 */
final class ChatPreferencesTypeTest extends TestCase
{
    public function testSubmitsEveryPreference(): void
    {
        $settings = new ChatUserSettings(new TestUser('Ana', 'ana@example.test'));
        $form     = Forms::createFormFactory()->create(ChatPreferencesType::class, $settings);

        self::assertSame('nowo_chat_preferences', $form->getName());
        self::assertSame('pop', $form->get('soundMessage')->getData());
        self::assertSame(80, (int) $form->get('volume')->getData());
        self::assertSame('mention', $form->get('soundMention')->getConfig()->getOption('attr')['data-nowo-chat-sound-select']);

        $form->submit([
            'soundMessage'        => 'bell',
            'soundMention'        => 'knock',
            'soundBuzz'           => 'buzz',
            'soundSupport'        => 'none',
            'volume'              => '150',
            'soundBackgroundOnly' => '1',
            'buzzOpenWindow'      => '1',
            'sendKey'             => 'ctrl_enter',
            'pushPreview'         => '1',
            'presenceVisible'     => '1',
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertSame(['message' => 'bell', 'mention' => 'knock', 'buzz' => 'buzz', 'support' => 'none'], $settings->preferences()['sounds']);
        self::assertSame(100, $settings->getVolume(), 'clamped');
        self::assertTrue($settings->isSoundBackgroundOnly());
        self::assertFalse($settings->isBuzzEnabled(), 'unchecked');
        self::assertFalse($settings->isOpenOnMessage());
        self::assertSame('ctrl_enter', $settings->getSendKey());
        self::assertFalse($settings->isEmailDigest());
    }

    public function testDockStatusFields(): void
    {
        $form = Forms::createFormFactory()->create(ChatDockStatusType::class);

        self::assertSame('nowo_chat_status', $form->getName());
        self::assertSame(['emoji', 'text', 'clearAfter'], array_keys($form->all()));
        self::assertSame('never', $form->get('clearAfter')->getData());
        self::assertSame(80, $form->get('text')->getConfig()->getOption('attr')['maxlength']);
    }
}

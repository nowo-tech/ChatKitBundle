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
        self::assertSame(['moreStatus', 'emoji', 'text', 'clearAfter', 'autoReplyEnabled', 'autoReplyText', 'autoReplyWhen', 'autoReplyFrom', 'autoReplyUntil', 'autoReplyMentions'], array_keys($form->all()));
        self::assertSame('never', $form->get('clearAfter')->getData());
        self::assertSame(80, $form->get('text')->getConfig()->getOption('attr')['maxlength']);
        self::assertContains('2h', array_values($form->get('clearAfter')->getConfig()->getOption('choices')));
    }

    public function testDockStatusSuggestions(): void
    {
        $view = Forms::createFormFactory()->create(ChatDockStatusType::class)->createView();

        self::assertSame(['emoji' => '🍕', 'text' => 'chat.status_preset_lunch', 'clear_after' => '1h'], $view->vars['status_presets'][0]);
        self::assertCount(5, $view->vars['status_presets']);
        $more = $view['moreStatus'];
        self::assertSame('chat.status_more_placeholder', $more->vars['placeholder']);
        self::assertSame(['chat.status_group_work', 'chat.status_group_personal'], array_keys($more->vars['choices']));
        $focus = $more->vars['choices']['chat.status_group_work']->choices[0];
        self::assertSame('0-0', $focus->value);
        self::assertSame('🎧 chat.status_preset_focus', $focus->label);
        self::assertSame(['data-preset-emoji' => '🎧', 'data-preset-text' => 'chat.status_preset_focus', 'data-preset-clear-after' => '2h'], $focus->attr);
        self::assertSame('status-more', $more->vars['attr']['data-part']);
    }

    public function testDockStatusSuggestionsFromTheOption(): void
    {
        $form = Forms::createFormFactory()->create(ChatDockStatusType::class, null, ['status_presets' => [
            'quick' => [['emoji' => '🚀', 'label' => 'Launching', 'clear_after' => '4h']],
            'more'  => [],
        ]]);
        $view = $form->createView();

        self::assertFalse($form->has('moreStatus'), 'no group: no drop-down');
        self::assertSame([['emoji' => '🚀', 'text' => 'Launching', 'clear_after' => '4h']], $view->vars['status_presets']);
    }
}

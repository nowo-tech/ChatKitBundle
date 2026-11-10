<?php

declare(strict_types=1);

namespace App\Chat;

use Nowo\ChatKitBundle\Contract\ChatSidebarSectionProviderInterface;
use Nowo\ChatKitBundle\Model\ChatSidebarAction;
use Nowo\ChatKitBundle\Model\ChatSidebarItem;
use Nowo\ChatKitBundle\Model\ChatSidebarSection;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * ChatKit 1.25.1 (audit KJ-I13): a demo sidebar section, so the full-page chat (/chat/) shows the grouped sidebar with a
 * host section and the Playwright e2e opens a real sidebar action dialog (UiKit remote form dialog). Temporary fixture
 * until the demo gets real rooms: one link row and one "Create room" action served by DemoRoomController.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class DemoSidebarProvider implements ChatSidebarSectionProviderInterface
{
    public const string SECTION = 'demo-rooms';

    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    public function sections(ChatUserInterface $viewer): iterable
    {
        $create = $this->urls->generate('demo_room_new', ['_locale' => $this->translator->getLocale()]);

        yield new ChatSidebarSection(
            self::SECTION,
            $this->translator->trans('demo.rooms.section'),
            items: [
                new ChatSidebarItem('home', $this->translator->trans('demo.nav.home'), 'hash', null, $this->urls->generate('homepage', ['_locale' => $this->translator->getLocale()])),
            ],
            actions: [
                new ChatSidebarAction('create', $this->translator->trans('demo.rooms.create'), 'plus', $create, $create),
            ],
        );
    }
}

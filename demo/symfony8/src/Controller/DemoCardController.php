<?php

declare(strict_types=1);

namespace App\Controller;

use App\Chat\DemoVaultCardProvider;
use Nowo\UiKitBundle\Dialog\RemoteDialog;
use Nowo\UiKitBundle\Dialog\RemoteDialogResponder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * ChatKit 2.6 (spec 259): the host dialogs of the demo cards — pick a vault item (inserts a card with
 * `nowo-chat:insert-card`, public/js/demo-cards.js), save a selection to the vault (filled from
 * `nowo-chat:slot-payload`), and the page a card action opens. Nothing is stored.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[IsGranted('IS_AUTHENTICATED')]
final class DemoCardController extends AbstractController
{
    #[Route('/{_locale}/demo/cards/vault', name: 'demo_cards_vault', requirements: ['_locale' => 'en|es'], methods: ['GET'])]
    public function vault(Request $request, RemoteDialogResponder $dialog): Response
    {
        return RemoteDialog::vary($this->render($dialog->template('demo/cards/page.html.twig', 'demo/cards/_vault.html.twig'), [
            'items'        => DemoVaultCardProvider::ITEMS,
            'conversation' => (string) $request->query->get('conversation', ''),
            'fragment'     => 'demo/cards/_vault.html.twig',
        ]));
    }

    #[Route('/{_locale}/demo/cards/save', name: 'demo_cards_save', requirements: ['_locale' => 'en|es'], methods: ['GET'])]
    public function save(RemoteDialogResponder $dialog): Response
    {
        return RemoteDialog::vary($this->render($dialog->template('demo/cards/page.html.twig', 'demo/cards/_save.html.twig'), ['fragment' => 'demo/cards/_save.html.twig']));
    }

    #[Route('/{_locale}/demo/cards/request', name: 'demo_cards_request', requirements: ['_locale' => 'en|es'], methods: ['GET'])]
    public function request(Request $request, RemoteDialogResponder $dialog): Response
    {
        $item = DemoVaultCardProvider::ITEMS[(string) $request->query->get('item', '')][0] ?? null;

        return RemoteDialog::vary($this->render($dialog->template('demo/cards/page.html.twig', 'demo/cards/_request.html.twig'), ['item' => $item, 'fragment' => 'demo/cards/_request.html.twig']));
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\DemoRoomType;
use Nowo\UiKitBundle\Dialog\RemoteDialog;
use Nowo\UiKitBundle\Dialog\RemoteDialogResponder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * ChatKit 1.25.1 (audit KJ-I13): the "Create room" action of DemoSidebarProvider — one URL, two modes: the full page
 * (the action's link, without JavaScript) and the fragment the sidebar's remote form dialog loads. Nothing is stored;
 * a valid name goes back to the full-page chat with `?room=<name>`.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class DemoRoomController extends AbstractController
{
    #[Route('/{_locale}/demo/rooms/new', name: 'demo_room_new', requirements: ['_locale' => 'en|es'], methods: ['GET', 'POST'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function new(Request $request, RemoteDialogResponder $dialog): Response
    {
        $form = $this->createForm(DemoRoomType::class)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{name: string} $data */
            $data = $form->getData();

            return $dialog->redirect($this->generateUrl('nowo_chat_page', ['room' => $data['name']]));
        }

        return RemoteDialog::vary($this->render($dialog->template('demo/room_new.html.twig', 'demo/_room_form.html.twig'), [
            'room_form' => $form,
        ]));
    }
}

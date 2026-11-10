<?php

declare(strict_types=1);

namespace App\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * ChatKit 1.25.1: the "Create room" form of the demo sidebar action (shown in the sidebar's remote form dialog).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 *
 * @extends AbstractType<array{name?: string}>
 */
#[FormKitConfig('chat_kit')]
final class DemoRoomType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addWithDefaults($builder, 'name', TextType::class, [
            'label' => 'demo.rooms.name',
            // Checked on the server only, so the dialog shows its 422 answer (no browser validation).
            'required' => false,
            'constraints' => [new NotBlank(), new Length(max: 40)],
        ]);
        $this->addWithDefaults($builder, 'submit', SubmitType::class, [
            'label' => 'demo.rooms.submit',
            'attr' => [
                'class' => 'btn btn-primary',
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'messages',
        ]);
    }
}

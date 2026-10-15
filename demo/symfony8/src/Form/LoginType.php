<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * In-memory demo login. Empty block prefix keeps {@code _username} / {@code _password}
 * / {@code _csrf_token} flat for {@code form_login}.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 *
 * @extends AbstractType<null>
 */
final class LoginType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('_username', TextType::class, [
                'label' => 'demo.login.username',
                'data' => $options['last_username'],
                'attr' => [
                    'autocomplete' => 'username',
                ],
            ])
            ->add('_password', PasswordType::class, [
                'label' => 'demo.login.password',
                'data' => 'password',
                'always_empty' => false,
                'attr' => [
                    'autocomplete' => 'current-password',
                ],
            ])
            ->add('_target_path', HiddenType::class, [
                'data' => $options['target_path'],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'demo.login.submit',
                'attr' => [
                    'class' => 'btn btn-primary',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_csrf_token',
            'csrf_token_id' => 'authenticate',
            'last_username' => 'alice',
            'target_path' => '/',
        ]);
        $resolver->setAllowedTypes('last_username', 'string');
        $resolver->setAllowedTypes('target_path', 'string');
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}

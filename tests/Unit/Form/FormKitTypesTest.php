<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Form;

use Nowo\ChatKitBundle\Form\ChatDockStatusType;
use Nowo\ChatKitBundle\Form\ChatPageMessageType;
use Nowo\ChatKitBundle\NowoChatKitBundle;
use Nowo\ChatKitBundle\Tests\Support\FormKitTestSupport;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormKitAbstractType;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Form\FormTypeInterface;

use function basename;
use function class_uses;
use function glob;

/**
 * REQ-TWIG-005 rule 11: every bundle form type (src/Form/*Type.php) is a FormKit Type on the bundle profile.
 */
final class FormKitTypesTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string}>
     */
    public static function formTypes(): iterable
    {
        $files = glob(__DIR__ . '/../../../src/Form/*Type.php') ?: [];
        self::assertNotSame([], $files);
        foreach ($files as $file) {
            /** @var class-string $class */
            $class = 'Nowo\\ChatKitBundle\\Form\\' . basename($file, '.php');

            yield $class => [$class];
        }
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('formTypes')]
    public function testFormTypeIsAFormKitType(string $class): void
    {
        $reflection = new ReflectionClass($class);
        self::assertTrue($reflection->implementsInterface(FormTypeInterface::class), $class);
        self::assertTrue(
            $reflection->isSubclassOf(FormKitAbstractType::class) || isset(class_uses($class)[FormOptionsTrait::class]),
            $class . ' must extend a FormKit base or use FormOptionsTrait',
        );
        $attributes = $reflection->getAttributes(FormKitConfig::class);
        self::assertCount(1, $attributes, $class . ' must carry #[FormKitConfig]');
        self::assertSame(NowoChatKitBundle::FORM_KIT_PROFILE, $attributes[0]->newInstance()->name);
    }

    public function testPageComposerUsesTheProfileDomainAndNoConventionKeys(): void
    {
        $view = FormKitTestSupport::formFactory()->create(ChatPageMessageType::class)->createView();

        self::assertArrayNotHasKey('class', $view->children['body']->vars['attr']);
        self::assertSame('chat.composer_placeholder', $view->children['body']->vars['attr']['placeholder']);
        self::assertNull($view->children['body']->vars['help']);
        self::assertSame('chat.composer_placeholder', $view->children['body']->vars['label']);
        self::assertSame('NowoChatKitBundle', $view->children['body']->vars['translation_domain']);
    }

    public function testStatusFieldsKeepTheirTranslatedLabelsUntranslated(): void
    {
        $view = FormKitTestSupport::formFactory()->create(ChatDockStatusType::class)->createView();

        self::assertFalse($view->children['clearAfter']->vars['translation_domain']);
        self::assertSame('nowo-chat-status-form__emoji', $view->children['emoji']->vars['attr']['class']);
        self::assertSame(['data-part' => 'auto-reply-enabled'], $view->children['autoReplyEnabled']->vars['attr']);
    }
}

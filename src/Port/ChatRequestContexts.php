<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Port;

use Nowo\ChatKitBundle\Model\ChatRequestContext;
use Nowo\ChatKitBundle\Model\ChatSecurityUser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_array;
use function is_int;
use function is_string;
use function method_exists;
use function strlen;
use function strrpos;
use function substr;
use function trim;

/**
 * 1.27: copies the HttpFoundation request and the Symfony security user into the framework-free values of the port
 * twins (ChatRequestContext, ChatSecurityUser). Never a header, a cookie, the session or the body. Stateless.
 *
 * @internal
 */
final class ChatRequestContexts
{
    /** Namespace marker of Doctrine's generated proxy classes (`Proxies\__CG__\App\Entity\User`). */
    private const string PROXY_MARKER = '\\__CG__\\';

    /**
     * The request as a ChatRequestContext.
     *
     * @param Request $request The request
     *
     * @return ChatRequestContext The context
     */
    public static function fromRequest(Request $request): ChatRequestContext
    {
        $route  = $request->attributes->get('_route');
        $params = $request->attributes->get('_route_params');

        return new ChatRequestContext(
            $request->getMethod(),
            $request->getScheme(),
            $request->getHost(),
            (int) $request->getPort(),
            $request->getBasePath(),
            $request->getPathInfo(),
            is_string($route) ? $route : null,
            is_array($params) ? $params : [],
            $request->query->all(),
            $request->getLocale(),
        );
    }

    /**
     * The security user as a ChatSecurityUser (null stays null).
     *
     * @param UserInterface|null $user The security user
     *
     * @return ChatSecurityUser|null The value
     */
    public static function fromUser(?UserInterface $user): ?ChatSecurityUser
    {
        if (!$user instanceof UserInterface) {
            return null;
        }
        $id = method_exists($user, 'getId') ? $user->getId() : null;
        $id = is_int($id) || (is_string($id) && trim($id) !== '') ? (string) $id : null;

        return new ChatSecurityUser(self::className($user), $id, $user->getUserIdentifier(), $user->getRoles());
    }

    /**
     * The user's class without a Doctrine proxy wrapper.
     */
    private static function className(object $user): string
    {
        $class = $user::class;
        $pos   = strrpos($class, self::PROXY_MARKER);

        return $pos === false ? $class : substr($class, $pos + strlen(self::PROXY_MARKER));
    }
}

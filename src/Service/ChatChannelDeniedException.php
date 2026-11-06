<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Service;

use RuntimeException;

/**
 * 1.21: a ChatChannelOpeningEvent listener refused to create the channel's conversation (ChatService::openChannel()).
 * The API answers 403 `channel_denied`; other callers of the service decide themselves.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ChatChannelDeniedException extends RuntimeException
{
    /**
     * @param string $channelKey The refused channel
     * @param string $reason Text for the person, as the listener gave it; empty when none
     */
    public function __construct(
        public readonly string $channelKey,
        public readonly string $reason = '',
    ) {
        parent::__construct('channel_denied');
    }
}

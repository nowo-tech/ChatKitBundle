<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

/**
 * Optional (1.31), implemented by the application: how many days chat messages are kept, read at run time (for example
 * from an administration setting stored in the database) instead of the container-build-time `retention_days`.
 *
 * The bundle asks it on every `nowo:chat:purge` run and never keeps the answer between runs, so the implementation
 * must not rely on being called once per process (FrankenPHP worker mode, kernel reset off) and should read its own
 * source each time. Alias the interface to your service to use it. Without one, or when it returns null, the configured
 * `retention_days` applies. The bundle keeps its minimum of 30 days: a smaller value is raised to 30 with a warning
 * in the log, and a value of 0 or less is ignored (the configured value applies, also with a warning).
 *
 * If the implementation throws, the purge run stops and nothing is deleted: falling back to the configured value could
 * delete messages the application meant to keep.
 */
interface RetentionPolicyProviderInterface
{
    /**
     * Days chat messages are kept.
     *
     * @return int|null The retention in days, or null to use the configured `retention_days`
     */
    public function retentionDays(): ?int;
}

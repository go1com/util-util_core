<?php

namespace go1\util\plan;

interface DeferredMessagesInterface
{
    public function getDeferredMessages(): array;
    public function clearDeferredMessages(): void;
}

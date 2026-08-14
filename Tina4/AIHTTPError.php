<?php

namespace Tina4;

class AIHTTPError extends AIError
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}

<?php

namespace Tina4;

final class ChatResponse
{
    public function __construct(
        public readonly string $text,
        public readonly string $model,
        public readonly array $usage,
        public readonly ?string $finish_reason,
        public readonly array $raw,
    ) {
    }
}

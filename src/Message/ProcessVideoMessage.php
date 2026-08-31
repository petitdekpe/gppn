<?php

namespace App\Message;

final class ProcessVideoMessage
{
    public function __construct(
        public readonly int $videoId,
    ) {
    }
}

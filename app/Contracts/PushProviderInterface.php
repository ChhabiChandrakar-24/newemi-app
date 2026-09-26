<?php

namespace App\Contracts;

interface PushProviderInterface
{
    /** @return array{status:string,message_id:?string,failure_code:?string,failure_message:?string} */
    public function send(string $token, array $data, bool $highPriority): array;
}

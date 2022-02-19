<?php

namespace App\DTO;

use Symfony\Component\Serializer\Annotation\Groups;

final class MonobankResponse
{
    #[Groups(['transaction:write'])]
    public array $data;

    #[Groups(['transaction:write'])]
    public string $accountId;

    #[Groups(['transaction:write'])]
    public array $statementItem;

    public function __construct(array $data)
    {
        $this->accountId = $data['account'];
        $this->statementItem = $data['statementItem'];
    }
}

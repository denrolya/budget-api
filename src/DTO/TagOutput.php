<?php

namespace App\DTO;

use Symfony\Component\Serializer\Annotation\Groups;

final class TagOutput
{
    #[Groups(['transaction:collection:read', 'account:item:read', 'debt:collection:read', 'category:write', 'category:collection:read', 'category:tree:read', 'tags:read', 'tag:write'])]
    public string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}

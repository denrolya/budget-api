<?php

namespace App\DTO;

use Symfony\Component\Serializer\Annotation\Groups;

final class TagInput
{
    #[Groups(['category:write', 'tag:write'])]
    public string $name;
}


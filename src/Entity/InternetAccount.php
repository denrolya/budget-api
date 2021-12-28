<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\HasLifecycleCallbacks()
 * @ORM\Entity(repositoryClass="App\Repository\InternetAccountRepository")
 */
class InternetAccount extends Account
{
    public function getType(): string
    {
        return self::ACCOUNT_TYPE_INTERNET;
    }
}

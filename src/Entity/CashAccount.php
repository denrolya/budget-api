<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\HasLifecycleCallbacks()
 * @ORM\Entity(repositoryClass="App\Repository\CashAccountRepository")
 */
class CashAccount extends Account
{
    public function getType(): string
    {
        return self::ACCOUNT_TYPE_CASH;
    }
}

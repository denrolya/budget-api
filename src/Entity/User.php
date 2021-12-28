<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use FOS\UserBundle\Model\User as BaseUser;

/**
 * @ORM\Entity
 * @ORM\Table(name="user")
 */
class User extends BaseUser implements UserInterface
{
    public const TEST_PASSWORD = 'password';

    public const ROLE_ADMIN = 'ROLE_ADMIN';

    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @ORM\Embedded(class="UserSettings")
     */
    private $settings;

    public function __construct()
    {
        parent::__construct();
        $this->settings = new UserSettings();
    }

    public function getBaseCurrency(): string
    {
        return $this->getSettings()->getBaseCurrency();
    }

    public function getSettings(): UserSettings
    {
        return $this->settings;
    }

    public function setSettings(UserSettings $settings): UserInterface
    {
        $this->settings = $settings;

        return $this;
    }
}

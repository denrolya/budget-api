<?php

namespace App\EventSubscriber;

use App\Entity\User;
use JetBrains\PhpStorm\ArrayShape;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class AuthenticationSubscriber implements EventSubscriberInterface
{
    #[ArrayShape([Events::JWT_CREATED => "array[]"])]
    public static function getSubscribedEvents(): array
    {
        return [
            Events::JWT_CREATED => [
                ['onJWTCreated', 5],
            ],
        ];
    }

    public function onJWTCreated(JWTCreatedEvent $event): void
    {
        /** @var User $user */
        $user = $event->getUser();
        $payload = $event->getData();
        $settings = $user->getSettings();

        $payload['settings'] = [
            'uiTheme' => $settings->getUiTheme(),
            'dashboardStatistics' => $settings->getDashboardStatistics(),
            'baseCurrency' => $settings->getBaseCurrency(),
        ];

        $event->setData($payload);
    }
}

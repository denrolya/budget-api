<?php

namespace App\Traits;

use Gedmo\SoftDeleteable\SoftDeleteableListener;

trait SoftDeletableTogglerController
{
    private function disableSoftDeletable(): void
    {
        $em = $this->getDoctrine()->getManager();

        foreach($em->getEventManager()->getListeners() as $eventName => $listeners) {
            foreach($listeners as $listener) {
                if($listener instanceof SoftDeleteableListener) {
                    $em->getEventManager()->removeEventListener($eventName, $listener);
                }
            }
        }

        $em->getFilters()->disable('softdeleteable');
    }
}

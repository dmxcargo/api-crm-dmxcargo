<?php

namespace App\Sales\Domain;

interface EventOutbox
{
    public function publish(IntegrationEvent $event): void;
}

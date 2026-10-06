<?php

declare(strict_types=1);

namespace IanFoxDev\SloKit\Symfony;

use IanFoxDev\SloKit\Recorder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Records every main request when the kernel terminates: after the response is sent under
 * PHP-FPM, so writing the metrics adds nothing to the response time. The route is the
 * "_route" attribute the router sets; the duration counts from REQUEST_TIME_FLOAT, so it
 * includes booting the kernel.
 *
 *     services:
 *       IanFoxDev\SloKit\Symfony\SloSubscriber:
 *         autoconfigure: true
 */
final readonly class SloSubscriber implements EventSubscriberInterface
{
    public function __construct(private Recorder $recorder) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'onTerminate'];
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $route = $request->attributes->get('_route');
        $started = $request->server->get('REQUEST_TIME_FLOAT');
        $seconds = is_numeric($started) ? microtime(true) - (float) $started : 0.0;

        $this->recorder->observe(\is_string($route) ? $route : null, $event->getResponse()->getStatusCode(), $seconds, $request->getMethod());
    }
}

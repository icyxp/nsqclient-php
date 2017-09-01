<?php
/**
 * Queue API
 * User: moyo
 * Date: 31/03/2017
 * Time: 3:59 PM
 */

namespace NSQClient;

use NSQClient\Async\Queue as QueueAsync;
use NSQClient\Access\Endpoint;
use NSQClient\Connection\Lookupd;
use NSQClient\Connection\Nsqd;
use NSQClient\Connection\Pool;
use NSQClient\Logger\Logger;

class Queue
{
    /**
     * @param Endpoint $endpoint
     * @param string $topic
     * @param mixed $message
     * @param callable $asyncResult
     * @return bool
     */
    public static function publish(Endpoint $endpoint, $topic, $message, callable $asyncResult = null)
    {
        if ($endpoint->isAsynced()) return QueueAsync::publish($endpoint, $topic, $message, $asyncResult);

        $routes = Lookupd::getNodes($endpoint, $topic);

        $route = $routes[rand(0, count($routes) - 1)];

        $keys = [$route['host'], $route['ports']['tcp']];

        return Pool::register($keys, function () use ($endpoint, $route) {

            Logger::ins()->info('Creating new nsqd for producer', [
                'lookupd' => $endpoint->getLookupd(),
                'route' => $route
            ]);
            return (new Nsqd($endpoint))
                ->setRoute($route)
                ->setLifecycle(SDK::$pubRecyclingSec)
                ->setProducer();

        })->setTopic($topic)->publish($message);
    }

    /**
     * @param Endpoint $endpoint
     * @param string $topic
     * @param string $channel
     * @param callable $processor
     * @param int $lifecycle
     * @return null
     */
    public static function subscribe(Endpoint $endpoint, $topic, $channel, callable $processor, $lifecycle = 0)
    {
        if ($endpoint->isAsynced()) return QueueAsync::subscribe($endpoint, $topic, $channel, $processor, $lifecycle);

        $routes = Lookupd::getNodes($endpoint, $topic);

        foreach ($routes as $route)
        {
            $keys = [$route['topic'], $route['host'], $route['ports']['tcp']];

            Pool::register($keys, function () use ($endpoint, $route, $topic, $processor, $lifecycle) {

                Logger::ins()->info('Creating new nsqd for consumer', [
                    'lookupd' => $endpoint->getLookupd(),
                    'route' => $route,
                    'topic' => $topic,
                    'lifecycle' => $lifecycle
                ]);
                return (new Nsqd($endpoint))
                    ->setRoute($route)
                    ->setTopic($topic)
                    ->setLifecycle($lifecycle)
                    ->setConsumer($processor);

            })->subscribe($channel);
        }

        Pool::getEvLoop()->run();

        return null;
    }
}
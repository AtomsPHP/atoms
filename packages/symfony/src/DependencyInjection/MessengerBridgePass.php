<?php

declare(strict_types=1);

namespace Atoms\Symfony\DependencyInjection;

use Atoms\Client\Callback\QueueBridge;
use Atoms\Symfony\Messenger\AtomJobHandler;
use Atoms\Symfony\Messenger\AtomJobMessage;
use Atoms\Symfony\Messenger\MessengerQueueBridge;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

final class MessengerBridgePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!interface_exists(MessageBusInterface::class) || !$container->has(MessageBusInterface::class)) {
            return;
        }

        $container->register(MessengerQueueBridge::class, MessengerQueueBridge::class)
            ->setArgument('$bus', new Reference(MessageBusInterface::class))
            ->setPublic(false);

        $container->setAlias(QueueBridge::class, MessengerQueueBridge::class)->setPublic(true);

        if (class_exists(AsMessageHandler::class)) {
            $container->register(AtomJobHandler::class, AtomJobHandler::class)
                ->setPublic(false)
                ->addTag('messenger.message_handler', ['handles' => AtomJobMessage::class]);
        }
    }
}

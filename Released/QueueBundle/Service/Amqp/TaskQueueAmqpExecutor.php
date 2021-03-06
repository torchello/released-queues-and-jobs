<?php

namespace Released\QueueBundle\Service\Amqp;

use Monolog\Logger;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\Exception as TestException;
use Psr\Log\LoggerInterface;
use Released\QueueBundle\DependencyInjection\Util\ConfigQueuedTaskType;
use Released\QueueBundle\Exception\TaskAddException;
use Released\QueueBundle\Exception\TaskRetryException;
use Released\QueueBundle\Model\BaseTask;
use Released\QueueBundle\Service\EnqueuerInterface;
use Released\QueueBundle\Service\Logger\TaskLoggerAggregate;
use Released\QueueBundle\Service\TaskLoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;


class TaskQueueAmqpExecutor implements TaskLoggerInterface
{

    /** @var ReleasedAmqpFactory */
    protected $factory;
    /** @var EnqueuerInterface */
    protected $enqueuer;
    /** @var ContainerInterface */
    protected $container;
    /** @var ConfigQueuedTaskType[] */
    protected $types;
    /** @var LoggerInterface|null */
    protected $logger;

    protected $messagesLimit = null;
    protected $memoryLimit = null;

    function __construct(
        ReleasedAmqpFactory $factory,
        EnqueuerInterface $enqueuer,
        ContainerInterface $container,
        $types,
        LoggerInterface $logger = null
    ) {
        $this->factory = $factory;
        $this->enqueuer = $enqueuer;
        $this->container = $container;
        $this->types = $this->fixTypes($types);
        $this->logger = $logger;
    }

    /**
     * @param AMQPMessage $message
     * @param TaskLoggerInterface|null $logger
     *
     * @return void This function does not return anything anymore. If task failed it is restarted manually from callback if needed.
     */
    public function processMessage(AMQPMessage $message, TaskLoggerInterface $logger = null)
    {
        $payload = MessageUtil::unserialize($message->getBody());


        $logger = is_null($logger) ? $this : new TaskLoggerAggregate([$this, $logger]);

        // Create task
        $task = $this->createTaskInstance($payload);

        // Execute task and nack if false
        try {
            if (false !== $task->execute($this->container, $logger)) {
                // Enqueue next tasks
                if (isset($payload[TaskQueueAmqpEnqueuer::PAYLOAD_NEXT])) {
                    foreach ($payload[TaskQueueAmqpEnqueuer::PAYLOAD_NEXT] as $nextPayload) {
                        $producer = $this->factory->getProducer($this->types[$nextPayload['type']]);

                        $producer->publish(MessageUtil::serialize($nextPayload));
                    }
                }
            } else {
                $this->retryTask($task);
            }
        } catch (TaskRetryException $exception) {
            $this->retryTask($task, true);
        } catch (TestException $exception) {
            throw $exception;
        } catch (\Exception $exception) {
            if (!is_null($this->logger)) {
                $this->logger->critical(sprintf(
                    '[%s:%s] %s',
                    $exception->getFile(),
                    $exception->getLine(),
                    $exception->getMessage()
                ));
            }
            $this->retryTask($task);
        }
    }

    /** {@inheritdoc} */
    public function log(BaseTask $task, $message, $type = Logger::INFO)
    {
        $type = $this->getLogLevel($type);

        if (!is_null($this->logger)) {
            $this->logger->log($type, $message, ['task_type' => $task->getType()]);
        }
    }

    /**
     * @param $types
     * @return array
     */
    protected function fixTypes($types): array
    {
        $result = [];

        foreach ($types as $key => $type) {
            if ($type instanceof ConfigQueuedTaskType) {
                $result[$key] = $type;
            } else {
                $result[$key] = new ConfigQueuedTaskType($key, $type['class_name'], $type['priority'], $type['local'], $type['retry_limit']);
            }
        }

        return $result;
    }

    /**
     * @param null $messagesLimit
     * @return self
     */
    public function setMessagesLimit($messagesLimit)
    {
        $this->messagesLimit = $messagesLimit;
        return $this;
    }

    /**
     * @param null $memoryLimit
     * @return self
     */
    public function setMemoryLimit($memoryLimit)
    {
        $this->memoryLimit = $memoryLimit;
        return $this;
    }

    private function getLogLevel($type)
    {
        if (is_numeric($type)) {
            return $type;
        }

        switch ($type) {
            case self::LOG_ERROR:
                return Logger::ERROR;
            case self::LOG_MESSAGE:
                return Logger::INFO;
            case self::LOG_NOTICE:
                return Logger::DEBUG;

            default:
                return Logger::INFO;
        }
    }

    /**
     * @param BaseTask $task
     * @param bool $force Force requeue task
     */
    protected function retryTask(BaseTask $task, $force = false)
    {
        $type = $this->types[$task->getType()];

        if ($force || $task->getRetries() <= $type->getRetryLimit()) {
            $this->enqueuer->retry($task);
        }
    }

    /**
     * @param $payload
     * @return BaseTask
     */
    protected function createTaskInstance($payload): BaseTask
    {
        $type = $this->types[$payload[TaskQueueAmqpEnqueuer::PAYLOAD_TYPE]];
        $class = $type->getClassName();
        if (!is_subclass_of($class, BaseTask::class)) {
            throw new TaskAddException("Task class '{$class}' must be subclass of " . BaseTask::class);
        }

        /** @var BaseTask $task */
        $task = new $class($payload[TaskQueueAmqpEnqueuer::PAYLOAD_DATA] ?? []);
        $task->setRetries($payload[TaskQueueAmqpEnqueuer::PAYLOAD_RETRY] ?? 0);

        if (isset($payload[TaskQueueAmqpEnqueuer::PAYLOAD_NEXT])) {
            foreach ((array)$payload[TaskQueueAmqpEnqueuer::PAYLOAD_NEXT] as $next) {
                $task->addNextTask($this->createTaskInstance($next));
            }
        }

        return $task;
    }
}

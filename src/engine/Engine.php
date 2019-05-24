<?php

namespace Crm\ScenariosModule\Engine;

use Crm\ScenariosModule\Events\FinishWaitEventHandler;
use Crm\ScenariosModule\Events\SegmentCheckEventHandler;
use Crm\ScenariosModule\Events\SendEmailEventHandler;
use Crm\ScenariosModule\Repository\ElementsRepository;
use Crm\ScenariosModule\Repository\JobsRepository;
use Exception;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;
use Nette\Utils\Json;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Tomaj\Hermes\Emitter;
use Tracy\Debugger;

class Engine
{
    private $sleepTime = 100;

    private $logger;

    private $startTime;

    private $jobsRepository;

    private $graphConfiguration;

    private $elementsRepository;

    private $hermesEmitter;

    public function __construct(
        Emitter $hermesEmitter,
        JobsRepository $jobsRepository,
        GraphConfiguration $graphConfiguration,
        ElementsRepository $elementsRepository,
        LoggerInterface $logger = null
    ) {
        $this->logger = $logger;
        $this->startTime = new DateTime();
        $this->jobsRepository = $jobsRepository;
        $this->graphConfiguration = $graphConfiguration;
        $this->elementsRepository = $elementsRepository;
        $this->hermesEmitter = $hermesEmitter;
    }

    public function run(bool $once = false)
    {
        try {
            while (true) {
                $this->graphConfiguration->reloadIfOutdated();

                foreach ($this->jobsRepository->getUnprocessedJobs()->fetchAll() as $job) {
                    $this->processCreatedJob($job);
                }

                foreach ($this->jobsRepository->getFinishedJobs()->fetchAll() as $job) {
                    $this->processFinishedJob($job);
                }

                foreach ($this->jobsRepository->getFailedJobs()->fetchAll() as $job) {
                    $this->processFailedJob($job);
                }

                if ($once) {
                    break;
                }

                sleep($this->sleepTime);
            }
        } catch (Exception $exception) {
            Debugger::log($exception, Debugger::EXCEPTION);
        }
    }

    private function processCreatedJob(ActiveRow $job)
    {
        // Triggers can be directly executed
        if ($job->trigger_id) {
            $this->jobsRepository->update($job, [
                'started_at' => new DateTime(),
                'finished_at' => new DateTime(),
                'state' => JobsRepository::STATE_FINISHED
            ]);
            $this->scheduleNextAfterTrigger($job);
        } elseif ($job->element_id) {
            $this->processJobElement($job);
        } else {
            $this->log(LogLevel::ERROR, 'Scenarios job without associated trigger or element', $this->jobLoggerContext($job));
            $job->delete();
        }
    }

    private function processJobElement(ActiveRow $job)
    {
        $element = $this->elementsRepository->find($job->element_id);
        $options = Json::decode($element->options);

        try {
            switch ($element->type) {
                case ElementsRepository::ELEMENT_TYPE_EMAIL:{
                    $this->jobsRepository->scheduleJob($job);
                    $this->hermesEmitter->emit(SendEmailEventHandler::createHermesMessage($job->id));
                    break;
                }
                case ElementsRepository::ELEMENT_TYPE_SEGMENT:{
                    $this->jobsRepository->scheduleJob($job);
                    $this->hermesEmitter->emit(SegmentCheckEventHandler::createHermesMessage($job->id));
                    break;
                }
                case ElementsRepository::ELEMENT_TYPE_WAIT:{
                    if (!isset($options['minutes'])) {
                        throw new InvalidJobException("Associated job element has no 'minutes' option");
                    }
                    $this->jobsRepository->startJob($job);
                    $this->hermesEmitter->emit(FinishWaitEventHandler::createHermesMessage($job->id, (int) $options['minutes']));
                    break;
                }
                default:{
                    throw new InvalidJobException('Associated job element has wrong type');
                    break;
                }
            }
        } catch (InvalidJobException $exception) {
            $this->log(LogLevel::ERROR, $exception->getMessage(), $this->jobLoggerContext($job));
            $job->delete();
        }
    }

    private function scheduleNextAfterTrigger(ActiveRow $job)
    {
        foreach ($this->graphConfiguration->triggerElements($job->trigger_id) as $elementId) {
            $this->jobsRepository->addElement($elementId, Json::decode($job->parameters));
        }
    }

    private function scheduleNextAfterElement(ActiveRow $job)
    {
        // TODO
    }

    private function log($level, string $message, array $context = [])
    {
        if ($this->logger) {
            $this->logger->log($level, $message, $context);
        }
    }

    private function jobLoggerContext(ActiveRow $job): array
    {
        return [
            'scenario_id' => $job->scenario_id,
            'trigger_id' => $job->trigger_id,
            'element_id' => $job->element_id,
            'state' => $job->state,
            'parameters' => $job->parameters,
            'result' => $job->result,
            'started_at' => $job->started_at,
            'finished_at' => $job->finished_at,
            'updated_at' => $job->updated_at,
        ];
    }

    private function deleteFinishedJobs()
    {
        // TODO
    }
}

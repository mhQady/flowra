<?php

namespace Flowra\Services;

use Flowra\Concretes\BaseWorkflow;
use Flowra\Contracts\HasWorkflowContract;
use Flowra\DTOs\BulkTransitionResult;
use Flowra\DTOs\Transition;
use Flowra\Exceptions\ApplyTransitionException;
use Illuminate\Support\LazyCollection;
use RuntimeException;
use Throwable;

class BulkTransitionService
{
    private Transition|string|null $transition = null;

    private iterable $targets = [];

    private int|string|null $appliedBy = null;
    private array $comments = [];

    private bool $continueOnError = false;

    private ?int $chunk = null;

    /**
     * @param  class-string<BaseWorkflow>  $workflowClass
     */
    private function __construct(private readonly string $workflowClass)
    {
        if (!is_subclass_of($workflowClass, BaseWorkflow::class)) {
            throw new RuntimeException(
                "Bulk transitions require a workflow class extending ".BaseWorkflow::class
            );
        }
    }

    /**
     * @param  class-string<BaseWorkflow>  $workflowClass
     */
    public static function for(string $workflowClass): self
    {
        return new self($workflowClass);
    }

    public function transition(string|Transition $transition): self
    {
        $this->transition = $transition;

        return $this;
    }

    public function targets(iterable $targets): self
    {
        $this->targets = $targets;

        return $this;
    }

    public function appliedBy(int|string|null $userId): self
    {
        $this->appliedBy = $userId;

        return $this;
    }

    public function comments(array $comments): self
    {
        $this->comments = array_merge($this->comments, $comments);

        return $this;
    }


    public function continueOnError(bool $value = true): self
    {
        $this->continueOnError = $value;

        return $this;
    }


    public function chunk(int|null $size = null): self
    {
        $this->chunk = $size > 0 ? $size : null;

        return $this;
    }

    /**
     * @throws Throwable
     */
    public function run(): BulkTransitionResult
    {
        if ($this->transition === null) {
            throw new ApplyTransitionException('Transition is required before running a bulk transition.');
        }

        $resolved = $this->resolveTransition($this->transition);

        $successes = [];
        $failures = [];

        foreach ($this->chunkTargets(LazyCollection::make($this->targets)) as $chunk) {
            foreach ($chunk as $target) {
                try {
                    $workflow = $this->workflowForTarget($target);
                    $transition = $this->prepareTransition($resolved, $workflow);

                    $workflow->apply($transition);

                    $successes[] = [
                        'target' => $target,
                        'status' => $workflow->status(),
                    ];
                } catch (Throwable $exception) {
                    $failures[] = [
                        'target' => $target,
                        'exception' => $exception,
                    ];

                    if (!$this->continueOnError) {
                        throw $exception;
                    }
                }
            }
        }

        return new BulkTransitionResult($successes, $failures);
    }

    private function resolveTransition(string|Transition $transition): Transition
    {
        if ($transition instanceof Transition) {
            return $transition;
        }

        $workflow = $this->workflowClass;
        $available = $workflow::transitions();

        if (!array_key_exists($transition, $available)) {
            throw new ApplyTransitionException("Transition [$transition] not found for workflow [$workflow].");
        }

        return $available[$transition];
    }

    private function workflowForTarget(mixed $target): BaseWorkflow
    {
        $workflow = $this->workflowClass;

        if ($target instanceof BaseWorkflow) {

            if (!($target instanceof $workflow)) {
                throw new ApplyTransitionException('Bulk transition requires targets of the same workflow class.');
            }

            return $target;
        }

        if (!($target instanceof HasWorkflowContract)) {
            throw new ApplyTransitionException('Bulk transition targets must implement HasWorkflowContract.');
        }

        return new $workflow($target);
    }

    private function prepareTransition(Transition $resolved, BaseWorkflow $workflow): Transition
    {
        $transition = clone $resolved;

        $transition->workflow($workflow);

        if ($this->appliedBy !== null) {
            $transition->appliedBy($this->appliedBy);
        }

        if ($this->comments !== []) {
            $transition->comment(...$this->comments);
        }

        return $transition;
    }

    private function chunkTargets(LazyCollection $targets): iterable
    {
        if ($this->chunk === null) {
            yield $targets;

            return;
        }

        foreach ($targets->chunk($this->chunk) as $chunk) {
            yield $chunk;
        }
    }

    /**
     * @throws Throwable
     */
    public function apply(
        iterable $targets,
        string|Transition $transition,
        int|string|null $appliedBy = null,
        array $comments = [],
        bool $continueOnError = false,
        ?int $chunk = null

    ): BulkTransitionResult {

        return $this->targets($targets)
            ->transition($transition)
            ->appliedBy($appliedBy)
            ->comments($comments)
            ->continueOnError($continueOnError)
            ->chunk($chunk)
            ->run();
    }
}

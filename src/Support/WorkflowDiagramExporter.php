<?php

namespace Flowra\Support;

use BackedEnum;
use Flowra\Concretes\BaseWorkflow;
use Flowra\DTOs\StateGroup;
use Flowra\DTOs\Transition;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use UnitEnum;

class WorkflowDiagramExporter
{
    public function render(string $workflowClass, string $format = 'mermaid'): string
    {
        $format = strtolower($format);

        $workflow = $this->buildWorkflowMetadata($workflowClass);
        return match ($format) {
            'mermaid' => $this->renderMermaid($workflow),
            'plantuml', 'plant-uml', 'plant' => $this->renderPlantUml($workflow),
            default => throw new InvalidArgumentException("Unsupported export format [{$format}]."),
        };
    }

    /**
     * @return array{
     *     class: class-string<BaseWorkflow>,
     *     states: array<string, array{id: string, label: string, group: string|null, is_group: bool}>,
     *     groups: array<int, array{key: string, children: array<int, string>}>,
     *     transitions: array<int, array{key: string, from: string, to: string}>
     * }
     */
    private function buildWorkflowMetadata(string $workflowClass): array
    {
        if (!class_exists($workflowClass)) {
            throw new InvalidArgumentException("Workflow class [{$workflowClass}] does not exist.");
        }

        if (!is_subclass_of($workflowClass, BaseWorkflow::class)) {
            throw new InvalidArgumentException("Workflow class [{$workflowClass}] must extend ".BaseWorkflow::class.'.');
        }

        $states = [];
        $usedIdentifiers = [];

        foreach ($workflowClass::states() as $state) {
            $this->registerState($states, $usedIdentifiers, $this->stateKey($state), $this->enumLabel($state));
        }

        $transitions = [];

        foreach ($workflowClass::transitions() as $transition) {
            if (!$transition instanceof Transition) {
                continue;
            }

            $fromKey = $this->stateKey($transition->from);
            $toKey = $this->stateKey($transition->to);

            $this->registerState($states, $usedIdentifiers, $fromKey, $this->enumLabel($transition->from));
            $this->registerState($states, $usedIdentifiers, $toKey, $this->enumLabel($transition->to));

            $transitions[] = [
                'key' => $transition->key,
                'from' => $fromKey,
                'to' => $toKey,
            ];
        }

        $groups = [];

        foreach ($workflowClass::stateGroups() as $group) {
            if ($group instanceof StateGroup) {
                $group = $group->toArray();
            }

            $groupStateMeta = Arr::get($group, 'state');

            if (!$groupStateMeta) {
                continue;
            }

            $groupKey = (string) Arr::get($groupStateMeta, 'key');
            $groupLabel = $this->groupLabel($groupStateMeta);

            $this->registerState($states, $usedIdentifiers, $groupKey, $groupLabel, true);

            $childKeys = [];
            foreach (Arr::get($group, 'children', []) as $childMeta) {
                $childKey = (string) Arr::get($childMeta, 'key');
                $childLabel = $this->groupLabel($childMeta);

                $this->registerState($states, $usedIdentifiers, $childKey, $childLabel);

                $states[$childKey]['group'] = $groupKey;
                $childKeys[] = $childKey;
            }

            $groups[] = [
                'key' => $groupKey,
                'children' => $childKeys,
            ];
        }

        return [
            'class' => $workflowClass,
            'states' => $states,
            'groups' => $groups,
            'transitions' => $transitions,
        ];
    }

    private function renderMermaid(array $workflow): string
    {
        $lines = [
            'stateDiagram-v2',
            sprintf('    %% %s', $workflow['class']),
        ];

        foreach ($workflow['groups'] as $group) {
            $groupState = $workflow['states'][$group['key']] ?? null;

            if (!$groupState) {
                continue;
            }

            $lines[] = sprintf('    state "%s" as %s {', $groupState['label'], $groupState['id']);

            foreach ($group['children'] as $childKey) {
                $child = $workflow['states'][$childKey] ?? null;
                if (!$child) {
                    continue;
                }

                $lines[] = sprintf('        state "%s" as %s', $child['label'], $child['id']);
            }

            $lines[] = '    }';
        }

        foreach ($workflow['states'] as $state) {
            if ($state['group'] !== null || $state['is_group']) {
                continue;
            }

            $lines[] = sprintf('    state "%s" as %s', $state['label'], $state['id']);
        }

        if ($workflow['transitions'] !== []) {
            $lines[] = '';
        }

        foreach ($workflow['transitions'] as $transition) {
            $from = $workflow['states'][$transition['from']]['id'] ?? $transition['from'];
            $to = $workflow['states'][$transition['to']]['id'] ?? $transition['to'];
            $lines[] = sprintf('    %s --> %s : %s', $from, $to, $transition['key']);
        }

        return implode(PHP_EOL, $lines);
    }

    private function renderPlantUml(array $workflow): string
    {
        $lines = [
            '@startuml',
            sprintf('title %s', $workflow['class']),
            '',
        ];

        foreach ($workflow['groups'] as $group) {
            $groupState = $workflow['states'][$group['key']] ?? null;
            if (!$groupState) {
                continue;
            }

            $lines[] = sprintf('state "%s" as %s {', $groupState['label'], $groupState['id']);

            foreach ($group['children'] as $childKey) {
                $child = $workflow['states'][$childKey] ?? null;
                if (!$child) {
                    continue;
                }

                $lines[] = sprintf('    state "%s" as %s', $child['label'], $child['id']);
            }

            $lines[] = '}';
            $lines[] = '';
        }

        foreach ($workflow['states'] as $state) {
            if ($state['group'] !== null || $state['is_group']) {
                continue;
            }

            $lines[] = sprintf('state "%s" as %s', $state['label'], $state['id']);
        }

        if ($workflow['transitions'] !== []) {
            $lines[] = '';
        }

        foreach ($workflow['transitions'] as $transition) {
            $from = $workflow['states'][$transition['from']]['id'] ?? $transition['from'];
            $to = $workflow['states'][$transition['to']]['id'] ?? $transition['to'];
            $lines[] = sprintf('%s --> %s : %s', $from, $to, $transition['key']);
        }

        $lines[] = '@enduml';

        return implode(PHP_EOL, $lines);
    }

    private function registerState(
        array &$states,
        array &$usedIdentifiers,
        string $key,
        string $label,
        bool $isGroup = false
    ): void {
        if ($key === '') {
            $key = spl_object_hash((object) []);
        }

        if (!isset($states[$key])) {
            $states[$key] = [
                'id' => $this->identifierFrom($key, $usedIdentifiers),
                'label' => $label !== '' ? $label : $key,
                'group' => null,
                'is_group' => $isGroup,
            ];
            return;
        }

        if ($states[$key]['label'] === '' && $label !== '') {
            $states[$key]['label'] = $label;
        }

        if ($isGroup) {
            $states[$key]['is_group'] = true;
        }
    }

    private function stateKey(UnitEnum|string $state): string
    {
        if ($state instanceof BackedEnum) {
            return (string) $state->value;
        }

        if ($state instanceof UnitEnum) {
            return $state->name;
        }

        return (string) $state;
    }

    private function enumLabel(UnitEnum $state): string
    {
        if ($state instanceof BackedEnum) {
            return sprintf('%s (%s)', $state->name, $state->value);
        }

        return $state->name;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function groupLabel(array $meta): string
    {
        $name = (string) ($meta['name'] ?? '');
        $value = (string) ($meta['value'] ?? '');

        if ($name !== '' && $value !== '' && $name !== $value) {
            return sprintf('%s (%s)', $name, $value);
        }

        if ($name !== '') {
            return $name;
        }

        if ($value !== '') {
            return $value;
        }

        return (string) ($meta['key'] ?? 'state');
    }

    private function identifierFrom(string $key, array &$usedIdentifiers): string
    {
        $base = preg_replace('/[^A-Za-z0-9_]/', '_', $key);
        $base = $base !== '' ? trim($base, '_') : 'state';
        if ($base === '') {
            $base = 'state';
        }

        $candidate = $base;
        $suffix = 2;

        while (in_array($candidate, $usedIdentifiers, true)) {
            $candidate = $base.'_'.$suffix;
            $suffix++;
        }

        $usedIdentifiers[] = $candidate;

        return $candidate;
    }
}

<?php

namespace Flowra\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Parses Mermaid/PlantUML diagrams into normalized Flowra workflow data.
 */
class WorkflowDiagramImporter
{
    /**
     * @var array<string>
     */
    private array $usedStateCases = [];

    /**
     * Parse the supplied diagram into states, transitions, and group metadata.
     *
     * @param  string  $diagram
     * @param  string  $format
     * @return array{
     *     format: string,
     *     states: array<string, array{id: string, case: string, value: string, label: string}>,
     *     transitions: array<int, array{key: string, from: string, to: string, label: string}>,
     *     groups: array<int, array{parent: string, children: array<int, string>}>
     * }
     */
    public function parse(string $diagram, string $format = 'auto'): array
    {
        $diagram = trim($diagram);

        if ($diagram === '') {
            throw new InvalidArgumentException('Diagram input is empty.');
        }

        $this->usedStateCases = [];

        $format = $this->detectFormat($diagram, $format);

        $states = $this->parseStates($diagram);
        $groups = $this->parseGroups($diagram);
        $transitions = $this->parseTransitions($diagram, $states);

        if ($states === []) {
            throw new InvalidArgumentException('No states were found in the supplied diagram.');
        }

        if ($transitions === []) {
            throw new InvalidArgumentException('No transitions were found in the supplied diagram.');
        }

        return [
            'format' => $format,
            'states' => $states,
            'transitions' => $transitions,
            'groups' => $groups,
        ];
    }

    /**
     * Determine which diagram format the parser should use.
     *
     * @param  string  $diagram
     * @param  string  $hint
     */
    private function detectFormat(string $diagram, string $hint): string
    {
        $hint = strtolower($hint);

        if ($hint !== 'auto') {
            return $hint;
        }

        if (str_contains($diagram, '@startuml') || str_contains($diagram, '@enduml')) {
            return 'plantuml';
        }

        if (str_contains($diagram, 'stateDiagram')) {
            return 'mermaid';
        }

        return 'mermaid';
    }

    /**
     * Extract and normalize unique states from the diagram.
     *
     * @param  string  $diagram
     * @return array<string, array{id: string, case: string, value: string, label: string}>
     */
    private function parseStates(string $diagram): array
    {
        preg_match_all('/state\s+"([^"]+)"\s+as\s+([A-Za-z0-9_]+)/', $diagram, $matches, PREG_SET_ORDER);

        $states = [];
        foreach ($matches as $match) {
            $label = trim($match[1]);
            $identifier = trim($match[2]);

            $parsed = $this->normalizeStateLabel($label);

            $case = $this->uniqueCaseName($parsed['case']);

            $states[$identifier] = [
                'id' => $identifier,
                'case' => $case,
                'value' => $parsed['value'],
                'label' => $label,
            ];
        }

        return $states;
    }

    /**
     * Parse transitions between states, adding fallback states as needed.
     *
     * @param  string  $diagram
     * @param  array<string, array{id: string, case: string, value: string, label: string}>  $states
     * @return array<int, array{key: string, from: string, to: string, label: string}>
     */
    private function parseTransitions(string $diagram, array &$states): array
    {
        preg_match_all('/([A-Za-z0-9_]+)\s*-->\s*([A-Za-z0-9_]+)\s*(?::\s*(.+))?/', $diagram, $matches, PREG_SET_ORDER);

        $transitions = [];
        $usedKeys = [];
        $counter = 1;

        foreach ($matches as $match) {
            $from = trim($match[1]);
            $to = trim($match[2]);
            $label = trim($match[3] ?? '');

            $key = $label !== ''
                ? $this->formatTransitionKey($label)
                : $this->formatTransitionKey($from.'_'.$to);

            if ($key === '') {
                $key = 'transition_'.$counter;
            }

            $key = $this->uniqueTransitionKey($key, $usedKeys);
            $counter++;

            $states[$from] ??= $this->fallbackState($from);
            $states[$to] ??= $this->fallbackState($to);

            $transitions[] = [
                'key' => $key,
                'from' => $from,
                'to' => $to,
                'label' => $label,
            ];
        }

        return $transitions;
    }

    /**
     * Normalize a Mermaid state label into enum case/value metadata.
     *
     * @param  string  $label
     * @return array{case: string, value: string}
     */
    private function normalizeStateLabel(string $label): array
    {
        if (preg_match('/^(.+?)\s*\((.+)\)$/', $label, $match)) {
            $name = trim($match[1]);
            $value = trim($match[2]);
        } else {
            $name = $label;
            $value = Str::of($label)->snake()->toString();
        }

        $case = Str::of($name)
            ->replaceMatches('/[^A-Za-z0-9]+/', '_')
            ->trim('_')
            ->upper()
            ->toString();

        if ($case === '') {
            $case = 'STATE';
        }

        $value = Str::of($value)->snake()->toString();

        return [
            'case' => $case,
            'value' => $value !== '' ? $value : Str::of($case)->lower()->replace('_', '-')->toString(),
        ];
    }

    /**
     * Ensure the generated enum case name is unique.
     *
     * @param  string  $candidate
     */
    private function uniqueCaseName(string $candidate): string
    {
        $original = $candidate;
        $suffix = 2;

        while (in_array($candidate, $this->usedStateCases, true)) {
            $candidate = $original.'_'.$suffix;
            $suffix++;
        }

        $this->usedStateCases[] = $candidate;

        return $candidate;
    }

    /**
     * Convert a transition label into a snake_case transition key.
     *
     * @param  string  $label
     */
    private function formatTransitionKey(string $label): string
    {
        return Str::of($label)
            ->snake()
            ->replace('-', '_')
            ->replaceMatches('/_{2,}/', '_')
            ->trim('_')
            ->toString();
    }

    /**
     * Ensure transition keys are unique across the diagram.
     *
     * @param  string  $candidate
     * @param  array<string>  $used
     */
    private function uniqueTransitionKey(string $candidate, array &$used): string
    {
        $original = $candidate;
        $suffix = 2;

        while (in_array($candidate, $used, true)) {
            $candidate = $original.'_'.$suffix;
            $suffix++;
        }

        $used[] = $candidate;

        return $candidate;
    }

    /**
     * Build a placeholder state entry when a node is referenced but not declared.
     *
     * @param  string  $identifier
     * @return array{id: string, case: string, value: string, label: string}
     */
    private function fallbackState(string $identifier): array
    {
        $parsed = $this->normalizeStateLabel($identifier);
        $case = $this->uniqueCaseName($parsed['case']);

        return [
            'id' => $identifier,
            'case' => $case,
            'value' => $parsed['value'],
            'label' => $identifier,
        ];
    }

    /**
     * Detect nested state blocks and return parent/child group relationships.
     *
     * @param  string  $diagram
     * @return array<int, array{parent: string, children: array<int, string>}>
     */
    private function parseGroups(string $diagram): array
    {
        $lines = preg_split('/\R/', $diagram) ?: [];
        $groups = [];
        $stack = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            if (preg_match('/state\s+"[^"]+"\s+as\s+([A-Za-z0-9_]+)\s*\{/', $trimmed, $match)) {
                $parent = $match[1];
                $stack[] = $parent;
                $groups[$parent] ??= [];
                continue;
            }

            if (str_contains($trimmed, '}')) {
                $closing = substr_count($trimmed, '}');
                while ($closing-- > 0 && $stack !== []) {
                    array_pop($stack);
                }
                continue;
            }

            if ($stack !== [] && preg_match('/state\s+"[^"]+"\s+as\s+([A-Za-z0-9_]+)/', $trimmed, $match)) {
                $child = $match[1];
                $parent = end($stack);
                if ($parent !== false) {
                    $groups[$parent][] = $child;
                }
            }
        }

        $result = [];

        foreach ($groups as $parent => $children) {
            $children = array_values(array_unique(array_filter($children)));

            if ($children === []) {
                continue;
            }

            $result[] = [
                'parent' => $parent,
                'children' => $children,
            ];
        }

        return $result;
    }
}

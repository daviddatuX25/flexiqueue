<?php

namespace App\Http\Requests;

use App\Models\Program;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Per program diagram visualizer plan: validate layout payload on PUT.
 * Tier 2.1: entity types must have entityId belonging to program.
 * Tier 2.2: invalid/deleted entityId is rejected (422). User should remove the node from the diagram or re-add from sidebar.
 */
class UpdateProgramDiagramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Ensure layout has nodes and edges (default empty) so "no diagram yet" is valid.
     */
    protected function prepareForValidation(): void
    {
        $layout = $this->input('layout', []);
        if (! is_array($layout)) {
            return;
        }
        if (! array_key_exists('nodes', $layout)) {
            $layout['nodes'] = [];
        }
        if (! array_key_exists('edges', $layout)) {
            $layout['edges'] = [];
        }
        $this->merge(['layout' => $layout]);
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $nodeTypes = 'station,track,process,staff,client_seat,image,shape,text,station_group,process_handle';

        return [
            'layout' => ['required', 'array'],
            'layout.viewport' => ['sometimes', 'array'],
            'layout.viewport.x' => ['sometimes', 'numeric'],
            'layout.viewport.y' => ['sometimes', 'numeric'],
            'layout.viewport.zoom' => ['sometimes', 'numeric', 'min:0.1', 'max:2'],
            'layout.nodes' => ['sometimes', 'array'],
            'layout.nodes.*.id' => ['required', 'string'],
            'layout.nodes.*.type' => ['required', 'string', 'in:'.$nodeTypes],
            'layout.nodes.*.position' => ['required', 'array'],
            'layout.nodes.*.position.x' => ['required', 'numeric'],
            'layout.nodes.*.position.y' => ['required', 'numeric'],
            'layout.nodes.*.parentId' => ['sometimes', 'nullable', 'string'],
            'layout.nodes.*.entityId' => ['sometimes', 'nullable', 'integer'],
            'layout.nodes.*.width' => ['sometimes', 'nullable', 'numeric', 'min:1'],
            'layout.nodes.*.height' => ['sometimes', 'nullable', 'numeric', 'min:1'],
            'layout.nodes.*.data' => ['sometimes', 'array'],
            'layout.edges' => ['sometimes', 'array'],
            'layout.edges.*.id' => ['sometimes', 'string'],
            'layout.edges.*.source' => ['sometimes', 'string'],
            'layout.edges.*.target' => ['sometimes', 'string'],
            'layout.edges.*.type' => ['sometimes', 'string'],
            'layout.edges.*.data' => ['sometimes', 'array'],
        ];
    }

    /**
     * Ensure entity nodes have entityId belonging to the program. Per plan Tier 2.1.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $program = $this->route('program');
            if (! $program instanceof Program) {
                return;
            }

            $layout = $this->input('layout');
            $nodes = $layout['nodes'] ?? [];
            if (! is_array($nodes)) {
                return;
            }

            $validStationIds = $program->stations()->pluck('id')->all();
            $validTrackIds = $program->serviceTracks()->pluck('id')->all();
            $validProcessIds = $program->processes()->pluck('id')->all();
            $validStaffUserIds = $program->stationAssignments()->pluck('user_id')
                ->merge($program->supervisedBy()->pluck('users.id'))
                ->unique()->values()->all();
            $stationProcessIds = $program->stations()->with('processes')->get()
                ->mapWithKeys(fn ($s) => [$s->id => $s->processes->pluck('id')->all()]);

            $entityTypes = ['station', 'track', 'process', 'staff', 'client_seat', 'station_group', 'process_handle'];
            foreach ($nodes as $index => $node) {
                if (! is_array($node)) {
                    continue;
                }
                $type = $node['type'] ?? null;
                if (! in_array($type, $entityTypes, true)) {
                    continue;
                }

                if ($type === 'client_seat') {
                    continue;
                }

                if ($type === 'process_handle') {
                    $data = $node['data'] ?? [];
                    $stationId = isset($data['stationId']) ? (int) $data['stationId'] : null;
                    $processId = isset($data['processId']) ? (int) $data['processId'] : null;
                    if ($stationId === null || $processId === null) {
                        $validator->errors()->add(
                            "layout.nodes.{$index}.data",
                            'Process handle node requires data.stationId and data.processId.'
                        );
                        continue;
                    }
                    if (! in_array($stationId, $validStationIds, true)) {
                        $validator->errors()->add(
                            "layout.nodes.{$index}.data.stationId",
                            "Station id {$stationId} does not belong to this program."
                        );
                        continue;
                    }
                    if (! in_array($processId, $validProcessIds, true)) {
                        $validator->errors()->add(
                            "layout.nodes.{$index}.data.processId",
                            "Process id {$processId} does not belong to this program."
                        );
                        continue;
                    }
                    $allowedProcessIds = $stationProcessIds[$stationId] ?? [];
                    if (! in_array($processId, $allowedProcessIds, true)) {
                        $validator->errors()->add(
                            "layout.nodes.{$index}.data.processId",
                            "Process id {$processId} is not assigned to station id {$stationId}."
                        );
                    }
                    continue;
                }

                if ($type === 'station_group') {
                    $entityId = $node['entityId'] ?? (is_array($node['data'] ?? null) ? ($node['data']['stationId'] ?? null) : null);
                    if ($entityId === null || $entityId === '') {
                        $validator->errors()->add(
                            "layout.nodes.{$index}.entityId",
                            'Station group node requires a valid station (entityId or data.stationId).'
                        );
                        continue;
                    }
                    $entityId = (int) $entityId;
                    if (! in_array($entityId, $validStationIds, true)) {
                        $validator->errors()->add(
                            "layout.nodes.{$index}.entityId",
                            "Station id {$entityId} does not belong to this program."
                        );
                    }
                    continue;
                }

                $entityId = $node['entityId'] ?? (is_array($node['data'] ?? null) ? ($node['data']['entityId'] ?? null) : null);
                if ($entityId === null || $entityId === '') {
                    $validator->errors()->add(
                        "layout.nodes.{$index}.entityId",
                        "Node type \"{$type}\" requires a valid entityId."
                    );
                    continue;
                }

                $entityId = (int) $entityId;
                $valid = match ($type) {
                    'station' => in_array($entityId, $validStationIds, true),
                    'track' => in_array($entityId, $validTrackIds, true),
                    'process' => in_array($entityId, $validProcessIds, true),
                    'staff' => in_array($entityId, $validStaffUserIds, true),
                    default => false,
                };

                if (! $valid) {
                    $validator->errors()->add(
                        "layout.nodes.{$index}.entityId",
                        "Entity id {$entityId} does not belong to this program for type \"{$type}\". Remove the node or re-add from the sidebar."
                    );
                }
            }
        });
    }
}

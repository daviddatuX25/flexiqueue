<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDiagramImageRequest;
use App\Http\Requests\UpdateProgramDiagramRequest;
use App\Models\Program;
use App\Models\ProgramDiagram;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

/**
 * Per program diagram visualizer plan: GET/PUT diagram layout, optional image upload.
 * Auth: admin only (middleware).
 */
class ProgramDiagramController extends Controller
{
    /**
     * GET /api/admin/programs/{program}/diagram
     * Return { layout: {...} } or { layout: null } if no diagram saved.
     */
    public function show(Program $program): JsonResponse
    {
        $diagram = $program->diagram;

        return response()->json([
            'layout' => $diagram?->layout,
        ]);
    }

    /**
     * PUT /api/admin/programs/{program}/diagram
     * Upsert diagram layout. Request body: { layout: { viewport?, nodes, edges? } }.
     */
    public function update(UpdateProgramDiagramRequest $request, Program $program): JsonResponse
    {
        $layout = $request->validated('layout');

        $diagram = ProgramDiagram::updateOrCreate(
            ['program_id' => $program->id],
            ['layout' => $layout]
        );

        return response()->json([
            'layout' => $diagram->layout,
        ]);
    }

    /**
     * POST /api/admin/programs/{program}/diagram/image
     * Upload image for diagram decoration. Returns { url } for use in image node data.url.
     */
    public function storeImage(StoreDiagramImageRequest $request, Program $program): JsonResponse
    {
        $file = $request->file('image');
        $ext = $file->getClientOriginalExtension() ?: 'jpg';
        $filename = $program->id.'_'.Str::uuid()->toString().'.'.$ext;

        Storage::disk('public')->makeDirectory('diagram-images');
        $path = $file->storeAs('diagram-images', $filename, 'public');

        if (! Storage::disk('public')->exists($path)) {
            return response()->json(['message' => 'Image could not be stored.'], 500);
        }

        $url = Storage::disk('public')->url($path);

        return response()->json(['url' => $url], 201);
    }
}

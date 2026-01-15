<?php

namespace App\Http\Controllers;

use App\Models\DecisionsMaker;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DecisionMakerController extends Controller
{
    /**
     * Show all decision makers
     */
    public function index()
    {
        $decisionMakers = DecisionsMaker::with(['media'])
            ->where('active', true)
            ->get();

        return response()->json($decisionMakers, Response::HTTP_OK);
    }

    /**
     * Show decision maker by id
     */
    public function show($id)
    {
        $decisionMaker = DecisionsMaker::with(['user', 'media'])
            ->find($id);

        if (!$decisionMaker) {
            return response()->json([
                'message' => 'Decision maker not found'
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json($decisionMaker, Response::HTTP_OK);
    }

    /**
     * Create decision maker
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'    => 'required|string|max:255',
            'surname' => 'required|string|max:255',
            'email'   => 'required|email|unique:decisions_makers,email',
            'phone' => 'required|string|max:50',
            'media'               => 'required|array|min:1',
            'media.*.type'        => 'required|string|max:50',
            'media.*.id'          => 'required|integer|exists:media,id',
        ]);

        $hasIdentityDocument = collect($validated['media'])
            ->contains(fn ($m) => $m['type'] === 'identity_document');

        if (!$hasIdentityDocument) {
            return response()->json([
                'message' => 'Il documento d\'identità è obbligatorio'
            ], 422);
        }

        $decisionMaker = null;

        \DB::transaction(function () use ($validated, &$decisionMaker) {

            $decisionMaker = DecisionsMaker::create([
                'name'    => $validated['name'],
                'surname' => $validated['surname'],
                'email'   => $validated['email'],
                'phone'   => $validated['phone'],
                'active'  => true,
            ]);

            foreach ($validated['media'] as $media) {
                \App\Models\DecisionsMakerMedia::create([
                    'id_decision_maker' => $decisionMaker->id,
                    'id_media'          => $media['id'],
                    'type'              => $media['type'],
                ]);
            }
        });

        return response()->json($decisionMaker->load('media'), 201);
    }

    /**
     * Update decision maker
     */
    public function update(Request $request, $id)
    {
        $decisionMaker = DecisionsMaker::find($id);

        if (!$decisionMaker) {
            return response()->json([
                'message' => 'Decision maker not found'
            ], Response::HTTP_NOT_FOUND);
        }

        $validated = $request->validate([
            'name'    => 'sometimes|string|max:255',
            'surname' => 'sometimes|string|max:255',
            'email'   => 'sometimes|email|unique:decisions_makers,email,' . $decisionMaker->id,
            'phone'   => 'nullable|string|max:50',
            'id_user' => 'sometimes|integer|exists:users,id',

            'media'        => 'sometimes|array|min:1',
            'media.*.type' => 'required_with:media|string|max:50',
            'media.*.id'   => 'required_with:media|integer|exists:media,id',
        ]);

        if (isset($validated['media'])) {
            $hasIdentityDocument = collect($validated['media'])
                ->contains(fn ($media) => $media['type'] === 'identity_document');

            if (!$hasIdentityDocument) {
                return response()->json([
                    'message' => 'Identity document is required'
                ], 422);
            }
        }

        \DB::transaction(function () use ($validated, $decisionMaker) {

            $decisionMaker->update(
                collect($validated)->except('media')->toArray()
            );

            if (isset($validated['media'])) {
                $decisionMaker->media()->delete();

                foreach ($validated['media'] as $media) {
                    \App\Models\DecisionsMakerMedia::create([
                        'id_decision_maker' => $decisionMaker->id,
                        'id_media'          => $media['id'],
                        'type'              => $media['type'],
                    ]);
                }
            }
        });

        return response()->json($decisionMaker->load('media'), Response::HTTP_OK);
    }

    /**
     * Soft delete (disable)
     */
    public function destroy($id)
    {
        $decisionMaker = DecisionsMaker::find($id);

        if (!$decisionMaker) {
            return response()->json([
                'message' => 'Decision maker not found'
            ], Response::HTTP_NOT_FOUND);
        }

        $decisionMaker->update([
            'active' => false
        ]);

        return response()->json([
            'message' => 'Decision maker disabled successfully'
        ], Response::HTTP_OK);
    }
}

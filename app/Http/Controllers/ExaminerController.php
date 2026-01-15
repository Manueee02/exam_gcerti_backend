<?php

namespace App\Http\Controllers;

use App\Models\Examiner;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ExaminerController extends Controller
{
    /**
     * Show all examiners
     */
    public function index()
    {
        $examiners = Examiner::with(['media'])
            ->where('active', true)
            ->get();

        return response()->json($examiners, Response::HTTP_OK);
    }

    /**
     * Show examiner by id
     */
    public function show($id)
    {
        $examiner = Examiner::with(['user', 'media'])
            ->find($id);

        if (!$examiner) {
            return response()->json([
                'message' => 'Examiner not found'
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json($examiner, Response::HTTP_OK);
    }

    /**
     * Create examiner
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'    => 'required|string|max:100',
            'surname' => 'required|string|max:100',
            'email'   => 'required|email|unique:examiners,email',
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

        $examiner = null;

        \DB::transaction(function () use ($validated, &$examiner) {

            $examiner = \App\Models\Examiner::create([
                'name'    => $validated['name'],
                'surname' => $validated['surname'],
                'email'   => $validated['email'],
                'phone'   => $validated['phone'],
                'active'  => 'true',
            ]);

            foreach ($validated['media'] as $media) {
                \App\Models\ExaminerMedia::create([
                    'id_examiner' => $examiner->id,
                    'id_media'    => $media['id'],
                    'type'        => $media['type'],
                ]);
            }
        });

        return response()->json($examiner->load('media'), 201);
    }

    /**
     * Update examiner
     */
    public function update(Request $request, $id)
    {
        $examiner = \App\Models\Examiner::find($id);

        if (!$examiner) {
            return response()->json([
                'message' => 'Examiner not found'
            ], 404);
        }

        $validated = $request->validate([
            'name'    => 'sometimes|string|max:255',
            'surname' => 'sometimes|string|max:255',
            'email'   => 'sometimes|email|unique:examiners,email,' . $examiner->id,
            'phone'   => 'nullable|string|max:50',
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

        \DB::transaction(function () use ($validated, $examiner) {

            $examiner->update(
                collect($validated)->except('media')->toArray()
            );

            if (isset($validated['media'])) {
                $examiner->media()->delete();

                foreach ($validated['media'] as $media) {
                    \App\Models\ExaminerMedia::create([
                        'id_examiner' => $examiner->id,
                        'id_media'    => $media['id'],
                        'type'        => $media['type'],
                    ]);
                }
            }
        });

        return response()->json($examiner->load('media'), 200);
    }


    /**
     * Soft delete (disattiva)
     */
    public function destroy($id)
    {
        $examiner = Examiner::find($id);

        if (!$examiner) {
            return response()->json([
                'message' => 'Examiner not found'
            ], Response::HTTP_NOT_FOUND);
        }

        $examiner->update([
            'active' => 'false'
        ]);

        return response()->json([
            'message' => 'Examiner disabled successfully'
        ], Response::HTTP_OK);
    }
}

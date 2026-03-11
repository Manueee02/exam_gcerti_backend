<?php


use App\Http\Controllers\CandidateController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\PlannedExamController;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DecisionMakerController;
use App\Http\Controllers\ExaminerController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\ResendVerificationController;
use App\Http\Controllers\Server1Controller;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UtilsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::middleware('log.activity')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/refresh-token', [AuthController::class, 'refreshToken']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    Route::post('/verify-reset-token', [AuthController::class, 'verifyResetToken']);
    Route::post('/register', [RegisterController::class, 'register']);
    Route::get('/verify-email/{token}', [EmailVerificationController::class, 'verify']);
    Route::post('/resend-verification', [ResendVerificationController::class, 'resend']);
});

//debug jwt
Route::get('/test-jwt', function (Request $request) {
    try {
        $user = auth('api')->user();
        return response()->json([
            'token_present' => $request->hasHeader('Authorization'),
            'token' => $request->header('Authorization'),
            'authenticated' => auth('api')->check(),
            'user' => $user,
            'guard' => config('auth.defaults.guard')
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 500);
    }
});


Route::middleware('auth:api', 'log.activity', 'check.active.token')->group(function () {
    // Auth routes
    Route::prefix('auth')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::get('/verify-token', [AuthController::class, 'verifyToken']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::put('/profile', [AuthController::class, 'updateProfile']);
        Route::put('/change-password', [AuthController::class, 'changePassword']);
    });

    // Scheme routes
    Route::get('/advanced-search', [UtilsController::class, 'advancedSearch']);

    //users
    Route::middleware('role:superAdmin')
        ->group(function () {
            Route::get('/users', [UserController::class, 'index']);
            Route::get('/users-roles', [UserController::class, 'indexRoles']);
            Route::put('/change-role-users/{id}', [UserController::class, 'updateRole']);
            Route::delete('/users/{id}', [UserController::class, 'destroy']);
            Route::post('/create-user', [UserController::class, 'store']);
        }
    );
    Route::middleware('role:admin,superAdmin')
        ->group(function () {
            Route::post('/create-user-server', [UserController::class, 'storeServerApp1User']);
            Route::get('/has-server-user/{id_auditor}', [UserController::class, 'auditorHasUser']);
        }
    );


    //media
    Route::prefix('media')->group(function () {
        // ── Originali (invariate) ──────────────────────────────────────────────
        Route::post('upload', [MediaController::class, 'upload']);
        Route::get('download/{id}', [MediaController::class, 'show']);
        Route::delete('delete/{id}', [MediaController::class, 'destroy']);

        // ── Nuovi endpoint soft delete (mode=edit) ─────────────────────────────
        Route::post('/{id}/soft-delete',      [MediaController::class, 'softDelete']);       // marca vecchio file come pending
        Route::post('/restore',               [MediaController::class, 'restore']);           // annulla soft delete (body: {ids})
        Route::post('/confirm-deletes',       [MediaController::class, 'confirmDeletes']);    // elimina davvero i pending (body: {ids})
        Route::post('/confirm-uploads',       [MediaController::class, 'confirmUploads']);    // promuovi temporanei a permanenti (body: {ids})
        Route::post('/discard-temporaries',   [MediaController::class, 'discardTemporaries']); // elimina temporanei orfani (body: {ids})
    });

    //Esaminatori
    Route::middleware('role:admin,superAdmin')
        ->prefix('examiners')
        ->group(function () {

            Route::get('/', [ExaminerController::class, 'index']);
            Route::get('/{id}', [ExaminerController::class, 'show']);
            Route::post('/', [ExaminerController::class, 'store']);
            Route::put('/{id}', [ExaminerController::class, 'update']);
            Route::delete('/{id}', [ExaminerController::class, 'destroy']);
            Route::get('/serverApp/examiner-decisionmaker', [Server1Controller::class, 'getExaminers']);
            Route::get('/serverApp/show/{id}', [Server1Controller::class, 'show']);
            Route::put('/serverApp/update/{id}', [Server1Controller::class, 'updateExaminer']);
            Route::post('/serverApp/qualifications/update-status', [Server1Controller::class, 'updateQualificationStatus']);
            Route::post('/serverApp/qualifications/update-status/noIaf', [Server1Controller::class, 'updateQualificationStatusNoIaf']);
        }
    );

    //Deliberanti
    Route::middleware('role:admin,superAdmin')
        ->prefix('decision-makers')
        ->group(function () {

            Route::get('/', [DecisionMakerController::class, 'index']);
            Route::get('/{id}', [DecisionMakerController::class, 'show']);
            Route::post('/', [DecisionMakerController::class, 'store']);
            Route::put('/{id}', [DecisionMakerController::class, 'update']);
            Route::delete('/{id}', [DecisionMakerController::class, 'destroy']);

        }
        );

    //Candidati
    Route::middleware('role:user')
        ->prefix('candidates')
        ->group(function () {
            Route::post('/store', [CandidateController::class, 'store']);
            Route::put('/update/{id}', [CandidateController::class, 'update']);

        }
    );
    Route::middleware('role:admin,superAdmin')
        ->prefix('candidates')
        ->group(function () {
            Route::get('/all', [CandidateController::class, 'index']);
            Route::put('/delete/{id}', [CandidateController::class, 'delete']);
        }
    );
    Route::get('candidates/{id}', [CandidateController::class, 'show']);

    //Utils
    Route::get('/get-all-data', [CandidateController::class, 'getAllData']);

});


Route::get('/test-cors', function () {
    return response()->json(['message' => 'CORS working!', 'timestamp' => now()]);
});

Route::fallback(function () {
    return response()->json([
        'error' => 'Endpoint non trovato',
        'message' => 'La risorsa richiesta non esiste'
    ], 404);
});



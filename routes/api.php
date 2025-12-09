<?php


use App\Http\Controllers\AuthController;
use App\Http\Controllers\MediaController;
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
Route::post('/login', [AuthController::class, 'login'])->name('login');
Route::post('/refresh-token', [AuthController::class, 'refreshToken']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);
Route::post('/verify-reset-token', [AuthController::class, 'verifyResetToken']);


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


Route::middleware('auth:api')->group(function () {
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
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users-roles', [UserController::class, 'indexRoles']);
    Route::put('/change-role-users/{id}', [UserController::class, 'updateRole']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);
    Route::post('/create-user', [UserController::class, 'store']);

    //media
    Route::prefix('media')->group(function () {
        Route::post('upload', [MediaController::class, 'upload']);
        Route::get('download/{id}', [MediaController::class, 'show']);
        Route::delete('delete/{id}', [MediaController::class, 'destroy']);
    });
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



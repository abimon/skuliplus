<?php

use App\Http\Controllers\Api\AcademicsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CentralManagementController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FamilyController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\OperationsController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SchoolManagementController;
use App\Http\Controllers\Api\SyncController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SkuliPlus mobile API
|--------------------------------------------------------------------------
|
| Token based API consumed by the SkuliPlus mobile app. Authentication is
| handled by Laravel Sanctum; clients send the token as a bearer credential.
| The Inertia web application in routes/web.php is unaffected.
|
*/

Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware(['auth:sanctum', 'abilities:mobile'])->group(function () {
    // Session --------------------------------------------------------------
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/refresh', [AuthController::class, 'refresh']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::post('auth/logout-all', [AuthController::class, 'logoutAll']);
    Route::get('auth/devices', [AuthController::class, 'devices']);
    Route::delete('auth/devices/{token}', [AuthController::class, 'revokeDevice']);

    // Profile, shared by every interface --------------------------------------
    Route::get('profile', [ProfileController::class, 'show']);
    Route::put('profile', [ProfileController::class, 'update']);
    Route::post('profile/password', [ProfileController::class, 'changePassword']);
    Route::post('profile/confirm-identity', [ProfileController::class, 'confirmIdentity']);
    Route::delete('profile', [ProfileController::class, 'destroy']);
    Route::get('directory', [ProfileController::class, 'directory']);

    // Home --------------------------------------------------------------------
    Route::get('dashboard', DashboardController::class);

    // Offline cache and queued writes -------------------------------------------
    Route::get('sync/status', [SyncController::class, 'status']);
    Route::get('sync/pull', [SyncController::class, 'pull']);
    Route::post('sync/push', [SyncController::class, 'push']);
    // Central management ------------------------------------------------------
    Route::prefix('central')->group(function () {
        Route::get('/', [CentralManagementController::class, 'index']);
        Route::get('schools', [CentralManagementController::class, 'schools']);
        Route::post('schools', [CentralManagementController::class, 'storeSchool']);
        Route::put('schools/{school}', [CentralManagementController::class, 'updateSchool']);
        Route::patch('schools/{school}/status', [CentralManagementController::class, 'setSchoolStatus']);
        Route::get('curricula', [CentralManagementController::class, 'curricula']);
        Route::post('curricula', [CentralManagementController::class, 'storeCurriculum']);
        Route::put('curricula/{curriculum}', [CentralManagementController::class, 'updateCurriculum']);
        Route::post('curricula/{curriculum}/levels', [CentralManagementController::class, 'storeLevel']);
        Route::get('requests', [CentralManagementController::class, 'requests']);
        Route::patch('requests/{centralRequest}', [CentralManagementController::class, 'handleRequest']);
    });

    // School management -------------------------------------------------------
    Route::prefix('school')->group(function () {
        Route::get('/', [SchoolManagementController::class, 'index']);
        Route::get('people', [SchoolManagementController::class, 'people']);
        Route::post('people', [SchoolManagementController::class, 'storePerson']);
        Route::get('people/{user}', [SchoolManagementController::class, 'showPerson']);
        Route::put('people/{user}', [SchoolManagementController::class, 'updatePerson']);
        Route::delete('people/{user}', [SchoolManagementController::class, 'destroyPerson']);
        Route::get('guardians', [SchoolManagementController::class, 'guardians']);
        Route::get('departments', [SchoolManagementController::class, 'departments']);
        Route::get('branding', [SchoolManagementController::class, 'branding']);
        Route::put('branding', [SchoolManagementController::class, 'branding']);
        Route::get('requests', [SchoolManagementController::class, 'requests']);
        Route::post('requests', [SchoolManagementController::class, 'storeRequest']);
    });
    // Academics ---------------------------------------------------------------
    Route::prefix('academics')->group(function () {
        Route::get('/', [AcademicsController::class, 'index']);
        Route::get('classes', [AcademicsController::class, 'classes']);
        Route::post('classes', [AcademicsController::class, 'storeClass']);
        Route::get('classes/{schoolClass}', [AcademicsController::class, 'showClass']);
        Route::post('streams', [AcademicsController::class, 'storeStream']);
        Route::post('enrollments', [AcademicsController::class, 'enroll']);
        Route::get('timetable', [AcademicsController::class, 'timetable']);
        Route::put('timetable-slots/{timetableSlot}', [AcademicsController::class, 'updateSlot']);
        Route::get('lessons', [AcademicsController::class, 'lessons']);
        Route::post('lessons', [AcademicsController::class, 'storeLesson']);
        Route::post('streams/{stream}/timetable', [AcademicsController::class, 'generateTimetable']);
        Route::get('exams', [AcademicsController::class, 'exams']);
        Route::post('exams', [AcademicsController::class, 'storeExam']);
        Route::get('results', [AcademicsController::class, 'results']);
        Route::post('results', [AcademicsController::class, 'storeResult']);
        Route::post('results/bulk', [AcademicsController::class, 'bulkStoreResults']);
    });

    // Finance -----------------------------------------------------------------
    Route::prefix('finance')->group(function () {
        Route::get('/', [FinanceController::class, 'index']);
        Route::post('accounts', [FinanceController::class, 'storeAccount']);
        Route::post('payments', [FinanceController::class, 'storePayment']);
        Route::post('withdrawals', [FinanceController::class, 'storeWithdrawal']);
        Route::get('learners/{student}/statement', [FinanceController::class, 'learnerStatement']);
    });
    // Operations ----------------------------------------------------------------
    Route::prefix('operations')->group(function () {
        Route::get('{module}', [OperationsController::class, 'index'])
            ->whereIn('module', ['library', 'gate', 'stores', 'activities', 'labs']);
        Route::post('library/books', [OperationsController::class, 'storeBook']);
        Route::post('library/borrow', [OperationsController::class, 'borrowBook']);
        Route::post('library/loans/{loan}/return', [OperationsController::class, 'returnBook']);
        Route::post('gate/check-in', [OperationsController::class, 'checkInVisitor']);
        Route::post('gate/checkout', [OperationsController::class, 'checkoutVisitor']);
        Route::post('stores/items', [OperationsController::class, 'storeStoreItem']);
        Route::post('stores/movements', [OperationsController::class, 'moveStoreStock']);
        Route::post('activities/clubs', [OperationsController::class, 'storeClub']);
        Route::post('activities/records', [OperationsController::class, 'storeActivity']);
        Route::post('labs/items', [OperationsController::class, 'storeLabItem']);
        Route::post('labs/movements', [OperationsController::class, 'moveLabStock']);
    });

    // Parents and learners ------------------------------------------------------
    Route::prefix('family')->group(function () {
        Route::get('children', [FamilyController::class, 'children']);
        Route::get('children/{student}', [FamilyController::class, 'child']);
    });

    Route::get('user', fn (Request $request) => $request->user());
});

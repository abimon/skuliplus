<?php

use App\Http\Controllers\AcademicController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\CentralManagementController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\PublicInquiryController;
use App\Http\Controllers\SchoolRequestController;
use App\Http\Controllers\SchoolUserController;
use App\Http\Controllers\StudentReportController;
use App\Http\Controllers\Super\CurriculumController;
use App\Http\Controllers\Super\SchoolController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function (Request $request) {
    return Inertia::render('welcome', [
        'sent' => $request->session()->get('sent', []),
    ]);
})->name('home');
Route::post('/inquiries', [PublicInquiryController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('inquiries.store');
Route::get('/school-inactive', function (Request $request) {
    return Inertia::render('auth/school-inactive', [
        'schoolName' => $request->session()->get('inactive_school_name'),
    ]);
})->name('school.inactive');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('module-unavailable', function (Request $request) {
        $module = $request->session()->get('unavailable_module');
        abort_unless(is_array($module) && isset($module['key'], $module['name']), 404);

        return Inertia::render('errors/module-unavailable', [
            'module' => $module,
        ]);
    })->name('module.unavailable');
    Route::middleware('school.module:finance')->group(function () {
        Route::get('finance', [FinanceController::class, 'index'])->name('finance.index');
        Route::post('finance/accounts', [FinanceController::class, 'storeAccount'])->name('finance.accounts.store');
        Route::post('finance/payments', [FinanceController::class, 'storePayment'])->name('finance.payments.store');
        Route::post('finance/withdrawals', [FinanceController::class, 'storeWithdrawal'])->name('finance.withdrawals.store');
        Route::get('finance/payments/{payment}/receipt', [FinanceController::class, 'receipt'])->name('finance.receipt');
        Route::get('finance/payments/{payment}/receipt.pdf', [FinanceController::class, 'downloadReceipt'])->name('finance.receipt.download');
    });
    Route::get('modules/{module}', [OperationsController::class, 'index'])->middleware('school.module')->name('operations.index');
    Route::post('modules/{module}', [OperationsController::class, 'store'])->middleware('school.module')->name('operations.store');
    Route::post('modules/library/borrow', [OperationsController::class, 'borrowBook'])->middleware('school.module:library')->name('library.borrow');
    Route::post('modules/library/loans/{loan}/return', [OperationsController::class, 'returnBook'])->middleware('school.module:library')->name('library.return');
    Route::post('modules/gate/checkout', [OperationsController::class, 'checkoutVisitor'])->middleware('school.module:gate')->name('gate.checkout');
    Route::post('modules/stores/movements', [OperationsController::class, 'moveStoreStock'])->middleware('school.module:stores')->name('stores.movement');
    Route::post('modules/labs/movements', [OperationsController::class, 'moveLabStock'])->middleware('school.module:labs')->name('labs.movement');
    Route::post('modules/promotions/process', [OperationsController::class, 'processPromotions'])->middleware('school.module:academics')->name('promotions.process');

    Route::middleware('school.module:academics')->group(function () {
        Route::get('academics', [AcademicController::class, 'index'])->name('academics.index');
        Route::get('academics/reports', [StudentReportController::class, 'index'])->name('academics.reports.index');
        Route::get('students/{student}/results', [StudentReportController::class, 'show'])->name('students.results');
        Route::get('students/{student}/results.pdf', [StudentReportController::class, 'pdf'])->name('students.results.pdf');
        Route::post('academics/results/email', [StudentReportController::class, 'email'])->name('academics.results.email');
        Route::get('academics/assessments', [AssessmentController::class, 'index'])->name('academics.assessments.index');
        Route::post('academics/assessments', [AssessmentController::class, 'storeExam'])->name('academics.assessments.store');
        Route::patch('academics/assessments/{exam}/publish', [AssessmentController::class, 'publishExam'])->name('academics.assessments.publish');
        Route::post('academics/assessments/{exam}/results', [AssessmentController::class, 'storeResult'])->name('academics.results.store');
        Route::get('academics/assessments/{exam}/template', [AssessmentController::class, 'template'])->name('academics.results.template');
        Route::post('academics/assessments/{exam}/import', [AssessmentController::class, 'import'])->name('academics.results.import');
        Route::post('academics/years', [AcademicController::class, 'storeAcademicYear'])->name('academics.years.store');
        Route::post('academics/terms', [AcademicController::class, 'storeTerm'])->name('academics.terms.store');
        Route::post('academics/classes', [AcademicController::class, 'storeClass'])->name('academics.classes.store');
        Route::post('academics/streams', [AcademicController::class, 'storeStream'])->name('academics.streams.store');
        Route::post('academics/enrollments', [AcademicController::class, 'enrollStudent'])->name('academics.enrollments.store');
        Route::post('academics/streams/{stream}/timetable', [AcademicController::class, 'generateTimetable'])->name('academics.timetable.generate');
        Route::put('academics/timetable-slots/{slot}', [AcademicController::class, 'updateTimetableSlot'])->name('academics.timetable.update');
    });

    Route::middleware('school.module:users')->group(function () {
        Route::get('users', [SchoolUserController::class, 'index'])->name('users.index');
        Route::post('users', [SchoolUserController::class, 'store'])->name('users.store');
        Route::put('users/{user}', [SchoolUserController::class, 'update'])->name('users.update');
        Route::get('users/import-template', [SchoolUserController::class, 'template'])->name('users.template');
        Route::post('users/import', [SchoolUserController::class, 'import'])->name('users.import');
        Route::get('users/{user}/id-card', [SchoolUserController::class, 'idCard'])->name('users.id-card');
    });

    Route::get('settings/school', [BrandingController::class, 'edit'])->name('branding.edit');
    Route::put('settings/school', [BrandingController::class, 'update'])->name('branding.update');
    Route::post('school/requests', [SchoolRequestController::class, 'store'])->name('school.requests.store');
    Route::post('dashboard/child', [DashboardController::class, 'selectChild'])->name('dashboard.child');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('schools', [SchoolController::class, 'index'])->name('schools.index');
        Route::post('schools', [SchoolController::class, 'store'])->name('schools.store');
        Route::put('schools/{school}', [SchoolController::class, 'update'])->name('schools.update');
        Route::patch('schools/{school}/status', [SchoolController::class, 'setActive'])->name('schools.status');
        Route::get('curricula', [CurriculumController::class, 'index'])->name('curricula.index');
        Route::post('curricula', [CurriculumController::class, 'store'])->name('curricula.store');
        Route::put('curricula/{curriculum}', [CurriculumController::class, 'update'])->name('curricula.update');
        Route::post('curricula/{curriculum}/levels', [CurriculumController::class, 'storeLevel'])->name('curricula.levels.store');
        Route::put('curricula/{curriculum}/levels/{level}', [CurriculumController::class, 'updateLevel'])->name('curricula.levels.update');
        Route::get('management', [CentralManagementController::class, 'index'])->name('management.index');
        Route::put('management/modules/{module}', [CentralManagementController::class, 'updateModule'])->name('management.modules.update');
        Route::patch('management/curricula/{curriculum}', [CentralManagementController::class, 'updateCurriculum'])->name('management.curricula.update');
        Route::patch('management/requests/{centralRequest}', [CentralManagementController::class, 'handleRequest'])->name('management.requests.update');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';

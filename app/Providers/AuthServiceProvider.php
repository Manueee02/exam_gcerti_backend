<?php

namespace App\Providers;

use App\Models\Candidate;
use App\Models\Exam;
use App\Models\ExamSession;
use App\Models\PlannedExam;
use App\Models\PlannedExamInscription;
use App\Models\Question;
use App\Policies\CandidatePolicy;
use App\Policies\ExamPolicy;
use App\Policies\ExamSessionPolicy;
use App\Policies\PlannedExamInscriptionPolicy;
use App\Policies\PlannedExamPolicy;
use App\Policies\QuestionPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     */
    protected $policies = [
        Candidate::class => CandidatePolicy::class,
        PlannedExamInscription::class => PlannedExamInscriptionPolicy::class,
        Question::class => QuestionPolicy::class,
        Exam::class => ExamPolicy::class,
        ExamSession::class => ExamSessionPolicy::class,
        PlannedExam::class => PlannedExamPolicy::class,
    ];

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->registerPolicies();
    }
}

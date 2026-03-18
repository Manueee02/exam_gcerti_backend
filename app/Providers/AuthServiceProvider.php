<?php

namespace App\Providers;

use App\Models\Candidate;
use App\Models\PlannedExamInscription;
use App\Policies\CandidatePolicy;
use App\Policies\PlannedExamInscriptionPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     */
    protected $policies = [
        Candidate::class => CandidatePolicy::class,
        PlannedExamInscription::class => PlannedExamInscriptionPolicy::class,
    ];

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->registerPolicies();
    }
}

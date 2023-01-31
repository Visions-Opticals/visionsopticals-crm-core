<?php

namespace App\Events;


use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class AccountRegistered extends Event
{
    /** @var Company|null  */
    public $company;
    
    /** @var User  */
    public $user;
    
    /**
     * AccountRegistered constructor.
     *
     * @param User         $user
     * @param Company|null $company
     */
    public function __construct(User $user, Company $company =  null)
    {
        $this->company = $company;
        $this->user = $user;
    }
}
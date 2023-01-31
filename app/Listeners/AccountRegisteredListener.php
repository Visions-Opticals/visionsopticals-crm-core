<?php

namespace App\Listeners;


use App\Events\AccountRegistered;
use App\Mail\WelcomeEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AccountRegisteredListener implements ShouldQueue
{
    use InteractsWithQueue;
    
    /**
     * @param AccountRegistered $event
     */
    public function handle(AccountRegistered $event)
    {

        $partner = $event->user->partner ?: null;
        # get the partner for the user
        $domains = null;
        if (!empty($partner)) {
            $domains = $partner->domainIssuances;
            # get the domain issued to the partner, if any
        }
        if (empty($domains) || $domains->count() === 0) {
            # no domains
            $domain = null;
        } else {
            $domain = $domains->first();
        }
        Mail::to($event->user)->send(new WelcomeEmail($event->user, $partner, $domain));
        return;
    }
}
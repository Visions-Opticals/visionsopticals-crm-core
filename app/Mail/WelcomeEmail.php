<?php

namespace App\Mail;

use App\Models\DomainIssuance;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class WelcomeEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @var DomainIssuance|null  */
    public $domain;

    /** @var Partner|null  */
    public $partner;

    /** @var User  */
    public $user;
    
    /** @var string  */
    public $loginLink;
    
    /** @var string  */
    public $baseUrl;
    
    /**
     * WelcomeEmail constructor.
     *
     * @param User                $user
     * @param Partner|null        $partner
     * @param DomainIssuance|null $domain
     */
    public function __construct(User $user, Partner $partner = null, DomainIssuance $domain = null)
    {
        $this->domain = $domain;
        $this->partner = $partner;
        $this->user = $user;
        if (!empty($partner) && !empty($domain)) {
            $suffix = app()->environment() === 'production' ? 'dorcas.io' : 'dorcas.local';
            $this->baseUrl = 'http://' . $domain->prefix . '.' . $suffix;
            $this->loginLink = custom_url($this->baseUrl, '/login');
        } else {
            $this->baseUrl = site_url('/');
            $this->loginLink = site_url('/login');
        }
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $configuration = !empty($this->partner) && !empty($this->partner->extra_data) ? $this->partner->extra_data : [];
       // Log::info($configuration);
        $subject = 'Welcome to ' . (!empty($configuration['hubConfig']['product_name']) ? $configuration['hubConfig']['product_name'] : 'Hub') . ', '.$this->user->firstname;
        $subdomain = 'https://hub.dorcas.io';
        if (!empty($this->domain)) {
            $subdomain = 'https://' . $this->domain->prefix . '.' . $this->domain->domain['data']['domain'];
        }
      //  $subdomain = env('SITE_URL');

        $supportEmail = config('mail.from.address');
        $supportName = config('mail.from.name');
        if (!empty($this->partner)) {
            $configuration = !empty($this->partner->extra_data) ? $this->partner->extra_data : [];
            $supportEmail = $configuration['support_email'] ?? $supportEmail;
            $supportName = $configuration['hubConfig']['product_name'] ?? $supportName;
        }
        return $this->from(config('mail.from.address'), $supportName)
                    ->replyTo($supportEmail, $supportName)
                    ->subject($subject)
                    ->view('emails.welcome')
                    ->with('subdomain', $subdomain);
    }
}
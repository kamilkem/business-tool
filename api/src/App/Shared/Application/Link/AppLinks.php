<?php

declare(strict_types=1);

namespace App\Shared\Application\Link;

use App\Organization\Domain\Model\MembershipRole;
use App\User\Domain\Model\PasswordTokenSecret;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Links into our frontend apps for e-mails (ADR 0013). The base URLs come from configuration
 * (ADMIN_URL, TIMECLOCK_URL), never from the request's Host header, and the token secret travels
 * in the URL fragment, which browsers send neither to the app's server nor in a Referer.
 */
final readonly class AppLinks
{
    private string $adminUrl;
    private string $timeclockUrl;

    public function __construct(
        #[Autowire(env: 'ADMIN_URL')]
        string $adminUrl,
        #[Autowire(env: 'TIMECLOCK_URL')]
        string $timeclockUrl,
    ) {
        $this->adminUrl = rtrim($adminUrl, '/');
        $this->timeclockUrl = rtrim($timeclockUrl, '/');
    }

    /** Owners and managers work in the admin panel, employees in the time clock. */
    public static function appFor(MembershipRole $role): ClientApp
    {
        return $role->canManageOrganization() ? ClientApp::Admin : ClientApp::Timeclock;
    }

    public function setPassword(ClientApp $app, PasswordTokenSecret $secret): string
    {
        return $this->baseUrl($app).'/set-password#token='.$secret->value;
    }

    public function signIn(ClientApp $app): string
    {
        return $this->baseUrl($app).'/login';
    }

    /** The app's start page; a person who is not signed in is sent to sign in first. */
    public function home(ClientApp $app): string
    {
        return $this->baseUrl($app).'/';
    }

    /** The admin's shifts page (#31), for owners and managers. */
    public function adminShifts(): string
    {
        return $this->adminUrl.'/time';
    }

    private function baseUrl(ClientApp $app): string
    {
        return match ($app) {
            ClientApp::Admin => $this->adminUrl,
            ClientApp::Timeclock => $this->timeclockUrl,
        };
    }
}

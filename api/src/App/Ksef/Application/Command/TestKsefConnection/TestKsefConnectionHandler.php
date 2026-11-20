<?php

declare(strict_types=1);

namespace App\Ksef\Application\Command\TestKsefConnection;

use App\Ksef\Application\KsefActor;
use App\Ksef\Domain\Exception\KsefConnectionForbiddenException;
use App\Ksef\Domain\Exception\KsefConnectionNotFoundException;
use App\Ksef\Domain\Exception\KsefCredentialsRejectedException;
use App\Ksef\Domain\Exception\KsefException;
use App\Ksef\Domain\Exception\KsefNotAvailableException;
use App\Ksef\Domain\Exception\KsefRateLimitedException;
use App\Ksef\Domain\Exception\KsefRequestFailedException;
use App\Ksef\Domain\Exception\KsefTokenNotSetException;
use App\Ksef\Domain\Exception\KsefUnavailableException;
use App\Ksef\Domain\KsefClientInterface;
use App\Ksef\Domain\Model\KsefAccess;
use App\Ksef\Domain\Model\KsefErrorCode;
use App\Ksef\Domain\Model\KsefPurchaseInvoiceQuery;
use App\Ksef\Domain\Model\Nip;
use App\Ksef\Domain\Repository\KsefConnectionRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Secret\Application\Query\RevealOrganizationSecret\RevealedSecretView;
use App\Secret\Application\Query\RevealOrganizationSecret\RevealOrganizationSecretQuery;
use App\Secret\Domain\Exception\SecretUnreadableException;
use App\Secret\Domain\Model\SecretKind;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Psr\Clock\ClockInterface;

final readonly class TestKsefConnectionHandler implements CommandHandlerInterface
{
    public function __construct(
        private KsefConnectionRepositoryInterface $connections,
        private KsefClientInterface $ksef,
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException    when the actor is not an active member of the organization
     * @throws KsefConnectionForbiddenException when the actor is an employee
     * @throws KsefConnectionNotFoundException  when the organization has no KSeF connection
     * @throws KsefNotAvailableException        when the organization is not in Poland or has no valid NIP
     * @throws KsefTokenNotSetException         when the token was removed
     */
    public function __invoke(TestKsefConnectionCommand $command): void
    {
        $actor = KsefActor::resolve($this->queryBus, $command->organizationId, $command->actorId);
        KsefAccess::assertCanUse($actor->id, $actor->role);
        $connection = $this->connections->findCurrent() ?? throw KsefConnectionNotFoundException::create();
        $nip = KsefAccess::nipFor($actor->countryCode, $actor->taxId);
        $secretId = $connection->getSecretId() ?? throw KsefTokenNotSetException::create();

        $now = $this->clock->now();
        try {
            /** @var RevealedSecretView|null $token */
            $token = $this->queryBus->ask(new RevealOrganizationSecretQuery($command->organizationId, $secretId->toRfc4122(), SecretKind::KsefToken->value));
            if (null === $token) {
                $connection->recordCheck($now, KsefErrorCode::TokenUnreadable, 'The saved KSeF token is missing: save it again.');
            } else {
                $this->check($nip, $token->value, $now);
                $connection->recordCheck($now);
            }
        } catch (SecretUnreadableException) {
            $connection->recordCheck($now, KsefErrorCode::TokenUnreadable, 'The saved KSeF token cannot be decrypted: save it again.');
        } catch (KsefCredentialsRejectedException $e) {
            $connection->recordCheck($now, KsefErrorCode::CredentialsRejected, $e->getMessage());
        } catch (KsefRateLimitedException $e) {
            $connection->recordCheck($now, KsefErrorCode::RateLimited, $e->getMessage());
        } catch (KsefUnavailableException $e) {
            $connection->recordCheck($now, KsefErrorCode::Unavailable, $e->getMessage());
        } catch (KsefRequestFailedException $e) {
            $connection->recordCheck($now, KsefErrorCode::RequestFailed, $e->getMessage());
        }

        $this->connections->save($connection);
    }

    /**
     * Authenticates, asks for one day of purchase invoice metadata (proves InvoiceRead) and revokes
     * the session. A failure to revoke it does not fail the check: the session expires by itself.
     */
    private function check(Nip $nip, #[\SensitiveParameter] string $token, \DateTimeImmutable $now): void
    {
        $session = $this->ksef->authenticateWithKsefToken($nip->value, $token);
        try {
            $this->ksef->queryPurchaseInvoiceMetadata($session, new KsefPurchaseInvoiceQuery($now->modify('-1 day'), $now));
        } finally {
            try {
                $this->ksef->endSession($session);
            } catch (KsefException) {
            }
        }
    }
}

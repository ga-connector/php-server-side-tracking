<?php

declare(strict_types=1);

namespace GaConnector\Tracking;

/**
 * The account record returned by {@see TrackingApiClient::verifyAccount()}.
 *
 * A small value object over the tracking API's `/api/v1/account` response,
 * with {@see Account::allows()} for the common "is this domain connected?"
 * check.
 */
final class Account
{
    public string $accountId;
    public string $accountName;
    public string $email;
    /** @var list<string> */
    public array $allowedDomains;

    /**
     * @param list<string> $allowedDomains
     */
    public function __construct(string $accountId, string $accountName, string $email, array $allowedDomains)
    {
        $this->accountId = $accountId;
        $this->accountName = $accountName;
        $this->email = $email;
        $this->allowedDomains = $allowedDomains;
    }

    /**
     * Build an Account from a decoded `/api/v1/account` response body.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $allowed = [];
        if (isset($data['allowed_domains']) && is_array($data['allowed_domains'])) {
            foreach ($data['allowed_domains'] as $item) {
                $allowed[] = (string) $item;
            }
        }

        return new self(
            (string) ($data['account_id'] ?? ''),
            (string) ($data['account_name'] ?? ''),
            (string) ($data['email'] ?? ''),
            $allowed
        );
    }

    /**
     * Whether the given domain is covered by this account's allowed domains
     * (case-insensitive): an exact host match, or a subdomain of a listed
     * domain (e.g. `www-staging.example.com` when `example.com` is listed).
     */
    public function allows(string $domain): bool
    {
        $needle = strtolower(trim($domain));
        if ($needle === '') {
            return false;
        }

        foreach ($this->allowedDomains as $allowed) {
            $allowed = strtolower(trim($allowed));
            if ($allowed === '') {
                continue;
            }
            if ($needle === $allowed) {
                return true;
            }
            $suffix = '.' . $allowed;
            $suffixLen = strlen($suffix);
            if (strlen($needle) > $suffixLen && substr($needle, -$suffixLen) === $suffix) {
                return true;
            }
        }

        return false;
    }
}


<?php

declare(strict_types=1);

/**
 * ScriptyLabs Registrar Adapter for ClientFence
 *
 * File: library/Registrar/Adapter/ScriptyLabs.php
 *
 * API documentation:
 * https://scriptylabs.com/docs/api
 *
 * ClientFence registrar documentation:
 * https://clientfence.com/docs/developers/registrars/
 *
 * Supported:
 * - Domain availability
 * - TLD price import
 * - Domain details
 * - Nameserver updates
 * - EPP authorization codes
 * - Domain locking / unlocking
 * - ClientFence transfer-status interface (unsupported by API)
 *
 * Not documented by ScriptyLabs:
 * - Domain registration
 * - Inbound transfers
 * - Domain renewal
 * - Transfer status lookup
 * - Contact updates
 */

class Registrar_Adapter_ScriptyLabs extends Registrar_AdapterAbstract implements Registrar_TldPrices, Registrar_TransferStatus
{
    private const API_BASE = 'https://api.scriptylabs.cloud/v1';

    private array $options;

    public function __construct(array $options)
    {
        $this->options = $options;

        if (empty($this->options['api_key'])) {
            throw new Registrar_Exception(
                'Enter your ScriptyLabs API key.'
            );
        }
    }

    /**
     * Registrar configuration.
     */
    public static function getConfig(): array
    {
        return [
            'label' => 'Manage .rw domains using ScriptyLabs API.',
            'form' => [
                'api_key' => [
                    'password',
                    [
                        'label' => 'ScriptyLabs API key',
                        'required' => true,
                    ],
                ],
            ],
        ];
    }

    /**
     * Check domain availability.
     *
     * GET /domains/search/single
     */
    public function isDomainAvailable(Registrar_Domain $domain): bool
    {
        $response = $this->request(
            'GET',
            '/domains/search/single',
            [],
            ['domain' => $domain->getName()],
            false
        );

        return (bool) ($response['data']['available'] ?? false);
    }

    /**
     * Check whether a domain can be transferred.
     *
     * ScriptyLabs does not document a transferability endpoint.
     */
    public function isDomaincanBeTransferred(Registrar_Domain $domain): bool
    {
        throw new Registrar_Exception(
            'ScriptyLabs does not provide a domain transferability endpoint.'
        );
    }

    /**
     * Register domain.
     *
     * Not currently available in the published ScriptyLabs API.
     */
    public function registerDomain(Registrar_Domain $domain): bool
    {
        throw new Registrar_Exception(
            'Domain registration is not currently supported by the ScriptyLabs API.'
        );
    }

    /**
     * Transfer domain.
     *
     * ScriptyLabs provides EPP codes for outbound transfers,
     * but does not document inbound transfer submission.
     */
    public function transferDomain(Registrar_Domain $domain): bool
    {
        throw new Registrar_Exception(
            'Inbound domain transfers are not currently supported by the ScriptyLabs API.'
        );
    }

    /**
     * Renew domain.
     */
    public function renewDomain(Registrar_Domain $domain): bool
    {
        throw new Registrar_Exception(
            'Domain renewal is not currently supported through the published ScriptyLabs API.'
        );
    }

    /**
     * Retrieve domain information.
     *
     * GET /domains/{name}
     */
    public function getDomainDetails(Registrar_Domain $domain): Registrar_Domain
    {
        $response = $this->request(
            'GET',
            '/domains/' . rawurlencode($domain->getName())
        );

        $details = $response['data'] ?? [];

        if (!empty($details['expirationDate'])) {
            $expiration = strtotime($details['expirationDate']);

            if ($expiration !== false) {
                $domain->setExpirationTime($expiration);
            }
        }

        // Update nameservers when the corresponding ClientFence
        // setters are available.
        if (isset($details['nameservers']) && is_array($details['nameservers'])) {
            $setters = [
                'setNs1',
                'setNs2',
                'setNs3',
                'setNs4',
            ];

            foreach ($setters as $index => $setter) {
                if (method_exists($domain, $setter)) {
                    $domain->{$setter}(
                        $details['nameservers'][$index] ?? ''
                    );
                }
            }
        }

        return $domain;
    }

    /**
     * Update domain nameservers.
     *
     * PUT /domains/{domainId}/nameservers
     */
    public function modifyNs(Registrar_Domain $domain): bool
    {
        $details = $this->getRemoteDomain($domain);

        $nameservers = array_values(array_filter([
            $domain->getNs1(),
            $domain->getNs2(),
            $domain->getNs3(),
            $domain->getNs4(),
        ]));

        if (count($nameservers) < 2) {
            throw new Registrar_Exception(
                'At least two nameservers are required.'
            );
        }

        $this->request(
            'PUT',
            '/domains/' . rawurlencode($details['id']) . '/nameservers',
            [
                'nameservers' => $nameservers,
            ]
        );

        return true;
    }

    /**
     * Retrieve EPP authorization code.
     *
     * PUT /domains/{domainId}/authorization-code
     */
    public function getEpp(Registrar_Domain $domain): string
    {
        $details = $this->getRemoteDomain($domain);

        $response = $this->request(
            'PUT',
            '/domains/' . rawurlencode($details['id']) . '/authorization-code'
        );

        $code = $response['data']['code'] ?? null;

        if (!is_string($code) || $code === '') {
            throw new Registrar_Exception(
                'ScriptyLabs did not return an authorization code.'
            );
        }

        return $code;
    }

    /**
     * Lock domain.
     *
     * PATCH /domains/{domainId}/lock
     */
    public function lock(Registrar_Domain $domain): bool
    {
        return $this->setLock($domain, true);
    }

    /**
     * Unlock domain.
     *
     * PATCH /domains/{domainId}/lock
     */
    public function unlock(Registrar_Domain $domain): bool
    {
        return $this->setLock($domain, false);
    }

    /**
     * Update domain lock state.
     */
    private function setLock(
        Registrar_Domain $domain,
        bool $locked
    ): bool {
        $details = $this->getRemoteDomain($domain);

        $this->request(
            'PATCH',
            '/domains/' . rawurlencode($details['id']) . '/lock',
            [
                'locked' => $locked,
            ]
        );

        return true;
    }

    /**
     * Import registrar TLD prices into ClientFence.
     *
     * GET /domains/tlds
     *
     * ScriptyLabs prices are denominated in RWF.
     *
     * ClientFence expects:
     * [
     *     'currency' => 'RWF',
     *     'prices' => [
     *         '.rw' => [
     *             'register' => 14900,
     *             'renew' => 14900,
     *             'transfer' => 3000,
     *         ],
     *     ],
     *     'remaining' => [],
     * ]
     */
    public function getTldPrices(array $tlds = []): array
    {
        $response = $this->request(
            'GET',
            '/domains/tlds',
            [],
            [],
            false
        );

        $availableTlds = $response['data'] ?? [];

        if (!is_array($availableTlds)) {
            throw new Registrar_Exception(
                'Invalid TLD price response from ScriptyLabs.'
            );
        }

        $prices = [];
        $remaining = [];

        $requestedTlds = array_map(
            static fn ($tld) => strtolower(
                str_starts_with($tld, '.') ? $tld : '.' . $tld
            ),
            $tlds
        );

        $foundTlds = [];

        foreach ($availableTlds as $tld) {
            if (empty($tld['name'])) {
                continue;
            }

            $name = strtolower($tld['name']);

            if (
                $requestedTlds !== []
                && !in_array($name, $requestedTlds, true)
            ) {
                continue;
            }

            $prices[$name] = [
                'register' => isset($tld['price'])
                    ? (float) $tld['price']
                    : null,

                'renew' => isset($tld['renewalPrice'])
                    ? (float) $tld['renewalPrice']
                    : null,

                'transfer' => isset($tld['transferPrice'])
                    ? (float) $tld['transferPrice']
                    : null,
            ];

            $foundTlds[] = $name;
        }

        // ClientFence retries TLDs listed in remaining.
        foreach ($requestedTlds as $requestedTld) {
            if (!in_array($requestedTld, $foundTlds, true)) {
                $remaining[] = $requestedTld;
            }
        }

        return [
            'currency' => 'RWF',
            'prices' => $prices,
            'remaining' => $remaining,
        ];
    }

    /**
     * Retrieve domain transfer status.
     *
     * ClientFence expects:
     * [
     *     'status' => Registrar_TransferStatus::PENDING,
     *     'message' => '...',
     * ]
     *
     * ScriptyLabs currently has no documented inbound transfer
     * status endpoint. Throwing an exception allows ClientFence
     * to retry the status check later.
     */
    public function getTransferStatus(Registrar_Domain $domain): array
    {
        throw new Registrar_Exception(
            'ScriptyLabs does not currently provide domain transfer status tracking.'
        );
    }

    /**
     * Contact modification is unsupported.
     */
    public function modifyContact(Registrar_Domain $domain): bool
    {
        throw new Registrar_Exception(
            'ScriptyLabs does not support contact modification through its API.'
        );
    }

    /**
     * Domain deletion is unsupported.
     */
    public function deleteDomain(Registrar_Domain $domain): bool
    {
        throw new Registrar_Exception(
            'ScriptyLabs does not support domain deletion through its API.'
        );
    }

    /**
     * Enable privacy protection.
     */
    public function enablePrivacyProtection(Registrar_Domain $domain): bool
    {
        throw new Registrar_Exception(
            'ScriptyLabs does not support privacy protection through its API.'
        );
    }

    /**
     * Disable privacy protection.
     */
    public function disablePrivacyProtection(Registrar_Domain $domain): bool
    {
        throw new Registrar_Exception(
            'ScriptyLabs does not support privacy protection through its API.'
        );
    }

    /**
     * Retrieve domain from ScriptyLabs.
     *
     * GET /domains/{name}
     */
    private function getRemoteDomain(Registrar_Domain $domain): array
    {
        $response = $this->request(
            'GET',
            '/domains/' . rawurlencode($domain->getName())
        );

        $details = $response['data'] ?? [];

        if (empty($details['id'])) {
            throw new Registrar_Exception(
                'ScriptyLabs did not return a domain ID for '
                . $domain->getName()
            );
        }

        return $details;
    }

    /**
     * Execute an HTTP request against ScriptyLabs.
     *
     * Authentication:
     * x-api-key: API_KEY
     *
     * Public endpoints do not require authentication.
     */
    private function request(
        string $method,
        string $path,
        array $body = [],
        array $query = [],
        bool $authenticated = true
    ): array {
        $headers = [
            'Accept' => 'application/json',
        ];

        if ($authenticated) {
            $headers['x-api-key'] = $this->options['api_key'];
        }

        $options = [
            'headers' => $headers,
        ];

        if ($query !== []) {
            $options['query'] = $query;
        }

        if (
            !in_array(strtoupper($method), ['GET', 'HEAD'], true)
            && $body !== []
        ) {
            $options['json'] = $body;
        }

        try {
            $response = $this->getHttpClient()->request(
                strtoupper($method),
                self::API_BASE . $path,
                $options
            );

            $status = $response->getStatusCode();
            $content = $response->getContent(false);

            if ($status < 200 || $status >= 300) {
                $error = json_decode($content, true);

                $message = is_array($error)
                    ? ($error['message'] ?? $error['error'] ?? $content)
                    : $content;

                throw new Registrar_Exception(
                    'ScriptyLabs API error (' . $status . '): ' . $message
                );
            }

            if ($content === '') {
                return [];
            }

            $result = json_decode($content, true);

            if (!is_array($result)) {
                throw new Registrar_Exception(
                    'ScriptyLabs returned an invalid JSON response.'
                );
            }

            return $result;

        } catch (Registrar_Exception $exception) {
            throw $exception;

        } catch (\Throwable $exception) {
            throw new Registrar_Exception(
                'ScriptyLabs connection error: '
                . $exception->getMessage()
            );
        }
    }
}

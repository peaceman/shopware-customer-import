<?php

namespace n2305\ShopwareCustomerImport;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Psr\Log\LoggerInterface;

class ShopwareApi
{
    public function __construct(LoggerInterface $logger, Client $httpClient)
    {
        $this->logger = $logger;
        $this->httpClient = $httpClient;
    }

    public function createCustomer(array $customerData): ?int
    {
        $response = $this->httpClient->post('/api/customers', ['json' => $customerData]);

        $data = json_decode((string) $response->getBody(), true)['data'] ?? [];

        return $data['id'] ?? null;
    }

    public function findCustomerIdByEmail(string $email): ?int
    {
        try {
            $response = $this->httpClient->get('/api/customers', [
                'query' => [
                    'filter' => [
                        [
                            'property' => 'email',
                            'expression' => '=',
                            'value' => $email,
                        ],
                    ],
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);
            if (empty($data['data'])) return null;

            [$customer] = $data['data'];

            return $customer['id'] ?? null;
        } catch (ClientException $e) {
            if ($e->getCode() === 404) {
                return null;
            }

            throw $e;
        }
    }

    public function updateCustomer(int $customerId, array $customerData): void
    {
        $this->httpClient->put("/api/customers/$customerId", [
            'json' => $customerData,
        ]);
    }

    public function createOrder(array $orderData): ?int
    {
        $response = $this->httpClient->post('/api/orders', [
            'json' => $orderData,
        ]);

        $data = json_decode((string) $response->getBody(), true);
        $orderId = $data['id'] ?? null;

        if ($orderId === null) {
            $this->logger->warning('Failed to create order', ['orderData' => $orderData, 'responseData' => $data]);
        }

        return $orderId;
    }
}

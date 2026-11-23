<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function order_accounting_packages(int $userId, string $orderNumber): array
{
    $query = db()->prepare("SELECT package_number, status, total_price, lines FROM orders
        WHERE user_id = ? AND marketplace = 'trendyol' AND order_number = ? ORDER BY package_number");
    $query->execute([$userId, $orderNumber]);
    return $query->fetchAll();
}

function order_accounting_settlements(int $userId, string $orderNumber): array
{
    $query = db()->prepare("SELECT transaction_type, shipment_package_id, debt, credit
        FROM settlements WHERE user_id = ? AND marketplace = 'trendyol' AND order_number = ?
        AND provider_id IS NOT NULL ORDER BY transaction_date, id");
    $query->execute([$userId, $orderNumber]);
    return $query->fetchAll();
}

function order_accounting_claims(array $rows, string $orderNumber): array
{
    $items = [];
    foreach ($rows as $row) {
        if ((string)($row['orderNumber'] ?? '') !== $orderNumber || !is_array($row['items'] ?? null)) {
            throw new UnexpectedValueException('İade kaydı siparişle eşleşmedi veya eksik.');
        }
        foreach ($row['items'] as $group) {
            if (!is_array($group['claimItems'] ?? null)) throw new UnexpectedValueException('İade kalemleri eksik.');
            foreach ($group['claimItems'] as $item) {
                $id = (string)($item['id'] ?? '');
                $status = $item['claimItemStatus']['name'] ?? '';
                if ($id === '' || isset($items[$id]) || !is_string($status) || $status === '') {
                    throw new UnexpectedValueException('İade kalemi kimliği veya durumu geçersiz.');
                }
                $items[$id] = ['line' => (string)($item['orderLineItemId'] ?? $group['orderLine']['id'] ?? ''), 'status' => $status];
            }
        }
    }
    return array_values($items);
}

function order_accounting_ledger(array $rows, string $orderNumber): array
{
    $matching = [];
    $invoiceIds = [];
    foreach ($rows as $row) {
        $type = (string)($row['transactionType'] ?? '');
        $source = (string)($row['source'] ?? '');
        if ($source === 'otherfinancials' && in_array($type, ['Kargo Faturası', 'Kargo Fatura'], true)) {
            $id = (string)($row['id'] ?? '');
            if ($id !== '') $invoiceIds[$id] = $id;
        }
        if ((string)($row['orderNumber'] ?? '') !== $orderNumber) continue;
        $matching[] = [
            'type' => $type,
            'package' => (string)($row['shipmentPackageId'] ?? ''),
            'debt' => (float)$row['debt'],
            'credit' => (float)$row['credit'],
            'commission' => order_accounting_amount($row['commissionAmount'] ?? null),
        ];
    }
    return ['rows' => $matching, 'invoices' => array_values($invoiceIds)];
}

function order_accounting_amount(mixed $value): ?float
{
    return is_numeric($value) && is_finite((float)$value) && abs((float)$value) <= 1e12 ? (float)$value : null;
}

function order_accounting_cargo(array $rows, string $orderNumber): array
{
    $items = [];
    foreach ($rows as $row) {
        if (!isset($row['orderNumber']) || (string)$row['orderNumber'] === '') {
            throw new UnexpectedValueException('Kargo faturası satırında sipariş numarası eksik.');
        }
        if ((string)$row['orderNumber'] !== $orderNumber) continue;
        $amount = order_accounting_amount($row['amount'] ?? null);
        $parcel = (string)($row['parcelUniqueId'] ?? '');
        $type = (string)($row['shipmentPackageType'] ?? '');
        $key = $parcel . '|' . $type;
        if ($parcel === '' || $type === '' || $amount === null || isset($items[$key])) {
            throw new UnexpectedValueException('Kargo kalemi eksik veya tekrarlı.');
        }
        $items[$key] = ['parcel' => $parcel, 'amount' => $amount, 'type' => $type];
    }
    return array_values($items);
}

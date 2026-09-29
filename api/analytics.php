<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

try {
    request_method('GET');
    $pdo = db();
    $artist = require_role($pdo, 'artist');

    $stmt = $pdo->prepare(
        'SELECT c.id, c.price, c.status, c.payment_status, c.created_at, c.updated_at,
                l.title, l.category
         FROM dbo.commissions c
         INNER JOIN dbo.listings l ON l.id = c.listing_id
         WHERE c.artist_id = :artist_id
         ORDER BY c.created_at ASC, c.id ASC'
    );
    $stmt->execute(['artist_id' => (int)$artist['id']]);

    $rows = $stmt->fetchAll();
    $points = [];
    $total = count($rows);
    $completed = 0;
    $revenue = 0.0;
    $turnaroundTotal = 0.0;
    $turnaroundCount = 0;

    foreach ($rows as $row) {
        $created = new DateTimeImmutable((string)$row['created_at']);
        $updated = new DateTimeImmutable((string)$row['updated_at']);
        $days = max(0, round(($updated->getTimestamp() - $created->getTimestamp()) / 86400, 2));
        $price = (float)$row['price'];
        $isCompleted = (string)$row['status'] === 'delivered';

        if ($isCompleted) {
            $completed++;
            $revenue += $price;
            $turnaroundTotal += $days;
            $turnaroundCount++;
        }

        $points[] = [
            'id' => (int)$row['id'],
            'x' => $days,
            'y' => $price,
            'status' => (string)$row['status'],
            'paymentStatus' => (string)$row['payment_status'],
            'title' => (string)$row['title'],
            'category' => (string)$row['category'],
            'createdAt' => iso_utc((string)$row['created_at']),
            'updatedAt' => iso_utc((string)$row['updated_at']),
        ];
    }

    $avgOrder = $total > 0 ? array_sum(array_column($points, 'y')) / $total : 0.0;
    $avgTurnaround = $turnaroundCount > 0 ? $turnaroundTotal / $turnaroundCount : 0.0;

    json_response(true, 'Artist analytics loaded.', [
        'summary' => [
            'totalCommissions' => $total,
            'completedCommissions' => $completed,
            'completionRate' => $total > 0 ? round(($completed / $total) * 100, 1) : 0.0,
            'revenue' => round($revenue, 2),
            'averageOrderValue' => round($avgOrder, 2),
            'averageTurnaroundDays' => round($avgTurnaround, 2),
        ],
        'scatter' => [
            'xLabel' => 'Turnaround time (days)',
            'yLabel' => 'Commission value',
            'points' => $points,
        ],
    ]);
} catch (Throwable $e) {
    json_response(false, safe_error_message($e), null, 500);
}

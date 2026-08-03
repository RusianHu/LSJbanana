<?php

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$testDb = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lsjbanana-generation-test-' . bin2hex(random_bytes(4)) . '.db';
$config = [
    'driver' => 'sqlite',
    'path' => $testDb,
    'sqlite' => ['path' => $testDb, 'busy_timeout_ms' => 5000],
];

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/generation_jobs.php';

try {
    $db = Database::getInstance($config);
    $db->repairCoreTables();
    $repository = new GenerationJobRepository($db);
    $repository->ensureSchema();
    $userId = $db->createUser('generation_test_' . bin2hex(random_bytes(3)), 'generation_' . bin2hex(random_bytes(3)) . '@example.test', password_hash('test-password', PASSWORD_DEFAULT), 1.00);
    if (!$userId) {
        throw new RuntimeException('failed to create test user');
    }

    $request = ['prompt' => 'test', 'request_data' => ['contents' => [['parts' => [['text' => 'test']]]]]];
    $created = $repository->createJob($userId, 'test-idempotency-key-001', 'generate', 'native', 'test-model', $request, [], 0.01, 3);
    $duplicate = $repository->createJob($userId, 'test-idempotency-key-001', 'generate', 'native', 'test-model', $request, [], 0.01, 3);
    if (!$created['created'] || $duplicate['created'] || $created['job']['id'] !== $duplicate['job']['id']) {
        throw new RuntimeException('idempotency check failed');
    }

    $workerId = 'test-worker';
    $claimed = $repository->claimNextJob($workerId);
    if (!$claimed || (int)$claimed['attempt_count'] !== 1) {
        throw new RuntimeException('claim check failed');
    }
    $repository->scheduleRetry((int)$claimed['id'], $workerId, 'TEST_RETRY', 'temporary', 1);
    sleep(2);
    $claimedAgain = $repository->claimNextJob($workerId);
    if (!$claimedAgain || (int)$claimedAgain['attempt_count'] !== 2) {
        throw new RuntimeException('retry claim check failed');
    }
    $repository->storeProvisionalResult((int)$claimedAgain['id'], $workerId, [
        'images' => ['images/test.png'], 'text' => '', 'thoughts' => [], 'warnings' => [], 'groundingMetadata' => null,
    ]);
    $succeeded = $repository->completeJob((int)$claimedAgain['id'], $workerId);
    if ($succeeded['status'] !== 'succeeded' || $succeeded['billing_state'] !== 'charged') {
        throw new RuntimeException('success settlement check failed');
    }

    $failedCreate = $repository->createJob($userId, 'test-idempotency-key-002', 'generate', 'native', 'test-model', $request, [], 0.01, 1);
    $failedClaim = $repository->claimNextJob($workerId);
    if (!$failedClaim) {
        throw new RuntimeException('failed job claim check failed');
    }
    $failed = $repository->failAndRefund((int)$failedClaim['id'], $workerId, 'TEST_FINAL', 'expected failure');
    if ($failed['status'] !== 'failed' || $failed['billing_state'] !== 'refunded') {
        throw new RuntimeException('refund settlement check failed');
    }

    $recoveredCreate = $repository->createJob($userId, 'test-idempotency-key-003', 'generate', 'native', 'test-model', $request, [], 0.01, 1);
    $interrupted = $repository->claimNextJob($workerId);
    if (!$interrupted || $repository->recoverInterruptedJobs() !== 1) {
        throw new RuntimeException('interrupted job recovery check failed');
    }
    $recovered = $repository->claimNextJob($workerId);
    if (!$recovered || (int)$recovered['attempt_count'] !== 1) {
        throw new RuntimeException('recovered attempt accounting check failed');
    }
    $repository->failAndRefund((int)$recovered['id'], $workerId, 'TEST_RECOVERED_FINAL', 'expected recovered failure');

    $balance = (float)($db->getUserById($userId)['balance'] ?? -1);
    if (abs($balance - 0.99) > 0.001) {
        throw new RuntimeException('unexpected balance: ' . $balance);
    }
    echo "generation jobs verification passed\n";
} finally {
    @unlink($testDb);
}

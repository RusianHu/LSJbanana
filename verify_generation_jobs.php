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
require_once __DIR__ . '/image_generation_service.php';

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

    $queuedCreate = $repository->createJob(
        $userId,
        'test-idempotency-key-004',
        'edit',
        'native',
        'test-model',
        $request,
        [['mime_type' => 'image/png', 'data' => 'queued-input']],
        0.01,
        3
    );
    $queuedCancelling = $repository->requestCancellation($queuedCreate['job']['public_id'], $userId);
    if (($queuedCancelling['status'] ?? '') !== 'cancelling'
        || trim((string)($queuedCancelling['worker_id'] ?? '')) !== '') {
        throw new RuntimeException('queued cancellation request check failed');
    }
    $queuedCancelled = $repository->finalizeCancellation((int)$queuedCancelling['id']);
    if ($queuedCancelled['status'] !== 'cancelled'
        || $queuedCancelled['billing_state'] !== 'refunded'
        || $repository->getInputs((int)$queuedCancelled['id']) !== []) {
        throw new RuntimeException('queued cancellation settlement check failed');
    }
    $queuedCancelledAgain = $repository->requestCancellation($queuedCancelled['public_id'], $userId);
    $queuedFinalizedAgain = $repository->finalizeCancellation((int)$queuedCancelled['id']);
    if (($queuedCancelledAgain['status'] ?? '') !== 'cancelled'
        || ($queuedFinalizedAgain['billing_state'] ?? '') !== 'refunded') {
        throw new RuntimeException('idempotent queued cancellation check failed');
    }

    $retryCreate = $repository->createJob($userId, 'test-idempotency-key-005', 'generate', 'native', 'test-model', $request, [], 0.01, 3);
    $retryClaim = $repository->claimNextJob($workerId);
    if (!$retryClaim || !$repository->scheduleRetry((int)$retryClaim['id'], $workerId, 'TEST_RETRY_WAIT', 'wait', 60)) {
        throw new RuntimeException('retry-wait setup failed');
    }
    $retryCancelling = $repository->requestCancellation($retryCreate['job']['public_id'], $userId);
    $retryCancelled = $repository->finalizeCancellation((int)$retryCancelling['id']);
    if (($retryCancelled['status'] ?? '') !== 'cancelled' || ($retryCancelled['billing_state'] ?? '') !== 'refunded') {
        throw new RuntimeException('retry-wait cancellation check failed');
    }

    $processingCreate = $repository->createJob(
        $userId,
        'test-idempotency-key-006',
        'edit',
        'native',
        'test-model',
        $request,
        [['mime_type' => 'image/png', 'data' => 'processing-input']],
        0.01,
        3
    );
    $processingClaim = $repository->claimNextJob($workerId);
    if (!$processingClaim) {
        throw new RuntimeException('processing cancellation setup failed');
    }
    $processingCancelling = $repository->requestCancellation($processingCreate['job']['public_id'], $userId);
    if (($processingCancelling['status'] ?? '') !== 'cancelling'
        || $repository->heartbeatAndContinue((int)$processingClaim['id'], $workerId)) {
        throw new RuntimeException('processing cancellation heartbeat check failed');
    }
    $wrongOwnerSettlement = $repository->finalizeCancellation((int)$processingClaim['id']);
    if (($wrongOwnerSettlement['status'] ?? '') !== 'cancelling') {
        throw new RuntimeException('processing cancellation ownership check failed');
    }
    $workerCancellation = $repository->getCancellationForWorker((int)$processingClaim['id'], $workerId);
    $processingCancelled = $repository->finalizeCancellation((int)$processingClaim['id'], $workerId);
    if ($workerCancellation === null
        || ($processingCancelled['status'] ?? '') !== 'cancelled'
        || ($processingCancelled['billing_state'] ?? '') !== 'refunded'
        || $repository->getInputs((int)$processingClaim['id']) !== []) {
        throw new RuntimeException('processing cancellation settlement check failed');
    }

    $successRaceCreate = $repository->createJob($userId, 'test-idempotency-key-007', 'generate', 'native', 'test-model', $request, [], 0.01, 1);
    $successRaceClaim = $repository->claimNextJob($workerId);
    if (!$successRaceClaim) {
        throw new RuntimeException('success race setup failed');
    }
    $repository->storeProvisionalResult((int)$successRaceClaim['id'], $workerId, [
        'images' => ['images/test-success-race.png'], 'text' => '', 'thoughts' => [], 'warnings' => [], 'groundingMetadata' => null,
    ]);
    $successRace = $repository->completeJob((int)$successRaceClaim['id'], $workerId);
    $cancelAfterSuccess = $repository->requestCancellation($successRace['public_id'], $userId);
    if (($cancelAfterSuccess['status'] ?? '') !== 'succeeded'
        || ($cancelAfterSuccess['billing_state'] ?? '') !== 'charged') {
        throw new RuntimeException('success must win cancellation race check failed');
    }

    $restartCreate = $repository->createJob($userId, 'test-idempotency-key-008', 'generate', 'native', 'test-model', $request, [], 0.01, 2);
    $restartClaim = $repository->claimNextJob($workerId);
    if (!$restartClaim) {
        throw new RuntimeException('restart cancellation setup failed');
    }
    $repository->requestCancellation($restartCreate['job']['public_id'], $userId);
    if ($repository->recoverInterruptedJobs() !== 0) {
        throw new RuntimeException('cancelling job must not be recovered as retry check failed');
    }
    $recoveryWorkerId = 'recovery-worker';
    if ($repository->claimCancellationForSettlement($recoveryWorkerId) !== null) {
        throw new RuntimeException('live worker cancellation must not be taken over');
    }
    $claimedCancellation = $repository->claimCancellationForSettlement($recoveryWorkerId, 0);
    if (!$claimedCancellation || ($claimedCancellation['status'] ?? '') !== 'cancelling') {
        throw new RuntimeException('worker cancellation takeover check failed');
    }
    $restartCancelled = $repository->finalizeCancellation((int)$claimedCancellation['id'], $recoveryWorkerId);
    if (($restartCancelled['status'] ?? '') !== 'cancelled' || $repository->claimNextJob($workerId) !== null) {
        throw new RuntimeException('cancelled job dispatch exclusion check failed');
    }

    $outputDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lsjbanana-cancel-output-' . bin2hex(random_bytes(4));
    if (!mkdir($outputDir, 0700, true) && !is_dir($outputDir)) {
        throw new RuntimeException('failed to create cancellation cleanup fixture');
    }
    $validName = 'gen_20260803_212345_' . str_repeat('a', 32) . '.png';
    $outsideName = 'gen_20260803_212346_' . str_repeat('b', 32) . '.png';
    $validPath = $outputDir . DIRECTORY_SEPARATOR . $validName;
    $unsafePath = $outputDir . DIRECTORY_SEPARATOR . 'keep.png';
    $outsidePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $outsideName;
    file_put_contents($validPath, 'generated');
    file_put_contents($unsafePath, 'keep');
    file_put_contents($outsidePath, 'outside');
    $cleanupService = new ImageGenerationService(['output_dir' => $outputDir, 'output_public_path' => 'images']);
    $deleted = $cleanupService->discardResult([
        'images' => ['images/' . $validName, '../' . $outsideName, 'images/keep.png'],
    ]);
    if ($deleted !== 1 || file_exists($validPath) || !file_exists($unsafePath) || !file_exists($outsidePath)) {
        throw new RuntimeException('cancellation result path safety check failed');
    }
    @unlink($unsafePath);
    @unlink($outsidePath);
    @rmdir($outputDir);

    $balance = (float)($db->getUserById($userId)['balance'] ?? -1);
    if (abs($balance - 0.98) > 0.001) {
        throw new RuntimeException('unexpected balance: ' . $balance);
    }
    echo "generation jobs verification passed\n";
} finally {
    @unlink($testDb);
}

<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

set_time_limit(0);

require_once __DIR__ . '/generation_jobs.php';
require_once __DIR__ . '/image_generation_service.php';
require_once __DIR__ . '/image_channel_lock.php';

$config = require __DIR__ . '/config.php';
$jobConfig = is_array($config['generation_jobs'] ?? null) ? $config['generation_jobs'] : [];
$pollInterval = max(1, min(30, (int)($jobConfig['worker_poll_interval'] ?? 2)));
$cancellationTakeoverAfter = max(10, min(300, (int)($jobConfig['cancellation_takeover_after'] ?? 30)));
$retryDelays = array_values(array_filter(
    array_map('intval', is_array($jobConfig['retry_delays'] ?? null) ? $jobConfig['retry_delays'] : [10, 30]),
    static fn(int $value): bool => $value > 0 && $value <= 3600
));
if ($retryDelays === []) {
    $retryDelays = [10, 30];
}
$once = in_array('--once', $argv, true);
$workerId = gethostname() . ':' . getmypid() . ':' . bin2hex(random_bytes(4));
$workerLockPath = (string)($jobConfig['worker_lock_file'] ?? (__DIR__ . '/logs/generation_worker.lock'));

$workerLock = ImageChannelLock::tryAcquire($workerLockPath);
if ($workerLock === null) {
    fwrite(STDERR, "Another generation worker is already running.\n");
    exit(2);
}

$channelLock = null;
if (($config['image_api_provider'] ?? $config['api_provider'] ?? 'native') === 'openai_images') {
    $openaiImagesConfig = is_array($config['openai_images'] ?? null) ? $config['openai_images'] : [];
    $channelLockPath = (string)($openaiImagesConfig['request_lock_file'] ?? '');
    $channelLock = ImageChannelLock::tryAcquire($channelLockPath !== '' ? $channelLockPath : null);
    if ($channelLock === null) {
        $workerLock->release();
        fwrite(STDERR, "The image channel is still held by another process.\n");
        exit(3);
    }
}

$repository = new GenerationJobRepository();
$repository->ensureSchema();
$service = new ImageGenerationService($config);
$stopRequested = false;

$settleCancellation = static function (array $cancellationJob, ?array $currentResult = null) use (
    $repository,
    $service,
    $workerId
): array {
    $storedResult = json_decode((string)($cancellationJob['result_json'] ?? ''), true);
    foreach ([$currentResult, is_array($storedResult) ? $storedResult : null] as $resultToDiscard) {
        if (!is_array($resultToDiscard)) {
            continue;
        }
        try {
            $service->discardResult($resultToDiscard);
        } catch (Throwable $cleanupError) {
            error_log(sprintf(
                '[GenerationWorker] Job %s cancellation file cleanup warning: %s',
                $cancellationJob['public_id'],
                $cleanupError->getMessage()
            ));
        }
    }

    $cancelled = $repository->finalizeCancellation((int)$cancellationJob['id'], $workerId);
    error_log(sprintf(
        '[GenerationWorker] Job %s cancelled safely, billing=%s',
        $cancelled['public_id'],
        $cancelled['billing_state']
    ));
    return $cancelled;
};

// worker 重启后先收尾取消任务，再恢复普通处理中任务，防止取消被误当成重试。
while (($interruptedCancellation = $repository->claimCancellationForSettlement(
    $workerId,
    $cancellationTakeoverAfter
)) !== null) {
    $settleCancellation($interruptedCancellation);
}
$recovered = $repository->recoverInterruptedJobs();
if ($recovered > 0) {
    error_log("[GenerationWorker] Recovered {$recovered} interrupted job(s)");
}

if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$stopRequested): void {
        $stopRequested = true;
    });
    pcntl_signal(SIGINT, static function () use (&$stopRequested): void {
        $stopRequested = true;
    });
}

do {
    $pendingCancellation = $repository->claimCancellationForSettlement($workerId, $cancellationTakeoverAfter);
    if ($pendingCancellation !== null) {
        $settleCancellation($pendingCancellation);
        if ($once || $stopRequested) {
            break;
        }
        continue;
    }

    $job = $repository->claimNextJob($workerId);
    if ($job === null) {
        if ($once || $stopRequested) {
            break;
        }
        sleep($pollInterval);
        continue;
    }

    $jobId = (int)$job['id'];
    $provisionalResult = json_decode((string)($job['result_json'] ?? ''), true);
    $hasProvisionalResult = is_array($provisionalResult) && !empty($provisionalResult['images'] ?? []);
    $result = null;
    try {
        $existingResult = json_decode((string)($job['result_json'] ?? ''), true);
        if (!is_array($existingResult) || empty($existingResult['images'])) {
            $inputs = $repository->getInputs($jobId);
            $result = $service->execute(
                $job,
                $inputs,
                static function () use ($repository, $jobId, $workerId): bool {
                    return $repository->heartbeatAndContinue($jobId, $workerId);
                }
            );
            // 从这一刻起不能按普通生成失败退款：上游结果已经落盘，只需继续持久化/结算。
            $hasProvisionalResult = true;
            $repository->storeProvisionalResult($jobId, $workerId, $result);
        }
        $completed = $repository->completeJob($jobId, $workerId);
        error_log(sprintf(
            '[GenerationWorker] Job %s succeeded on attempt %d/%d',
            $completed['public_id'],
            $completed['attempt_count'],
            $completed['max_attempts']
        ));
    } catch (Throwable $e) {
        $cancellation = $repository->getCancellationForWorker($jobId, $workerId);
        if ($cancellation !== null) {
            $settleCancellation($cancellation, is_array($result) ? $result : null);
            continue;
        }

        $retryable = $e instanceof ImageGenerationExecutionException && $e->isRetryable();
        $attempt = (int)($job['attempt_count'] ?? 1);
        $maxAttempts = (int)($job['max_attempts'] ?? 1);
        $httpCode = $e instanceof ImageGenerationExecutionException ? $e->getHttpCode() : 500;
        $errorCode = 'GENERATION_FAILED_' . $httpCode;
        $message = trim($e->getMessage()) !== '' ? $e->getMessage() : '图片生成任务失败';

        if (($retryable || $hasProvisionalResult) && ($hasProvisionalResult || $attempt < $maxAttempts)) {
            $delayIndex = min(max(0, $attempt - 1), count($retryDelays) - 1);
            $delay = $retryDelays[$delayIndex];
            $scheduled = $repository->scheduleRetry($jobId, $workerId, $errorCode, $message, $delay);
            if (!$scheduled) {
                $cancellation = $repository->getCancellationForWorker($jobId, $workerId);
                if ($cancellation !== null) {
                    $settleCancellation($cancellation, is_array($result) ? $result : null);
                    continue;
                }
                throw new RuntimeException('任务状态已变化，无法安排重试', 0, $e);
            }
            error_log(sprintf(
                '[GenerationWorker] Job %s retry scheduled in %ds after attempt %d/%d: %s',
                $job['public_id'],
                $delay,
                $attempt,
                $maxAttempts,
                $message
            ));
        } else {
            try {
                $failed = $repository->failAndRefund($jobId, $workerId, $errorCode, $message);
                if (($failed['status'] ?? '') === 'cancelling') {
                    $settleCancellation($failed, is_array($result) ? $result : null);
                    continue;
                }
                error_log(sprintf(
                    '[GenerationWorker] Job %s failed after %d/%d attempt(s), billing=%s: %s',
                    $failed['public_id'],
                    $failed['attempt_count'],
                    $failed['max_attempts'],
                    $failed['billing_state'],
                    $message
                ));
            } catch (Throwable $settlementError) {
                error_log(sprintf(
                    '[GenerationWorker] CRITICAL: job %s failure settlement failed: %s; original error: %s',
                    $job['public_id'],
                    $settlementError->getMessage(),
                    $message
                ));
                throw $settlementError;
            }
        }
    }
} while (!$once && !$stopRequested);

$workerLock->release();
if ($channelLock instanceof ImageChannelLock) {
    $channelLock->release();
}

<?php

require_once __DIR__ . '/db.php';

/**
 * 持久化图片生成任务及其计费状态。
 *
 * 浏览器请求只负责入队；长时间上游调用由 CLI worker 执行。
 */
final class GenerationJobRepository
{
    private Database $db;
    private PDO $pdo;
    private string $driver;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->pdo = $this->db->getPdo();
        $this->driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function ensureSchema(): void
    {
        if ($this->driver === 'mysql') {
            $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS generation_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(32) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    idempotency_key VARCHAR(64) NOT NULL,
    action VARCHAR(20) NOT NULL,
    provider VARCHAR(32) NOT NULL,
    model_name VARCHAR(100) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'queued',
    request_json LONGTEXT NOT NULL,
    result_json LONGTEXT NULL,
    billing_amount DECIMAL(10, 4) NOT NULL,
    balance_before DECIMAL(10, 2) NOT NULL,
    balance_after DECIMAL(10, 2) NOT NULL,
    billing_state VARCHAR(20) NOT NULL DEFAULT 'deducted',
    attempt_count INT NOT NULL DEFAULT 0,
    max_attempts INT NOT NULL DEFAULT 3,
    next_attempt_at DATETIME NULL,
    worker_id VARCHAR(100) NULL,
    locked_at DATETIME NULL,
    heartbeat_at DATETIME NULL,
    error_code VARCHAR(64) NULL,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_generation_jobs_public_id (public_id),
    UNIQUE KEY uq_generation_jobs_user_idempotency (user_id, idempotency_key),
    KEY idx_generation_jobs_dispatch (status, next_attempt_at, id),
    KEY idx_generation_jobs_user_created (user_id, created_at),
    CONSTRAINT fk_generation_jobs_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
            $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS generation_job_inputs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id BIGINT UNSIGNED NOT NULL,
    position INT NOT NULL,
    mime_type VARCHAR(64) NOT NULL,
    image_data MEDIUMBLOB NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_generation_job_inputs_position (job_id, position),
    CONSTRAINT fk_generation_job_inputs_job FOREIGN KEY (job_id) REFERENCES generation_jobs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
            return;
        }

        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS generation_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id VARCHAR(32) NOT NULL UNIQUE,
    user_id INTEGER NOT NULL,
    idempotency_key VARCHAR(64) NOT NULL,
    action VARCHAR(20) NOT NULL,
    provider VARCHAR(32) NOT NULL,
    model_name VARCHAR(100) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'queued',
    request_json TEXT NOT NULL,
    result_json TEXT,
    billing_amount DECIMAL(10, 4) NOT NULL,
    balance_before DECIMAL(10, 2) NOT NULL,
    balance_after DECIMAL(10, 2) NOT NULL,
    billing_state VARCHAR(20) NOT NULL DEFAULT 'deducted',
    attempt_count INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 3,
    next_attempt_at DATETIME,
    worker_id VARCHAR(100),
    locked_at DATETIME,
    heartbeat_at DATETIME,
    error_code VARCHAR(64),
    error_message TEXT,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    started_at DATETIME,
    completed_at DATETIME,
    UNIQUE (user_id, idempotency_key),
    FOREIGN KEY (user_id) REFERENCES users(id)
)
SQL);
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_generation_jobs_dispatch ON generation_jobs(status, next_attempt_at, id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_generation_jobs_user_created ON generation_jobs(user_id, created_at)');
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS generation_job_inputs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    job_id INTEGER NOT NULL,
    position INTEGER NOT NULL,
    mime_type VARCHAR(64) NOT NULL,
    image_data BLOB NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE (job_id, position),
    FOREIGN KEY (job_id) REFERENCES generation_jobs(id) ON DELETE CASCADE
)
SQL);
    }

    /**
     * @param array<int,array{mime_type:string,data:string}> $inputs
     * @return array{job:array,created:bool}
     */
    public function createJob(
        int $userId,
        string $idempotencyKey,
        string $action,
        string $provider,
        string $modelName,
        array $request,
        array $inputs,
        float $amount,
        int $maxAttempts
    ): array {
        $existing = $this->findByIdempotencyKey($userId, $idempotencyKey);
        if ($existing !== null) {
            return ['job' => $existing, 'created' => false];
        }

        $publicId = bin2hex(random_bytes(16));
        $requestJson = json_encode(
            $request,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $now = $this->now();

        try {
            $jobId = $this->db->transaction(function () use (
                $userId,
                $idempotencyKey,
                $action,
                $provider,
                $modelName,
                $requestJson,
                $inputs,
                $amount,
                $maxAttempts,
                $publicId,
                $now
            ): int {
                $userLockSql = 'SELECT id FROM users WHERE id = :user_id';
                if ($this->driver === 'mysql') {
                    $userLockSql .= ' FOR UPDATE';
                }
                $userLock = $this->pdo->prepare($userLockSql);
                $userLock->execute([':user_id' => $userId]);
                if (!$userLock->fetchColumn()) {
                    throw new GenerationJobBillingException('USER_NOT_FOUND');
                }

                $existingInTransaction = $this->findByIdempotencyKey($userId, $idempotencyKey, true);
                if ($existingInTransaction !== null) {
                    return -(int)$existingInTransaction['id'];
                }

                $deduction = $this->db->atomicDeductBalance($userId, $amount);
                if (!($deduction['success'] ?? false)) {
                    throw new GenerationJobBillingException(
                        (string)($deduction['error'] ?? 'DEDUCT_FAILED'),
                        isset($deduction['balance_before']) ? (float)$deduction['balance_before'] : null
                    );
                }

                $stmt = $this->pdo->prepare(
                    'INSERT INTO generation_jobs
                    (public_id, user_id, idempotency_key, action, provider, model_name, status, request_json,
                     billing_amount, balance_before, balance_after, billing_state, attempt_count, max_attempts,
                     next_attempt_at, created_at, updated_at)
                    VALUES
                    (:public_id, :user_id, :idempotency_key, :action, :provider, :model_name, :status, :request_json,
                     :billing_amount, :balance_before, :balance_after, :billing_state, 0, :max_attempts,
                     :next_attempt_at, :created_at, :updated_at)'
                );
                $stmt->execute([
                    ':public_id' => $publicId,
                    ':user_id' => $userId,
                    ':idempotency_key' => $idempotencyKey,
                    ':action' => $action,
                    ':provider' => $provider,
                    ':model_name' => $modelName,
                    ':status' => 'queued',
                    ':request_json' => $requestJson,
                    ':billing_amount' => $amount,
                    ':balance_before' => $deduction['balance_before'],
                    ':balance_after' => $deduction['balance_after'],
                    ':billing_state' => 'deducted',
                    ':max_attempts' => max(1, min(5, $maxAttempts)),
                    ':next_attempt_at' => $now,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
                $jobId = (int)$this->pdo->lastInsertId();

                $inputStmt = $this->pdo->prepare(
                    'INSERT INTO generation_job_inputs (job_id, position, mime_type, image_data, created_at)
                     VALUES (:job_id, :position, :mime_type, :image_data, :created_at)'
                );
                foreach ($inputs as $position => $input) {
                    $inputStmt->bindValue(':job_id', $jobId, PDO::PARAM_INT);
                    $inputStmt->bindValue(':position', $position, PDO::PARAM_INT);
                    $inputStmt->bindValue(':mime_type', (string)$input['mime_type'], PDO::PARAM_STR);
                    $inputStmt->bindValue(':image_data', (string)$input['data'], PDO::PARAM_LOB);
                    $inputStmt->bindValue(':created_at', $now, PDO::PARAM_STR);
                    $inputStmt->execute();
                }

                return $jobId;
            });
        } catch (PDOException $e) {
            $existingAfterRace = $this->findByIdempotencyKey($userId, $idempotencyKey);
            if ($existingAfterRace !== null) {
                return ['job' => $existingAfterRace, 'created' => false];
            }
            throw $e;
        }

        if ($jobId < 0) {
            $job = $this->findById(abs($jobId));
            if ($job === null) {
                throw new RuntimeException('幂等任务查询失败');
            }
            return ['job' => $job, 'created' => false];
        }

        $job = $this->findById($jobId);
        if ($job === null) {
            throw new RuntimeException('任务创建后无法读取');
        }
        return ['job' => $job, 'created' => true];
    }

    public function findForUser(string $publicId, int $userId): ?array
    {
        return $this->findForUserInternal($publicId, $userId);
    }

    /**
     * 请求取消任务。这里只锁定状态，退款和文件清理由 API 或 worker 完成。
     */
    public function requestCancellation(string $publicId, int $userId): ?array
    {
        return $this->db->transaction(function () use ($publicId, $userId): ?array {
            $job = $this->findForUserInternal($publicId, $userId, true);
            if ($job === null) {
                return null;
            }

            $status = (string)($job['status'] ?? '');
            if (!in_array($status, ['queued', 'processing', 'retry_wait'], true)) {
                return $job;
            }

            $now = $this->now();
            $isProcessing = $status === 'processing';
            $stmt = $this->pdo->prepare(
                "UPDATE generation_jobs
                 SET status = 'cancelling', next_attempt_at = NULL,
                     worker_id = :worker_id, locked_at = :locked_at,
                     heartbeat_at = :heartbeat_at, error_code = 'GENERATION_CANCEL_REQUESTED',
                     error_message = :error_message, updated_at = :updated_at
                 WHERE id = :id AND status = :status"
            );
            $stmt->execute([
                ':worker_id' => $isProcessing ? ($job['worker_id'] ?? null) : null,
                ':locked_at' => $isProcessing ? ($job['locked_at'] ?? null) : null,
                ':heartbeat_at' => $isProcessing ? ($job['heartbeat_at'] ?? null) : null,
                ':error_message' => '用户已请求取消，正在安全终止任务。',
                ':updated_at' => $now,
                ':id' => (int)$job['id'],
                ':status' => $status,
            ]);

            return $this->findById((int)$job['id']) ?? $job;
        });
    }

    public function findLatestActiveForUser(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM generation_jobs
             WHERE user_id = :user_id AND status IN ('queued', 'processing', 'retry_wait', 'cancelling')
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':user_id' => $userId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($job) ? $job : null;
    }

    public function getQueuePosition(array $job): int
    {
        if (!in_array($job['status'] ?? '', ['queued', 'retry_wait'], true)) {
            return 0;
        }
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM generation_jobs
             WHERE status IN ('queued', 'processing', 'retry_wait') AND id <= :id"
        );
        $stmt->execute([':id' => (int)$job['id']]);
        return max(1, (int)$stmt->fetchColumn());
    }

    public function recoverInterruptedJobs(): int
    {
        $now = $this->now();
        $stmt = $this->pdo->prepare(
            "UPDATE generation_jobs
             SET status = 'retry_wait', attempt_count = CASE
                     WHEN attempt_count >= max_attempts THEN CASE WHEN max_attempts > 1 THEN max_attempts - 1 ELSE 0 END
                     ELSE attempt_count
                 END,
                 worker_id = NULL, locked_at = NULL, heartbeat_at = NULL,
                 next_attempt_at = :next_attempt_at, error_code = 'WORKER_INTERRUPTED',
                 error_message = :error_message, updated_at = :updated_at
             WHERE status = 'processing'"
        );
        $stmt->execute([
            ':next_attempt_at' => $now,
            ':error_message' => '后台执行进程曾中断，任务已恢复并将继续处理。',
            ':updated_at' => $now,
        ]);
        return $stmt->rowCount();
    }

    public function claimNextJob(string $workerId): ?array
    {
        return $this->db->transaction(function () use ($workerId): ?array {
            $now = $this->now();
            $sql = "SELECT * FROM generation_jobs
                    WHERE status IN ('queued', 'retry_wait')
                      AND (next_attempt_at IS NULL OR next_attempt_at <= :now)
                    ORDER BY id ASC LIMIT 1";
            if ($this->driver === 'mysql') {
                $sql .= ' FOR UPDATE';
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':now' => $now]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($job)) {
                return null;
            }

            $update = $this->pdo->prepare(
                "UPDATE generation_jobs
                 SET status = 'processing', attempt_count = attempt_count + 1,
                     worker_id = :worker_id, locked_at = :locked_at, heartbeat_at = :heartbeat_at,
                     started_at = COALESCE(started_at, :started_at), updated_at = :updated_at
                 WHERE id = :id AND status IN ('queued', 'retry_wait')"
            );
            $update->execute([
                ':worker_id' => $workerId,
                ':locked_at' => $now,
                ':heartbeat_at' => $now,
                ':started_at' => $now,
                ':updated_at' => $now,
                ':id' => (int)$job['id'],
            ]);
            if ($update->rowCount() !== 1) {
                return null;
            }
            return $this->findById((int)$job['id']);
        });
    }

    /**
     * worker 优先接管没有完成收尾的取消任务，包括进程重启前遗留的任务。
     */
    public function claimCancellationForSettlement(string $workerId, int $staleAfterSeconds = 30): ?array
    {
        return $this->db->transaction(function () use ($workerId, $staleAfterSeconds): ?array {
            $staleBefore = date('Y-m-d H:i:s', time() - max(0, min(3600, $staleAfterSeconds)));
            $claimable = "status = 'cancelling'
                          AND (worker_id IS NULL OR worker_id = '' OR worker_id = :worker_id
                               OR heartbeat_at IS NULL OR heartbeat_at <= :stale_before)";
            $sql = "SELECT * FROM generation_jobs WHERE {$claimable} ORDER BY id ASC LIMIT 1";
            if ($this->driver === 'mysql') {
                $sql .= ' FOR UPDATE';
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':worker_id' => $workerId,
                ':stale_before' => $staleBefore,
            ]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($job)) {
                return null;
            }

            $now = $this->now();
            $update = $this->pdo->prepare(
                "UPDATE generation_jobs
                 SET worker_id = :worker_id, locked_at = :locked_at,
                     heartbeat_at = :heartbeat_at, updated_at = :updated_at
                 WHERE id = :id AND status = 'cancelling'
                   AND (worker_id IS NULL OR worker_id = '' OR worker_id = :expected_worker_id
                        OR heartbeat_at IS NULL OR heartbeat_at <= :stale_before)"
            );
            $update->execute([
                ':worker_id' => $workerId,
                ':expected_worker_id' => $workerId,
                ':stale_before' => $staleBefore,
                ':locked_at' => $now,
                ':heartbeat_at' => $now,
                ':updated_at' => $now,
                ':id' => (int)$job['id'],
            ]);
            if ($update->rowCount() !== 1) {
                return null;
            }
            return $this->findById((int)$job['id']);
        });
    }

    /** @return array<int,array{position:int,mime_type:string,data:string}> */
    public function getInputs(int $jobId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT position, mime_type, image_data FROM generation_job_inputs WHERE job_id = :job_id ORDER BY position'
        );
        $stmt->execute([':job_id' => $jobId]);
        $inputs = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $data = $row['image_data'] ?? '';
            if (is_resource($data)) {
                $data = stream_get_contents($data);
            }
            $inputs[] = [
                'position' => (int)$row['position'],
                'mime_type' => (string)$row['mime_type'],
                'data' => is_string($data) ? $data : '',
            ];
        }
        return $inputs;
    }

    public function heartbeat(int $jobId, string $workerId): void
    {
        $this->heartbeatAndContinue($jobId, $workerId);
    }

    /**
     * 原子确认任务仍归当前 worker 执行；取消请求一旦先获得行锁就会返回 false。
     */
    public function heartbeatAndContinue(int $jobId, string $workerId): bool
    {
        return $this->db->transaction(function () use ($jobId, $workerId): bool {
            $job = $this->findById($jobId, true);
            if ($job === null
                || ($job['status'] ?? '') !== 'processing'
                || ($job['worker_id'] ?? '') !== $workerId) {
                return false;
            }

            $now = $this->now();
            $stmt = $this->pdo->prepare(
                "UPDATE generation_jobs SET heartbeat_at = :heartbeat_at, updated_at = :updated_at
                 WHERE id = :id AND status = 'processing' AND worker_id = :worker_id"
            );
            $stmt->execute([
                ':heartbeat_at' => $now,
                ':updated_at' => $now,
                ':id' => $jobId,
                ':worker_id' => $workerId,
            ]);
            return true;
        });
    }

    public function getCancellationForWorker(int $jobId, string $workerId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM generation_jobs
             WHERE id = :id AND status = 'cancelling' AND worker_id = :worker_id LIMIT 1"
        );
        $stmt->execute([':id' => $jobId, ':worker_id' => $workerId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($job) ? $job : null;
    }

    public function storeProvisionalResult(int $jobId, string $workerId, array $result): void
    {
        $resultJson = json_encode(
            $result,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $stmt = $this->pdo->prepare(
            "UPDATE generation_jobs SET result_json = :result_json, heartbeat_at = :heartbeat_at, updated_at = :updated_at
             WHERE id = :id AND status = 'processing' AND worker_id = :worker_id"
        );
        $now = $this->now();
        $stmt->execute([
            ':result_json' => $resultJson,
            ':heartbeat_at' => $now,
            ':updated_at' => $now,
            ':id' => $jobId,
            ':worker_id' => $workerId,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('无法保存任务的临时结果');
        }
    }

    public function completeJob(int $jobId, string $workerId): array
    {
        return $this->db->transaction(function () use ($jobId, $workerId): array {
            $job = $this->findById($jobId, true);
            if ($job === null) {
                throw new RuntimeException('待结算任务不存在');
            }
            if (($job['status'] ?? '') === 'succeeded') {
                return $job;
            }
            if (($job['status'] ?? '') !== 'processing' || ($job['worker_id'] ?? '') !== $workerId) {
                throw new RuntimeException('任务状态已变化，拒绝重复结算');
            }

            $result = json_decode((string)($job['result_json'] ?? ''), true);
            if (!is_array($result) || empty($result['images']) || !is_array($result['images'])) {
                throw new RuntimeException('任务没有可结算的图片结果');
            }

            if (($job['billing_state'] ?? '') === 'deducted') {
                $request = json_decode((string)$job['request_json'], true);
                $prompt = is_array($request) ? (string)($request['prompt'] ?? '') : '';
                $remark = json_encode([
                    'job_id' => $job['public_id'],
                    'prompt' => mb_substr($prompt, 0, 200, 'UTF-8'),
                    'images' => array_map('basename', $result['images']),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $this->db->logConsumption(
                    (int)$job['user_id'],
                    (string)$job['action'],
                    (float)$job['billing_amount'],
                    (float)$job['balance_before'],
                    (float)$job['balance_after'],
                    count($result['images']),
                    (string)$job['model_name'],
                    is_string($remark) ? $remark : null
                );
            }

            $now = $this->now();
            $stmt = $this->pdo->prepare(
                "UPDATE generation_jobs
                 SET status = 'succeeded', billing_state = 'charged', worker_id = NULL,
                     locked_at = NULL, heartbeat_at = :heartbeat_at, error_code = NULL, error_message = NULL,
                     completed_at = :completed_at, updated_at = :updated_at
                 WHERE id = :id AND status = 'processing' AND worker_id = :worker_id"
            );
            $stmt->execute([
                ':heartbeat_at' => $now,
                ':completed_at' => $now,
                ':updated_at' => $now,
                ':id' => $jobId,
                ':worker_id' => $workerId,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('任务成功状态写入失败');
            }
            $this->deleteInputs($jobId);
            return $this->findById($jobId) ?? $job;
        });
    }

    public function scheduleRetry(
        int $jobId,
        string $workerId,
        string $errorCode,
        string $errorMessage,
        int $delaySeconds
    ): bool {
        $nextAttempt = date('Y-m-d H:i:s', time() + max(1, $delaySeconds));
        $stmt = $this->pdo->prepare(
            "UPDATE generation_jobs
             SET status = 'retry_wait', next_attempt_at = :next_attempt_at, worker_id = NULL,
                 locked_at = NULL, heartbeat_at = NULL, error_code = :error_code,
                 error_message = :error_message, updated_at = :updated_at
             WHERE id = :id AND status = 'processing' AND worker_id = :worker_id"
        );
        $stmt->execute([
            ':next_attempt_at' => $nextAttempt,
            ':error_code' => mb_substr($errorCode, 0, 64, 'UTF-8'),
            ':error_message' => mb_substr($errorMessage, 0, 4000, 'UTF-8'),
            ':updated_at' => $this->now(),
            ':id' => $jobId,
            ':worker_id' => $workerId,
        ]);
        return $stmt->rowCount() === 1;
    }

    /**
     * 完成取消结算。调用方必须先清理 job.result_json 及当前内存中的结果文件。
     */
    public function finalizeCancellation(int $jobId, ?string $workerId = null): array
    {
        return $this->db->transaction(function () use ($jobId, $workerId): array {
            $job = $this->findById($jobId, true);
            if ($job === null) {
                throw new RuntimeException('待取消任务不存在');
            }
            if (($job['status'] ?? '') === 'cancelled') {
                return $job;
            }
            if (in_array($job['status'] ?? '', ['succeeded', 'failed'], true)) {
                return $job;
            }
            if (($job['status'] ?? '') !== 'cancelling') {
                throw new RuntimeException('任务未处于取消状态');
            }

            $owner = (string)($job['worker_id'] ?? '');
            if (($workerId === null && $owner !== '')
                || ($workerId !== null && $owner !== $workerId)) {
                return $job;
            }

            $billingState = (string)($job['billing_state'] ?? 'deducted');
            if ($billingState === 'deducted') {
                $refunded = $this->db->atomicRefundBalance(
                    (int)$job['user_id'],
                    (float)$job['billing_amount']
                );
                $billingState = $refunded ? 'refunded' : 'refund_failed';
            }

            $now = $this->now();
            $stmt = $this->pdo->prepare(
                "UPDATE generation_jobs
                 SET status = 'cancelled', billing_state = :billing_state, result_json = NULL,
                     worker_id = NULL, locked_at = NULL, heartbeat_at = :heartbeat_at,
                     error_code = 'GENERATION_CANCELLED', error_message = :error_message,
                     completed_at = :completed_at, updated_at = :updated_at
                 WHERE id = :id AND status = 'cancelling'"
            );
            $stmt->execute([
                ':billing_state' => $billingState,
                ':heartbeat_at' => $now,
                ':error_message' => '任务已由用户取消。',
                ':completed_at' => $now,
                ':updated_at' => $now,
                ':id' => $jobId,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('任务取消状态写入失败');
            }
            $this->deleteInputs($jobId);
            return $this->findById($jobId) ?? $job;
        });
    }

    public function failAndRefund(
        int $jobId,
        string $workerId,
        string $errorCode,
        string $errorMessage
    ): array {
        return $this->db->transaction(function () use ($jobId, $workerId, $errorCode, $errorMessage): array {
            $job = $this->findById($jobId, true);
            if ($job === null) {
                throw new RuntimeException('待退款任务不存在');
            }
            if (in_array($job['status'] ?? '', ['succeeded', 'failed', 'cancelled', 'cancelling'], true)) {
                return $job;
            }
            if (($job['status'] ?? '') !== 'processing' || ($job['worker_id'] ?? '') !== $workerId) {
                throw new RuntimeException('任务状态已变化，拒绝重复退款');
            }

            $billingState = (string)$job['billing_state'];
            if ($billingState === 'deducted') {
                $refunded = $this->db->atomicRefundBalance(
                    (int)$job['user_id'],
                    (float)$job['billing_amount']
                );
                $billingState = $refunded ? 'refunded' : 'refund_failed';
            }

            $now = $this->now();
            $stmt = $this->pdo->prepare(
                "UPDATE generation_jobs
                 SET status = 'failed', billing_state = :billing_state, worker_id = NULL,
                     locked_at = NULL, heartbeat_at = :heartbeat_at, error_code = :error_code,
                     error_message = :error_message, completed_at = :completed_at, updated_at = :updated_at
                 WHERE id = :id AND status = 'processing' AND worker_id = :worker_id"
            );
            $stmt->execute([
                ':billing_state' => $billingState,
                ':heartbeat_at' => $now,
                ':error_code' => mb_substr($errorCode, 0, 64, 'UTF-8'),
                ':error_message' => mb_substr($errorMessage, 0, 4000, 'UTF-8'),
                ':completed_at' => $now,
                ':updated_at' => $now,
                ':id' => $jobId,
                ':worker_id' => $workerId,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('任务失败状态写入失败');
            }
            $this->deleteInputs($jobId);
            return $this->findById($jobId) ?? $job;
        });
    }

    private function deleteInputs(int $jobId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM generation_job_inputs WHERE job_id = :job_id');
        $stmt->execute([':job_id' => $jobId]);
    }

    private function findByIdempotencyKey(int $userId, string $key, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT * FROM generation_jobs WHERE user_id = :user_id AND idempotency_key = :key LIMIT 1';
        if ($forUpdate && $this->driver === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId, ':key' => $key]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($job) ? $job : null;
    }

    private function findForUserInternal(string $publicId, int $userId, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT * FROM generation_jobs WHERE public_id = :public_id AND user_id = :user_id LIMIT 1';
        if ($forUpdate && $this->driver === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':public_id' => $publicId, ':user_id' => $userId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($job) ? $job : null;
    }

    private function findById(int $jobId, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT * FROM generation_jobs WHERE id = :id LIMIT 1';
        if ($forUpdate && $this->driver === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($job) ? $job : null;
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}

final class GenerationJobBillingException extends RuntimeException
{
    public function __construct(string $message, private ?float $balance = null)
    {
        parent::__construct($message);
    }

    public function getBalance(): ?float
    {
        return $this->balance;
    }
}

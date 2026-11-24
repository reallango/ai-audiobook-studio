<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Queue of chapter generation jobs, and the code that runs one. The worker process calls runNext() in a loop.
 * Everything a job needs is in the database, so a restart only means picking the job up again: finished segments are
 * kept and only the missing ones are generated.
 */
final class JobService
{
    /** A RUNNING job whose heartbeat is older than this is taken over (its worker died). */
    public const STALE_SECONDS = 60;

    public function __construct(private ?GenerationService $generation = null)
    {
        $this->generation ??= new GenerationService();
    }

    /** Queues a chapter for background generation. Returns the existing job when one is already waiting or running. */
    public function enqueue(string $projectId, string $logicalId): array
    {
        $chapter = (new NarrationService())->chapter($projectId, $logicalId);
        if ($chapter['status'] !== 'approved') throw new InvalidArgumentException('Approve the chapter before generating audio.');

        $job = $this->forChapter($logicalId);
        if ($job !== null && in_array($job['status'], ['QUEUED', 'RUNNING'], true)) return $job;
        // segments that ran out of attempts (the voice server was down) get fresh attempts when asked again
        $this->db()->prepare("UPDATE segments SET status='PENDING', attempts=0, error=NULL, claimed_at=NULL WHERE logical_section_id=:l AND status='FAILED'")
            ->execute(['l' => $logicalId]);

        $this->db()->prepare(
            "INSERT INTO generation_jobs(id,project_id,logical_section_id,status) VALUES(:id,:p,:l,'QUEUED')
             ON CONFLICT(logical_section_id) DO UPDATE SET status='QUEUED', message='', requested_at=CURRENT_TIMESTAMP, started_at=NULL, finished_at=NULL, heartbeat_at=NULL"
        )->execute(['id' => self::uuid(), 'p' => $projectId, 'l' => $logicalId]);
        return $this->forChapter($logicalId) ?? throw new \RuntimeException('The job could not be queued.');
    }

    public function cancel(string $projectId, string $logicalId): void
    {
        $this->db()->prepare("UPDATE generation_jobs SET status='CANCELLED', message='Cancelled.', finished_at=CURRENT_TIMESTAMP WHERE project_id=:p AND logical_section_id=:l AND status IN ('QUEUED','RUNNING')")
            ->execute(['p' => $projectId, 'l' => $logicalId]);
    }

    /** @return array<string,mixed>|null */
    public function forChapter(string $logicalId): ?array
    {
        $s = $this->db()->prepare('SELECT * FROM generation_jobs WHERE logical_section_id=:l');
        $s->execute(['l' => $logicalId]);
        return $s->fetch() ?: null;
    }

    /** True when the worker has been seen recently. */
    public static function workerOnline(int $withinSeconds = 30): bool
    {
        $s = Database::connection()->prepare("SELECT setting_value FROM settings WHERE setting_key='worker_heartbeat'");
        $s->execute();
        $v = $s->fetchColumn();
        if (is_string($v) && (time() - (int) strtotime($v . ' UTC')) <= $withinSeconds) return true;
        return (int) Database::connection()->query("SELECT COUNT(*) FROM generation_jobs WHERE status='RUNNING' AND heartbeat_at > datetime('now','-150 seconds')")->fetchColumn() > 0;
    }

    public static function beat(): void
    {
        Database::connection()->prepare("INSERT INTO settings(setting_key,setting_value) VALUES('worker_heartbeat', datetime('now')) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value, updated_at=CURRENT_TIMESTAMP")->execute();
    }

    /**
     * Runs the oldest waiting (or abandoned) job to completion. Returns false when there was nothing to do.
     * @param null|callable():bool $shouldStop checked between segments
     */
    public function runNext(?callable $shouldStop = null): bool
    {
        $job = $this->claim();
        if ($job === null) return false;
        $projectId = (string) $job['project_id'];
        $logicalId = (string) $job['logical_section_id'];

        try {
            $status = $this->generation->status($projectId, $logicalId);
            if (!$status['approved']) throw new InvalidArgumentException('The chapter is no longer approved.');
            if ($status['needs_prepare'] || !$status['prepared']) $this->generation->prepare($projectId, $logicalId);

            for (;;) {
                if ($this->generation->status($projectId, $logicalId)['needs_prepare']) $this->generation->prepare($projectId, $logicalId);
                if ($shouldStop !== null && $shouldStop()) { $this->release($job, 'QUEUED', 'Paused by a restart.'); return true; }
                if (!$this->stillWanted($job)) return true;     // cancelled
                $this->heartbeat($job);
                $step = $this->generation->generateNext($projectId, $logicalId);
                if ($step['segment'] === null) {
                    if (!empty($step['busy'])) { usleep(500000); continue; }
                    break;
                }
            }
            $counts = $this->generation->status($projectId, $logicalId)['counts'];
            if ($counts['failed'] > 0) {
                $this->release($job, 'FAILED', $counts['failed'] . ' segment(s) failed after several tries. Open the chapter for the reasons, then retry.');
                return true;
            }
            if (!$this->stillWanted($job)) return true;
            $latest = $this->generation->status($projectId, $logicalId);
            if (!$latest['approved']) throw new InvalidArgumentException('The chapter is no longer approved.');
            if ($latest['needs_prepare']) {                       // changed during the last segment: one more round
                $this->release($job, 'QUEUED', 'The chapter changed while it was being made; carrying on with the new text or voice.');
                return true;
            }
            $this->generation->assemble($projectId, $logicalId);
            $this->release($job, 'DONE', 'Chapter audio is ready.');
        } catch (Throwable $e) {
            error_log('generation job failed: ' . $e->getMessage());
            $this->release($job, 'FAILED', mb_substr($e->getMessage(), 0, 300));
        }
        return true;
    }

    /** At worker start: segments left GENERATING by a dead process are made available again. */
    public function recover(): int
    {
        $n = $this->db()->exec("UPDATE segments SET status='PENDING', claimed_at=NULL WHERE status='GENERATING'");
        $this->db()->exec("UPDATE generation_jobs SET status='QUEUED' WHERE status='RUNNING'");
        return (int) $n;
    }

    // ----- internals -----

    /** @return array<string,mixed>|null */
    private function claim(): ?array
    {
        $db = $this->db();
        $s = $db->prepare("SELECT * FROM generation_jobs WHERE status='QUEUED' OR (status='RUNNING' AND heartbeat_at < datetime('now', :stale)) ORDER BY requested_at LIMIT 1");
        $s->execute(['stale' => '-' . self::STALE_SECONDS . ' seconds']);
        $job = $s->fetch();
        if (!$job) return null;
        $c = $db->prepare("UPDATE generation_jobs SET status='RUNNING', started_at=COALESCE(started_at, CURRENT_TIMESTAMP), heartbeat_at=CURRENT_TIMESTAMP, message='' WHERE id=:id AND status=:old");
        $c->execute(['id' => $job['id'], 'old' => $job['status']]);
        return $c->rowCount() === 1 ? $job : null;
    }

    /** @param array<string,mixed> $job */
    private function heartbeat(array $job): void
    {
        $this->db()->prepare("UPDATE generation_jobs SET heartbeat_at=CURRENT_TIMESTAMP WHERE id=:id AND status='RUNNING'")->execute(['id' => $job['id']]);
        self::beat();
    }

    /** @param array<string,mixed> $job */
    private function stillWanted(array $job): bool
    {
        $s = $this->db()->prepare('SELECT status FROM generation_jobs WHERE id=:id');
        $s->execute(['id' => $job['id']]);
        return $s->fetchColumn() === 'RUNNING';
    }

    /** @param array<string,mixed> $job */
    private function release(array $job, string $status, string $message): void
    {
        $this->db()->prepare("UPDATE generation_jobs SET status=:s, message=:m, finished_at=CASE WHEN :s IN ('DONE','FAILED') THEN CURRENT_TIMESTAMP ELSE NULL END WHERE id=:id AND status='RUNNING'")
            ->execute(['s' => $status, 'm' => $message, 'id' => $job['id']]);
    }

    private function db(): PDO
    {
        return Database::connection();
    }

    private static function uuid(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 15) | 64);
        $d[8] = chr((ord($d[8]) & 63) | 128);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}

<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Background tasks that are not chapter audio (today: building the chaptered M4B), and the activity feed that shows
 * every kind of background work for a project.
 */
final class TaskService
{
    public const KINDS = ['m4b' => 'Building the chaptered M4B'];
    /** A running task with no sign of life for this long is taken as abandoned (the worker was restarted). */
    private const STALE_SECONDS = 3600;

    /** Queues a build. Returns the one already waiting or running when there is one. @return array<string,mixed> */
    public function enqueue(string $projectId, string $kind = 'm4b'): array
    {
        if (!isset(self::KINDS[$kind])) throw new InvalidArgumentException('Unknown task.');
        if ($kind === 'm4b' && (new ExportService())->readyChapters($projectId) === []) throw new InvalidArgumentException('No chapter has finished audio yet.');
        $open = $this->open($projectId, $kind);
        if ($open !== null) return $open;
        $id = self::uuid();
        $this->db()->prepare("INSERT INTO background_tasks(id,project_id,kind,status) VALUES(:id,:p,:k,'QUEUED')")->execute(['id' => $id, 'p' => $projectId, 'k' => $kind]);
        return $this->find($id) ?? throw new \RuntimeException('The task could not be queued.');
    }

    /** Runs the oldest waiting task to completion. False when there was nothing to do. */
    public function runNext(): bool
    {
        $task = $this->claim();
        if ($task === null) return false;
        try {
            if ($task['kind'] === 'm4b') {
                $built = (new ExportService())->buildM4b((string) $task['project_id']);
                $this->finish($task, 'DONE', 'Built with ' . (int) $built['chapter_count'] . ' chapter markers.');
            } else {
                $this->finish($task, 'FAILED', 'Unknown task.');
            }
        } catch (Throwable $e) {
            error_log('background task failed: ' . $e->getMessage());
            $this->finish($task, 'FAILED', mb_substr($e->getMessage(), 0, 300));
        }
        return true;
    }

    public static function beat(): void
    {
        Database::connection()->prepare("INSERT INTO settings(setting_key,setting_value) VALUES('task_worker_heartbeat', datetime('now')) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value, updated_at=CURRENT_TIMESTAMP")->execute();
    }

    /** True when the task worker has been seen recently, or is in the middle of a long build (which does not beat). */
    public static function workerOnline(int $withinSeconds = 30): bool
    {
        $db = Database::connection();
        $s = $db->prepare("SELECT setting_value FROM settings WHERE setting_key='task_worker_heartbeat'");
        $s->execute();
        $v = $s->fetchColumn();
        if (is_string($v) && (time() - (int) strtotime($v . ' UTC')) <= $withinSeconds) return true;
        return (int) $db->query("SELECT COUNT(*) FROM background_tasks WHERE status='RUNNING'")->fetchColumn() > 0;
    }

    /** True while a task of this kind is waiting or running for the project. */
    public function isBusy(string $projectId, string $kind = 'm4b'): bool
    {
        return $this->open($projectId, $kind) !== null;
    }

    /** At worker start: a task left RUNNING by a dead process waits again. */
    public function recover(): int
    {
        return (int) $this->db()->exec("UPDATE background_tasks SET status='QUEUED' WHERE status='RUNNING'");
    }

    /**
     * Everything going on for a project, newest first, for the activity bar.
     * @return array{worker:bool,task_worker:bool,active:int,ahead:int,ahead_books:list<string>,items:list<array<string,mixed>>}
     */
    public function activity(string $projectId): array
    {
        $db = $this->db();
        $items = [];

        $jobs = $db->prepare(
            "SELECT j.status, j.message, j.requested_at, j.started_at, j.finished_at, j.logical_section_id AS section, s.title, s.position,
                    (SELECT COUNT(*) FROM segments g WHERE g.logical_section_id=j.logical_section_id) AS total,
                    (SELECT COUNT(*) FROM segments g WHERE g.logical_section_id=j.logical_section_id AND g.status='DONE') AS done
             FROM generation_jobs j JOIN logical_sections s ON s.id=j.logical_section_id
             WHERE j.project_id=:p AND (j.status IN ('QUEUED','RUNNING') OR j.finished_at > datetime('now','-30 minutes'))
             ORDER BY CASE j.status WHEN 'RUNNING' THEN 0 WHEN 'QUEUED' THEN 1 ELSE 2 END, s.position
             LIMIT 60"
        );
        $jobs->execute(['p' => $projectId]);
        foreach ($jobs->fetchAll() as $j) {
            $items[] = [
                'kind' => 'chapter', 'label' => 'Audio for ' . $j['title'], 'status' => $j['status'], 'message' => (string) $j['message'],
                'done' => (int) $j['done'], 'total' => (int) $j['total'], 'section' => (string) $j['section'],
                'started' => $j['started_at'], 'finished' => $j['finished_at'],
            ];
        }

        $tasks = $db->prepare(
            "SELECT * FROM background_tasks WHERE project_id=:p AND (status IN ('QUEUED','RUNNING') OR finished_at > datetime('now','-30 minutes')) ORDER BY requested_at DESC LIMIT 10"
        );
        $tasks->execute(['p' => $projectId]);
        foreach ($tasks->fetchAll() as $t) {
            array_unshift($items, [
                'kind' => (string) $t['kind'], 'label' => self::KINDS[$t['kind']] ?? (string) $t['kind'], 'status' => $t['status'], 'message' => (string) $t['message'],
                'done' => 0, 'total' => 0, 'section' => '', 'started' => $t['started_at'], 'finished' => $t['finished_at'],
            ]);
        }

        // Chapters from other books that the worker will do before this book's waiting chapters.
        $ahead = 0;
        $aheadBooks = [];
        $mine = $db->prepare("SELECT MIN(requested_at) FROM generation_jobs WHERE project_id=:p AND status='QUEUED'");
        $mine->execute(['p' => $projectId]);
        $oldest = $mine->fetchColumn();
        $runningQuery = $db->prepare("SELECT COUNT(*) FROM generation_jobs WHERE project_id=:p AND status='RUNNING'");
        $runningQuery->execute(['p' => $projectId]);
        $running = (int) $runningQuery->fetchColumn();
        if (is_string($oldest) && $running === 0) {
            $other = $db->prepare(
                "SELECT p.title, COUNT(*) n FROM generation_jobs g JOIN projects p ON p.id=g.project_id
                 WHERE g.project_id<>:p AND (g.status='RUNNING' OR (g.status='QUEUED' AND g.requested_at < :oldest)) GROUP BY p.id ORDER BY p.title"
            );
            $other->execute(['p' => $projectId, 'oldest' => $oldest]);
            foreach ($other->fetchAll() as $o) { $ahead += (int) $o['n']; $aheadBooks[] = (string) $o['title']; }
        }

        $active = count(array_filter($items, static fn (array $i): bool => in_array($i['status'], ['QUEUED', 'RUNNING'], true)));
        return ['worker' => JobService::workerOnline(), 'task_worker' => self::workerOnline(), 'active' => $active, 'ahead' => $ahead, 'ahead_books' => $aheadBooks, 'items' => $items];
    }

    // ----- internals -----

    /** @return array<string,mixed>|null */
    private function claim(): ?array
    {
        $db = $this->db();
        // A build waits only for its own book's chapter audio (so it includes the chapters still being made), never for another book's.
        $s = $db->prepare("SELECT * FROM background_tasks t WHERE (t.status='QUEUED' OR (t.status='RUNNING' AND t.heartbeat_at < datetime('now', :stale)))
             AND NOT EXISTS (SELECT 1 FROM generation_jobs g WHERE g.project_id=t.project_id AND g.status IN ('QUEUED','RUNNING')) ORDER BY t.requested_at LIMIT 1");
        $s->execute(['stale' => '-' . self::STALE_SECONDS . ' seconds']);
        $task = $s->fetch();
        if (!$task) return null;
        $c = $db->prepare("UPDATE background_tasks SET status='RUNNING', started_at=CURRENT_TIMESTAMP, heartbeat_at=CURRENT_TIMESTAMP, message='' WHERE id=:id AND status=:old");
        $c->execute(['id' => $task['id'], 'old' => $task['status']]);
        return $c->rowCount() === 1 ? $task : null;
    }

    /** @param array<string,mixed> $task */
    private function finish(array $task, string $status, string $message): void
    {
        $this->db()->prepare('UPDATE background_tasks SET status=:s, message=:m, finished_at=CURRENT_TIMESTAMP WHERE id=:id')->execute(['s' => $status, 'm' => $message, 'id' => $task['id']]);
    }

    /** @return array<string,mixed>|null */
    private function open(string $projectId, string $kind): ?array
    {
        $s = $this->db()->prepare("SELECT * FROM background_tasks WHERE project_id=:p AND kind=:k AND status IN ('QUEUED','RUNNING') LIMIT 1");
        $s->execute(['p' => $projectId, 'k' => $kind]);
        return $s->fetch() ?: null;
    }

    /** @return array<string,mixed>|null */
    private function find(string $id): ?array
    {
        $s = $this->db()->prepare('SELECT * FROM background_tasks WHERE id=:id');
        $s->execute(['id' => $id]);
        return $s->fetch() ?: null;
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

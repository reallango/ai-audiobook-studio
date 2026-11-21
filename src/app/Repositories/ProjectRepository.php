<?php
declare(strict_types=1);
namespace App\Repositories;

use App\Core\Database;
use PDO;

final class ProjectRepository
{
    public function all(bool $includeArchived = false): array
    {
        $sql = 'SELECT * FROM projects' . ($includeArchived ? '' : " WHERE status <> 'ARCHIVED'") . ' ORDER BY updated_at DESC, title COLLATE NOCASE';
        return Database::connection()->query($sql)->fetchAll();
    }
    public function find(string $id): ?array
    {
        $s = Database::connection()->prepare('SELECT * FROM projects WHERE id = :id');
        $s->execute(['id' => $id]); $row = $s->fetch(); return $row ?: null;
    }
    public function create(array $project): void
    {
        $s = Database::connection()->prepare('INSERT INTO projects (id,slug,title,status,active_provider_profile_id,active_voice_profile_id,audio_bitrate_kbps,created_at,updated_at) VALUES (:id,:slug,:title,:status,:provider,:voice,:bitrate,:created,:updated)');
        $s->execute(['id'=>$project['id'],'slug'=>$project['slug'],'title'=>$project['title'],'status'=>'NEW','provider'=>$project['provider'],'voice'=>$project['voice'],'bitrate'=>$project['bitrate'],'created'=>$project['created_at'],'updated'=>$project['created_at']]);
    }
    public function archive(string $id): bool
    {
        $s = Database::connection()->prepare("UPDATE projects SET status='ARCHIVED', archived_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=:id AND status<>'ARCHIVED'");
        $s->execute(['id'=>$id]); return $s->rowCount() === 1;
    }
    public function restore(string $id): bool
    {
        $s = Database::connection()->prepare("UPDATE projects SET status='NEW', archived_at=NULL, updated_at=CURRENT_TIMESTAMP WHERE id=:id AND status='ARCHIVED'");
        $s->execute(['id'=>$id]); return $s->rowCount() === 1;
    }
    public function delete(string $id): bool
    {
        $s = Database::connection()->prepare('DELETE FROM projects WHERE id=:id');
        $s->execute(['id'=>$id]); return $s->rowCount() === 1;
    }
}

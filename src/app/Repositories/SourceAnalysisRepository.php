<?php
declare(strict_types=1);
namespace App\Repositories;
use App\Core\Database;
final class SourceAnalysisRepository
{
    public function analyzed(string $projectId): bool { $s=Database::connection()->prepare('SELECT 1 FROM source_metadata WHERE project_id=:p'); $s->execute(['p'=>$projectId]); return (bool)$s->fetchColumn(); }
    public function metadata(string $projectId): ?array { $s=Database::connection()->prepare('SELECT * FROM source_metadata WHERE project_id=:p'); $s->execute(['p'=>$projectId]); return $s->fetch() ?: null; }
    public function sections(string $projectId): array { $s=Database::connection()->prepare('SELECT * FROM source_sections WHERE project_id=:p ORDER BY spine_index'); $s->execute(['p'=>$projectId]); return $s->fetchAll(); }
    public function section(string $projectId,string $id): ?array { $s=Database::connection()->prepare('SELECT * FROM source_sections WHERE project_id=:p AND id=:id'); $s->execute(['p'=>$projectId,'id'=>$id]); return $s->fetch() ?: null; }
    public function blocks(string $sectionId): array { $s=Database::connection()->prepare('SELECT * FROM source_blocks WHERE source_section_id=:s ORDER BY block_index'); $s->execute(['s'=>$sectionId]); return $s->fetchAll(); }
    public function warnings(string $projectId): array { $s=Database::connection()->prepare('SELECT * FROM import_warnings WHERE project_id=:p ORDER BY id'); $s->execute(['p'=>$projectId]); return $s->fetchAll(); }
}

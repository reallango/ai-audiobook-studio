<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class SourceAssetRepository
{
    public function findByProject(string $projectId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM source_assets WHERE project_id = :project_id'
        );
        $statement->execute(['project_id' => $projectId]);
        $asset = $statement->fetch();
        return $asset ?: null;
    }

    public function create(array $asset): void
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO source_assets (
                id, project_id, original_name, stored_relpath, sha256,
                byte_size, media_type, zip_entries,
                has_mimetype_entry, has_container_entry, imported_at
             ) VALUES (
                :id, :project_id, :original_name, :stored_relpath, :sha256,
                :byte_size, :media_type, :zip_entries,
                :has_mimetype_entry, :has_container_entry, :imported_at
             )'
        );
        $statement->execute($asset);
    }
}

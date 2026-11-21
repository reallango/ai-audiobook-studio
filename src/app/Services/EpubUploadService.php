<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\ProjectRepository;
use App\Repositories\SourceAssetRepository;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use ZipArchive;

final class EpubUploadService
{
    private const MAX_BYTES = 536870912;
    private const MAX_ZIP_ENTRIES = 20000;

    public function upload(string $projectId, array $upload): array
    {
        $project = (new ProjectRepository())->find($projectId);
        if (!$project) {
            throw new InvalidArgumentException('Project was not found.');
        }
        if ($project['status'] === 'ARCHIVED') {
            throw new InvalidArgumentException('Restore the project before importing an EPUB.');
        }
        if ((new SourceAssetRepository())->findByProject($projectId)) {
            throw new InvalidArgumentException('This project already has an immutable source EPUB.');
        }
        if (!extension_loaded('zip')) {
            throw new RuntimeException('The PHP ZIP extension is required.');
        }

        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException($this->uploadError($error));
        }

        $temporaryUpload = (string) ($upload['tmp_name'] ?? '');
        $originalName = basename((string) ($upload['name'] ?? ''));
        $byteSize = (int) ($upload['size'] ?? 0);

        if ($temporaryUpload === '' || !is_file($temporaryUpload)) {
            throw new InvalidArgumentException('The uploaded temporary file is unavailable.');
        }
        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'epub') {
            throw new InvalidArgumentException('Select a file with the .epub extension.');
        }
        if ($byteSize < 20 || $byteSize > self::MAX_BYTES) {
            throw new InvalidArgumentException('The EPUB is empty, invalid, or exceeds 512 MB.');
        }

        $projectRoot = rtrim((string) config('paths.projects'), '/') . '/' . $projectId;
        $sourceDirectory = $projectRoot . '/source';
        if (!is_dir($sourceDirectory) &&
            !mkdir($sourceDirectory, 0770, true) &&
            !is_dir($sourceDirectory)) {
            throw new RuntimeException('Could not create source storage.');
        }

        $finalPath = $sourceDirectory . '/original.epub';
        if (is_file($finalPath)) {
            throw new InvalidArgumentException('An immutable original.epub already exists for this project.');
        }

        $stagedPath = $sourceDirectory . '/upload-' . bin2hex(random_bytes(8)) . '.tmp';
        if (!move_uploaded_file($temporaryUpload, $stagedPath)) {
            throw new RuntimeException('PHP could not move the uploaded EPUB into project storage.');
        }

        try {
            $sha256 = hash_file('sha256', $stagedPath);
            if ($sha256 === false) {
                throw new RuntimeException('Could not calculate the EPUB SHA-256 checksum.');
            }

            $zip = new ZipArchive();
            $opened = $zip->open($stagedPath);
            if ($opened !== true) {
                throw new InvalidArgumentException('The upload is not a readable ZIP-based EPUB.');
            }

            try {
                $entryCount = $zip->numFiles;
                if ($entryCount < 1 || $entryCount > self::MAX_ZIP_ENTRIES) {
                    throw new InvalidArgumentException('The EPUB archive entry count is invalid or excessive.');
                }

                for ($index = 0; $index < $entryCount; $index++) {
                    $stats = $zip->statIndex($index);
                    $entryName = (string) ($stats['name'] ?? '');
                    if ($entryName === '' ||
                        str_contains($entryName, "\0") ||
                        str_starts_with($entryName, '/') ||
                        preg_match('#(^|/)\.\.(/|$)#', $entryName)) {
                        throw new InvalidArgumentException('The EPUB contains an unsafe archive path.');
                    }
                }

                $hasMimetype = $zip->locateName('mimetype') !== false;
                $hasContainer = $zip->locateName('META-INF/container.xml') !== false;
                if (!$hasContainer) {
                    throw new InvalidArgumentException('The EPUB is missing META-INF/container.xml.');
                }

                if ($hasMimetype) {
                    $mimetype = trim((string) $zip->getFromName('mimetype'));
                    if ($mimetype !== 'application/epub+zip') {
                        throw new InvalidArgumentException('The EPUB mimetype entry is invalid.');
                    }
                }
            } finally {
                $zip->close();
            }

            $assetId = self::uuid();
            $importedAt = gmdate('Y-m-d H:i:s');
            $metadata = [
                'id' => $assetId,
                'project_id' => $projectId,
                'original_name' => $originalName,
                'stored_relpath' => 'source/original.epub',
                'sha256' => $sha256,
                'byte_size' => $byteSize,
                'media_type' => 'application/epub+zip',
                'zip_entries' => $entryCount,
                'has_mimetype_entry' => $hasMimetype ? 1 : 0,
                'has_container_entry' => $hasContainer ? 1 : 0,
                'imported_at' => $importedAt,
            ];

            $manifest = [
                'format_version' => 1,
                'source_immutable' => true,
                'project_id' => $projectId,
                'asset_id' => $assetId,
                'original_name' => $originalName,
                'stored_name' => 'original.epub',
                'sha256' => $sha256,
                'byte_size' => $byteSize,
                'media_type' => 'application/epub+zip',
                'zip_entries' => $entryCount,
                'has_mimetype_entry' => $hasMimetype,
                'has_container_entry' => $hasContainer,
                'imported_at' => gmdate('c'),
                'analysis_status' => 'PENDING_PHASE_2B',
            ];

            $manifestTemporary = $sourceDirectory . '/manifest.json.tmp';
            $manifestFinal = $sourceDirectory . '/manifest.json';
            $encodedManifest = json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            if ($encodedManifest === false ||
                file_put_contents($manifestTemporary, $encodedManifest, LOCK_EX) === false) {
                throw new RuntimeException('Could not write the source manifest.');
            }

            $pdo = Database::connection();
            $pdo->beginTransaction();
            try {
                (new SourceAssetRepository())->create($metadata);
                $update = $pdo->prepare(
                    "UPDATE projects
                     SET status = 'IMPORTED', updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id"
                );
                $update->execute(['id' => $projectId]);

                if (!rename($stagedPath, $finalPath)) {
                    throw new RuntimeException('Could not promote the immutable source EPUB.');
                }
                if (!rename($manifestTemporary, $manifestFinal)) {
                    throw new RuntimeException('Could not promote the source manifest.');
                }

                $pdo->commit();
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                @unlink($finalPath);
                @unlink($manifestTemporary);
                @unlink($manifestFinal);
                throw $error;
            }

            return $metadata;
        } catch (Throwable $error) {
            @unlink($stagedPath);
            throw $error;
        }
    }

    private function uploadError(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'The EPUB exceeds the configured upload limit.',
            UPLOAD_ERR_PARTIAL => 'The EPUB upload was incomplete.',
            UPLOAD_ERR_NO_FILE => 'Select an EPUB file to upload.',
            UPLOAD_ERR_NO_TMP_DIR => 'The PHP upload temporary directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'PHP could not write the uploaded file.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload.',
            default => 'The EPUB upload failed with error code ' . $error . '.',
        };
    }

    private static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

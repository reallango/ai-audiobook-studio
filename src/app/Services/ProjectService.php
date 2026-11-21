<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database;
use App\Repositories\ProjectRepository;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ProjectService
{
    public function __construct(private readonly ProjectRepository $projects = new ProjectRepository()) {}

    public function create(string $title, string $provider, string $voice, int $bitrate): array
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? '');
        if ($title === '' || mb_strlen($title) > 200) throw new InvalidArgumentException('Title is required and must be 200 characters or fewer.');
        if (!in_array($bitrate, [64,96,128], true)) throw new InvalidArgumentException('Select a supported audio quality.');
        $provider = trim($provider) ?: 'kokoro'; $voice = trim($voice);
        if (mb_strlen($provider) > 100 || mb_strlen($voice) > 100) throw new InvalidArgumentException('Provider or voice value is too long.');

        $id = self::uuid(); $slug = $this->uniqueSlug($title, $id); $now = gmdate('Y-m-d H:i:s');
        $root = rtrim((string) config('paths.projects'), '/') . '/' . $id;
        $dirs = ['source','derived/text','derived/previews','derived/audio','exports','backups','logs'];
        $pdo = Database::connection(); $pdo->beginTransaction();
        try {
            foreach ($dirs as $dir) if (!mkdir($root . '/' . $dir, 0770, true) && !is_dir($root . '/' . $dir)) throw new RuntimeException('Unable to create project storage.');
            $manifest = ['project_id'=>$id,'title'=>$title,'created_at'=>$now,'source_immutable'=>true,'format_version'=>1];
            if (file_put_contents($root . '/project.json.tmp', json_encode($manifest, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), LOCK_EX) === false) throw new RuntimeException('Unable to write project manifest.');
            rename($root . '/project.json.tmp', $root . '/project.json');
            $project = ['id'=>$id,'slug'=>$slug,'title'=>$title,'provider'=>$provider,'voice'=>$voice,'bitrate'=>$bitrate,'created_at'=>$now];
            $this->projects->create($project); $pdo->commit(); return $this->projects->find($id) ?? $project;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack(); $this->removeTree($root); throw $e;
        }
    }

    public function deleteArchived(string $id, string $confirmation): void
    {
        $project = $this->projects->find($id);
        if (!$project || $project['status'] !== 'ARCHIVED') throw new InvalidArgumentException('Only archived projects can be permanently deleted.');
        if ($confirmation !== $project['title']) throw new InvalidArgumentException('Project title confirmation did not match.');
        $root = rtrim((string) config('paths.projects'), '/') . '/' . $id;
        $trash = rtrim((string) config('paths.projects'), '/') . '/.trash-' . $id . '-' . gmdate('YmdHis');
        if (is_dir($root) && !rename($root, $trash)) throw new RuntimeException('Could not stage project files for deletion.');
        try { if (!$this->projects->delete($id)) throw new RuntimeException('Project database record was not deleted.'); $this->removeTree($trash); }
        catch (Throwable $e) { if (is_dir($trash)) @rename($trash,$root); throw $e; }
    }

    private function uniqueSlug(string $title, string $id): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title) ?? '', '-'));
        if ($slug === '') $slug = 'project';
        return substr($slug,0,80) . '-' . substr(str_replace('-','',$id),0,8);
    }
    private static function uuid(): string
    {
        $d=random_bytes(16); $d[6]=chr((ord($d[6])&0x0f)|0x40); $d[8]=chr((ord($d[8])&0x3f)|0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));
    }
    private function removeTree(string $path): void
    {
        if (!is_dir($path)) return;
        $items=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach($items as $item) $item->isDir()?@rmdir($item->getPathname()):@unlink($item->getPathname()); @rmdir($path);
    }
}

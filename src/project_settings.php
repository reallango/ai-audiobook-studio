<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\Csrf;
use App\Core\Database;
use App\Repositories\ProjectRepository;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'message' => 'POST is required.']);
    exit;
}

try {
    $payload = json_decode(
        (string) file_get_contents('php://input'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    if (!Csrf::validate($payload['_token'] ?? null)) {
        http_response_code(419);
        echo json_encode(['ok' => false, 'message' => 'Security token expired. Reload the project page.']);
        exit;
    }

    $projectId = (string) ($payload['project_id'] ?? '');
    $project = (new ProjectRepository())->find($projectId);
    if (!$project) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Project not found.']);
        exit;
    }
    if ($project['status'] === 'ARCHIVED') {
        throw new InvalidArgumentException('Restore the project before changing generation settings.');
    }

    $provider = trim((string) ($payload['provider'] ?? ''));
    $voice = trim((string) ($payload['voice'] ?? ''));
    $bitrate = (int) ($payload['bitrate'] ?? 0);

    if ($provider === '' || mb_strlen($provider) > 100) {
        throw new InvalidArgumentException('Provider must contain 1 to 100 characters.');
    }
    if (mb_strlen($voice) > 100) {
        throw new InvalidArgumentException('Voice must contain 100 characters or fewer.');
    }
    if (!in_array($bitrate, [64, 96, 128], true)) {
        throw new InvalidArgumentException('Select a supported audio quality.');
    }

    $statement = Database::connection()->prepare(
        'UPDATE projects
         SET active_provider_profile_id=:provider,
             active_voice_profile_id=:voice,
             audio_bitrate_kbps=:bitrate,
             updated_at=CURRENT_TIMESTAMP
         WHERE id=:id'
    );
    $statement->execute([
        'provider' => $provider,
        'voice' => $voice !== '' ? $voice : null,
        'bitrate' => $bitrate,
        'id' => $projectId,
    ]);

    echo json_encode([
        'ok' => true,
        'message' => 'Generation settings saved.',
        'settings' => [
            'provider' => $provider,
            'voice' => $voice,
            'bitrate' => $bitrate,
        ],
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'message' => $error->getMessage(),
    ], JSON_UNESCAPED_SLASHES);
}

<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database;
use InvalidArgumentException;

/**
 * Where a project stands in the workflow: Source, Structure, Voice and rules, Review, Generate, Export.
 * The project page shows this as a stepper with one clear next action.
 */
final class ProjectWorkflow
{
    /**
     * @param array<string,mixed> $project
     * @return array{steps:array<int,array<string,mixed>>,next:array<string,mixed>,counts:array<string,mixed>}
     */
    public static function build(array $project, bool $imported, bool $analyzed, ?string $filename): array
    {
        $id = (string) $project['id'];
        $db = Database::connection();
        $archived = ($project['status'] ?? '') === 'ARCHIVED';

        $total = $included = 0;
        if ($analyzed) {
            $s = $db->prepare('SELECT COUNT(*), COALESCE(SUM(included),0) FROM logical_sections WHERE project_id=:p');
            $s->execute(['p' => $id]);
            [$total, $included] = array_map('intval', $s->fetch(\PDO::FETCH_NUM) ?: [0, 0]);
        }

        // chapters with something to read: approval and audio state
        $reviewable = $approved = $changed = $ready = $working = $failed = $todo = 0;
        $audioMs = 0;
        $export = null;
        $exportCurrent = false;
        if ($included > 0) {
            $rows = (new NarrationService())->overview($id);
            try {
                $summaries = (new GenerationService())->summaries($id, $rows);
            } catch (InvalidArgumentException $e) {
                $summaries = [];                            // archived project or an invalid voice
            }
            foreach ($rows as $row) {
                if ((int) $row['words'] <= 0) continue;
                $reviewable++;
                if ($row['status'] === 'changed') $changed++;
                if ($row['status'] !== 'approved') continue;
                $approved++;
                $state = $summaries[$row['id']]['state'] ?? 'none';
                if ($state === 'ready') { $ready++; $audioMs += (int) ($summaries[$row['id']]['duration_ms'] ?? 0); }
                elseif ($state === 'working') $working++;
                elseif ($state === 'failed') $failed++;
                else $todo++;
            }
            $exports = new ExportService();
            $export = $exports->exportRow($id);
            $exportCurrent = $export !== null && $ready > 0 && $exports->isCurrent($id, $export);
        }

        $pron = new PronunciationService();
        $linked = $pron->projectSets($id);
        $ruleCounts = array_column($pron->sets(), 'rule_count', 'id');
        $projectRules = count($pron->rules('project', $id));
        $voice = (string) ($project['active_voice_profile_id'] ?? '');
        $speed = (float) ($project['tts_speed'] ?? 1.0);

        $done = [
            'source' => $imported && $analyzed,
            'structure' => $total > 0,
            'voice' => $voice !== '',
            'review' => $reviewable > 0 && $approved === $reviewable,
            'generate' => $approved > 0 && $ready === $approved && $working === 0 && $failed === 0,
            'export' => $exportCurrent,
        ];
        $current = null;
        foreach ($done as $key => $isDone) {
            if (!$isDone) { $current = $key; break; }
        }

        $steps = [
            ['key' => 'source', 'title' => 'Source', 'summary' => !$imported ? 'No EPUB yet.' : ($filename ?? 'EPUB') . ($analyzed ? ', analyzed.' : ', not analyzed yet.')],
            ['key' => 'structure', 'title' => 'Structure', 'summary' => $total > 0 ? "$included of $total sections included as chapters." : 'Chapters not built yet.'],
            ['key' => 'voice', 'title' => 'Voice and rules', 'summary' => ($voice !== '' ? "Voice $voice at " . number_format($speed, 2) . 'x. ' : 'No voice chosen. ')
                . count($linked) . ' linked ruleset' . (count($linked) === 1 ? '' : 's') . ', ' . $projectRules . ' project rule' . ($projectRules === 1 ? '' : 's') . '.'],
            ['key' => 'review', 'title' => 'Review', 'summary' => $reviewable > 0 ? "$approved of $reviewable chapters approved" . ($changed > 0 ? ", $changed changed since approval." : '.') : 'Nothing to review yet.'],
            ['key' => 'generate', 'title' => 'Generate', 'summary' => $approved === 0 ? 'Approve chapters first.'
                : "$ready of $approved approved chapters have audio" . ($working > 0 ? ", $working generating" : '') . ($failed > 0 ? ", $failed failed" : '') . '.'],
            ['key' => 'export', 'title' => 'Export', 'summary' => $export === null ? 'No audiobook file built yet.'
                : ($exportCurrent ? 'Audiobook file is up to date.' : 'Audiobook file is out of date; chapters changed since.')],
        ];
        // a step is done only when everything before it is; the first unfinished one is current, the rest wait
        $reached = false;
        foreach ($steps as &$step) {
            if ($reached) { $step['state'] = 'todo'; continue; }
            if ($done[$step['key']]) { $step['state'] = 'done'; continue; }
            $step['state'] = 'current';
            $reached = true;
        }
        unset($step);

        $next = match ($current) {
            'source' => !$imported
                ? ['type' => 'link', 'label' => 'Import the EPUB', 'reason' => 'Start by adding the book.', 'href' => base_url('epub-upload', ['id' => $id])]
                : ['type' => 'post', 'label' => 'Analyze the EPUB', 'reason' => 'Reads the chapters and paragraphs out of the book.', 'action' => 'epub-analyze'],
            'structure' => ['type' => 'link', 'label' => 'Build the audiobook structure', 'reason' => 'Group the book\'s files into chapters and choose what is read.',
                'href' => base_url('arrange', ['id' => $id])],
            'voice' => ['type' => 'link', 'label' => 'Choose a voice', 'reason' => 'Pick the narrator in the settings.', 'href' => base_url('project', ['id' => $id]) . '#settings'],
            'review' => ['type' => 'link', 'label' => 'Review and approve chapters', 'reason' => "$approved of $reviewable approved. Read the narration, fix what sounds wrong, then approve.", 'href' => base_url('proofread', ['id' => $id])],
            'generate' => $working > 0
                ? ['type' => 'link', 'label' => 'Audio is being generated', 'reason' => "$ready of $approved chapters are done so far.", 'href' => base_url('proofread', ['id' => $id])]
                : ($failed > 0
                    ? ['type' => 'link', 'label' => 'Fix the failed chapters', 'reason' => "$failed chapter(s) failed. Open them for the reason, then retry.", 'href' => base_url('proofread', ['id' => $id])]
                    : ['type' => 'link', 'label' => 'Generate audio', 'reason' => ($approved - $ready) . ' approved chapter(s) still need audio.', 'href' => base_url('proofread', ['id' => $id])]),
            'export' => ['type' => 'link', 'label' => 'Build the audiobook file', 'reason' => 'Join the finished chapters into a chaptered M4B, or download the chapter files.', 'href' => base_url('export', ['id' => $id])],
            default => ['type' => 'link', 'label' => 'Download the audiobook', 'reason' => 'Everything is done. The M4B is ready.', 'href' => base_url('export', ['id' => $id])],
        };
        if ($archived) $next = ['type' => 'none', 'label' => 'This project is archived', 'reason' => 'Restore it to continue.'];

        // The four stages the Library shows: where the book is, and how far along that stage.
        $stage = match ($current) {
            'source', 'voice' => ['key' => 'import', 'progress' => $imported ? 0.5 : 0.0],
            'structure' => ['key' => 'arrange', 'progress' => 0.0],
            'review', 'generate' => ['key' => 'proofread', 'progress' => $reviewable > 0 ? ($approved + $ready) / (2 * $reviewable) : 0.0],
            'export' => ['key' => 'export', 'progress' => 0.5],
            default => ['key' => 'done', 'progress' => 1.0],
        };

        return [
            'stage' => $stage,
            'steps' => $steps,
            'next' => $next,
            'counts' => ['total' => $total, 'included' => $included, 'reviewable' => $reviewable, 'approved' => $approved, 'ready' => $ready, 'working' => $working,
                'failed' => $failed, 'todo' => $todo, 'audio_ms' => $audioMs, 'linked' => count($linked), 'project_rules' => $projectRules],
            'linked' => array_map(static fn (array $s): array => ['id' => (string) $s['id'], 'name' => (string) $s['name'], 'rules' => (int) ($ruleCounts[$s['id']] ?? 0)], $linked),
            'export' => $export,
            'export_current' => $exportCurrent,
        ];
    }
}

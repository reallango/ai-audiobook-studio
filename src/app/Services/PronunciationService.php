<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database;
use InvalidArgumentException;
use PDO;

/**
 * Layered pronunciation overrides, applied to text before it is sent to Kokoro.
 * Precedence, lowest to highest: default < voice < ruleset < project.
 */
final class PronunciationService
{
    public const LAYER_LABELS = ['default' => 'Default', 'voice' => 'Voice', 'set' => 'Ruleset', 'project' => 'Project'];

    /** Bundled lists the UI can import as a ruleset (file name => set name). */
    public const BUNDLED = ['sisko-original.json' => 'Star Trek DS9: Sisko (original list)'];

    // ----- rules -----

    /** @return array<int,array<string,mixed>> */
    public function rules(string $scope, string $ref = ''): array
    {
        $this->assertLayer($scope, $ref);
        $s = $this->db()->prepare('SELECT * FROM pronunciation_rules WHERE scope=:s AND scope_ref=:r ORDER BY pattern COLLATE NOCASE');
        $s->execute(['s' => $scope, 'r' => $ref]);
        return $s->fetchAll();
    }

    /** Adds a rule, or replaces the spoken form of an existing rule with the same pattern in that layer. */
    public function save(string $scope, string $ref, string $pattern, string $replacement, bool $caseSensitive = false, bool $wholeWord = true, string $note = ''): void
    {
        $this->assertLayer($scope, $ref);
        $pattern = trim($pattern);
        $replacement = trim($replacement);
        if ($pattern === '' || mb_strlen($pattern) > 100) throw new InvalidArgumentException('The written form must be 1 to 100 characters.');
        // An empty spoken form is allowed: it removes the text from the narration (watermarks, page markers).
        if (mb_strlen($replacement) > 200) throw new InvalidArgumentException('The spoken form is limited to 200 characters.');
        if (mb_strlen($note) > 300) throw new InvalidArgumentException('The note is limited to 300 characters.');
        $s = $this->db()->prepare(
            'INSERT INTO pronunciation_rules(scope,scope_ref,pattern,replacement,case_sensitive,whole_word,note)
             VALUES(:s,:r,:p,:rep,:cs,:ww,:n)
             ON CONFLICT(scope,scope_ref,pattern) DO UPDATE SET replacement=excluded.replacement, case_sensitive=excluded.case_sensitive,
               whole_word=excluded.whole_word, note=excluded.note, updated_at=CURRENT_TIMESTAMP'
        );
        $s->execute(['s' => $scope, 'r' => $ref, 'p' => $this->normalize($pattern), 'rep' => $replacement, 'cs' => (int) $caseSensitive, 'ww' => (int) $wholeWord, 'n' => $note]);
    }

    public function setEnabled(int $ruleId, bool $enabled): void
    {
        $this->db()->prepare('UPDATE pronunciation_rules SET enabled=:e, updated_at=CURRENT_TIMESTAMP WHERE id=:id')->execute(['e' => (int) $enabled, 'id' => $ruleId]);
    }

    /**
     * Moves or copies one rule to another ruleset (default, a voice, a shared ruleset or a project).
     * A rule with the same written form already there is replaced. Returns 'created' or 'replaced'.
     */
    public function transfer(int $ruleId, string $toScope, string $toRef, bool $move): string
    {
        $s = $this->db()->prepare('SELECT * FROM pronunciation_rules WHERE id=:id');
        $s->execute(['id' => $ruleId]);
        $rule = $s->fetch();
        if (!$rule) throw new InvalidArgumentException('That rule no longer exists.');
        $toRef = $toScope === 'default' ? '' : $toRef;
        $this->assertLayer($toScope, $toRef);
        if ($rule['scope'] === $toScope && (string) $rule['scope_ref'] === $toRef) throw new InvalidArgumentException('The rule is already in that ruleset.');

        $db = $this->db();
        $exists = $db->prepare('SELECT 1 FROM pronunciation_rules WHERE scope=:s AND scope_ref=:r AND pattern=:p');
        $exists->execute(['s' => $toScope, 'r' => $toRef, 'p' => $rule['pattern']]);
        $replaced = (bool) $exists->fetchColumn();
        $db->beginTransaction();
        try {
            $db->prepare(
                'INSERT INTO pronunciation_rules(scope,scope_ref,pattern,replacement,case_sensitive,whole_word,enabled,note)
                 VALUES(:s,:r,:p,:rep,:cs,:ww,:en,:n)
                 ON CONFLICT(scope,scope_ref,pattern) DO UPDATE SET replacement=excluded.replacement, case_sensitive=excluded.case_sensitive,
                   whole_word=excluded.whole_word, enabled=excluded.enabled, note=excluded.note, updated_at=CURRENT_TIMESTAMP'
            )->execute(['s' => $toScope, 'r' => $toRef, 'p' => $rule['pattern'], 'rep' => $rule['replacement'], 'cs' => $rule['case_sensitive'], 'ww' => $rule['whole_word'], 'en' => $rule['enabled'], 'n' => $rule['note']]);
            if ($move) $db->prepare('DELETE FROM pronunciation_rules WHERE id=:id')->execute(['id' => $ruleId]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        return $replaced ? 'replaced' : 'created';
    }

    /** A readable name for a ruleset, for messages. */
    public function layerName(string $scope, string $ref): string
    {
        return match ($scope) {
            'default' => 'Default',
            'voice' => 'Voice ' . $ref,
            'set' => 'Ruleset ' . (string) ($this->set($ref)['name'] ?? '?'),
            'project' => 'Project ' . (string) ($this->db()->query('SELECT title FROM projects WHERE id=' . $this->db()->quote($ref))->fetchColumn() ?: '?'),
            default => $scope,
        };
    }

    public function delete(int $ruleId): void
    {
        $this->db()->prepare('DELETE FROM pronunciation_rules WHERE id=:id')->execute(['id' => $ruleId]);
    }

    /**
     * Imports a JSON object {"written": "spoken", ...} into a layer. Returns the number of rules saved.
     * @param array<string,mixed> $map
     */
    public function importMap(string $scope, string $ref, array $map): int
    {
        $this->assertLayer($scope, $ref);
        $db = $this->db();
        $db->beginTransaction();
        try {
            $n = 0;
            foreach ($map as $written => $spoken) {
                if (!is_string($spoken)) throw new InvalidArgumentException('Every value must be text: "' . mb_substr((string) $written, 0, 40) . '" is not.');
                $this->save($scope, $ref, (string) $written, $spoken);
                $n++;
            }
            $db->commit();
            return $n;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public static function decodeJsonMap(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new InvalidArgumentException('Expected a JSON object like {"Bajor": "Bay-jor"}.');
        }
        if (count($data) > 5000) throw new InvalidArgumentException('That list has more than 5000 entries.');
        return $data;
    }

    // ----- rulesets (named, shared) -----

    /** @return array<int,array<string,mixed>> */
    public function sets(): array
    {
        return $this->db()->query(
            'SELECT s.*, (SELECT COUNT(*) FROM pronunciation_rules r WHERE r.scope=\'set\' AND r.scope_ref=s.id) AS rule_count,
                    (SELECT COUNT(*) FROM project_pronunciation_sets p WHERE p.set_id=s.id) AS project_count
             FROM pronunciation_sets s ORDER BY s.name COLLATE NOCASE'
        )->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function set(string $id): ?array
    {
        $s = $this->db()->prepare('SELECT * FROM pronunciation_sets WHERE id=:id');
        $s->execute(['id' => $id]);
        return $s->fetch() ?: null;
    }

    public function createSet(string $name, string $description = ''): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) throw new InvalidArgumentException('A ruleset name must be 1 to 100 characters.');
        $id = self::uuid();
        try {
            $this->db()->prepare('INSERT INTO pronunciation_sets(id,name,description) VALUES(:id,:n,:d)')->execute(['id' => $id, 'n' => $name, 'd' => mb_substr(trim($description), 0, 300)]);
        } catch (\PDOException $e) {
            throw new InvalidArgumentException('A ruleset with that name already exists.');
        }
        return $id;
    }

    public function deleteSet(string $id): void
    {
        $db = $this->db();
        $db->beginTransaction();
        $db->prepare("DELETE FROM pronunciation_rules WHERE scope='set' AND scope_ref=:id")->execute(['id' => $id]);
        $db->prepare('DELETE FROM project_pronunciation_sets WHERE set_id=:id')->execute(['id' => $id]);
        $db->prepare('DELETE FROM pronunciation_sets WHERE id=:id')->execute(['id' => $id]);
        $db->commit();
    }

    /** @return array<int,array<string,mixed>> sets linked to a project, in override order (later wins) */
    public function projectSets(string $projectId): array
    {
        $s = $this->db()->prepare('SELECT s.*, p.position FROM project_pronunciation_sets p JOIN pronunciation_sets s ON s.id=p.set_id WHERE p.project_id=:p ORDER BY p.position, s.name COLLATE NOCASE');
        $s->execute(['p' => $projectId]);
        return $s->fetchAll();
    }

    public function attachSet(string $projectId, string $setId): void
    {
        if (!$this->set($setId)) throw new InvalidArgumentException('That ruleset does not exist.');
        $pos = (int) $this->db()->query("SELECT COALESCE(MAX(position),0)+1 FROM project_pronunciation_sets WHERE project_id=" . $this->db()->quote($projectId))->fetchColumn();
        $this->db()->prepare('INSERT OR IGNORE INTO project_pronunciation_sets(project_id,set_id,position) VALUES(:p,:s,:pos)')->execute(['p' => $projectId, 's' => $setId, 'pos' => $pos]);
    }

    public function detachSet(string $projectId, string $setId): void
    {
        $this->db()->prepare('DELETE FROM project_pronunciation_sets WHERE project_id=:p AND set_id=:s')->execute(['p' => $projectId, 's' => $setId]);
    }

    // ----- resolving and applying -----

    /**
     * The rules in force for a voice and (optionally) a project, after layering.
     * @return array<string,array<string,mixed>> keyed by lowercase pattern; each has layer and layer_label
     */
    public function effective(?string $projectId, ?string $voice, array $extraSetIds = []): array
    {
        $layers = [['default', '', 'Default']];
        if ($voice !== null && $voice !== '') $layers[] = ['voice', $voice, 'Voice ' . $voice];
        // Rulesets applied on top of the voice without a project, for testing the set you are editing.
        $linked = $projectId !== null && $projectId !== '' ? array_column($this->projectSets($projectId), 'id') : [];
        foreach ($extraSetIds as $setId) {
            $set = $this->set((string) $setId);
            if ($set !== null && !in_array($set['id'], $linked, true)) $layers[] = ['set', (string) $set['id'], 'Ruleset ' . $set['name']];
        }
        if ($projectId !== null && $projectId !== '') {
            foreach ($this->projectSets($projectId) as $set) $layers[] = ['set', (string) $set['id'], 'Ruleset ' . $set['name']];
            $layers[] = ['project', $projectId, 'Project'];
        }
        $effective = [];
        foreach ($layers as $rank => [$scope, $ref, $label]) {
            foreach ($this->rules($scope, $ref) as $rule) {
                $key = mb_strtolower((string) $rule['pattern']);
                if ((int) $rule['enabled'] !== 1) {
                    unset($effective[$key]);        // a disabled rule switches off the same pattern from lower layers
                    continue;
                }
                // A rule for a word also decides its possessive, over a LOWER layer's separate rule for "word's".
                $possessive = $key . "'s";
                if (isset($effective[$possessive]) && $effective[$possessive]['rank'] < $rank) unset($effective[$possessive]);
                $effective[$key] = $rule + ['layer' => $scope, 'layer_label' => $label, 'rank' => $rank];
            }
        }
        return $effective;
    }

    /**
     * Rewrites text with the effective rules. Matching is one pass, longest pattern first, so a spoken form is never
     * rewritten again. Curly apostrophes are treated as straight ones.
     *
     * Besides the result and the rules that fired, it returns the text as pieces (what was written, what is spoken,
     * and why), which is how the chapter page marks the places where the text changed.
     *
     * @param array<string,array<string,mixed>> $effective
     * @return array{text:string,hits:array<string,int>,pieces:array<int,array<string,mixed>>}
     */
    public function apply(string $text, array $effective, bool $normalizeCaps = true): array
    {
        $text = str_replace(["\u{2019}", "\u{2018}"], "'", $text);
        if ($effective === [] && !$normalizeCaps) return ['text' => $text, 'hits' => [], 'pieces' => $text === '' ? [] : [self::plainPiece($text, 0)]];
        $rules = array_values($effective);
        usort($rules, static fn (array $a, array $b): int => mb_strlen((string) $b['pattern']) <=> mb_strlen((string) $a['pattern']));

        $parts = [];
        $byKey = [];
        foreach ($rules as $rule) {
            $body = preg_quote((string) $rule['pattern'], '/');
            if ((int) $rule['case_sensitive'] !== 1) $body = '(?i:' . $body . ')';
            // A whole-word rule also covers the possessive: a rule for Bajor handles Bajor's and BAJOR'S too.
            if ((int) $rule['whole_word'] === 1) $body = '(?<![\p{L}\p{N}_\'])' . $body . '(?:\'[sS])?(?![\p{L}\p{N}_\'])';
            $parts[] = $body;
            $byKey[mb_strtolower((string) $rule['pattern'])] = $rule;
        }
        // Last alternative, so any rule wins over it: an ALL-CAPS word that no rule covers (Kokoro would spell it out).
        if ($normalizeCaps) {
            $parts[] = '(?<caps>' . self::CAPS_TOKEN . ')';
            $parts[] = '(?<poss>(?<![\p{L}\p{N}_\'])\p{N}+\'S(?![\p{L}\p{N}_\']))';        // 9'S -> 9's
        }

        $hits = [];
        $pieces = [];
        $pos = 0;
        if (preg_match_all('/' . implode('|', $parts) . '/u', $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($all as $m) {
                [$matched, $offset] = $m[0];
                if ($offset > $pos) $pieces[] = self::plainPiece(substr($text, $pos, $offset - $pos), $pos);
                $pos = $offset + strlen($matched);
                if (isset($m['caps']) && $m['caps'][0] !== '') {
                    $normal = self::normalCase($matched);
                    if ($normal === null || $normal === $matched) { $pieces[] = self::plainPiece($matched, $offset); continue; }
                    $hits['[caps] ' . $matched] = ($hits['[caps] ' . $matched] ?? 0) + 1;
                    $pieces[] = ['src' => $matched, 'src_start' => $offset, 'out' => $normal, 'kind' => 'caps', 'rule' => $matched, 'scope' => '', 'scope_ref' => '', 'label' => 'ALL-CAPS word'];
                    continue;
                }
                if (isset($m['poss']) && $m['poss'][0] !== '') {
                    $pieces[] = ['src' => $matched, 'src_start' => $offset, 'out' => substr($matched, 0, -1) . 's', 'kind' => 'caps', 'rule' => $matched, 'scope' => '', 'scope_ref' => '', 'label' => "ALL-CAPS 's"];
                    continue;
                }
                $rule = $byKey[mb_strtolower($matched)] ?? null;
                $suffix = '';
                if ($rule === null && preg_match('/^(.+)\'[sS]$/u', $matched, $sm)) {      // the possessive of a word that has a rule
                    $base = $byKey[mb_strtolower($sm[1])] ?? null;
                    if ($base !== null && (int) $base['whole_word'] === 1) { $rule = $base; $suffix = "'s"; }
                }
                $replacement = $rule === null ? '' : (string) $rule['replacement'] . $suffix;
                if ($rule === null || $replacement === $matched) { $pieces[] = self::plainPiece($matched, $offset); continue; }
                $hits[$rule['pattern']] = ($hits[$rule['pattern']] ?? 0) + 1;
                $pieces[] = ['src' => $matched, 'src_start' => $offset, 'out' => $replacement, 'kind' => 'rule', 'rule' => (string) $rule['pattern'],
                    'scope' => (string) ($rule['scope'] ?? ($rule['layer'] ?? '')), 'scope_ref' => (string) ($rule['scope_ref'] ?? ''), 'label' => (string) ($rule['layer_label'] ?? '')];
            }
        }
        if ($pos < strlen($text)) $pieces[] = self::plainPiece(substr($text, $pos), $pos);
        return ['text' => implode('', array_column($pieces, 'out')), 'hits' => $hits, 'pieces' => $pieces];
    }

    /** @return array<string,mixed> */
    public static function plainPiece(string $text, int $start): array
    {
        return ['src' => $text, 'src_start' => $start, 'out' => $text, 'kind' => 'plain', 'rule' => '', 'scope' => '', 'scope_ref' => '', 'label' => ''];
    }

    /** An ALL-CAPS word: capitals and digits, optionally joined by apostrophes or hyphens (STARBASE, WASN'T, NCC-1701). */
    private const CAPS_TOKEN = '(?<![\p{L}\p{N}_\'])\p{Lu}[\p{Lu}\p{N}]*(?:[\'-][\p{Lu}\p{N}]+)*(?![\p{L}\p{N}_\'])';

    /**
     * STARBASE becomes Starbase, WASN'T becomes Wasn't. Null means leave it: a single letter, or a Roman numeral
     * (II, IV, XII), which a rule should decide.
     */
    public static function normalCase(string $token): ?string
    {
        $letters = preg_replace('/[^\p{L}]/u', '', $token) ?? '';
        if (mb_strlen($letters) < 2) return null;
        if (preg_match('/^[IVXLCDM]+$/', $letters) && !preg_match('/[\'-]/', $token)) return null;
        $lower = mb_strtolower($token);
        return preg_replace_callback('/\p{L}/u', static fn (array $m): string => mb_strtoupper($m[0]), $lower, 1) ?? $lower;
    }

    /**
     * Adds temporary rules on top of the effective ones, to try a change before saving it.
     * @param array<string,array<string,mixed>> $effective
     * @param array<int,array{pattern:string,replacement:string}> $temporary
     * @return array<string,array<string,mixed>>
     */
    public function withTemporary(array $effective, array $temporary): array
    {
        foreach (array_slice($temporary, 0, 20) as $t) {
            $pattern = trim(str_replace(["\u{2019}", "\u{2018}"], "'", (string) ($t['pattern'] ?? '')));
            if ($pattern === '' || mb_strlen($pattern) > 100 || mb_strlen((string) ($t['replacement'] ?? '')) > 200) continue;
            $effective[mb_strtolower($pattern)] = [
                'pattern' => $pattern, 'replacement' => (string) ($t['replacement'] ?? ''), 'case_sensitive' => 0, 'whole_word' => 1,
                'enabled' => 1, 'layer' => 'temp', 'layer_label' => 'Trying it now',
            ];
        }
        return $effective;
    }

    /**
     * Every ALL-CAPS word in the given texts that no rule covers, with how often it occurs and what it becomes.
     * @param string[] $texts
     * @param array<string,array<string,mixed>> $effective
     * @return array<int,array{token:string,becomes:?string,count:int}>
     */
    public static function capsAudit(array $texts, array $effective): array
    {
        $counts = [];
        foreach ($texts as $text) {
            $text = str_replace(["\u{2019}", "\u{2018}"], "'", $text);
            if (preg_match_all('/' . self::CAPS_TOKEN . '/u', $text, $m)) {
                foreach ($m[0] as $token) {
                    if (isset($effective[mb_strtolower($token)])) continue;
                    if (mb_strlen((string) preg_replace('/[^\p{L}]/u', '', $token)) < 2) continue;   // "I", "A": nothing to decide
                    $counts[$token] = ($counts[$token] ?? 0) + 1;
                }
            }
        }
        $rows = [];
        foreach ($counts as $token => $n) {
            $normal = self::normalCase((string) $token);
            $rows[] = ['token' => (string) $token, 'becomes' => $normal, 'count' => $n];
        }
        usort($rows, static fn (array $a, array $b): int => [$b['count'], $a['token']] <=> [$a['count'], $b['token']]);
        return $rows;
    }

    // ----- internals -----

    private function assertLayer(string $scope, string $ref): void
    {
        switch ($scope) {
            case 'default':
                if ($ref !== '') throw new InvalidArgumentException('The default layer has no reference.');
                return;
            case 'voice':
                if (!KokoroClient::validVoice($ref)) throw new InvalidArgumentException('That voice name is not valid.');
                return;
            case 'set':
                if (!$this->set($ref)) throw new InvalidArgumentException('That ruleset does not exist.');
                return;
            case 'project':
                $s = $this->db()->prepare('SELECT 1 FROM projects WHERE id=:id');
                $s->execute(['id' => $ref]);
                if (!$s->fetchColumn()) throw new InvalidArgumentException('That project does not exist.');
                return;
            default:
                throw new InvalidArgumentException('Unknown pronunciation layer.');
        }
    }

    private function normalize(string $pattern): string
    {
        return str_replace(["\u{2019}", "\u{2018}"], "'", $pattern);
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

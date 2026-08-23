<?php
/**
 * PipelineTemplateManager.php
 *
 * CRUD + validation for pipeline TYPE definitions and their ordered stages.
 * A type (pipeline_templates) has N stages (pipeline_stages); each stage has
 * exactly one default owner — a user OR a role.
 *
 * @package BDC_IMS
 * @subpackage Pipelines
 */

require_once(__DIR__ . '/../../helpers/SchemaHelper.php');
require_once(__DIR__ . '/../../auth/TemporaryAccessManager.php');
require_once(__DIR__ . '/RequestActionExecutor.php');
require_once(__DIR__ . '/../../config/PipelineConfig.php');

class PipelineTemplateManager
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * List pipeline types.
     *
     * @param bool $includeInactive Include archived (is_active = 0) types
     * @param bool $includeStages   Attach the ordered stage list to each type
     * @return array
     */
    public function listTemplates($includeInactive = false, $includeStages = false)
    {
        try {
            $where = $includeInactive ? '' : 'WHERE t.is_active = 1';
            $stmt = $this->pdo->prepare("
                SELECT
                    t.id, t.name, t.description, t.is_active, t.is_system,
                    t.created_by, creator.username AS created_by_username,
                    t.created_at, t.updated_at,
                    (SELECT COUNT(*) FROM pipeline_stages s WHERE s.pipeline_template_id = t.id) AS stage_count
                FROM pipeline_templates t
                LEFT JOIN users creator ON t.created_by = creator.id
                $where
                ORDER BY t.name ASC
            ");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $templates = array_map(function ($row) {
                return [
                    'id' => (int)$row['id'],
                    'name' => $row['name'],
                    'description' => $row['description'],
                    'is_active' => (int)$row['is_active'],
                    'is_system' => (int)$row['is_system'],
                    'stage_count' => (int)$row['stage_count'],
                    'created_by' => $row['created_by'] ? [
                        'id' => (int)$row['created_by'],
                        'username' => $row['created_by_username']
                    ] : null,
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at']
                ];
            }, $rows);

            if ($includeStages) {
                foreach ($templates as &$tpl) {
                    $tpl['stages'] = $this->getStages($tpl['id']);
                }
                unset($tpl);
            }

            return $templates;
        } catch (Exception $e) {
            error_log("PipelineTemplateManager::listTemplates error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get a single type with its ordered stages.
     *
     * @param int $templateId
     * @return array|null
     */
    public function getTemplate($templateId)
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    t.id, t.name, t.description, t.is_active, t.is_system,
                    t.created_by, creator.username AS created_by_username,
                    t.created_at, t.updated_at
                FROM pipeline_templates t
                LEFT JOIN users creator ON t.created_by = creator.id
                WHERE t.id = ?
            ");
            $stmt->execute([$templateId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                return null;
            }

            return [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'description' => $row['description'],
                'is_active' => (int)$row['is_active'],
                'is_system' => (int)$row['is_system'],
                'created_by' => $row['created_by'] ? [
                    'id' => (int)$row['created_by'],
                    'username' => $row['created_by_username']
                ] : null,
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'stages' => $this->getStages($templateId)
            ];
        } catch (Exception $e) {
            error_log("PipelineTemplateManager::getTemplate error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get ordered stages for a type, with owner display names resolved.
     *
     * @param int $templateId
     * @return array
     */
    public function getStages($templateId)
    {
        // effect_* arrive with seeder 2026_08_20_002; select them only once the
        // migration has landed, so the code is safe to deploy ahead of it.
        $hasEffects = $this->supportsStageEffects();
        $effectSelect = $hasEffects ? ', s.effect_type, s.effect_config' : '';

        $stmt = $this->pdo->prepare("
            SELECT
                s.id, s.name, s.position, s.instructions,
                s.default_assignee_user_id, u.username AS default_user_username,
                s.default_assignee_role_id, r.display_name AS default_role_name{$effectSelect}
            FROM pipeline_stages s
            LEFT JOIN users u ON s.default_assignee_user_id = u.id
            LEFT JOIN roles r ON s.default_assignee_role_id = r.id
            WHERE s.pipeline_template_id = ?
            ORDER BY s.position ASC
        ");
        $stmt->execute([$templateId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($row) {
            return [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'position' => (int)$row['position'],
                'instructions' => $row['instructions'],
                'effect_type' => $row['effect_type'] ?? null,
                'effect_config' => $row['effect_config'] ?? null,
                'default_assignee' => $this->formatOwner(
                    $row['default_assignee_user_id'],
                    $row['default_user_username'],
                    $row['default_assignee_role_id'],
                    $row['default_role_name']
                )
            ];
        }, $rows);
    }

    /**
     * Has 2026_08_20_002 been applied? Steps carry a side effect only once it has.
     */
    public function supportsStageEffects()
    {
        return SchemaHelper::hasColumn($this->pdo, 'pipeline_stages', 'effect_type');
    }

    /**
     * Create a pipeline type with its stages.
     *
     * @param array $data ['name', 'description', 'is_active', 'stages' => [...]]
     *   Each stage: ['name', 'instructions', 'assignee_type' => user|role, 'assignee_id']
     * @param int $userId
     * @return array ['success' => bool, 'template_id' => int, 'errors' => array]
     */
    public function createTemplate($data, $userId)
    {
        $validation = $this->validateTemplate($data);
        if (!$validation['valid']) {
            return ['success' => false, 'errors' => $validation['errors']];
        }

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("
                INSERT INTO pipeline_templates (name, description, is_active, created_by, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                trim($data['name']),
                isset($data['description']) ? trim($data['description']) : null,
                isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1,
                $userId
            ]);
            $templateId = (int)$this->pdo->lastInsertId();

            $this->insertStages($templateId, $data['stages']);

            $this->pdo->commit();
            return ['success' => true, 'template_id' => $templateId, 'errors' => []];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("PipelineTemplateManager::createTemplate error: " . $e->getMessage());
            // Unique name collision surfaces as a friendly error.
            if (strpos($e->getMessage(), 'uq_pipeline_templates_name') !== false) {
                return ['success' => false, 'errors' => ['A pipeline type with this name already exists']];
            }
            return ['success' => false, 'errors' => ['Failed to create pipeline type: ' . $e->getMessage()]];
        }
    }

    /**
     * Update a pipeline type. Stages, when supplied, fully REPLACE the existing
     * set (running pipelines are unaffected because they snapshot their stages).
     *
     * @param int $templateId
     * @param array $data
     * @param int $userId
     * @return array
     */
    public function updateTemplate($templateId, $data, $userId)
    {
        $existing = $this->getTemplate($templateId);
        if (!$existing) {
            return ['success' => false, 'errors' => ['Pipeline type not found']];
        }

        // Built-in (system) types are protected: their steps and description may
        // be edited, but they cannot be renamed or archived.
        if (!empty($existing['is_system'])) {
            if (isset($data['name']) && trim($data['name']) !== $existing['name']) {
                return ['success' => false, 'errors' => ['The built-in request type cannot be renamed']];
            }
            if (isset($data['is_active']) && (int)(bool)$data['is_active'] === 0) {
                return ['success' => false, 'errors' => ['The built-in request type cannot be archived']];
            }
        }

        // Validate only when stages are being replaced; otherwise validate scalars.
        $replacingStages = array_key_exists('stages', $data);
        if ($replacingStages) {
            $validation = $this->validateTemplate($data, false);
            if (!$validation['valid']) {
                return ['success' => false, 'errors' => $validation['errors']];
            }
        }

        try {
            $this->pdo->beginTransaction();

            $fields = [];
            $params = [];
            if (isset($data['name'])) {
                if (trim($data['name']) === '') {
                    $this->pdo->rollBack();
                    return ['success' => false, 'errors' => ['Name cannot be empty']];
                }
                $fields[] = 'name = ?';
                $params[] = trim($data['name']);
            }
            if (array_key_exists('description', $data)) {
                $fields[] = 'description = ?';
                $params[] = $data['description'] !== null ? trim($data['description']) : null;
            }
            if (isset($data['is_active'])) {
                $fields[] = 'is_active = ?';
                $params[] = (int)(bool)$data['is_active'];
            }

            if (!empty($fields)) {
                $params[] = $templateId;
                $stmt = $this->pdo->prepare("UPDATE pipeline_templates SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = ?");
                $stmt->execute($params);
            }

            if ($replacingStages) {
                $del = $this->pdo->prepare("DELETE FROM pipeline_stages WHERE pipeline_template_id = ?");
                $del->execute([$templateId]);
                $this->insertStages($templateId, $data['stages']);
            }

            $this->pdo->commit();
            return ['success' => true, 'errors' => []];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("PipelineTemplateManager::updateTemplate error: " . $e->getMessage());
            if (strpos($e->getMessage(), 'uq_pipeline_templates_name') !== false) {
                return ['success' => false, 'errors' => ['A pipeline type with this name already exists']];
            }
            return ['success' => false, 'errors' => ['Failed to update pipeline type: ' . $e->getMessage()]];
        }
    }

    /**
     * Delete a pipeline type.
     *
     * Requests raised from a type do NOT depend on it surviving: the FK is
     * logical (no constraint), each request's steps were snapshotted into
     * ticket_stage_progress when it was created, and both read queries LEFT JOIN
     * the type. The only thing a delete costs is the type's NAME on those
     * requests - so it is stamped onto them first, and then the type goes.
     *
     * $force is the second ask. Without it, a type with requests behind it comes
     * back refused AND carrying the count, so the caller can put the real number
     * in front of whoever is about to press delete. Built-in types are never
     * deletable, force or not.
     *
     * @param int  $templateId
     * @param bool $force Proceed even though requests reference this type
     * @return array
     */
    public function deleteTemplate($templateId, $force = false)
    {
        try {
            $existing = $this->getTemplate($templateId);
            if (!$existing) {
                return ['success' => false, 'errors' => ['Pipeline type not found']];
            }

            // Built-in (system) types can never be deleted.
            if (!empty($existing['is_system'])) {
                return ['success' => false, 'errors' => ['Cannot delete a built-in request type']];
            }

            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM tickets WHERE pipeline_template_id = ?");
            $stmt->execute([$templateId]);
            $requestCount = (int)$stmt->fetchColumn();
            $keepsName = SchemaHelper::hasColumn($this->pdo, 'tickets', 'pipeline_type_name');

            // Code reaches production ~20s after a save; seeders are applied by
            // hand afterwards. In that window there is nowhere to put the name,
            // so the old refusal is still the right answer - it just says why.
            if ($requestCount > 0 && !$keepsName) {
                return [
                    'success' => false,
                    'errors' => ['Cannot delete a type that requests were created from until seeder '
                        . '2026_08_22_006 has been applied - it adds the column that keeps their type '
                        . 'name readable. Archive it instead for now.']
                ];
            }

            if ($requestCount > 0 && !$force) {
                return [
                    'success' => false,
                    'request_count' => $requestCount,
                    'errors' => [$requestCount . ' request' . ($requestCount === 1 ? ' was' : 's were')
                        . ' created from this type. Deleting it keeps them, and they still show "'
                        . $existing['name'] . '" as their type.']
                ];
            }

            $this->pdo->beginTransaction();
            if ($requestCount > 0) {
                // Requests raised before the snapshot column existed have no name
                // of their own yet. This is the last moment it can be read.
                $stamp = $this->pdo->prepare("
                    UPDATE tickets
                       SET pipeline_type_name = ?
                     WHERE pipeline_template_id = ?
                       AND (pipeline_type_name IS NULL OR pipeline_type_name = '')
                ");
                $stamp->execute([$existing['name'], $templateId]);
            }
            $this->pdo->prepare("DELETE FROM pipeline_stages WHERE pipeline_template_id = ?")->execute([$templateId]);
            $this->pdo->prepare("DELETE FROM pipeline_templates WHERE id = ?")->execute([$templateId]);
            $this->pdo->commit();

            return ['success' => true, 'errors' => []];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("PipelineTemplateManager::deleteTemplate error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['Failed to delete pipeline type: ' . $e->getMessage()]];
        }
    }

    // ---------------------------------------------------------------------
    // Internal helpers
    // ---------------------------------------------------------------------

    /**
     * Insert an ordered list of stages (positions assigned by array order).
     */
    private function insertStages($templateId, $stages)
    {
        $hasEffects = $this->supportsStageEffects();

        $stmt = $hasEffects
            ? $this->pdo->prepare("
                INSERT INTO pipeline_stages
                    (pipeline_template_id, name, position, default_assignee_user_id, default_assignee_role_id, instructions, effect_type, effect_config, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
              ")
            : $this->pdo->prepare("
                INSERT INTO pipeline_stages
                    (pipeline_template_id, name, position, default_assignee_user_id, default_assignee_role_id, instructions, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
              ");

        $position = 1;
        foreach ($stages as $stage) {
            $userId = null;
            $roleId = null;
            if (($stage['assignee_type'] ?? null) === 'user') {
                $userId = (int)$stage['assignee_id'];
            } else {
                $roleId = (int)$stage['assignee_id'];
            }

            $params = [
                $templateId,
                trim($stage['name']),
                $position,
                $userId,
                $roleId,
                isset($stage['instructions']) && $stage['instructions'] !== '' ? trim($stage['instructions']) : null
            ];

            if ($hasEffects) {
                $effectType = !empty($stage['effect_type']) ? trim($stage['effect_type']) : null;
                $params[] = $effectType;
                // effect_config is meaningless without a type, and is normalised
                // back to compact JSON so what's stored is always parseable.
                $params[] = $effectType === null
                    ? null
                    : json_encode($this->normaliseEffectConfig($stage['effect_config'] ?? null));
            }

            $stmt->execute($params);
            $position++;
        }
    }

    /**
     * Accept effect_config as either a JSON string or an already-decoded array.
     */
    private function normaliseEffectConfig($raw)
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    /**
     * Validate a type payload.
     *
     * @param array $data
     * @param bool $requireName Require the name field (false on partial update)
     * @return array ['valid' => bool, 'errors' => array]
     */
    private function validateTemplate($data, $requireName = true)
    {
        $errors = [];

        if ($requireName) {
            if (empty($data['name']) || trim($data['name']) === '') {
                $errors[] = 'Pipeline type name is required';
            } elseif (mb_strlen($data['name']) > 120) {
                $errors[] = 'Name must be 120 characters or less';
            }
        }

        $stages = $data['stages'] ?? null;
        if (!is_array($stages) || count($stages) < 1) {
            $errors[] = 'At least one stage is required';
            return ['valid' => empty($errors), 'errors' => $errors];
        }

        foreach ($stages as $i => $stage) {
            $label = 'Stage #' . ((int)$i + 1);
            if (empty($stage['name']) || trim($stage['name']) === '') {
                $errors[] = "$label: name is required";
            } elseif (mb_strlen($stage['name']) > 120) {
                $errors[] = "$label: name must be 120 characters or less";
            }

            $type = $stage['assignee_type'] ?? null;
            $id = $stage['assignee_id'] ?? null;
            if (!in_array($type, ['user', 'role'], true)) {
                $errors[] = "$label: assignee_type must be 'user' or 'role'";
            } elseif (empty($id) || !is_numeric($id)) {
                $errors[] = "$label: a default owner (assignee_id) is required";
            } elseif ($type === 'user' && !$this->userExists((int)$id)) {
                $errors[] = "$label: assigned user not found";
            } elseif ($type === 'role' && !$this->roleExists((int)$id)) {
                $errors[] = "$label: assigned role not found";
            }

            $errors = array_merge($errors, $this->validateStageEffect($stage, $label));
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    /**
     * Validate a step's optional side effect.
     *
     * The action list is re-checked against the request type's snapshotted
     * ceiling at approval time as well — this is the early, friendly failure,
     * not the security boundary.
     */
    private function validateStageEffect($stage, $label)
    {
        $errors = [];

        if (empty($stage['effect_type'])) {
            return $errors;
        }

        $effectType = trim($stage['effect_type']);

        // A retired effect is neither authorable nor an error to REPORT as
        // unknown: existing request types still carry it, and an admin editing
        // an unrelated step of such a type must not be blocked from saving.
        // The round-trip preserves it; nothing new can select it, because it is
        // absent from getStageEffectTypes().
        if (in_array($effectType, PipelineConfig::getRetiredEffectTypes(), true)) {
            return $errors;
        }

        if (!in_array($effectType, PipelineConfig::getStageEffectTypes(), true)) {
            $errors[] = "$label: unknown effect type '$effectType'";
            return $errors;
        }

        if (!$this->supportsStageEffects()) {
            $errors[] = "$label: step effects are unavailable until the "
                . "pipeline-stage-effects migration has been applied";
            return $errors;
        }

        if ($effectType === PipelineConfig::EFFECT_EXECUTE_REQUEST) {
            $config = $this->normaliseEffectConfig($stage['effect_config'] ?? null);

            $actionTypes = $config['action_types'] ?? null;
            if (!is_array($actionTypes) || empty($actionTypes)) {
                $errors[] = "$label: this effect needs a non-empty 'action_types' list";
            } else {
                $known = RequestActionExecutor::actionTypes();
                foreach ($actionTypes as $actionType) {
                    if (!in_array($actionType, $known, true)) {
                        $errors[] = "$label: '$actionType' is not something a request can perform";
                    }
                }
            }
        }

        return $errors;
    }

    private function formatOwner($userId, $username, $roleId, $roleName)
    {
        if ($userId) {
            return ['type' => 'user', 'id' => (int)$userId, 'name' => $username];
        }
        if ($roleId) {
            return ['type' => 'role', 'id' => (int)$roleId, 'name' => $roleName];
        }
        return null;
    }

    private function userExists($userId)
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetchColumn() > 0;
    }

    private function roleExists($roleId)
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM roles WHERE id = ?");
        $stmt->execute([$roleId]);
        return $stmt->fetchColumn() > 0;
    }
}

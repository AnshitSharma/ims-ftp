<?php
/**
 * SchemaHelper.php
 *
 * Deploy-ordering schema probes.
 *
 * Code reaches production by FTP ~20s after a save; seeders are applied to the
 * database by hand, afterwards. Every read of a newly-added column goes through
 * this so that window is harmless rather than a site-wide 500.
 *
 * Extracted verbatim from TemporaryAccessManager::hasColumn() on 2026-08-23,
 * when the temporary-grant subsystem was retired in favour of approval-driven
 * automation. The probe never belonged to that class -- it is a generic utility
 * that the pipeline engine depends on, and it had to outlive its old home.
 *
 * The cache key format and the $GLOBALS slot are UNCHANGED on purpose: during
 * the transition some call sites use the old static and some the new one, and a
 * shared cache means the two can never disagree and never double-query.
 *
 * @package BDC_IMS
 * @subpackage Helpers
 */

class SchemaHelper
{
    /**
     * Does $table have $column yet?
     *
     * Results are cached for the life of the request -- one SHOW COLUMNS per
     * table/column at most.
     *
     * Static because BaseFunctions::loadUserPermissionData(),
     * ACL::loadUserPermissions() and the pipeline engine all need it without
     * owning an instance.
     *
     * @param PDO    $pdo
     * @param string $table
     * @param string $column
     * @return bool
     */
    public static function hasColumn(PDO $pdo, $table, $column)
    {
        $cacheKey = $table . '.' . $column;
        if (isset($GLOBALS['_schema_column_cache'][$cacheKey])) {
            return $GLOBALS['_schema_column_cache'][$cacheKey];
        }

        $exists = false;
        try {
            // Table and column are compile-time constants at every call site,
            // never request input -- SHOW COLUMNS cannot be parameterised.
            $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($column));
            $exists = ($stmt && $stmt->fetch(PDO::FETCH_ASSOC) !== false);
        } catch (Exception $e) {
            // Treat any failure as "not there" and fall back to the pre-migration
            // behaviour. A schema probe must never deny a legitimate permission.
            error_log("Schema probe failed for {$cacheKey}: " . $e->getMessage());
        }

        $GLOBALS['_schema_column_cache'][$cacheKey] = $exists;
        return $exists;
    }
}

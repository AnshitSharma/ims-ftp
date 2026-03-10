<?php
/**
 * CleanupHelper - Utility for cleaning up expired/used tokens
 *
 * Can be called manually or via cron job to prevent database bloat.
 */
class CleanupHelper {
    /**
     * Delete expired and used password reset tokens older than 24 hours
     *
     * Deletes tokens that are:
     * - Expired (past their expires_at timestamp)
     * - Used and older than 24 hours (used_at is set and > 24 hours ago)
     *
     * @param PDO $pdo Database connection
     * @return int Number of tokens deleted
     */
    public static function cleanupExpiredPasswordResets($pdo) {
        try {
            // Delete tokens that are expired OR used and older than 24 hours
            $stmt = $pdo->prepare(
                "DELETE FROM password_resets
                 WHERE expires_at < NOW()
                 OR (used_at IS NOT NULL AND used_at < DATE_SUB(NOW(), INTERVAL 24 HOUR))"
            );
            $stmt->execute();

            $deletedCount = $stmt->rowCount();

            if ($deletedCount > 0) {
                error_log("[CleanupHelper] Deleted {$deletedCount} old password reset tokens");
            }

            return $deletedCount;

        } catch (PDOException $e) {
            error_log("[CleanupHelper] Cleanup failed: " . $e->getMessage());
            return 0;
        }
    }
}

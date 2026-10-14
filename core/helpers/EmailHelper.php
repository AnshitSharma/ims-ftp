<?php
/**
 * EmailHelper - Utility for sending emails
 *
 * Sends through GraphMailer, so the reset email goes out via Microsoft Graph
 * once MAIL_SENDER_UPN is set and via PHP mail() until then. If GraphMailer
 * cannot be loaded, it calls mail() directly.
 */
if (is_readable(__DIR__ . '/GraphMailer.php')) {
    require_once(__DIR__ . '/GraphMailer.php');
}

class EmailHelper {
    /**
     * Send password reset email to user
     *
     * @param string $email User's email address
     * @param string $username User's username
     * @param string $resetLink Full URL to reset password page with token
     * @return bool True if mail was sent successfully, false otherwise
     */
    public static function sendPasswordResetEmail($email, $username, $resetLink) {
        $fromAddress = getenv('MAIL_FROM_ADDRESS') ?: 'noreply@bdc-ims.com';
        $fromName = getenv('MAIL_FROM_NAME') ?: 'BDC IMS';

        $subject = "Password Reset Request - BDC IMS";

        $htmlBody = self::getPasswordResetEmailTemplate($username, $resetLink);

        $error = null;
        if (class_exists('GraphMailer') && method_exists('GraphMailer', 'sendHtml')) {
            list($success, $error) = GraphMailer::sendHtml($email, $subject, $htmlBody);
        } else {
            $headers = "MIME-Version: 1.0\r\n";
            $headers .= "Content-type: text/html; charset=UTF-8\r\n";
            $headers .= "From: {$fromName} <{$fromAddress}>\r\n";

            $success = mail($email, $subject, $htmlBody, $headers);
        }

        // Outcome only. This used to log the recipient address AND the reset
        // link itself (2026-09-13): anyone who could read the error log held a
        // working, unexpired account-takeover link for every reset requested.
        // GraphMailer's error is a status and code, never the link.
        error_log(sprintf(
            "[EmailHelper] Password reset email %s%s",
            $success ? 'sent' : 'FAILED',
            $error ? " ($error)" : ''
        ));

        return $success;
    }

    /**
     * Get HTML email template for password reset
     *
     * @param string $username User's username
     * @param string $resetLink Full URL to reset password page with token
     * @return string HTML email content
     */
    private static function getPasswordResetEmailTemplate($username, $resetLink) {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #007bff; color: white; padding: 20px; text-align: center; }
        .content { padding: 30px 20px; background: #f9f9f9; }
        .button { display: inline-block; padding: 12px 30px; background: #007bff;
                  color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .footer { padding: 20px; text-align: center; color: #666; font-size: 12px; }
        .warning { background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>BDC Inventory Management System</h1>
        </div>
        <div class="content">
            <p>Hello {$username},</p>
            <p>We received a request to reset your password. Click the button below to create a new password:</p>
            <div style="text-align: center;">
                <a href="{$resetLink}" class="button">Reset Password</a>
            </div>
            <p>Or copy and paste this link into your browser:</p>
            <p style="word-break: break-all; color: #007bff;">{$resetLink}</p>
            <div class="warning">
                <strong>Important:</strong> This link will expire in 1 hour and can only be used once.
            </div>
            <p>If you did not request a password reset, please ignore this email. Your password will remain unchanged.</p>
        </div>
        <div class="footer">
            <p>&copy; 2025 BDC IMS. This is an automated email, please do not reply.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }
}

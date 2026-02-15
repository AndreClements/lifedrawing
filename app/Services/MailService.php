<?php

declare(strict_types=1);

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Thin wrapper around PHPMailer for SMTP delivery.
 *
 * Sends HTML emails with the octagram logo CID-embedded in the footer.
 * Plain-text body is auto-wrapped in a minimal HTML template.
 */
final class MailService
{
    private const LOGO_CID = 'octagram-logo';

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $fromAddress,
        private readonly string $fromName,
        private readonly string $encryption = 'tls',
    ) {}

    /**
     * Send an email with the octagram footer. Returns true on success.
     *
     * $body is plain text — it gets wrapped in HTML automatically.
     */
    public function send(string $to, string $subject, string $body): bool
    {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $this->host;
            $mail->SMTPAuth = true;
            $mail->Username = $this->username;
            $mail->Password = $this->password;
            $mail->SMTPSecure = $this->encryption;
            $mail->Port = $this->port;

            $mail->setFrom($this->fromAddress, $this->fromName);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->CharSet = 'UTF-8';
            $mail->isHTML(true);

            // Plain-text fallback
            $mail->AltBody = $body;

            // HTML version with octagram footer
            $mail->Body = $this->wrapHtml($body);

            // CID-embed the octagram logo
            $logoPath = $this->logoPath();
            if ($logoPath !== null) {
                $mail->addEmbeddedImage($logoPath, self::LOGO_CID, 'octagram.png', 'base64', 'image/png');
            }

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Mail send failed to {$to}: " . $mail->ErrorInfo);
            return false;
        }
    }

    private function wrapHtml(string $plainText): string
    {
        $escaped = nl2br(htmlspecialchars($plainText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

        $logoHtml = '';
        if ($this->logoPath() !== null) {
            $logoHtml = '<div style="text-align:center;padding:24px 0 8px;"><img src="cid:' . self::LOGO_CID . '" width="64" height="64" alt="Life Drawing Randburg"></div>';
        }

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head><meta charset="UTF-8"></head>
        <body style="margin:0;padding:0;background:#f5f5f0;font-family:Georgia,'Times New Roman',serif;">
          <table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f0;">
            <tr><td align="center" style="padding:32px 16px;">
              <table width="560" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:4px;">
                <tr><td style="padding:32px;color:#1a1a2e;font-size:15px;line-height:1.6;">
                  {$escaped}
                </td></tr>
                <tr><td>
                  {$logoHtml}
                </td></tr>
              </table>
            </td></tr>
          </table>
        </body>
        </html>
        HTML;
    }

    private function logoPath(): ?string
    {
        // Works both locally and on production — logo lives in public/assets/img/
        $candidates = [
            (defined('LDR_ROOT') ? LDR_ROOT : '') . '/public/assets/img/octagram.png',
            dirname(__DIR__, 2) . '/public/assets/img/octagram.png',
        ];

        foreach ($candidates as $path) {
            if ($path !== '' && file_exists($path)) {
                return $path;
            }
        }
        return null;
    }
}

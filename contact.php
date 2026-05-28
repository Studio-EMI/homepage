<?php
declare(strict_types=1);

/**
 * Studio EMI - お問い合わせフォーム受信スクリプト
 * 送信元: https://studioemi.jp/ の体験レッスン予約フォーム
 */

mb_language('Japanese');
mb_internal_encoding('UTF-8');

// ---- 設定 ----
const ADMIN_EMAIL          = 'pilates@studioemi.jp';
const FROM_EMAIL           = 'noreply@studioemi.jp';      // 管理者宛て envelope sender
const AUTO_REPLY_FROM_EMAIL = 'pilates@studioemi.jp';     // お客様への自動返信（キャリアメールでブロックされにくく）
const FROM_NAME            = 'Studio EMI';
const SITE_URL             = 'https://studioemi.jp/';
const LINE_URL             = 'https://lin.ee/zs9VNFe';
const THANKS_PAGE          = 'thanks.html';
const MIN_FORM_SECS        = 1;     // 即送信（autofill 等）も許容
const MAX_FORM_SECS        = 604800; // タブ放置7日まで許容

// ---- POST以外は弾く ----
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}

// ---- honeypot（隠しフィールドに入力があればbot）----
if (!empty($_POST['website'] ?? '')) {
    // botには成功画面を見せて静かにドロップ
    header('Location: ' . THANKS_PAGE);
    exit;
}

// ---- timestamp チェック ----
// ts が空・0 のとき（JS未実行・古い in-app browser 等）はスルー。bot判定は honeypot に任せる。
// ts が入っているときだけ「速すぎ / 古すぎ」を判定する。
$ts = (int)($_POST['ts'] ?? 0);
$now = time();
if ($ts > 0) {
    $elapsed = $now - $ts;
    if ($elapsed < MIN_FORM_SECS || $elapsed > MAX_FORM_SECS) {
        http_response_code(400);
        exit(render_error('フォームの送信タイミングが不正です。お手数ですが、ページを再読み込みしてからもう一度お試しください。'));
    }
}

// ---- 入力取得＆トリム ----
$name      = trim((string)($_POST['name']      ?? ''));
$phone     = trim((string)($_POST['phone']     ?? ''));
$email     = trim((string)($_POST['email']     ?? ''));
$preferred = trim((string)($_POST['preferred'] ?? ''));
$message   = trim((string)($_POST['message']   ?? ''));

// ---- バリデーション ----
$errors = [];
if ($name === '' || mb_strlen($name) > 100)       $errors[] = 'お名前';
if ($phone === '' || mb_strlen($phone) > 30)      $errors[] = '電話番号';
if ($email === '' || mb_strlen($email) > 200 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'メールアドレス';
}
if ($preferred === '' || mb_strlen($preferred) > 300) $errors[] = 'ご希望日時';
if (mb_strlen($message) > 2000) $errors[] = 'お悩み・ご要望';

if ($errors) {
    http_response_code(400);
    exit(render_error('入力内容にエラーがあります: ' . implode(', ', $errors)));
}

// ---- メールヘッダインジェクション対策（改行系を完全に除去） ----
$clean = static function (string $s): string {
    return preg_replace('/[\r\n\x00]+/u', '', $s) ?? '';
};
$name_safe      = $clean($name);
$phone_safe     = $clean($phone);
$email_safe     = $clean($email);
$preferred_safe = $clean($preferred);
// 本文は改行を保持、ただしCR/NULは除去
$message_safe   = preg_replace('/[\r\x00]+/u', '', $message) ?? '';

$now_str = date('Y-m-d H:i:s');
$ip      = $_SERVER['REMOTE_ADDR'] ?? '-';
$ua      = $_SERVER['HTTP_USER_AGENT'] ?? '-';

// ---- 管理者宛て通知メール ----
$admin_subject = '【Studio EMI】体験レッスン予約フォーム受信';
$admin_body = <<<EOT
Studio EMI のお問い合わせフォームから新規予約が届きました。

────────────────────────
■ お名前
{$name_safe}

■ 電話番号
{$phone_safe}

■ メールアドレス
{$email_safe}

■ ご希望日時
{$preferred_safe}

■ お悩み・ご要望
{$message_safe}
────────────────────────

受信日時 : {$now_str}
送信元IP : {$ip}
UA       : {$ua}

このメールは Studio EMI 公式サイトのお問い合わせフォームから自動送信されています。
返信は Reply-To のお客様メールアドレス宛てに送ってください。
EOT;

$from_header   = FROM_NAME . ' <' . FROM_EMAIL . '>';
$mime_from     = mb_encode_mimeheader(FROM_NAME) . ' <' . FROM_EMAIL . '>';
$reply_to_user = mb_encode_mimeheader($name_safe) . ' <' . $email_safe . '>';

$admin_headers = "From: {$mime_from}\r\n"
               . "Reply-To: {$reply_to_user}\r\n"
               . "X-Mailer: PHP/" . PHP_VERSION;

$admin_ok = mb_send_mail(ADMIN_EMAIL, $admin_subject, $admin_body, $admin_headers, '-f' . FROM_EMAIL);

// ---- お客様宛て自動返信メール ----
$site_url = SITE_URL;
$user_subject = '【Studio EMI】お問い合わせありがとうございます';
$user_body = <<<EOT
{$name_safe} 様

このたびは Studio EMI（スタジオEMI）にお問い合わせいただき、
誠にありがとうございます。

以下の内容で受け付けいたしました。
24時間以内に担当者よりご連絡いたしますので、今しばらくお待ちくださいませ。

────────────────────────
■ お名前
{$name_safe}

■ 電話番号
{$phone_safe}

■ メールアドレス
{$email_safe}

■ ご希望日時
{$preferred_safe}

■ お悩み・ご要望
{$message_safe}
────────────────────────

※このメールは自動送信です。本メールに返信いただいても確認できません。
※ご返信は studioemi へ直接ご連絡くださいませ。

────────────────────────
Studio EMI（スタジオEMI）
パーソナルピラティススタジオ
大阪・和泉
{$site_url}
EOT;

// お客様宛て自動返信は pilates@ 名義で送る（noreply@ よりキャリアメールでブロックされにくい）
$auto_reply_mime_from = mb_encode_mimeheader(FROM_NAME) . ' <' . AUTO_REPLY_FROM_EMAIL . '>';
$user_headers = "From: {$auto_reply_mime_from}\r\n"
              . "X-Mailer: PHP/" . PHP_VERSION;

$user_ok = mb_send_mail($email_safe, $user_subject, $user_body, $user_headers, '-f' . AUTO_REPLY_FROM_EMAIL);

// ---- 結果ハンドリング ----
if (!$admin_ok) {
    http_response_code(500);
    exit(render_error('送信処理でエラーが発生しました。お手数ですが、下記の LINE よりお問い合わせください。'));
}

// 成功 → thanks ページへ
header('Location: ' . THANKS_PAGE);
exit;


// ============================================================
function render_error(string $message): string
{
    $msg = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $line_url = LINE_URL;
    return <<<HTML
<!doctype html>
<html lang="ja"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="robots" content="noindex">
<title>送信エラー | Studio EMI</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, "Hiragino Kaku Gothic ProN", "Yu Gothic", sans-serif; background:#faf7f2; color:#333; margin:0; padding:48px 24px; line-height:1.7; }
  .box { max-width:520px; margin:0 auto; background:#fff; padding:32px; border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,.08); }
  h1 { font-size:1.2rem; margin:0 0 16px; color:#c00; }
  .alt { margin-top:24px; padding:18px; background:#faf7f2; border:1px solid #e5ddd2; border-radius:8px; font-size:.9rem; }
  .alt p { margin:0 0 10px; }
  .btns { margin-top:24px; display:flex; gap:12px; flex-wrap:wrap; }
  .btn { display:inline-block; padding:10px 22px; background:#333; color:#fff; text-decoration:none; border-radius:6px; font-size:.9rem; }
  .btn.line { background:#06C755; }
</style>
</head><body>
<div class="box">
<h1>送信エラー</h1>
<p>{$msg}</p>
<div class="alt">
  <p><strong>うまく送信できない場合</strong></p>
  <p>お手数をおかけしますが、LINE から直接ご連絡ください。担当者よりご案内いたします。</p>
</div>
<div class="btns">
  <a class="btn" href="javascript:history.back()">戻る</a>
  <a class="btn line" href="{$line_url}" target="_blank" rel="noopener">LINEで問い合わせ</a>
</div>
</div>
</body></html>
HTML;
}

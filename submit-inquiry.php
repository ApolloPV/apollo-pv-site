<?php
// Apollo PV Designs website inquiry handler
// Receives form submissions, emails the Apollo PV sales inbox, and stores a private CSV backup.

function clean_text($value) {
    $value = is_string($value) ? $value : '';
    $value = trim($value);
    $value = str_replace(["\r", "\0"], '', $value);
    return $value;
}

function fail_request($message) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /');
    exit;
}

// Honeypot spam check. Real users should never fill this field.
if (!empty($_POST['bot-field'] ?? '')) {
    header('Location: /thank-you.html');
    exit;
}

$name = clean_text($_POST['name'] ?? '');
$email = filter_var(clean_text($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$phone = clean_text($_POST['phone'] ?? '');
$project_type = clean_text($_POST['project_type'] ?? '');
$location = clean_text($_POST['location'] ?? '');
$need = clean_text($_POST['need'] ?? '');
$message = clean_text($_POST['message'] ?? '');

if ($name === '' || !$email || $message === '') {
    fail_request('Please go back and complete the required fields: name, email, and project details.');
}

$submitted_at = gmdate('Y-m-d H:i:s') . ' UTC';
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

$to = 'sales@apollopvdesign.com';
$subject = 'New Apollo PV website inquiry: ' . $name;

$body = "New website inquiry from apollopvdesign.com\n\n"
    . "Submitted: {$submitted_at}\n"
    . "Name: {$name}\n"
    . "Email: {$email}\n"
    . "Phone: {$phone}\n"
    . "Property type: {$project_type}\n"
    . "Project location: {$location}\n"
    . "Need: {$need}\n\n"
    . "Project details:\n{$message}\n\n"
    . "---\n"
    . "IP: {$ip}\n"
    . "User agent: {$user_agent}\n";

$headers = [];
$headers[] = 'From: Apollo PV Website <no-reply@apollopvdesign.com>';
$headers[] = 'Reply-To: ' . $name . ' <' . $email . '>';
$headers[] = 'Content-Type: text/plain; charset=UTF-8';

// Store a backup CSV inside a directory protected by .htaccess.
$storage_dir = __DIR__ . '/submissions';
if (!is_dir($storage_dir)) {
    mkdir($storage_dir, 0755, true);
}
$deny_file = $storage_dir . '/.htaccess';
if (!file_exists($deny_file)) {
    file_put_contents($deny_file, "Require all denied\n");
}
$index_file = $storage_dir . '/index.html';
if (!file_exists($index_file)) {
    file_put_contents($index_file, "<!doctype html><title>Not found</title>\n");
}

$csv_path = $storage_dir . '/inquiries.csv';
$is_new_file = !file_exists($csv_path);
$fh = fopen($csv_path, 'a');
if ($fh) {
    if ($is_new_file) {
        fputcsv($fh, ['submitted_at', 'name', 'email', 'phone', 'property_type', 'location', 'need', 'message', 'ip', 'user_agent']);
    }
    fputcsv($fh, [$submitted_at, $name, $email, $phone, $project_type, $location, $need, $message, $ip, $user_agent]);
    fclose($fh);
}

$mail_sent = mail($to, $subject, $body, implode("\r\n", $headers));

// Even if mail fails, the CSV backup should keep the inquiry retrievable.
header('Location: /thank-you.html' . ($mail_sent ? '?sent=1' : '?saved=1'));
exit;

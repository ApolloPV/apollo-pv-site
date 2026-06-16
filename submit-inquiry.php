<?php
// Apollo PV Designs website inquiry handler
// Receives form submissions, emails the Apollo PV sales inbox, and stores a private CSV backup.

function clean_text($value) {
    $value = is_string($value) ? $value : '';
    $value = trim($value);
    $value = str_replace(["\r", "\0"], '', $value);
    return $value;
}



function normalize_phone($phone) {
    $phone = trim($phone);
    if ($phone === '') {
        return '';
    }
    if (strpos($phone, '+') === 0) {
        return preg_replace('/[^+0-9]/', '', $phone);
    }
    $digits = preg_replace('/\D+/', '', $phone);
    if (strlen($digits) === 10) {
        return '+1' . $digits;
    }
    if (strlen($digits) === 11 && strpos($digits, '1') === 0) {
        return '+' . $digits;
    }
    return $phone;
}

function append_sms_log($direction, $from, $to, $body, $message_sid, $sms_status = '') {
    $storage_dir = __DIR__ . '/submissions';
    if (!is_dir($storage_dir)) {
        mkdir($storage_dir, 0755, true);
    }
    $csv_path = $storage_dir . '/sms-messages.csv';
    $is_new_file = !file_exists($csv_path);
    $fh = fopen($csv_path, 'a');
    if ($fh) {
        if ($is_new_file) {
            fputcsv($fh, ['submitted_at', 'direction', 'from', 'to', 'body', 'message_sid', 'sms_status']);
        }
        fputcsv($fh, [gmdate('Y-m-d H:i:s') . ' UTC', $direction, $from, $to, $body, $message_sid, $sms_status]);
        fclose($fh);
    }
}

function send_twilio_sms($to_phone, $name) {
    $config_path = __DIR__ . '/config/twilio.php';
    if (!file_exists($config_path)) {
        return 'missing_config';
    }
    $config = include $config_path;
    $sid = $config['account_sid'] ?? '';
    $token = $config['auth_token'] ?? '';
    $from = $config['from_number'] ?? '';
    if ($sid === '' || $token === '' || $from === '') {
        return 'missing_config_values';
    }
    if (!function_exists('curl_init')) {
        return 'missing_curl';
    }

    $to = normalize_phone($to_phone);
    if ($to === '') {
        return 'missing_phone';
    }

    $first = trim(explode(' ', trim($name))[0] ?? '');
    $greeting = $first !== '' ? "Hi {$first}" : 'Hi';
    $body = $greeting . ", this is Apollo PV Designs. Thanks for requesting solar info. I can help collect a few details so we can review your project. Is this for a home or business? Reply STOP to opt out.";

    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'From' => $from,
        'To' => $to,
        'Body' => $body,
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    curl_setopt($ch, CURLOPT_USERPWD, $sid . ':' . $token);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $status < 200 || $status >= 300) {
        throw new Exception('Twilio API error ' . $status . ': ' . ($error ?: $response));
    }
    $decoded = json_decode($response, true);
    $message_sid = $decoded['sid'] ?? 'sent';
    append_sms_log('outbound', $from, $to, $body, $message_sid, $decoded['status'] ?? 'queued');
    return 'sms_' . $message_sid;
}

function hubspot_request($method, $path, $token, $payload = null) {
    $url = 'https://api.hubapi.com' . $path;
    $ch = curl_init($url);
    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
    ];
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $status < 200 || $status >= 300) {
        throw new Exception('HubSpot API error ' . $status . ': ' . ($error ?: $body));
    }
    return json_decode($body, true);
}

function push_to_hubspot($name, $email, $phone, $project_type, $location, $need, $message, $submitted_at) {
    $config_path = __DIR__ . '/config/hubspot.php';
    if (!file_exists($config_path)) {
        return 'missing_config';
    }
    $config = include $config_path;
    $token = $config['token'] ?? '';
    $pipeline_id = $config['pipeline_id'] ?? '';
    $stage_id = $config['stage_id'] ?? '';
    if ($token === '' || $pipeline_id === '' || $stage_id === '') {
        return 'missing_config_values';
    }
    if (!function_exists('curl_init')) {
        return 'missing_curl';
    }

    $parts = preg_split('/\s+/', trim($name), 2);
    $firstname = $parts[0] ?? $name;
    $lastname = $parts[1] ?? '';

    $search = hubspot_request('POST', '/crm/v3/objects/contacts/search', $token, [
        'filterGroups' => [[
            'filters' => [[
                'propertyName' => 'email',
                'operator' => 'EQ',
                'value' => $email,
            ]],
        ]],
        'properties' => ['email', 'firstname', 'lastname', 'phone'],
        'limit' => 1,
    ]);

    $contact_props = [
        'email' => $email,
        'firstname' => $firstname,
        'lastname' => $lastname,
        'phone' => $phone,
        'lifecyclestage' => 'lead',
        'hs_lead_status' => 'NEW',
    ];

    if (!empty($search['results'][0]['id'])) {
        $contact_id = $search['results'][0]['id'];
        hubspot_request('PATCH', '/crm/v3/objects/contacts/' . $contact_id, $token, ['properties' => $contact_props]);
    } else {
        $created = hubspot_request('POST', '/crm/v3/objects/contacts', $token, ['properties' => $contact_props]);
        $contact_id = $created['id'];
    }

    $deal_description = "Website solar inquiry submitted {$submitted_at}

"
        . "Name: {$name}
Email: {$email}
Phone: {$phone}
"
        . "Property type: {$project_type}
Project location: {$location}
Need: {$need}

"
        . "Project details:
{$message}";

    $deal = hubspot_request('POST', '/crm/v3/objects/deals', $token, [
        'properties' => [
            'dealname' => 'Website Inquiry - ' . $name,
            'pipeline' => $pipeline_id,
            'dealstage' => $stage_id,
            'description' => substr($deal_description, 0, 6000),
        ],
        'associations' => [[
            'to' => ['id' => $contact_id],
            'types' => [[
                'associationCategory' => 'HUBSPOT_DEFINED',
                'associationTypeId' => 3,
            ]],
        ]],
    ]);

    return 'deal_' . ($deal['id'] ?? 'created');
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

$hubspot_status = 'not_attempted';
try {
    $hubspot_status = push_to_hubspot($name, $email, $phone, $project_type, $location, $need, $message, $submitted_at);
} catch (Exception $e) {
    $hubspot_status = 'error';
    file_put_contents($storage_dir . '/hubspot-errors.log', '[' . $submitted_at . '] ' . $e->getMessage() . "\n", FILE_APPEND);
}

$sms_status = 'not_attempted';
try {
    $sms_status = send_twilio_sms($phone, $name);
} catch (Exception $e) {
    $sms_status = 'error';
    file_put_contents($storage_dir . '/twilio-errors.log', '[' . $submitted_at . '] ' . $e->getMessage() . "\n", FILE_APPEND);
}

// Even if mail, HubSpot, or SMS fails, the CSV backup should keep the inquiry retrievable.
$query_parts = [];
$query_parts[] = $mail_sent ? 'sent=1' : 'saved=1';
if (strpos($hubspot_status, 'deal_') === 0) {
    $query_parts[] = 'hubspot=1';
}
if (strpos($sms_status, 'sms_') === 0) {
    $query_parts[] = 'sms=1';
}
header('Location: /thank-you.html?' . implode('&', $query_parts));
exit;
